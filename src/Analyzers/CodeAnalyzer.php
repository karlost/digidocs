<?php

namespace Digihood\Digidocs\Analyzers;

use Digihood\Digidocs\Services\CodeVisitor;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\Error;
use Exception;

class CodeAnalyzer
{
    public function __invoke(string $file_path, bool $include_context = true): array
    {
        $fullPath = base_path($file_path);

        if (!file_exists($fullPath)) {
            return [
                'status' => 'error',
                'error' => 'File not found',
                'file_path' => $file_path
            ];
        }

        $content = file_get_contents($fullPath);

        if ($content === false) {
            return [
                'status' => 'error',
                'error' => 'Could not read file',
                'file_path' => $file_path
            ];
        }

        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        try {
            $ast = $parser->parse($content);
            $visitor = new CodeVisitor();
            $traverser = new NodeTraverser();
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            $analysis = [
                'status' => 'success',
                'file_path' => $file_path,
                'file_size' => filesize($fullPath),
                'lines_count' => substr_count($content, "\n") + 1,
                'namespace' => $visitor->getNamespace(),
                'classes' => $visitor->getClasses(),
                'methods' => $visitor->getMethods(),
                'properties' => $visitor->getProperties(),
                'imports' => $visitor->getImports(),
                'existing_docs' => $visitor->getExistingDocs(),
                'file_content_preview' => $this->getContentPreview($content)
            ];

            if ($include_context) {
                $analysis['laravel_context'] = $this->getLaravelContext($file_path, $analysis);
            }

            return $analysis;

        } catch (Error $e) {
            return [
                'status' => 'error',
                'error' => 'Parse error: ' . $e->getMessage(),
                'file_path' => $file_path,
                'line' => $e->getStartLine()
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => 'Analysis error: ' . $e->getMessage(),
                'file_path' => $file_path
            ];
        }
    }

    /**
     * Získá Laravel kontext pro soubor
     */
    private function getLaravelContext(string $filePath, array $analysis): array
    {
        $context = [
            'type' => 'unknown',
            'framework_features' => []
        ];

        // Detekce typu souboru podle cesty
        if (str_contains($filePath, 'Controllers/')) {
            $context['type'] = 'controller';
            $context['framework_features'] = $this->getControllerFeatures($analysis);
        } elseif (str_contains($filePath, 'Models/')) {
            $context['type'] = 'model';
            $context['framework_features'] = $this->getModelFeatures($analysis);
        } elseif (str_contains($filePath, 'Middleware/')) {
            $context['type'] = 'middleware';
            $context['framework_features'] = $this->getMiddlewareFeatures($analysis);
        } elseif (str_contains($filePath, 'Commands/')) {
            $context['type'] = 'command';
            $context['framework_features'] = $this->getCommandFeatures($analysis);
        } elseif (str_contains($filePath, 'Jobs/')) {
            $context['type'] = 'job';
            $context['framework_features'] = $this->getJobFeatures($analysis);
        } elseif (str_contains($filePath, 'Providers/')) {
            $context['type'] = 'service_provider';
            $context['framework_features'] = $this->getServiceProviderFeatures($analysis);
        }

        return $context;
    }

    private function getControllerFeatures(array $analysis): array
    {
        $features = [];

        // Zkontroluj extends
        foreach ($analysis['classes'] as $class) {
            if ($class['extends']) {
                $features[] = "Extends: {$class['extends']}";
            }
        }

        // Zkontroluj metody
        $actionMethods = array_filter($analysis['methods'], function($method) {
            return $method['is_public'] && !in_array($method['name'], ['__construct', '__destruct']);
        });

        if (!empty($actionMethods)) {
            $features[] = "Actions: " . implode(', ', array_column($actionMethods, 'name'));
        }

        return $features;
    }

    private function getModelFeatures(array $analysis): array
    {
        $features = [];

        // Zkontroluj extends (obvykle Model nebo Eloquent)
        foreach ($analysis['classes'] as $class) {
            if ($class['extends']) {
                $features[] = "Extends: {$class['extends']}";
            }
        }

        // Zkontroluj properties (fillable, guarded, etc.)
        $modelProperties = array_filter($analysis['properties'], function($prop) {
            return in_array($prop['name'], ['fillable', 'guarded', 'hidden', 'casts', 'dates']);
        });

        foreach ($modelProperties as $prop) {
            $features[] = "Property: {$prop['name']}";
        }

        return $features;
    }

    private function getMiddlewareFeatures(array $analysis): array
    {
        $features = [];

        // Zkontroluj handle metodu
        $handleMethod = array_filter($analysis['methods'], fn($m) => $m['name'] === 'handle');
        if (!empty($handleMethod)) {
            $features[] = "Has handle method";
        }

        return $features;
    }

    private function getCommandFeatures(array $analysis): array
    {
        $features = [];

        // Zkontroluj signature a description properties
        $commandProps = array_filter($analysis['properties'], function($prop) {
            return in_array($prop['name'], ['signature', 'description']);
        });

        foreach ($commandProps as $prop) {
            $features[] = "Property: {$prop['name']}";
        }

        // Zkontroluj handle metodu
        $handleMethod = array_filter($analysis['methods'], fn($m) => $m['name'] === 'handle');
        if (!empty($handleMethod)) {
            $features[] = "Has handle method";
        }

        return $features;
    }

    private function getJobFeatures(array $analysis): array
    {
        $features = [];

        // Zkontroluj implements
        foreach ($analysis['classes'] as $class) {
            if (!empty($class['implements'])) {
                $features[] = "Implements: " . implode(', ', $class['implements']);
            }
        }

        // Zkontroluj handle metodu
        $handleMethod = array_filter($analysis['methods'], fn($m) => $m['name'] === 'handle');
        if (!empty($handleMethod)) {
            $features[] = "Has handle method";
        }

        return $features;
    }

    private function getServiceProviderFeatures(array $analysis): array
    {
        $features = [];

        // Zkontroluj boot a register metody
        $bootMethod = array_filter($analysis['methods'], fn($m) => $m['name'] === 'boot');
        $registerMethod = array_filter($analysis['methods'], fn($m) => $m['name'] === 'register');

        if (!empty($bootMethod)) {
            $features[] = "Has boot method";
        }

        if (!empty($registerMethod)) {
            $features[] = "Has register method";
        }

        return $features;
    }

    /**
     * Získá náhled obsahu souboru (první a poslední řádky)
     */
    private function getContentPreview(string $content): array
    {
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        $preview = [
            'total_lines' => $totalLines,
            'first_lines' => array_slice($lines, 0, min(5, $totalLines)),
            'last_lines' => $totalLines > 10 ? array_slice($lines, -5) : []
        ];

        return $preview;
    }
}
