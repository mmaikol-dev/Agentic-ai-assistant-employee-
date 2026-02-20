<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

class ChatMemoryService
{
    public function resolveConversation(Authenticatable $user, ?string $conversationId = null): ChatConversation
    {
        $userId = (int) $user->getAuthIdentifier();

        if (is_string($conversationId) && $conversationId !== '') {
            $conversation = ChatConversation::query()
                ->where('id', $conversationId)
                ->where('user_id', $userId)
                ->first();

            if ($conversation !== null) {
                return $conversation;
            }
        }

        $existing = ChatConversation::query()
            ->where('user_id', $userId)
            ->orderByDesc('last_activity_at')
            ->orderByDesc('created_at')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return ChatConversation::query()->create([
            'user_id' => $userId,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    public function recentMessages(ChatConversation $conversation, int $maxMessages = 20): array
    {
        return ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->latest('id')
            ->limit(max(1, $maxMessages))
            ->get(['role', 'content'])
            ->reverse()
            ->map(function (ChatMessage $message): array {
                return [
                    'role' => (string) $message->role,
                    'content' => (string) $message->content,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $assistantMetadata
     */
    public function persistExchange(
        ChatConversation $conversation,
        string $userMessage,
        string $assistantMessage,
        array $assistantMetadata = []
    ): void {
        ChatMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        if (trim($assistantMessage) !== '') {
            ChatMessage::query()->create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $assistantMessage,
                'metadata' => $assistantMetadata === [] ? null : $assistantMetadata,
            ]);
        }

        $conversation->forceFill([
            'last_activity_at' => Carbon::now(),
        ])->save();
    }
}
