<?php

namespace Digihood\Digidocs\Analyzers;

use Exception;

class CodeDiffer
{
    public function __invoke(string $file_path, string $old_content, string $new_content): array
    {
        try {
            // Základní statistiky
            $oldLines = explode("\n", $old_content);
            $newLines = explode("\n", $new_content);

            $addedLines = [];
            $removedLines = [];
            $modifiedLines = [];

            // Jednoduchá diff analýza
            $maxLines = max(count($oldLines), count($newLines));
            for ($i = 0; $i < $maxLines; $i++) {
                $oldLine = $oldLines[$i] ?? null;
                $newLine = $newLines[$i] ?? null;

                if ($oldLine === null) {
                    $addedLines[] = ['line' => $i + 1, 'content' => $newLine];
                } elseif ($newLine === null) {
                    $removedLines[] = ['line' => $i + 1, 'content' => $oldLine];
                } elseif (trim($oldLine) !== trim($newLine)) {
                    $modifiedLines[] = [
                        'line' => $i + 1,
                        'old' => $oldLine,
                        'new' => $newLine
                    ];
                }
            }

            // Analýza typů změn
            $changeTypes = [];
            $newContentLower = strtolower($new_content);
            $oldContentLower = strtolower($old_content);

            // Detekce různých typů změn
            if (substr_count($newContentLower, 'class ') !== substr_count($oldContentLower, 'class ')) {
                $changeTypes[] = 'class_changes';
            }
            if (substr_count($newContentLower, 'function ') !== substr_count($oldContentLower, 'function ')) {
                $changeTypes[] = 'method_changes';
            }
            if (substr_count($newContentLower, 'public ') !== substr_count($oldContentLower, 'public ')) {
                $changeTypes[] = 'visibility_changes';
            }
            if (substr_count($newContentLower, 'use ') !== substr_count($oldContentLower, 'use ')) {
                $changeTypes[] = 'import_changes';
            }

            // Pokročilá analýza změn
            $analysis = $this->analyzeChanges($old_content, $new_content, $addedLines, $removedLines, $modifiedLines);

            return [
                'status' => 'success',
                'file_path' => $file_path,
                'statistics' => [
                    'added_lines' => count($addedLines),
                    'removed_lines' => count($removedLines),
                    'modified_lines' => count($modifiedLines),
                    'total_changes' => count($addedLines) + count($removedLines) + count($modifiedLines)
                ],
                'change_types' => array_merge($changeTypes, $analysis['change_types']),
                'analysis' => $analysis,
                'changes' => [
                    'added' => array_slice($addedLines, 0, 10), // Limit pro výkon
                    'removed' => array_slice($removedLines, 0, 10),
                    'modified' => array_slice($modifiedLines, 0, 10)
                ]
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
     * Pokročilá analýza změn
     */
    private function analyzeChanges(string $oldContent, string $newContent, array $addedLines, array $removedLines, array $modifiedLines): array
    {
        $analysis = [
            'structural_changes' => false,
            'semantic_changes' => false,
            'comments_only' => false,
            'whitespace_only' => false,
            'change_types' => [
                'total_changes' => count($addedLines) + count($removedLines) + count($modifiedLines)
            ]
        ];

        // Kontrola pouze whitespace změn
        if (trim(preg_replace('/\s+/', ' ', $oldContent)) === trim(preg_replace('/\s+/', ' ', $newContent))) {
            $analysis['whitespace_only'] = true;
            return $analysis;
        }

        // Kontrola pouze komentářů
        $oldWithoutComments = $this->removeComments($oldContent);
        $newWithoutComments = $this->removeComments($newContent);
        
        if (trim($oldWithoutComments) === trim($newWithoutComments)) {
            $analysis['comments_only'] = true;
            return $analysis;
        }

        // Detekce strukturálních změn
        $structuralKeywords = ['class', 'interface', 'trait', 'function', 'public', 'private', 'protected', 'static', 'abstract', 'final'];
        foreach ($structuralKeywords as $keyword) {
            if (substr_count(strtolower($oldContent), $keyword . ' ') !== substr_count(strtolower($newContent), $keyword . ' ')) {
                $analysis['structural_changes'] = true;
                break;
            }
        }

        // Detekce sémantických změn
        if (!$analysis['structural_changes'] && count($modifiedLines) > 0) {
            $analysis['semantic_changes'] = true;
        }

        return $analysis;
    }

    /**
     * Odstraní komentáře z kódu
     */
    private function removeComments(string $content): string
    {
        // Odstraní jednořádkové komentáře
        $content = preg_replace('/\/\/.*$/m', '', $content);
        
        // Odstraní víceřádkové komentáře
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        
        // Odstraní PHPDoc komentáře
        $content = preg_replace('/\/\*\*.*?\*\//s', '', $content);
        
        return $content;
    }
}
