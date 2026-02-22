<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class McpToolInvokerService
{
    /**
     * @param class-string $toolClass
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function invoke(string $toolClass, array $args): array
    {
        if (! class_exists($toolClass)) {
            return ['type' => 'error', 'message' => "MCP tool class not found: {$toolClass}"];
        }

        try {
            /** @var object $tool */
            $tool = app($toolClass);
            if (! method_exists($tool, 'handle')) {
                return ['type' => 'error', 'message' => "MCP tool has no handle method: {$toolClass}"];
            }

            $request = new class($args) extends Request
            {
                /**
                 * Backward-compatible accessor for tools that call $request->arguments().
                 *
                 * @return array<string, mixed>
                 */
                public function arguments(): array
                {
                    return $this->all();
                }
            };
            $method = new \ReflectionMethod($tool, 'handle');
            $response = $method->getNumberOfParameters() >= 2
                ? $tool->handle($request, Response::text(''))
                : $tool->handle($request);
            $raw = (string) $response->content();

            if ($response->isError()) {
                return [
                    'type' => 'error',
                    'message' => $raw !== '' ? $raw : 'MCP tool returned an error response.',
                    'tool_class' => $toolClass,
                ];
            }

            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $decoded['tool_class'] = $toolClass;
                $decoded['type'] = (string) ($decoded['type'] ?? 'mcp_tool_result');
                return $decoded;
            }

            return [
                'type' => 'mcp_tool_result',
                'tool_class' => $toolClass,
                'content' => $raw,
            ];
        } catch (\Throwable $e) {
            Log::warning('Failed to invoke MCP tool', [
                'tool_class' => $toolClass,
                'error' => $e->getMessage(),
            ]);

            return [
                'type' => 'error',
                'message' => 'MCP tool invocation failed: '.$e->getMessage(),
                'tool_class' => $toolClass,
            ];
        }
    }
}
