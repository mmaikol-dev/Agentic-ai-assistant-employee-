export interface TaskPayload {
    title: string;
    description?: string;
    schedule_type: 'immediate' | 'one_time' | 'recurring' | 'event_triggered';
    run_at?: string;
    cron_expression?: string;
    cron_human?: string;
    event_condition?: string;
    timezone?: string;
    priority?: 'low' | 'normal' | 'high';
    execution_plan?: Array<{
        step: number;
        action: string;
        tool?: string;
        tool_input?: Record<string, unknown>;
        input_summary?: string;
        depends_on?: number[];
    }>;
    expected_output?: string;
    original_user_request?: string;
}

type TaskApiResponse = {
    task: {
        id: string;
        title: string;
    };
    message: string;
};

function getCsrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export async function submitTaskToBackend(
    payload: TaskPayload,
    chatMessageId?: string,
): Promise<TaskApiResponse | null> {
    try {
        const response = await fetch('/api/tasks', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                ...payload,
                chat_message_id: chatMessageId,
            }),
        });

        if (!response.ok) {
            throw new Error(`Failed to create task (${response.status}).`);
        }

        return (await response.json()) as TaskApiResponse;
    } catch (error) {
        console.error('Task creation error:', error);
        return null;
    }
}
