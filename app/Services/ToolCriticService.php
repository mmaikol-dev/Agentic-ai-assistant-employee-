<?php

namespace App\Services;

class ToolCriticService
{
    /**
     * @param array<string, mixed> $result
     * @return array{ok: bool, issues: array<int, string>, severity: string}
     */
    public function evaluate(string $toolName, array $result): array
    {
        $issues = [];
        $severity = 'low';

        if (($result['type'] ?? null) === 'error') {
            return [
                'ok' => false,
                'issues' => [(string) ($result['message'] ?? 'Tool failed.')],
                'severity' => 'high',
            ];
        }

        if ($toolName === 'financial_report') {
            $totalOrders = (int) ($result['total_orders'] ?? 0);
            $totalRevenue = (float) ($result['total_revenue'] ?? 0);
            if ($totalOrders <= 0) {
                $issues[] = 'Financial report returned zero orders.';
            }
            if ($totalRevenue < 0) {
                $issues[] = 'Financial report returned negative revenue.';
                $severity = 'high';
            }
        }

        if ($toolName === 'send_whatsapp_message' && ($result['type'] ?? '') !== 'whatsapp_message_sent') {
            $issues[] = 'WhatsApp send did not return success type.';
            $severity = 'high';
        }

        if ($toolName === 'create_task') {
            if (empty($result['id']) || empty($result['task_url'])) {
                $issues[] = 'Task creation missing id or task_url.';
                $severity = 'high';
            }
        }

        return [
            'ok' => $issues === [],
            'issues' => $issues,
            'severity' => $severity,
        ];
    }
}
