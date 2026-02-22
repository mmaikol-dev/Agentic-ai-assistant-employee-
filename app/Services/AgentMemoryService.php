<?php

namespace App\Services;

use App\Models\AgentMemory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AgentMemoryService
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function storeEpisode(int $userId, string $scope, string $memoryKey, string $content, array $metadata = []): AgentMemory
    {
        if (! $this->isReady()) {
            return new AgentMemory([
                'user_id' => $userId,
                'scope' => $scope,
                'memory_key' => $memoryKey,
                'content' => $content,
                'embedding' => $this->simpleEmbedding($content),
                'metadata' => $metadata,
                'last_accessed_at' => now(),
            ]);
        }

        try {
            return AgentMemory::query()->create([
                'user_id' => $userId,
                'scope' => $scope,
                'memory_key' => $memoryKey,
                'content' => $content,
                'embedding' => $this->simpleEmbedding($content),
                'metadata' => $metadata,
                'last_accessed_at' => now(),
            ]);
        } catch (QueryException $e) {
            Log::warning('Skipping memory store because persistence is unavailable.', [
                'error' => $e->getMessage(),
            ]);

            return new AgentMemory([
                'user_id' => $userId,
                'scope' => $scope,
                'memory_key' => $memoryKey,
                'content' => $content,
                'embedding' => $this->simpleEmbedding($content),
                'metadata' => $metadata,
                'last_accessed_at' => now(),
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function retrieveRelevant(int $userId, string $query, int $limit = 5): array
    {
        if (trim($query) === '') {
            return [];
        }

        if (! $this->isReady()) {
            return [];
        }

        try {
            $records = AgentMemory::query()
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get();
        } catch (QueryException $e) {
            Log::warning('Skipping memory retrieval because persistence is unavailable.', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $scored = $records->map(function (AgentMemory $memory) use ($query): array {
            $score = $this->lexicalSimilarity($query, (string) $memory->content);
            return [
                'id' => (string) $memory->id,
                'scope' => (string) $memory->scope,
                'memory_key' => (string) $memory->memory_key,
                'content' => (string) $memory->content,
                'metadata' => is_array($memory->metadata) ? $memory->metadata : [],
                'score' => $score,
            ];
        })->sortByDesc('score')->take($limit)->values()->all();

        try {
            AgentMemory::query()
                ->whereIn('id', collect($scored)->pluck('id')->all())
                ->update(['last_accessed_at' => now()]);
        } catch (QueryException) {
            // Keep retrieval non-blocking even if update fails.
        }

        return $scored;
    }

    private function isReady(): bool
    {
        try {
            return Schema::hasTable('agent_memories');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, float>
     */
    private function simpleEmbedding(string $text): array
    {
        $tokens = $this->tokens($text);
        $vector = array_fill(0, 16, 0.0);

        foreach ($tokens as $token) {
            $bucket = crc32($token) % 16;
            $vector[$bucket] += 1.0;
        }

        return $vector;
    }

    private function lexicalSimilarity(string $a, string $b): float
    {
        $ta = array_unique($this->tokens($a));
        $tb = array_unique($this->tokens($b));

        if ($ta === [] || $tb === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($ta, $tb));
        $union = count(array_unique([...$ta, ...$tb]));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        return array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [],
            fn ($token) => strlen($token) >= 3
        ));
    }
}
