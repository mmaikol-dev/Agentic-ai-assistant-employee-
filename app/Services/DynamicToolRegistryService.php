<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tool as McpTool;

class DynamicToolRegistryService
{
    /**
     * @param array<int, array<string, mixed>> $builtInTools
     * @return array{tools: array<int, array<string, mixed>>, mcp_map: array<string, class-string>}
     */
    public function build(array $builtInTools): array
    {
        $builtInNames = collect($builtInTools)
            ->map(fn (array $tool) => (string) data_get($tool, 'function.name', ''))
            ->filter(fn (string $name) => $name !== '')
            ->values()
            ->all();

        $tools = $builtInTools;
        $mcpMap = [];
        $registeredNames = [];

        foreach ($this->discoverMcpToolClasses() as $toolClass) {
            try {
                /** @var McpTool $instance */
                $instance = app($toolClass);
                $serialized = $instance->toArray();
                $name = (string) ($serialized['name'] ?? '');
                $classBase = class_basename($toolClass);
                $derived = Str::snake((string) preg_replace('/Tool$/', '', $classBase));
                $publicName = $derived !== '' ? $derived : $name;

                if ($publicName === '' && $name === '') {
                    continue;
                }
                if ($publicName === '') {
                    $publicName = $name;
                }
                $isDuplicateName = in_array($publicName, $builtInNames, true) || in_array($publicName, $registeredNames, true);

                $inputSchema = is_array($serialized['inputSchema'] ?? null)
                    ? $serialized['inputSchema']
                    : ['type' => 'object', 'properties' => []];

                if (! $isDuplicateName) {
                    $tools[] = [
                        'type' => 'function',
                        'function' => [
                            'name' => $publicName,
                            'description' => (string) ($serialized['description'] ?? $publicName),
                            'parameters' => [
                                'type' => 'object',
                                'properties' => is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [],
                                'required' => is_array($inputSchema['required'] ?? null) ? $inputSchema['required'] : [],
                            ],
                        ],
                    ];
                    $registeredNames[] = $publicName;
                }

                foreach ($this->aliasesForToolName($publicName, $name, $derived) as $alias) {
                    if ($alias === '') {
                        continue;
                    }
                    $mcpMap[$alias] = $toolClass;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return [
            'tools' => $tools,
            'mcp_map' => $mcpMap,
        ];
    }

    /**
     * @return array<int, class-string>
     */
    private function discoverMcpToolClasses(): array
    {
        $toolsDir = app_path('Mcp/Tools');
        if (! File::isDirectory($toolsDir)) {
            return [];
        }

        $classes = [];
        foreach (File::files($toolsDir) as $file) {
            $base = $file->getFilenameWithoutExtension();
            $class = 'App\\Mcp\\Tools\\'.$base;
            if (! class_exists($class)) {
                continue;
            }
            if (! is_subclass_of($class, McpTool::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * @return array<int, string>
     */
    private function aliasesForToolName(string ...$names): array
    {
        $aliases = [];

        foreach ($names as $name) {
            $base = trim(Str::lower($name));
            if ($base === '') {
                continue;
            }

            $aliases[] = $base;
            $aliases[] = Str::snake($base);
            $aliases[] = str_replace(['_', '-'], '', $base);
        }

        return array_values(array_unique(array_filter($aliases, fn (string $v): bool => $v !== '')));
    }
}
