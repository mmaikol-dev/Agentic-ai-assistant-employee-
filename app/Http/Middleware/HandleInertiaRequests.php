<?php

namespace App\Http\Middleware;

use App\Models\ChatConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $chatSessions = [];
        $user = $request->user();

        if ($user !== null) {
            $chatSessions = ChatConversation::query()
                ->where('user_id', (int) $user->id)
                ->with([
                    'latestMessage' => static fn ($query) => $query->select([
                        'chat_messages.id',
                        'chat_messages.conversation_id',
                        'chat_messages.content',
                        'chat_messages.created_at',
                    ]),
                ])
                ->orderByDesc('last_activity_at')
                ->orderByDesc('created_at')
                ->limit(12)
                ->get()
                ->map(function (ChatConversation $conversation): array {
                    $preview = trim((string) ($conversation->latestMessage?->content ?? ''));
                    $title = trim((string) ($conversation->title ?? ''));

                    if ($title === '') {
                        $title = $preview !== ''
                            ? Str::limit($preview, 42)
                            : 'New conversation';
                    }

                    return [
                        'id' => (string) $conversation->id,
                        'title' => $title,
                        'preview' => $preview !== '' ? Str::limit($preview, 80) : 'No messages yet',
                        'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
                    ];
                })
                ->values()
                ->all();
        }

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'chat' => [
                'sessions' => $chatSessions,
            ],
        ];
    }
}
