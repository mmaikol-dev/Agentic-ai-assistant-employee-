<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ToolsController extends Controller
{
    public function index(): Response
    {
        $root = app_path('Mcp/Tools');
        if (! File::isDirectory($root)) {
            return Inertia::render('tools/index', [
                'tools' => [],
            ]);
        }

        $tools = collect(File::allFiles($root))
            ->filter(fn ($file): bool => Str::endsWith($file->getFilename(), '.php'))
            ->map(function ($file) use ($root): array {
                $fullPath = $file->getPathname();
                $content = (string) File::get($fullPath);
                $relativePath = Str::of($fullPath)
                    ->replace('\\', '/')
                    ->after(str_replace('\\', '/', $root).'/')
                    ->toString();
                $toolKey = Str::beforeLast($relativePath, '.php');
                $label = (string) Str::of($toolKey)->afterLast('/')->replace('-', ' ')->title();
                $className = (string) Str::beforeLast($file->getFilename(), '.php');
                $description = $this->extractDescription($content);
                $category = $this->inferCategory($className, $description);
                $methodCount = preg_match_all('/\bfunction\s+[a-zA-Z_][a-zA-Z0-9_]*\s*\(/', $content);
                $lineCount = count(preg_split('/\R/', $content) ?: []);
                $sourceExplained = $this->buildSourceExplanation($className, $description, $content);

                return [
                    'key' => $toolKey,
                    'label' => $label,
                    'path' => $relativePath,
                    'content' => $content,
                    'class_name' => $className,
                    'description' => $description,
                    'category' => $category,
                    'icon_key' => $this->iconForCategory($category),
                    'risk_level' => $this->riskForClassName($className),
                    'method_count' => (int) ($methodCount ?: 0),
                    'line_count' => $lineCount,
                    'source_explained' => $sourceExplained,
                    'updated_at' => now()->setTimestamp($file->getMTime())->toIso8601String(),
                ];
            })
            ->sortBy('label')
            ->values()
            ->all();

        return Inertia::render('tools/index', [
            'tools' => $tools,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
            'content' => ['present', 'string'],
        ]);

        $path = $this->resolveToolFilePath((string) $validated['key']);
        if ($path === null) {
            return back()->withErrors([
                'key' => 'Invalid tool path.',
            ]);
        }

        File::put($path, (string) $validated['content']);

        return to_route('tools.index', status: 303);
    }

    private function resolveToolFilePath(string $key): ?string
    {
        $root = app_path('Mcp/Tools');
        $realRoot = realpath($root);
        if ($realRoot === false) {
            return null;
        }

        $normalized = trim(str_replace('\\', '/', $key), '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            return null;
        }

        $candidate = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalized).'.php';
        $realCandidate = realpath($candidate);
        if ($realCandidate === false || ! str_starts_with($realCandidate, $realRoot)) {
            return null;
        }

        if (! File::exists($realCandidate)) {
            return null;
        }

        return $realCandidate;
    }

    private function extractDescription(string $content): string
    {
        if (preg_match('/protected\s+string\s+\$description\s*=\s*[\'"](.+?)[\'"]\s*;/s', $content, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return 'No tool description found.';
    }

    private function inferCategory(string $className, string $description): string
    {
        $name = Str::lower($className.' '.$description);

        if (str_contains($name, 'report')) {
            return 'reporting';
        }
        if (str_contains($name, 'send') || str_contains($name, 'email') || str_contains($name, 'whatsapp')) {
            return 'messaging';
        }
        if (str_contains($name, 'create') || str_contains($name, 'edit') || str_contains($name, 'update') || str_contains($name, 'mutation')) {
            return 'mutation';
        }
        if (str_contains($name, 'get') || str_contains($name, 'list') || str_contains($name, 'query')) {
            return 'query';
        }
        if (str_contains($name, 'scaffold') || str_contains($name, 'schema') || str_contains($name, 'workspace')) {
            return 'platform';
        }

        return 'general';
    }

    private function iconForCategory(string $category): string
    {
        return match ($category) {
            'reporting' => 'chart',
            'messaging' => 'message',
            'mutation' => 'edit',
            'query' => 'search',
            'platform' => 'wrench',
            default => 'box',
        };
    }

    private function riskForClassName(string $className): string
    {
        $name = Str::lower($className);
        if (str_contains($name, 'send') || str_contains($name, 'create') || str_contains($name, 'edit') || str_contains($name, 'update')) {
            return 'high';
        }
        if (str_contains($name, 'scaffold') || str_contains($name, 'schema') || str_contains($name, 'workspace')) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return array{
     *   summary: string,
     *   capabilities: array<int, string>,
     *   flow: array<int, string>,
     *   methods: array<int, array{name: string, visibility: string, intent: string}>
     * }
     */
    private function buildSourceExplanation(string $className, string $description, string $content): array
    {
        preg_match_all(
            '/\b(public|protected|private)\s+function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/',
            $content,
            $methodMatches,
            PREG_SET_ORDER
        );

        $methods = collect($methodMatches)
            ->map(function (array $match): array {
                $visibility = (string) ($match[1] ?? 'public');
                $name = (string) ($match[2] ?? 'method');

                return [
                    'name' => $name,
                    'visibility' => $visibility,
                    'intent' => $this->inferMethodIntent($name),
                ];
            })
            ->values()
            ->all();

        $capabilities = [];
        $lower = Str::lower($content);
        if (str_contains($lower, 'validator::make') || str_contains($lower, 'validate(')) {
            $capabilities[] = 'Input validation';
        }
        if (str_contains($lower, 'sheetorder::query') || str_contains($lower, '->where(') || str_contains($lower, '->get(')) {
            $capabilities[] = 'Database querying';
        }
        if (str_contains($lower, 'response->error') || str_contains($lower, 'response->text')) {
            $capabilities[] = 'Structured MCP responses';
        }
        if (str_contains($lower, 'file::') || str_contains($lower, 'storage::')) {
            $capabilities[] = 'Filesystem operations';
        }
        if (str_contains($lower, 'http::') || str_contains($lower, 'client')) {
            $capabilities[] = 'External API calls';
        }

        $flow = [
            'Accepts request arguments',
            'Validates incoming fields',
            'Executes tool-specific business logic',
            'Returns structured result payload',
        ];

        if ($capabilities === []) {
            $capabilities[] = 'Business workflow orchestration';
        }

        $summary = trim($description) !== ''
            ? $description
            : "This tool coordinates {$className} operations with structured request/response handling.";

        return [
            'summary' => $summary,
            'capabilities' => array_values(array_unique($capabilities)),
            'flow' => $flow,
            'methods' => $methods,
        ];
    }

    private function inferMethodIntent(string $methodName): string
    {
        $name = Str::lower($methodName);

        if (str_starts_with($name, 'schema')) {
            return 'Defines expected input fields and types.';
        }
        if (str_starts_with($name, 'handle')) {
            return 'Main execution entrypoint for the tool request.';
        }
        if (str_starts_with($name, 'parse')) {
            return 'Normalizes raw values into safer formats.';
        }
        if (str_starts_with($name, 'build')) {
            return 'Builds derived structures used by responses.';
        }
        if (str_starts_with($name, 'infer')) {
            return 'Infers context-specific behavior from inputs.';
        }
        if (str_starts_with($name, 'resolve')) {
            return 'Resolves internal references or dependencies.';
        }
        if (str_starts_with($name, 'create')) {
            return 'Creates new records or generated artifacts.';
        }
        if (str_starts_with($name, 'update') || str_starts_with($name, 'edit')) {
            return 'Updates existing data or state.';
        }
        if (str_starts_with($name, 'list') || str_starts_with($name, 'get')) {
            return 'Fetches and formats data for output.';
        }

        return 'Supports internal tool execution flow.';
    }
}
