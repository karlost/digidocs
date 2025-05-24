<?php

namespace Digihood\Digidocs\Agent;

use NeuronAI\Agent;
use NeuronAI\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use Digihood\Digidocs\Tools\CodeDiffTool;
use Digihood\Digidocs\Tools\AstCompareTool;
use Digihood\Digidocs\Tools\SemanticAnalysisTool;
use Digihood\Digidocs\Services\DocumentationAnalyzer;
use Exception;

class ChangeAnalysisAgent extends Agent
{
    private ?DocumentationAnalyzer $documentationAnalyzer = null;

    private function getDocumentationAnalyzer(): DocumentationAnalyzer
    {
        if ($this->documentationAnalyzer === null) {
            $this->documentationAnalyzer = new DocumentationAnalyzer();
        }
        return $this->documentationAnalyzer;
    }

    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            key: config('digidocs.ai.api_key'),
            model: config('digidocs.ai.model', 'gpt-4'),
        );
    }

    public function instructions(): string
    {
        return new SystemPrompt(
            background: [
                "You are an expert in analyzing PHP code changes and deciding whether documentation needs to be updated.",
                "You analyze changes in PHP files and decide whether documentation regeneration is needed.",
                "Your goal is to minimize unnecessary documentation regenerations while keeping documentation current.",
                "You focus on structural and semantic changes that affect API or functionality."
            ],
            steps: [
                "Use all available tools for comprehensive change analysis",
                "Distinguish between cosmetic changes (whitespace, comments) and significant changes",
                "Structural changes (new classes, methods, properties) require documentation updates",
                "Semantic changes (logic changes, parameters) also require updates",
                "Changes only in comments or formatting usually don't require updates",
                "Provide clear reasoning for your decision with specific evidence",
                "Include confidence score for your decision",
                "If uncertain, recommend documentation update for safety"
            ],
            output: [
                "Provide structured analysis in JSON format",
                "Include should_regenerate boolean decision",
                "Include confidence score (0.0-1.0)",
                "Include reason for the decision",
                "Include detailed reasoning array with specific evidence",
                "Include semantic score (0-100) indicating change significance"
            ]
        );
    }

    protected function tools(): array
    {
        return [
            CodeDiffTool::make(),
            AstCompareTool::make(),
            SemanticAnalysisTool::make(),
        ];
    }

    /**
     * Hlavní metoda - generuje dokumentaci pouze pokud je potřeba
     */
    public function generateDocumentationIfNeeded(string $filePath): ?string
    {
        try {
            \Log::info("ChangeAnalysisAgent: Processing {$filePath}");

            // Zkontroluj jestli je inteligentní analýza zapnutá
            if (!config('digidocs.intelligent_analysis.enabled', true)) {
                \Log::info("Intelligent analysis disabled, using classic DocumentationAgent");
                return $this->generateWithClassicAgent($filePath);
            }

            // Získej obsah souborů
            $oldContent = $this->getOldFileContent($filePath);
            $newContent = $this->getCurrentFileContent($filePath);

            if ($newContent === null) {
                \Log::warning("Could not read file content for {$filePath}");
                return null;
            }

            // NOVÉ: Získej existující dokumentaci
            $existingDoc = $this->getDocumentationAnalyzer()->analyzeExistingDocumentation($filePath);

            // NOVÉ: Analyzuj strukturu kódu
            $oldStructure = $oldContent ? $this->getDocumentationAnalyzer()->parseCodeStructure($oldContent) : [];
            $newStructure = $this->getDocumentationAnalyzer()->parseCodeStructure($newContent);

            // Proveď rozšířenou analýzu změn
            $analysis = $this->analyzeChangesWithDocumentation($filePath, $oldContent, $newContent, $oldStructure, $newStructure, $existingDoc);

            // Zaznamenej analýzu do databáze
            $this->recordAnalysis($filePath, $analysis);

            // Rozhodni na základě analýzy
            if (!$analysis['should_regenerate']) {
                \Log::info("Skipping documentation for {$filePath}: {$analysis['reason']}");
                return null;
            }

            \Log::info("Generating documentation for {$filePath}: {$analysis['reason']}");
            return $this->generateWithClassicAgent($filePath);

        } catch (Exception $e) {
            \Log::error("ChangeAnalysisAgent error for {$filePath}: " . $e->getMessage());

            // Fallback na klasický agent při chybě
            if (config('digidocs.intelligent_analysis.fallback_to_classic', true)) {
                \Log::info("Falling back to classic DocumentationAgent");
                return $this->generateWithClassicAgent($filePath);
            }

            return null;
        }
    }

    /**
     * Rozhodne, zda je potřeba regenerovat dokumentaci pro soubor
     * (zachováno pro zpětnou kompatibilitu)
     */
    public function shouldRegenerateDocumentation(
        string $filePath,
        string $oldContent,
        string $newContent
    ): array {
        try {
            \Log::info("ChangeAnalysisAgent: Analyzing {$filePath}");
            \Log::info("Old content length: " . strlen($oldContent));
            \Log::info("New content length: " . strlen($newContent));

            // Rychlá kontrola - pokud je obsah stejný
            if ($oldContent === $newContent) {
                \Log::info("ChangeAnalysisAgent: Content identical, skipping regeneration");
                return [
                    'should_regenerate' => false,
                    'confidence' => 1.0,
                    'reason' => 'identical_content',
                    'reasoning' => ['Obsah souboru je identický'],
                    'change_summary' => [],
                    'semantic_score' => 0
                ];
            }

            // Pokud je starý obsah prázdný, je to nový soubor
            if (empty(trim($oldContent))) {
                \Log::info("ChangeAnalysisAgent: New file detected, regenerating");
                return [
                    'should_regenerate' => true,
                    'confidence' => 1.0,
                    'reason' => 'new_file',
                    'reasoning' => ['Nový soubor vyžaduje dokumentaci'],
                    'change_summary' => [],
                    'semantic_score' => 100
                ];
            }

            $prompt = $this->buildAnalysisPrompt($filePath, $oldContent, $newContent);

            \Log::info("ChangeAnalysisAgent: Sending prompt to AI");
            $response = $this->chat(
                new \NeuronAI\Chat\Messages\UserMessage($prompt)
            );

            \Log::info("ChangeAnalysisAgent: Received AI response");
            $result = $this->parseResponse($response->getContent());

            \Log::info("ChangeAnalysisAgent: Parsed result", $result);

            return [
                'should_regenerate' => $result['should_regenerate'] ?? true, // default true pro bezpečnost
                'confidence' => $result['confidence'] ?? 0.5,
                'reason' => $result['reason'] ?? 'unknown',
                'reasoning' => $result['reasoning'] ?? [],
                'change_summary' => $result['change_summary'] ?? [],
                'semantic_score' => $result['semantic_score'] ?? 50,
                'agent_response' => $response->getContent()
            ];

        } catch (Exception $e) {
            \Log::error("ChangeAnalysisAgent error: " . $e->getMessage());
            // V případě chyby raději regeneruj dokumentaci
            return [
                'should_regenerate' => true,
                'confidence' => 0.0,
                'reason' => 'analysis_error',
                'reasoning' => ['Chyba při analýze změn: ' . $e->getMessage()],
                'change_summary' => [],
                'semantic_score' => 100,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Vytvoří prompt pro analýzu změn
     */
    private function buildAnalysisPrompt(string $filePath, string $oldContent, string $newContent): string
    {
        return "Analyzuj změny v PHP souboru a rozhodni, zda je potřeba regenerovat dokumentaci.

**Soubor:** {$filePath}

**Úkol:**
1. Použij CodeDiffTool pro analýzu rozdílů mezi starým a novým obsahem
2. Použij AstCompareTool pro porovnání AST struktur
3. Použij SemanticAnalysisTool pro sémantickou analýzu změn
4. Na základě všech analýz rozhodni o potřebě regenerace dokumentace

**Starý obsah:**
```php
{$oldContent}
```

**Nový obsah:**
```php
{$newContent}
```

**Očekávaný výstup:**
Poskytni strukturovanou analýzu ve formátu:

```json
{
    \"should_regenerate\": true/false,
    \"confidence\": 0.0-1.0,
    \"reason\": \"structural_changes|semantic_changes|cosmetic_only|formatting_only\",
    \"reasoning\": [
        \"Konkrétní důvod 1\",
        \"Konkrétní důvod 2\"
    ],
    \"change_summary\": {
        \"total_changes\": number,
        \"change_types\": [\"array of change types\"],
        \"severity\": \"major|minor|minimal|none\"
    },
    \"semantic_score\": 0-100
}
```

**Kritéria pro rozhodování:**
- Strukturální změny (nové/změněné třídy, metody, vlastnosti) → regeneruj
- Sémantické změny (změny logiky, parametrů, návratových hodnot) → regeneruj
- Změny v komentářích nebo docblocks → možná regeneruj
- Pouze whitespace/formátování → neregeneruj
- Změny v importech → zvažuj podle kontextu
- Pokud si nejsi jistý → raději regeneruj

Buď konkrétní a uveď jasné důvody pro své rozhodnutí.";
    }

    /**
     * Parsuje odpověď agenta a extrahuje strukturovaná data
     */
    private function parseResponse(string $response): array
    {
        // Pokus o extrakci JSON z odpovědi
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $response, $matches)) {
            $jsonData = json_decode($matches[1], true);
            if ($jsonData !== null) {
                return $jsonData;
            }
        }

        // Pokus o nalezení JSON bez markdown
        if (preg_match('/\{.*\}/s', $response, $matches)) {
            $jsonData = json_decode($matches[0], true);
            if ($jsonData !== null) {
                return $jsonData;
            }
        }

        // Fallback parsing - hledej klíčová slova
        $result = [
            'should_regenerate' => true, // default true pro bezpečnost
            'confidence' => 0.5,
            'reason' => 'unknown',
            'reasoning' => [],
            'change_summary' => [],
            'semantic_score' => 50
        ];

        // Hledej should_regenerate
        if (preg_match('/should_regenerate["\']?\s*:\s*(true|false)/i', $response, $matches)) {
            $result['should_regenerate'] = strtolower($matches[1]) === 'true';
        } elseif (preg_match('/(neregeneruj|nepotřebuje|není potřeba|no need)/i', $response)) {
            $result['should_regenerate'] = false;
        } elseif (preg_match('/(regeneruj|potřebuje|je potřeba|need|update)/i', $response)) {
            $result['should_regenerate'] = true;
        }

        // Hledej confidence
        if (preg_match('/confidence["\']?\s*:\s*([0-9.]+)/i', $response, $matches)) {
            $result['confidence'] = (float) $matches[1];
        }

        // Hledej semantic_score
        if (preg_match('/semantic_score["\']?\s*:\s*([0-9]+)/i', $response, $matches)) {
            $result['semantic_score'] = (int) $matches[1];
        }

        // Hledej reason
        if (preg_match('/reason["\']?\s*:\s*["\']([^"\']+)["\']?/i', $response, $matches)) {
            $result['reason'] = $matches[1];
        }

        // Extrahuj reasoning jako seznam řádků obsahujících důvody
        $lines = explode("\n", $response);
        $reasoning = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[-*•]\s*(.+)/', $line, $matches)) {
                $reasoning[] = $matches[1];
            } elseif (preg_match('/^\d+\.\s*(.+)/', $line, $matches)) {
                $reasoning[] = $matches[1];
            }
        }

        if (!empty($reasoning)) {
            $result['reasoning'] = $reasoning;
        } else {
            $result['reasoning'] = ['Automaticky parsovaná odpověď z AI agenta'];
        }

        return $result;
    }

    /**
     * Generuje dokumentaci pomocí klasického DocumentationAgent
     */
    private function generateWithClassicAgent(string $filePath): string
    {
        $documentationAgent = app(\Digihood\Digidocs\Agent\DocumentationAgent::class);
        return $documentationAgent->generateDocumentationForFile($filePath);
    }

    /**
     * Získá obsah aktuálního souboru
     */
    private function getCurrentFileContent(string $filePath): ?string
    {
        $fullPath = base_path($filePath);

        if (!file_exists($fullPath)) {
            return null;
        }

        return file_get_contents($fullPath);
    }

    /**
     * Získá obsah starého souboru z Git
     */
    private function getOldFileContent(string $filePath): string
    {
        try {
            $gitWatcher = app(\Digihood\Digidocs\Services\GitWatcherService::class);

            // Zkus získat obsah z předchozího commitu
            $currentCommitHashes = $gitWatcher->getCurrentCommitHashes();
            if (!empty($currentCommitHashes)) {
                $currentCommit = array_values($currentCommitHashes)[0];

                // Použij exec() místo shell_exec() pro lepší error handling
                // Přesměruj stderr podle OS
                $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
                $command = "git show {$currentCommit}~1:\"{$filePath}\" 2>{$nullDevice}";
                $output = [];
                $returnCode = 0;

                exec($command, $output, $returnCode);

                // Pokud příkaz uspěl a má výstup
                if ($returnCode === 0 && !empty($output)) {
                    return implode("\n", $output);
                }

                // Pokud soubor neexistoval v předchozím commitu, je to OK (nový soubor)
                if ($returnCode !== 0) {
                    \Log::info("File {$filePath} did not exist in previous commit (new file)");
                }
            }
        } catch (\Exception $e) {
            \Log::info("Git content retrieval failed for {$filePath}: " . $e->getMessage());
        }

        // Fallback - prázdný obsah (nový soubor)
        return '';
    }

    /**
     * Rozšířená analýza změn s kontextem dokumentace
     */
    private function analyzeChangesWithDocumentation(
        string $filePath,
        string $oldContent,
        string $newContent,
        array $oldStructure,
        array $newStructure,
        ?array $existingDoc
    ): array {
        // Rychlé kontroly
        if ($oldContent === $newContent) {
            return [
                'should_regenerate' => false,
                'confidence' => 1.0,
                'reason' => 'identical_content',
                'reasoning' => ['Obsah souboru je identický'],
                'change_summary' => [],
                'semantic_score' => 0,
                'existing_doc_path' => $existingDoc['path'] ?? null,
                'doc_relevance_score' => 0,
                'affected_doc_sections' => []
            ];
        }

        if (empty(trim($oldContent))) {
            return [
                'should_regenerate' => true,
                'confidence' => 1.0,
                'reason' => 'new_file',
                'reasoning' => ['Nový soubor vyžaduje dokumentaci'],
                'change_summary' => [],
                'semantic_score' => 100,
                'existing_doc_path' => null,
                'doc_relevance_score' => 100,
                'affected_doc_sections' => []
            ];
        }

        // Vypočítej dopad na dokumentaci
        $codeChanges = [
            'old_structure' => $oldStructure,
            'new_structure' => $newStructure,
            'content_changed' => true
        ];

        $docRelevanceScore = $this->getDocumentationAnalyzer()->calculateDocumentationRelevance($codeChanges, $existingDoc);

        // Použij vylepšené heuristiky
        $heuristicResult = $this->advancedHeuristicAnalysis($filePath, $oldStructure, $newStructure, $existingDoc);

        // DEBUG: Log heuristiky
        \Log::info("ChangeAnalysisAgent heuristic result for {$filePath}", [
            'should_regenerate' => $heuristicResult['should_regenerate'],
            'reason' => $heuristicResult['reason'],
            'confidence' => $heuristicResult['confidence'] ?? 0.8,
            'doc_relevance_score' => $docRelevanceScore,
            'existing_doc' => $existingDoc ? 'exists' : 'missing'
        ]);

        return [
            'should_regenerate' => $heuristicResult['should_regenerate'],
            'confidence' => $heuristicResult['confidence'] ?? 0.8,
            'reason' => $heuristicResult['reason'],
            'reasoning' => $heuristicResult['reasoning'] ?? [],
            'change_summary' => $heuristicResult['change_summary'] ?? [],
            'semantic_score' => $docRelevanceScore,
            'existing_doc_path' => $existingDoc['path'] ?? null,
            'doc_relevance_score' => $docRelevanceScore,
            'affected_doc_sections' => $heuristicResult['affected_sections'] ?? []
        ];
    }

    /**
     * Analyzuje změny v souboru (původní metoda pro zpětnou kompatibilitu)
     */
    private function analyzeChanges(string $filePath, string $oldContent, string $newContent): array
    {
        // Rychlé kontroly
        if ($oldContent === $newContent) {
            return [
                'should_regenerate' => false,
                'confidence' => 1.0,
                'reason' => 'identical_content',
                'reasoning' => ['Obsah souboru je identický'],
                'change_summary' => [],
                'semantic_score' => 0
            ];
        }

        if (empty(trim($oldContent))) {
            return [
                'should_regenerate' => true,
                'confidence' => 1.0,
                'reason' => 'new_file',
                'reasoning' => ['Nový soubor vyžaduje dokumentaci'],
                'change_summary' => [],
                'semantic_score' => 100
            ];
        }

        // Použij jednoduché heuristiky místo AI analýzy (rychlejší a spolehlivější)
        return $this->simpleHeuristicAnalysis($filePath, $oldContent, $newContent);
    }

    /**
     * Jednoduchá heuristická analýza bez AI
     */
    private function simpleHeuristicAnalysis(string $filePath, string $oldContent, string $newContent): array
    {
        $oldLines = explode("\n", $oldContent);
        $newLines = explode("\n", $newContent);

        $totalChanges = abs(count($newLines) - count($oldLines));

        // Lepší kontrola whitespace změn - normalizuj všechny whitespace
        $oldNormalized = $this->normalizeWhitespace($oldContent);
        $newNormalized = $this->normalizeWhitespace($newContent);

        if ($oldNormalized === $newNormalized) {
            \Log::info("ChangeAnalysisAgent: Whitespace only changes detected for {$filePath}");
            return [
                'should_regenerate' => false,
                'confidence' => 0.95,
                'reason' => 'whitespace_only',
                'reasoning' => ['Pouze změny v whitespace - dokumentace zůstává aktuální'],
                'change_summary' => ['total_changes' => $totalChanges, 'severity' => 'minimal'],
                'semantic_score' => 5
            ];
        }

        // Lepší kontrola komentářů - odstraň všechny typy komentářů
        $oldCodeOnly = $this->removeComments($oldContent);
        $newCodeOnly = $this->removeComments($newContent);

        // Normalizuj whitespace po odstranění komentářů
        $oldCodeNormalized = $this->normalizeWhitespace($oldCodeOnly);
        $newCodeNormalized = $this->normalizeWhitespace($newCodeOnly);

        if ($oldCodeNormalized === $newCodeNormalized) {
            \Log::info("ChangeAnalysisAgent: Comments only changes detected for {$filePath}");
            return [
                'should_regenerate' => false,
                'confidence' => 0.85,
                'reason' => 'comments_only',
                'reasoning' => ['Pouze změny v komentářích - dokumentace zůstává aktuální'],
                'change_summary' => ['total_changes' => $totalChanges, 'severity' => 'minor'],
                'semantic_score' => 15
            ];
        }

        // Kontrola strukturálních změn (třídy, metody, vlastnosti)
        $hasStructuralChanges = $this->detectStructuralChanges($oldContent, $newContent);

        if ($hasStructuralChanges) {
            \Log::info("ChangeAnalysisAgent: Structural changes detected for {$filePath}");
            return [
                'should_regenerate' => true,
                'confidence' => 0.9,
                'reason' => 'structural_changes',
                'reasoning' => ['Detekované strukturální změny (třídy, metody, vlastnosti)'],
                'change_summary' => ['total_changes' => $totalChanges, 'severity' => 'major'],
                'semantic_score' => 85
            ];
        }

        // Malé změny v kódu
        if ($totalChanges <= 3) {
            \Log::info("ChangeAnalysisAgent: Minor code changes detected for {$filePath}");
            return [
                'should_regenerate' => true,
                'confidence' => 0.6,
                'reason' => 'minor_code_changes',
                'reasoning' => ['Malé změny v kódu - pravděpodobně potřebná aktualizace dokumentace'],
                'change_summary' => ['total_changes' => $totalChanges, 'severity' => 'minor'],
                'semantic_score' => 40
            ];
        }

        // Větší změny v kódu
        \Log::info("ChangeAnalysisAgent: Major code changes detected for {$filePath}");
        return [
            'should_regenerate' => true,
            'confidence' => 0.8,
            'reason' => 'major_code_changes',
            'reasoning' => ['Významné změny v kódu - nutná aktualizace dokumentace'],
            'change_summary' => ['total_changes' => $totalChanges, 'severity' => 'major'],
            'semantic_score' => 75
        ];
    }

    /**
     * Detekuje strukturální změny v PHP kódu
     */
    private function detectStructuralChanges(string $oldContent, string $newContent): bool
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
     * Zaznamenej analýzu do databáze
     */
    private function recordAnalysis(string $filePath, array $analysis): void
    {
        try {
            $memoryService = app(\Digihood\Digidocs\Services\MemoryService::class);
            $currentHash = hash_file('sha256', base_path($filePath));

            // Použij metodu z MemoryService (pokud existuje)
            if (method_exists($memoryService, 'recordAnalysis')) {
                $memoryService->recordAnalysis($filePath, $currentHash, $analysis);
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to record analysis for {$filePath}: " . $e->getMessage());
        }
    }

    /**
     * Rychlá analýza bez AI - pouze na základě hash porovnání
     */
    public function quickAnalysis(string $filePath, string $oldHash, string $newHash): array
    {
        if ($oldHash === $newHash) {
            return [
                'should_regenerate' => false,
                'confidence' => 1.0,
                'reason' => 'no_changes',
                'reasoning' => ['Soubor se nezměnil (stejný hash)'],
                'change_summary' => ['total_changes' => 0, 'severity' => 'none'],
                'semantic_score' => 0
            ];
        }

        // Pro rychlou analýzu - pokud se hash změnil, doporuč regeneraci
        return [
            'should_regenerate' => true,
            'confidence' => 0.7,
            'reason' => 'hash_changed',
            'reasoning' => ['Hash souboru se změnil - potřebná detailní analýza'],
            'change_summary' => ['total_changes' => 1, 'severity' => 'unknown'],
            'semantic_score' => 50
        ];
    }

    /**
     * Vylepšené heuristiky s kontextem dokumentace
     */
    private function advancedHeuristicAnalysis(
        string $filePath,
        array $oldStructure,
        array $newStructure,
        ?array $existingDoc
    ): array {
        // 1. Žádná dokumentace = vždy generuj
        if (!$existingDoc) {
            return [
                'should_regenerate' => true,
                'confidence' => 1.0,
                'reason' => 'no_existing_doc',
                'reasoning' => ['Žádná existující dokumentace - nutné vygenerovat'],
                'change_summary' => ['severity' => 'major'],
                'affected_sections' => []
            ];
        }

        // 2. Pouze privátní změny = přeskoč (kontrola PŘED ostatními)
        if ($this->onlyPrivateChanges($oldStructure, $newStructure)) {
            return [
                'should_regenerate' => false,
                'confidence' => 0.8,
                'reason' => 'private_changes_only',
                'reasoning' => ['Pouze změny v privátních metodách - dokumentace nemusí být aktualizována'],
                'change_summary' => ['severity' => 'minor'],
                'affected_sections' => []
            ];
        }

        // 3. Změny ve veřejných metodách = generuj
        if ($this->hasPublicApiChanges($oldStructure, $newStructure)) {
            return [
                'should_regenerate' => true,
                'confidence' => 0.95,
                'reason' => 'public_api_changes',
                'reasoning' => ['Změny ve veřejném API vyžadují aktualizaci dokumentace'],
                'change_summary' => ['severity' => 'major'],
                'affected_sections' => $this->getAffectedSections($oldStructure, $newStructure, $existingDoc)
            ];
        }

        // 4. Změny v dokumentovaných částech = generuj
        if ($this->affectsDocumentedParts($oldStructure, $newStructure, $existingDoc)) {
            return [
                'should_regenerate' => true,
                'confidence' => 0.9,
                'reason' => 'documented_parts_changed',
                'reasoning' => ['Změny ovlivňují části kódu, které jsou dokumentované'],
                'change_summary' => ['severity' => 'major'],
                'affected_sections' => $this->getAffectedSections($oldStructure, $newStructure, $existingDoc)
            ];
        }

        // 5. Strukturální změny = generuj
        if ($this->hasStructuralChanges($oldStructure, $newStructure)) {
            return [
                'should_regenerate' => true,
                'confidence' => 0.85,
                'reason' => 'structural_changes',
                'reasoning' => ['Strukturální změny (nové/odstraněné třídy, metody)'],
                'change_summary' => ['severity' => 'major'],
                'affected_sections' => $this->getAffectedSections($oldStructure, $newStructure, $existingDoc)
            ];
        }

        // 6. Fallback - při nejistotě raději generuj
        return [
            'should_regenerate' => true,
            'confidence' => 0.6,
            'reason' => 'uncertain_impact',
            'reasoning' => ['Nejasný dopad změn - pro jistotu regeneruji dokumentaci'],
            'change_summary' => ['severity' => 'unknown'],
            'affected_sections' => []
        ];
    }

    /**
     * Zkontroluj změny ve veřejných API
     */
    private function hasPublicApiChanges(array $oldStructure, array $newStructure): bool
    {
        // Porovnej veřejné metody tříd
        foreach ($newStructure['classes'] ?? [] as $newClass) {
            $oldClass = $this->findClassByName($oldStructure['classes'] ?? [], $newClass['name']);

            if (!$oldClass) {
                return true; // Nová třída
            }

            if ($this->hasPublicMethodChanges($oldClass['methods'] ?? [], $newClass['methods'] ?? [])) {
                return true;
            }
        }

        // Zkontroluj odstraněné třídy
        foreach ($oldStructure['classes'] ?? [] as $oldClass) {
            if (!$this->findClassByName($newStructure['classes'] ?? [], $oldClass['name'])) {
                return true; // Odstraněná třída
            }
        }

        return false;
    }

    /**
     * Zkontroluj zda změny ovlivňují dokumentované části
     */
    private function affectsDocumentedParts(array $oldStructure, array $newStructure, array $existingDoc): bool
    {
        if (!isset($existingDoc['documented_elements'])) {
            return false;
        }

        $documentedElements = $existingDoc['documented_elements'];

        foreach ($documentedElements as $element) {
            if (!$this->elementExistsInStructure($element, $newStructure)) {
                return true; // Dokumentovaný element byl odstraněn
            }
        }

        return false;
    }

    /**
     * Zkontroluj zda jsou pouze privátní změny
     */
    private function onlyPrivateChanges(array $oldStructure, array $newStructure): bool
    {
        // Kontrola počtu veřejných metod
        $oldPublicCount = $this->countPublicMethods($oldStructure);
        $newPublicCount = $this->countPublicMethods($newStructure);

        if ($oldPublicCount !== $newPublicCount) {
            return false; // Změnil se počet veřejných metod
        }

        // Kontrola počtu veřejných vlastností
        $oldPublicProperties = $this->countPublicProperties($oldStructure);
        $newPublicProperties = $this->countPublicProperties($newStructure);

        if ($oldPublicProperties !== $newPublicProperties) {
            return false; // Změnil se počet veřejných vlastností
        }

        // Kontrola signatur veřejných metod
        foreach ($newStructure['classes'] ?? [] as $newClass) {
            $oldClass = $this->findClassByName($oldStructure['classes'] ?? [], $newClass['name']);

            if (!$oldClass) {
                return false; // Nová třída
            }

            // Porovnej signatury veřejných metod
            if (!$this->publicMethodSignaturesMatch($oldClass['methods'] ?? [], $newClass['methods'] ?? [])) {
                return false; // Změnily se signatury veřejných metod
            }
        }

        return true; // Pouze privátní změny
    }

    /**
     * Zkontroluj strukturální změny
     */
    private function hasStructuralChanges(array $oldStructure, array $newStructure): bool
    {
        return (
            count($oldStructure['classes'] ?? []) !== count($newStructure['classes'] ?? []) ||
            count($oldStructure['functions'] ?? []) !== count($newStructure['functions'] ?? []) ||
            count($oldStructure['interfaces'] ?? []) !== count($newStructure['interfaces'] ?? []) ||
            count($oldStructure['traits'] ?? []) !== count($newStructure['traits'] ?? [])
        );
    }

    /**
     * Najdi třídu podle jména
     */
    private function findClassByName(array $classes, string $name): ?array
    {
        foreach ($classes as $class) {
            if ($class['name'] === $name) {
                return $class;
            }
        }
        return null;
    }

    /**
     * Zkontroluj změny ve veřejných metodách
     */
    private function hasPublicMethodChanges(array $oldMethods, array $newMethods): bool
    {
        $oldPublicMethods = array_filter($oldMethods, fn($m) => ($m['visibility'] ?? 'public') === 'public');
        $newPublicMethods = array_filter($newMethods, fn($m) => ($m['visibility'] ?? 'public') === 'public');

        return count($oldPublicMethods) !== count($newPublicMethods);
    }

    /**
     * Spočítej veřejné metody
     */
    private function countPublicMethods(array $structure): int
    {
        $count = 0;
        foreach ($structure['classes'] ?? [] as $class) {
            foreach ($class['methods'] ?? [] as $method) {
                if (($method['visibility'] ?? 'public') === 'public') {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Spočítej veřejné vlastnosti
     */
    private function countPublicProperties(array $structure): int
    {
        $count = 0;
        foreach ($structure['classes'] ?? [] as $class) {
            foreach ($class['properties'] ?? [] as $property) {
                if (($property['visibility'] ?? 'public') === 'public') {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Zkontroluj zda se signatury veřejných metod shodují
     */
    private function publicMethodSignaturesMatch(array $oldMethods, array $newMethods): bool
    {
        $oldPublicMethods = array_filter($oldMethods, fn($m) => ($m['visibility'] ?? 'public') === 'public');
        $newPublicMethods = array_filter($newMethods, fn($m) => ($m['visibility'] ?? 'public') === 'public');

        // Porovnej názvy metod
        $oldNames = array_map(fn($m) => $m['name'], $oldPublicMethods);
        $newNames = array_map(fn($m) => $m['name'], $newPublicMethods);

        sort($oldNames);
        sort($newNames);

        return $oldNames === $newNames;
    }

    /**
     * Zkontroluj zda element existuje ve struktuře
     */
    private function elementExistsInStructure(array $element, array $structure): bool
    {
        $type = $element['type'];
        $name = $element['name'];

        switch ($type) {
            case 'class':
                return $this->findClassByName($structure['classes'] ?? [], $name) !== null;
            case 'function':
                foreach ($structure['functions'] ?? [] as $func) {
                    if ($func['name'] === $name) return true;
                }
                return false;
            default:
                return true; // Neznámý typ - předpokládej že existuje
        }
    }

    /**
     * Získej ovlivněné sekce dokumentace
     */
    private function getAffectedSections(array $oldStructure, array $newStructure, array $existingDoc): array
    {
        $affectedSections = [];

        // Jednoduchá implementace - vrať všechny sekce pokud jsou změny
        if (isset($existingDoc['sections'])) {
            $affectedSections = array_keys($existingDoc['sections']);
        }

        return $affectedSections;
    }
}
