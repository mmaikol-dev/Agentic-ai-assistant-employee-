<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\FinancialReportExportController;
use App\Http\Controllers\ReportTaskController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\WhatsappController;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::get('dashboard', function () {
    return redirect()->route('chat');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::get('chat', function (Request $request) {
    $selectedConversationId = $request->string('conversation')->toString();
    $userId = (int) $request->user()->id;

    $conversation = null;
    if ($selectedConversationId !== '') {
        $conversation = ChatConversation::query()
            ->where('user_id', $userId)
            ->where('id', $selectedConversationId)
            ->first();
    }

    $initialMessages = [];
    if ($conversation !== null) {
        $initialMessages = ChatMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get(['id', 'role', 'content'])
            ->map(fn (ChatMessage $message): array => [
                'id' => 'msg-'.$message->id,
                'role' => (string) $message->role,
                'content' => (string) $message->content,
            ])
            ->values()
            ->all();
    }

    return Inertia::render('chat', [
        'ollamaModel' => config('services.ollama.model'),
        'initialConversationId' => $conversation?->id,
        'initialMessages' => $initialMessages,
    ]);
})->middleware(['auth', 'verified'])->name('chat');

Route::post('chat/message', [ChatController::class, 'store'])
    ->middleware(['auth', 'verified'])
    ->name('chat.message');

Route::post('chat/stream', [ChatController::class, 'stream'])
    ->middleware(['auth', 'verified'])
    ->name('chat.stream');

Route::get('reports/financial/export', [FinancialReportExportController::class, 'download'])
    ->middleware(['auth', 'verified'])
    ->name('reports.financial.export');

Route::get('report-tasks/{taskId}', [ReportTaskController::class, 'show'])
    ->middleware(['auth', 'verified'])
    ->name('report-tasks.show');

Route::post('report-tasks/{taskId}/confirm', [ReportTaskController::class, 'confirm'])
    ->middleware(['auth', 'verified'])
    ->name('report-tasks.confirm');

Route::post('whatsapp/send-chat', [WhatsappController::class, 'sendChat'])
    ->middleware(['auth', 'verified'])
    ->name('whatsapp.send-chat');

Route::post('whatsapp/send-message/{id}', [WhatsappController::class, 'sendMessage'])
    ->middleware(['auth', 'verified'])
    ->name('whatsapp.send-message');

Route::post('whatsapp/webhook', [WhatsappController::class, 'webhook'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('whatsapp.webhook');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::post('tasks/{task}/cancel', [TaskController::class, 'cancel'])->name('tasks.cancel');
    Route::post('tasks/{task}/retry', [TaskController::class, 'retry'])->name('tasks.retry');
    Route::get('tasks/{task}/stream', [TaskController::class, 'stream'])->name('tasks.stream');
});

require __DIR__.'/settings.php';
