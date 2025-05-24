<?php

namespace Digihood\Digidocs\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Exception;

class CodeDiffTool
{
    public static function make(): Tool
    {
        return Tool::make(
            'analyze_code_diff',
            'Compare two versions of a file and analyze the differences between them.'
        )->addProperty(
            new ToolProperty(
                name: 'file_path',
                type: 'string',
                description: 'Path to the file to analyze',
                required: true
            )
        )->addProperty(
            new ToolProperty(
                name: 'old_hash',
                type: 'string',
                description: 'Hash of the old version (for Git comparison)',
                required: false
            )
        )->addProperty(
            new ToolProperty(
                name: 'new_hash',
                type: 'string',
                description: 'Hash of the new version (for Git comparison)',
                required: false
            )
        )->addProperty(
            new ToolProperty(
                name: 'old_content',
                type: 'string',
                description: 'Content of the old version (alternative to Git hashes)',
                required: false
            )
        )->addProperty(
            new ToolProperty(
                name: 'new_content',
                type: 'string',
                description: 'Content of the new version (alternative to Git hashes)',
                required: false
            )
        )->setCallable(new CodeDiffAnalyzer());
    }
}

class CodeDiffAnalyzer
{
    public function __invoke(
        string $file_path,
        ?string $old_hash = null,
        ?string $new_hash = null,
        ?string $old_content = null,
        ?string $new_content = null
    ): array {
        try {
            // Získej obsah souborů
            if ($old_content && $new_content) {
                $oldContent = $old_content;
                $newContent = $new_content;
            } elseif ($old_hash && $new_hash) {
                $oldContent = $this->getContentFromGit($file_path, $old_hash);
                $newContent = $this->getContentFromGit($file_path, $new_hash);
            } else {
                // Porovnej s aktuálním souborem
                $fullPath = base_path($file_path);
                if (!file_exists($fullPath)) {
                    return [
                        'status' => 'error',
                        'error' => 'File not found',
                        'file_path' => $file_path
                    ];
                }
                $newContent = file_get_contents($fullPath);
                $oldContent = $old_content ?? '';
            }

            // Analýza rozdílů
            $diff = $this->generateDiff($oldContent, $newContent);
            $analysis = $this->analyzeDifferences($oldContent, $newContent, $diff);

            return [
                'status' => 'success',
                'file_path' => $file_path,
                'diff' => $diff,
                'analysis' => $analysis,
                'old_hash' => $old_hash,
                'new_hash' => $new_hash,
                'statistics' => $this->getStatistics($oldContent, $newContent)
            ];

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'file_path' => $file_path
            ];
        }
    }

    /**
     * Získá obsah souboru z Git commitu
     */
    private function getContentFromGit(string $filePath, string $hash): string
    {
        try {
            $command = "git show {$hash}:{$filePath}";
            $output = shell_exec($command);
            return $output ?: '';
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Generuje unified diff
     */
    private function generateDiff(string $oldContent, string $newContent): array
    {
        $oldLines = explode("\n", $oldContent);
        $newLines = explode("\n", $newContent);

        $diff = [];
        $oldIndex = 0;
        $newIndex = 0;

        while ($oldIndex < count($oldLines) || $newIndex < count($newLines)) {
            if ($oldIndex >= count($oldLines)) {
                // Přidané řádky
                $diff[] = [
                    'type' => 'added',
                    'line_number' => $newIndex + 1,
                    'content' => $newLines[$newIndex]
                ];
                $newIndex++;
            } elseif ($newIndex >= count($newLines)) {
                // Smazané řádky
                $diff[] = [
                    'type' => 'removed',
                    'line_number' => $oldIndex + 1,
                    'content' => $oldLines[$oldIndex]
                ];
                $oldIndex++;
            } elseif ($oldLines[$oldIndex] === $newLines[$newIndex]) {
                // Nezměněné řádky
                $diff[] = [
                    'type' => 'unchanged',
                    'old_line' => $oldIndex + 1,
                    'new_line' => $newIndex + 1,
                    'content' => $oldLines[$oldIndex]
                ];
                $oldIndex++;
                $newIndex++;
            } else {
                // Změněné řádky
                $diff[] = [
                    'type' => 'changed',
                    'old_line' => $oldIndex + 1,
                    'new_line' => $newIndex + 1,
                    'old_content' => $oldLines[$oldIndex],
                    'new_content' => $newLines[$newIndex]
                ];
                $oldIndex++;
                $newIndex++;
            }
        }

        return $diff;
    }

    /**
     * Analyzuje typy změn
     */
    private function analyzeDifferences(string $oldContent, string $newContent, array $diff): array
    {
        $analysis = [
            'change_types' => [],
            'affected_lines' => [],
            'whitespace_only' => true,
            'comments_only' => true,
            'structural_changes' => false,
            'semantic_changes' => false
        ];

        $addedLines = 0;
        $removedLines = 0;
        $changedLines = 0;

        foreach ($diff as $change) {
            switch ($change['type']) {
                case 'added':
                    $addedLines++;
                    $analysis['affected_lines'][] = $change['line_number'];
                    if (!$this->isWhitespaceOnly($change['content'])) {
                        $analysis['whitespace_only'] = false;
                    }
                    if (!$this->isCommentOnly($change['content'])) {
                        $analysis['comments_only'] = false;
                    }
                    break;

                case 'removed':
                    $removedLines++;
                    $analysis['affected_lines'][] = $change['line_number'];
                    if (!$this->isWhitespaceOnly($change['content'])) {
                        $analysis['whitespace_only'] = false;
                    }
                    if (!$this->isCommentOnly($change['content'])) {
                        $analysis['comments_only'] = false;
                    }
                    break;

                case 'changed':
                    $changedLines++;
                    $analysis['affected_lines'][] = $change['old_line'];

                    // Kontrola typu změny
                    if (!$this->isWhitespaceChange($change['old_content'], $change['new_content'])) {
                        $analysis['whitespace_only'] = false;
                    }
                    if (!$this->isCommentChange($change['old_content'], $change['new_content'])) {
                        $analysis['comments_only'] = false;
                    }
                    break;
            }
        }

        // Detekce strukturálních změn
        $analysis['structural_changes'] = $this->hasStructuralChanges($oldContent, $newContent);
        $analysis['semantic_changes'] = !$analysis['whitespace_only'] && !$analysis['comments_only'];

        $analysis['change_types'] = [
            'added_lines' => $addedLines,
            'removed_lines' => $removedLines,
            'changed_lines' => $changedLines,
            'total_changes' => $addedLines + $removedLines + $changedLines
        ];

        return $analysis;
    }

    /**
     * Kontroluje jestli je řádek pouze whitespace
     */
    private function isWhitespaceOnly(string $line): bool
    {
        return trim($line) === '';
    }

    /**
     * Kontroluje jestli je řádek pouze komentář
     */
    private function isCommentOnly(string $line): bool
    {
        $trimmed = trim($line);
        return str_starts_with($trimmed, '//') ||
               str_starts_with($trimmed, '#') ||
               str_starts_with($trimmed, '/*') ||
               str_starts_with($trimmed, '*') ||
               str_starts_with($trimmed, '*/');
    }

    /**
     * Kontroluje jestli je změna pouze whitespace
     */
    private function isWhitespaceChange(string $old, string $new): bool
    {
        return trim($old) === trim($new);
    }

    /**
     * Kontroluje jestli je změna pouze v komentářích
     */
    private function isCommentChange(string $old, string $new): bool
    {
        return $this->isCommentOnly($old) && $this->isCommentOnly($new);
    }

    /**
     * Detekuje strukturální změny (třídy, metody, vlastnosti)
     */
    private function hasStructuralChanges(string $oldContent, string $newContent): bool
    {
        // Jednoduché regex patterns pro PHP struktury
        $patterns = [
            '/class\s+\w+/',
            '/interface\s+\w+/',
            '/trait\s+\w+/',
            '/function\s+\w+\s*\(/',
            '/public\s+function\s+\w+/',
            '/private\s+function\s+\w+/',
            '/protected\s+function\s+\w+/',
            '/public\s+\$\w+/',
            '/private\s+\$\w+/',
            '/protected\s+\$\w+/',
            '/const\s+\w+\s*=/',
        ];

        foreach ($patterns as $pattern) {
            $oldMatches = preg_match_all($pattern, $oldContent);
            $newMatches = preg_match_all($pattern, $newContent);

            if ($oldMatches !== $newMatches) {
                return true;
            }
        }

        return false;
    }

    /**
     * Získá statistiky změn
     */
    private function getStatistics(string $oldContent, string $newContent): array
    {
        $oldLines = explode("\n", $oldContent);
        $newLines = explode("\n", $newContent);

        return [
            'old_lines_count' => count($oldLines),
            'new_lines_count' => count($newLines),
            'lines_difference' => count($newLines) - count($oldLines),
            'old_size' => strlen($oldContent),
            'new_size' => strlen($newContent),
            'size_difference' => strlen($newContent) - strlen($oldContent),
            'similarity_ratio' => $this->calculateSimilarity($oldContent, $newContent)
        ];
    }

    /**
     * Vypočítá podobnost mezi dvěma texty
     */
    private function calculateSimilarity(string $old, string $new): float
    {
        if (empty($old) && empty($new)) {
            return 1.0;
        }

        if (empty($old) || empty($new)) {
            return 0.0;
        }

        similar_text($old, $new, $percent);
        return round($percent / 100, 3);
    }
}
