<?php

namespace App\Services;

use App\Models\AgentMemory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AgentMemoryService
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function storeEpisode(int $userId, string $scope, string $memoryKey, string $content, array $metadata = []): AgentMemory
    {
        $semanticEmbedding = $this->embedText($content);
        $embedding = $semanticEmbedding ?? $this->simpleEmbedding($content);
        $enrichedMetadata = $this->withEmbeddingMetadata($metadata, isRealEmbedding: $semanticEmbedding !== null);

        if (! $this->isReady()) {
            return new AgentMemory([
                'user_id' => $userId,
                'scope' => $scope,
                'memory_key' => $memoryKey,
                'content' => $content,
                'embedding' => $embedding,
                'metadata' => $enrichedMetadata,
                'last_accessed_at' => now(),
            ]);
        }

        try {
            return AgentMemory::query()->create([
                'user_id' => $userId,
                'scope' => $scope,
                'memory_key' => $memoryKey,
                'content' => $content,
                'embedding' => $embedding,
                'metadata' => $enrichedMetadata,
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
                'embedding' => $embedding,
                'metadata' => $enrichedMetadata,
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
                ->limit(250)
                ->get();
        } catch (QueryException $e) {
            Log::warning('Skipping memory retrieval because persistence is unavailable.', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $querySemantic = $this->embedText($query);
        $queryFallback = $this->simpleEmbedding($query);

        $scored = $records->map(function (AgentMemory $memory) use ($query): array {
            $score = $this->lexicalSimilarity($query, (string) $memory->content);
            return [
                'id' => (string) $memory->id,
                'scope' => (string) $memory->scope,
                'memory_key' => (string) $memory->memory_key,
                'content' => (string) $memory->content,
                'embedding' => is_array($memory->embedding) ? $memory->embedding : null,
                'metadata' => is_array($memory->metadata) ? $memory->metadata : [],
                'score' => $score,
            ];
        })->map(function (array $row) use ($querySemantic, $queryFallback): array {
            $memoryVector = $this->normalizeVector($row['embedding'] ?? null);
            $semanticScore = $this->semanticSimilarity(
                $querySemantic ?? $queryFallback,
                $memoryVector
            );

            if ($semanticScore !== null) {
                $row['score'] = ($semanticScore * 0.82) + ((float) $row['score'] * 0.18);
                $row['semantic_score'] = $semanticScore;
            }

            return $row;
        })->sortByDesc('score')->take($limit)->values()->map(function (array $row): array {
            unset($row['embedding']);
            return $row;
        })->all();

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
     * @param array<int, float>|null $queryEmbedding
     * @param array<int, float>|null $memoryEmbedding
     */
    private function semanticSimilarity(?array $queryEmbedding, ?array $memoryEmbedding): ?float
    {
        if ($queryEmbedding === null || $memoryEmbedding === null) {
            return null;
        }

        $size = min(count($queryEmbedding), count($memoryEmbedding));
        if ($size === 0 || count($queryEmbedding) !== count($memoryEmbedding)) {
            return null;
        }

        $dot = 0.0;
        $queryNorm = 0.0;
        $memoryNorm = 0.0;

        for ($i = 0; $i < $size; $i++) {
            $q = (float) $queryEmbedding[$i];
            $m = (float) $memoryEmbedding[$i];
            $dot += $q * $m;
            $queryNorm += $q * $q;
            $memoryNorm += $m * $m;
        }

        if ($queryNorm <= 0 || $memoryNorm <= 0) {
            return null;
        }

        return $dot / (sqrt($queryNorm) * sqrt($memoryNorm));
    }

    /**
     * @return array<int, float>|null
     */
    private function embedText(string $text): ?array
    {
        $model = trim((string) config('services.ollama.embedding_model', ''));
        if ($model === '' || trim($text) === '') {
            return null;
        }

        $baseUrl = (string) config('services.ollama.base_url', 'http://127.0.0.1:11434');
        $timeout = (int) config('services.ollama.embedding_timeout', 20);
        $payloadText = mb_substr($text, 0, 5000);

        try {
            $legacy = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->timeout(max(5, $timeout))
                ->post('/api/embeddings', [
                    'model' => $model,
                    'prompt' => $payloadText,
                ]);

            if ($legacy->successful()) {
                $vector = $this->extractEmbeddingVector($legacy->json());
                if ($vector !== null) {
                    return $vector;
                }
            }

            $modern = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->timeout(max(5, $timeout))
                ->post('/api/embed', [
                    'model' => $model,
                    'input' => $payloadText,
                ]);

            if ($modern->successful()) {
                return $this->extractEmbeddingVector($modern->json());
            }
        } catch (ConnectionException $e) {
            Log::warning('Embedding connection failed; falling back to lexical memory retrieval.', [
                'error' => $e->getMessage(),
                'model' => $model,
                'base_url' => $baseUrl,
            ]);
        } catch (Throwable $e) {
            Log::warning('Embedding generation failed; falling back to lexical memory retrieval.', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);
        }

        return null;
    }

    /**
     * @param mixed $raw
     * @return array<int, float>|null
     */
    private function extractEmbeddingVector(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $candidates = [
            $raw['embedding'] ?? null,
            $raw['embeddings'][0] ?? null,
            $raw['data'][0]['embedding'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $vector = $this->normalizeVector($candidate);
            if ($vector !== null) {
                return $vector;
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @return array<int, float>|null
     */
    private function normalizeVector(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $vector = [];
        foreach ($value as $item) {
            if (! is_numeric($item)) {
                return null;
            }

            $num = (float) $item;
            if (! is_finite($num)) {
                return null;
            }
            $vector[] = $num;
        }

        return $vector === [] ? null : $vector;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function withEmbeddingMetadata(array $metadata, bool $isRealEmbedding): array
    {
        $base = $metadata;
        $base['embedding_kind'] = $isRealEmbedding ? 'ollama' : 'fallback_hash';

        if ($isRealEmbedding) {
            $base['embedding_model'] = (string) config('services.ollama.embedding_model', '');
        }

        return $base;
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
