<?php

namespace Digihood\Digidocs\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Exception;

class SemanticAnalysisTool
{
    public static function make(): Tool
    {
        return Tool::make(
            'analyze_semantic_changes',
            'Analyze semantic significance of code changes to determine if documentation update is needed.'
        )->addProperty(
            new ToolProperty(
                name: 'diff_analysis',
                type: 'string',
                description: 'JSON string with output from CodeDiffTool analysis',
                required: true
            )
        )->addProperty(
            new ToolProperty(
                name: 'ast_analysis',
                type: 'string',
                description: 'JSON string with output from AstCompareTool analysis',
                required: true
            )
        )->addProperty(
            new ToolProperty(
                name: 'file_path',
                type: 'string',
                description: 'Path to the file being analyzed',
                required: false
            )
        )->setCallable(new SemanticAnalyzer());
    }
}

class SemanticAnalyzer
{
    public function __invoke(string $diff_analysis, string $ast_analysis, ?string $file_path = null): array
    {
        try {
            // Dekóduj JSON stringy
            $diffData = json_decode($diff_analysis, true);
            $astData = json_decode($ast_analysis, true);

            if (!$diffData || !$astData) {
                return [
                    'status' => 'error',
                    'error' => 'Invalid JSON input data',
                    'file_path' => $file_path
                ];
            }

            // Analýza sémantické významnosti změn
            $semanticScore = $this->calculateSemanticScore($diffData, $astData);
            $changeClassification = $this->classifyChanges($diffData, $astData);
            $recommendation = $this->generateRecommendation($semanticScore, $changeClassification);

            return [
                'status' => 'success',
                'file_path' => $file_path,
                'semantic_score' => $semanticScore,
                'change_classification' => $changeClassification,
                'recommendation' => $recommendation,
                'should_regenerate_docs' => $recommendation['should_regenerate'],
                'confidence' => $recommendation['confidence'],
                'reasoning' => $recommendation['reasoning']
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
     * Vypočítá sémantické skóre změn (0-100)
     */
    private function calculateSemanticScore(array $diffAnalysis, array $astAnalysis): int
    {
        $score = 0;

        // Skóre z diff analýzy
        if (isset($diffAnalysis['analysis'])) {
            $analysis = $diffAnalysis['analysis'];

            // Strukturální změny = vysoké skóre
            if ($analysis['structural_changes']) {
                $score += 40;
            }

            // Sémantické změny = střední skóre
            if ($analysis['semantic_changes']) {
                $score += 25;
            }

            // Pouze komentáře = nízké skóre
            if ($analysis['comments_only']) {
                $score += 5;
            }

            // Pouze whitespace = minimální skóre
            if ($analysis['whitespace_only']) {
                $score += 1;
            }

            // Počet změn
            $totalChanges = $analysis['change_types']['total_changes'] ?? 0;
            if ($totalChanges > 10) {
                $score += 15;
            } elseif ($totalChanges > 5) {
                $score += 10;
            } elseif ($totalChanges > 1) {
                $score += 5;
            }
        }

        // Skóre z AST analýzy
        if (isset($astAnalysis['comparison'])) {
            $comparison = $astAnalysis['comparison'];

            // Změny v namespace
            if ($comparison['namespace_changed']) {
                $score += 20;
            }

            // Změny v třídách
            if ($comparison['classes_changes']['has_changes']) {
                $score += $this->scoreClassChanges($comparison['classes_changes']);
            }

            // Změny v rozhraních
            if ($comparison['interfaces_changes']['has_changes']) {
                $score += 25;
            }

            // Změny ve funkcích
            if ($comparison['functions_changes']['has_changes']) {
                $score += 20;
            }

            // Změny v importech
            if ($comparison['uses_changes']['has_changes']) {
                $score += 10;
            }
        }

        // Skóre ze shrnutí změn
        if (isset($astAnalysis['change_summary'])) {
            $summary = $astAnalysis['change_summary'];

            switch ($summary['severity']) {
                case 'major':
                    $score += 30;
                    break;
                case 'minor':
                    $score += 15;
                    break;
                case 'minimal':
                    $score += 5;
                    break;
            }
        }

        return min(100, max(0, $score));
    }

    /**
     * Vypočítá skóre pro změny v třídách
     */
    private function scoreClassChanges(array $classChanges): int
    {
        $score = 0;

        // Přidané/odebrané třídy
        $score += count($classChanges['added']) * 15;
        $score += count($classChanges['removed']) * 15;

        // Změněné třídy
        foreach ($classChanges['modified'] as $className => $changes) {
            if ($changes['extends_changed']) {
                $score += 20;
            }

            if ($changes['implements_changes']['has_changes']) {
                $score += 15;
            }

            if ($changes['methods_changes']['has_changes']) {
                $methodChanges = $changes['methods_changes'];
                $score += count($methodChanges['added']) * 10;
                $score += count($methodChanges['removed']) * 10;
                $score += count($methodChanges['modified']) * 8;
            }

            if ($changes['properties_changes']['has_changes']) {
                $propChanges = $changes['properties_changes'];
                $score += count($propChanges['added']) * 5;
                $score += count($propChanges['removed']) * 5;
                $score += count($propChanges['modified']) * 3;
            }

            if ($changes['modifiers_changed']['abstract'] || $changes['modifiers_changed']['final']) {
                $score += 15;
            }
        }

        return $score;
    }

    /**
     * Klasifikuje typy změn
     */
    private function classifyChanges(array $diffAnalysis, array $astAnalysis): array
    {
        $classification = [
            'primary_type' => 'unknown',
            'categories' => [],
            'impact_level' => 'low',
            'documentation_relevance' => 'low'
        ];

        // Analýza z diff
        if (isset($diffAnalysis['analysis'])) {
            $analysis = $diffAnalysis['analysis'];

            if ($analysis['whitespace_only']) {
                $classification['primary_type'] = 'formatting';
                $classification['categories'][] = 'whitespace';
                $classification['impact_level'] = 'none';
                $classification['documentation_relevance'] = 'none';
            } elseif ($analysis['comments_only']) {
                $classification['primary_type'] = 'documentation';
                $classification['categories'][] = 'comments';
                $classification['impact_level'] = 'low';
                $classification['documentation_relevance'] = 'low';
            } elseif ($analysis['structural_changes']) {
                $classification['primary_type'] = 'structural';
                $classification['categories'][] = 'structure';
                $classification['impact_level'] = 'high';
                $classification['documentation_relevance'] = 'high';
            } elseif ($analysis['semantic_changes']) {
                $classification['primary_type'] = 'semantic';
                $classification['categories'][] = 'logic';
                $classification['impact_level'] = 'medium';
                $classification['documentation_relevance'] = 'medium';
            }
        }

        // Analýza z AST
        if (isset($astAnalysis['comparison'])) {
            $comparison = $astAnalysis['comparison'];

            if ($comparison['classes_changes']['has_changes']) {
                $classification['categories'][] = 'classes';
                if ($classification['impact_level'] === 'low') {
                    $classification['impact_level'] = 'medium';
                }
                if ($classification['documentation_relevance'] === 'low') {
                    $classification['documentation_relevance'] = 'medium';
                }
            }

            if ($comparison['interfaces_changes']['has_changes']) {
                $classification['categories'][] = 'interfaces';
                $classification['impact_level'] = 'high';
                $classification['documentation_relevance'] = 'high';
            }

            if ($comparison['functions_changes']['has_changes']) {
                $classification['categories'][] = 'functions';
                if ($classification['impact_level'] !== 'high') {
                    $classification['impact_level'] = 'medium';
                }
                if ($classification['documentation_relevance'] !== 'high') {
                    $classification['documentation_relevance'] = 'medium';
                }
            }

            if ($comparison['namespace_changed']) {
                $classification['categories'][] = 'namespace';
                $classification['impact_level'] = 'high';
                $classification['documentation_relevance'] = 'high';
            }

            if ($comparison['uses_changes']['has_changes']) {
                $classification['categories'][] = 'imports';
            }
        }

        // Určení primárního typu pokud není nastaven
        if ($classification['primary_type'] === 'unknown') {
            if (in_array('classes', $classification['categories']) ||
                in_array('interfaces', $classification['categories'])) {
                $classification['primary_type'] = 'structural';
            } elseif (in_array('functions', $classification['categories'])) {
                $classification['primary_type'] = 'functional';
            } elseif (in_array('imports', $classification['categories'])) {
                $classification['primary_type'] = 'dependencies';
            } else {
                $classification['primary_type'] = 'minor';
            }
        }

        return $classification;
    }

    /**
     * Generuje doporučení pro regeneraci dokumentace
     */
    private function generateRecommendation(int $semanticScore, array $classification): array
    {
        $recommendation = [
            'should_regenerate' => false,
            'confidence' => 0.0,
            'reasoning' => [],
            'priority' => 'low'
        ];

        // Rozhodování na základě skóre
        if ($semanticScore >= 70) {
            $recommendation['should_regenerate'] = true;
            $recommendation['confidence'] = 0.95;
            $recommendation['priority'] = 'high';
            $recommendation['reasoning'][] = "Vysoké sémantické skóre ({$semanticScore}/100) indikuje významné změny";
        } elseif ($semanticScore >= 40) {
            $recommendation['should_regenerate'] = true;
            $recommendation['confidence'] = 0.75;
            $recommendation['priority'] = 'medium';
            $recommendation['reasoning'][] = "Střední sémantické skóre ({$semanticScore}/100) naznačuje potřebu aktualizace dokumentace";
        } elseif ($semanticScore >= 20) {
            $recommendation['should_regenerate'] = true;
            $recommendation['confidence'] = 0.50;
            $recommendation['priority'] = 'low';
            $recommendation['reasoning'][] = "Nízké sémantické skóre ({$semanticScore}/100), ale změny mohou ovlivnit dokumentaci";
        } else {
            $recommendation['should_regenerate'] = false;
            $recommendation['confidence'] = 0.85;
            $recommendation['priority'] = 'none';
            $recommendation['reasoning'][] = "Velmi nízké sémantické skóre ({$semanticScore}/100) - změny pravděpodobně neovlivní dokumentaci";
        }

        // Úprava na základě klasifikace
        switch ($classification['documentation_relevance']) {
            case 'high':
                $recommendation['should_regenerate'] = true;
                $recommendation['confidence'] = min(0.95, $recommendation['confidence'] + 0.2);
                $recommendation['reasoning'][] = "Změny mají vysokou relevanci pro dokumentaci";
                break;

            case 'none':
                $recommendation['should_regenerate'] = false;
                $recommendation['confidence'] = 0.90;
                $recommendation['priority'] = 'none';
                $recommendation['reasoning'][] = "Změny nemají vliv na dokumentaci (pouze formátování/whitespace)";
                break;
        }

        // Speciální případy
        if ($classification['primary_type'] === 'formatting') {
            $recommendation['should_regenerate'] = false;
            $recommendation['confidence'] = 0.95;
            $recommendation['reasoning'][] = "Pouze formátovací změny - dokumentace zůstává aktuální";
        }

        if (in_array('interfaces', $classification['categories']) ||
            in_array('namespace', $classification['categories'])) {
            $recommendation['should_regenerate'] = true;
            $recommendation['confidence'] = 0.90;
            $recommendation['priority'] = 'high';
            $recommendation['reasoning'][] = "Změny v rozhraních nebo namespace vyžadují aktualizaci dokumentace";
        }

        return $recommendation;
    }
}
