<?php

namespace App\Services;

class AgentToolPolicyService
{
    /**
     * @var array<string, string>
     */
    private array $riskByTool = [
        'list_orders' => 'low',
        'get_order' => 'low',
        'financial_report' => 'low',
        'get_report_task_status' => 'low',
        'create_task' => 'medium',
        'create_report_task' => 'medium',
        'setup_integration' => 'medium',
        'scaffold_mcp_tool' => 'medium',
        'model_schema_workspace' => 'medium',
        'send_email' => 'high',
        'send_grid_email' => 'high',
        'send_whatsapp_message' => 'high',
        'create_order' => 'high',
        'edit_order' => 'high',
    ];

    /**
     * @param array<string, mixed> $args
     * @return array{allowed: bool, risk: string, reason: string|null, requires_confirmation: bool}
     */
    public function authorize(string $toolName, array $args): array
    {
        $risk = $this->riskByTool[$toolName] ?? 'medium';
        $requiresConfirmation = in_array($risk, ['high', 'critical'], true);

        if (! $requiresConfirmation) {
            return [
                'allowed' => true,
                'risk' => $risk,
                'reason' => null,
                'requires_confirmation' => false,
            ];
        }

        $isConfirmed = filter_var($args['confirmed'] ?? false, FILTER_VALIDATE_BOOL);
        if ($isConfirmed) {
            return [
                'allowed' => true,
                'risk' => $risk,
                'reason' => null,
                'requires_confirmation' => true,
            ];
        }

        return [
            'allowed' => false,
            'risk' => $risk,
            'reason' => 'Explicit confirmation is required for this high-risk action. Re-run with confirmed=true.',
            'requires_confirmation' => true,
        ];
    }

    public function riskFor(string $toolName): string
    {
        return $this->riskByTool[$toolName] ?? 'medium';
    }
}
