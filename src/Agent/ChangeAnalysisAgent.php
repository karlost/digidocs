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
use Digihood\Digidocs\Services\CostTracker;
use Digihood\Digidocs\Services\CodeDocumentationMemory;
use Exception;

class ChangeAnalysisAgent extends Agent
{
    private ?DocumentationAnalyzer $documentationAnalyzer = null;
    private ?CostTracker $costTracker = null;
    private ?CodeDocumentationMemory $memory = null;

    private function getDocumentationAnalyzer(): DocumentationAnalyzer
    {
        if ($this->documentationAnalyzer === null) {
            $this->documentationAnalyzer = new DocumentationAnalyzer();
        }
        return $this->documentationAnalyzer;
    }

    /**
     * Nastaví cost tracker pro sledování tokenů
     */
    public function setCostTracker(CostTracker $costTracker): self
    {
        $this->costTracker = $costTracker;
        $this->observe($costTracker);
        return $this;
    }

    /**
     * Nastaví memory service pro RAG context
     */
    public function setMemory(CodeDocumentationMemory $memory): self
    {
        $this->memory = $memory;
        return $this;
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
                "Consider existing documentation context when analyzing changes",
                "Check if changes affect documented APIs or behavior",
                "Distinguish between cosmetic changes (whitespace, comments) and significant changes",
                "Structural changes (new classes, methods, properties) require documentation updates",
                "Semantic changes (logic changes, parameters) also require updates",
                "Changes only in comments or formatting usually don't require updates",
                "Identify which other documentation might be affected by these changes",
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
            
            // Pokud neexistuje dokumentace, vynutit regeneraci
            if (!$existingDoc) {
                \Log::info("No existing documentation for {$filePath} - forcing documentation generation");
                $analysis = [
                    'should_regenerate' => true,
                    'confidence' => 1.0,
                    'reason' => 'no_existing_doc',
                    'reasoning' => ['Neexistuje dokumentace - vygenerovat novou'],
                    'change_summary' => [],
                    'semantic_score' => 100,
                    'existing_doc_path' => null,
                    'doc_relevance_score' => 100,
                    'affected_doc_sections' => []
                ];
            } else {
                // NOVÉ: Analyzuj strukturu kódu
                $oldStructure = $oldContent ? $this->getDocumentationAnalyzer()->parseCodeStructure($oldContent) : [];
                $newStructure = $this->getDocumentationAnalyzer()->parseCodeStructure($newContent);

                // Proveď rozšířenou analýzu změn - POUZE heuristická analýza, žádné AI
                $analysis = $this->analyzeChangesWithDocumentation($filePath, $oldContent, $newContent, $oldStructure, $newStructure, $existingDoc);
            }

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
     * Generuje dokumentaci pomocí klasického DocumentationAgent
     */
    private function generateWithClassicAgent(string $filePath): string
    {
        // KLÍČOVÁ OPRAVA: Vytvoř novou instanci agenta místo mazání historie
        // Tím se zachovají systémové instrukce a vyřeší se problém s JSON výstupem
        $documentationAgent = new \Digihood\Digidocs\Agent\DocumentationAgent();

        // Nastav cost tracker pokud je dostupný
        if ($this->costTracker) {
            $documentationAgent->setCostTracker($this->costTracker);
        }

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

        // Získej RAG kontext pokud je k dispozici
        $ragContext = [];
        if ($this->memory) {
            $ragContext = $this->memory->getCodeDocumentationContext($filePath);
            
            // Zkontroluj ovlivněné dokumentace
            $affectedDocs = $this->memory->updateRelatedDocumentation($filePath, [
                'modified_classes' => $this->extractModifiedClasses($oldStructure, $newStructure),
                'modified_methods' => $this->extractModifiedMethods($oldStructure, $newStructure)
            ]);
        }

        // Vypočítej dopad na dokumentaci
        $codeChanges = [
            'old_structure' => $oldStructure,
            'new_structure' => $newStructure,
            'content_changed' => true,
            'rag_context' => $ragContext,
            'affected_docs' => $affectedDocs ?? []
        ];

        $docRelevanceScore = $this->getDocumentationAnalyzer()->calculateDocumentationRelevance($codeChanges, $existingDoc);

        // Použij vylepšené heuristiky
        $heuristicResult = $this->advancedHeuristicAnalysis($filePath, $oldContent, $newContent, $oldStructure, $newStructure, $existingDoc);

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
     * Vylepšené heuristiky s kontextem dokumentace
     */
    private function advancedHeuristicAnalysis(
        string $filePath,
        string $oldContent,
        string $newContent,
        array $oldStructure,
        array $newStructure,
        ?array $existingDoc
    ): array {
        // 0. Kontrola kosmetických změn (komentáře, whitespace)
        if ($this->isOnlyCosmeticChange($oldContent, $newContent)) {
            return [
                'should_regenerate' => false,
                'confidence' => 0.9,
                'reason' => 'cosmetic_changes_only',
                'reasoning' => ['Pouze kosmetické změny (komentáře, whitespace) - dokumentace zůstává aktuální'],
                'change_summary' => ['severity' => 'minimal'],
                'affected_sections' => []
            ];
        }

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

        // 2. Pouze privátní změny = zvažuj podle rozsahu změn
        if ($this->onlyPrivateChanges($oldStructure, $newStructure)) {
            // Zkontroluj rozsah privátních změn
            $privateChangeImpact = $this->assessPrivateChangeImpact($oldContent, $newContent);

            if ($privateChangeImpact['is_significant']) {
                return [
                    'should_regenerate' => true,
                    'confidence' => 0.7,
                    'reason' => 'significant_private_changes',
                    'reasoning' => ['Významné změny v privátních metodách mohou ovlivnit celkovou funkcionalitu'],
                    'change_summary' => ['severity' => 'moderate'],
                    'affected_sections' => []
                ];
            } else {
                return [
                    'should_regenerate' => false,
                    'confidence' => 0.8,
                    'reason' => 'minor_private_changes',
                    'reasoning' => ['Pouze drobné změny v privátních metodách - dokumentace zůstává aktuální'],
                    'change_summary' => ['severity' => 'minor'],
                    'affected_sections' => []
                ];
            }
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

        // Kontrola počtu metod
        if (count($oldPublicMethods) !== count($newPublicMethods)) {
            return true;
        }

        // Kontrola signatur metod (parametry, návratové typy)
        foreach ($newPublicMethods as $newMethod) {
            $oldMethod = $this->findMethodByName($oldPublicMethods, $newMethod['name']);

            if (!$oldMethod) {
                return true; // Nová metoda
            }

            // Porovnej signatury
            if (!$this->methodSignaturesMatch($oldMethod, $newMethod)) {
                return true; // Změnila se signatura
            }
        }

        return false;
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

        // Kontrola počtu metod
        if (count($oldPublicMethods) !== count($newPublicMethods)) {
            return false;
        }

        // Porovnej signatury každé metody
        foreach ($newPublicMethods as $newMethod) {
            $oldMethod = $this->findMethodByName($oldPublicMethods, $newMethod['name']);

            if (!$oldMethod || !$this->methodSignaturesMatch($oldMethod, $newMethod)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Najdi metodu podle názvu
     */
    private function findMethodByName(array $methods, string $name): ?array
    {
        foreach ($methods as $method) {
            if ($method['name'] === $name) {
                return $method;
            }
        }
        return null;
    }

    /**
     * Porovnej signatury dvou metod (parametry, návratový typ)
     */
    private function methodSignaturesMatch(array $oldMethod, array $newMethod): bool
    {
        // Porovnej návratový typ
        if (($oldMethod['return_type'] ?? '') !== ($newMethod['return_type'] ?? '')) {
            return false;
        }

        // Porovnej parametry
        $oldParams = $oldMethod['parameters'] ?? [];
        $newParams = $newMethod['parameters'] ?? [];

        if (count($oldParams) !== count($newParams)) {
            return false;
        }

        // Porovnej každý parametr
        for ($i = 0; $i < count($oldParams); $i++) {
            $oldParam = $oldParams[$i];
            $newParam = $newParams[$i];

            // Porovnej název parametru
            if (($oldParam['name'] ?? '') !== ($newParam['name'] ?? '')) {
                return false;
            }

            // Porovnej typ parametru
            if (($oldParam['type'] ?? '') !== ($newParam['type'] ?? '')) {
                return false;
            }

            // Porovnej default hodnotu
            if (($oldParam['default'] ?? null) !== ($newParam['default'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Zhodnoť dopad privátních změn
     */
    private function assessPrivateChangeImpact(string $oldContent, string $newContent): array
    {
        $oldLines = explode("\n", $oldContent);
        $newLines = explode("\n", $newContent);

        $totalLines = max(count($oldLines), count($newLines));
        $changedLines = abs(count($newLines) - count($oldLines));

        // Počítej změny v řádcích
        $diffLines = 0;
        $maxLines = max(count($oldLines), count($newLines));
        for ($i = 0; $i < $maxLines; $i++) {
            $oldLine = $oldLines[$i] ?? '';
            $newLine = $newLines[$i] ?? '';
            if (trim($oldLine) !== trim($newLine)) {
                $diffLines++;
            }
        }

        $changePercentage = $totalLines > 0 ? ($diffLines / $totalLines) * 100 : 0;

        // Hledej klíčová slova indikující významné změny
        $significantKeywords = [
            'new ', 'class ', 'function ', 'return ', 'throw ', 'catch ',
            'if (', 'else', 'switch', 'case', 'for (', 'while (', 'foreach (',
            'array_', 'json_', 'file_', 'curl_', 'http_', 'sql', 'database',
            'cache', 'session', 'config', 'env(', 'log::', 'error', 'exception'
        ];

        $keywordChanges = 0;
        $newContentLower = strtolower($newContent);
        $oldContentLower = strtolower($oldContent);

        foreach ($significantKeywords as $keyword) {
            $oldCount = substr_count($oldContentLower, $keyword);
            $newCount = substr_count($newContentLower, $keyword);
            if ($oldCount !== $newCount) {
                $keywordChanges++;
            }
        }

        // Rozhodnutí o významnosti
        $isSignificant = (
            $changePercentage > 20 ||  // Více než 20% řádků změněno
            $changedLines > 10 ||      // Více než 10 řádků přidáno/odebráno
            $keywordChanges > 3        // Více než 3 významná klíčová slova změněna
        );

        return [
            'is_significant' => $isSignificant,
            'change_percentage' => $changePercentage,
            'changed_lines' => $changedLines,
            'keyword_changes' => $keywordChanges,
            'total_lines' => $totalLines
        ];
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

    /**
     * Zkontroluj zda jsou změny pouze kosmetické (komentáře, whitespace)
     */
    private function isOnlyCosmeticChange(string $oldContent, string $newContent): bool
    {
        // Odstraň komentáře a normalizuj whitespace
        $oldNormalized = $this->normalizeCodeForComparison($oldContent);
        $newNormalized = $this->normalizeCodeForComparison($newContent);

        return $oldNormalized === $newNormalized;
    }

    /**
     * Normalizuje kód pro porovnání - odstraní komentáře a whitespace
     */
    private function normalizeCodeForComparison(string $content): string
    {
        // Odstraň jednořádkové komentáře //
        $content = preg_replace('/\/\/.*$/m', '', $content);

        // Odstraň víceřádkové komentáře /* */
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);

        // Odstraň DocBlock komentáře /** */
        $content = preg_replace('/\/\*\*.*?\*\//s', '', $content);

        // Normalizuj whitespace - odstraň nadbytečné mezery a prázdné řádky
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        return $content;
    }

    /**
     * Extrahuje modifikované třídy
     */
    private function extractModifiedClasses(array $oldStructure, array $newStructure): array
    {
        $modifiedClasses = [];
        
        $oldClasses = array_column($oldStructure['classes'] ?? [], 'name');
        $newClasses = array_column($newStructure['classes'] ?? [], 'name');
        
        // Přidané třídy
        $addedClasses = array_diff($newClasses, $oldClasses);
        foreach ($addedClasses as $class) {
            $modifiedClasses[] = $class;
        }
        
        // Zkontroluj změněné třídy
        foreach ($oldStructure['classes'] ?? [] as $oldClass) {
            foreach ($newStructure['classes'] ?? [] as $newClass) {
                if ($oldClass['name'] === $newClass['name']) {
                    // Zkontroluj změny v metodách nebo vlastnostech
                    $oldMethods = array_column($oldClass['methods'] ?? [], 'name');
                    $newMethods = array_column($newClass['methods'] ?? [], 'name');
                    
                    if ($oldMethods !== $newMethods) {
                        $modifiedClasses[] = $oldClass['name'];
                    }
                    break;
                }
            }
        }
        
        return array_unique($modifiedClasses);
    }

    /**
     * Extrahuje modifikované metody
     */
    private function extractModifiedMethods(array $oldStructure, array $newStructure): array
    {
        $modifiedMethods = [];
        
        foreach ($oldStructure['classes'] ?? [] as $oldClass) {
            foreach ($newStructure['classes'] ?? [] as $newClass) {
                if ($oldClass['name'] === $newClass['name']) {
                    $oldMethods = $oldClass['methods'] ?? [];
                    $newMethods = $newClass['methods'] ?? [];
                    
                    // Porovnej metody
                    foreach ($oldMethods as $oldMethod) {
                        $found = false;
                        foreach ($newMethods as $newMethod) {
                            if ($oldMethod['name'] === $newMethod['name']) {
                                // Zkontroluj signaturu
                                if ($this->hasMethodSignatureChanged($oldMethod, $newMethod)) {
                                    $modifiedMethods[] = $oldClass['name'] . '::' . $oldMethod['name'];
                                }
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $modifiedMethods[] = $oldClass['name'] . '::' . $oldMethod['name'];
                        }
                    }
                    
                    // Přidané metody
                    foreach ($newMethods as $newMethod) {
                        $found = false;
                        foreach ($oldMethods as $oldMethod) {
                            if ($oldMethod['name'] === $newMethod['name']) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $modifiedMethods[] = $newClass['name'] . '::' . $newMethod['name'];
                        }
                    }
                }
            }
        }
        
        return array_unique($modifiedMethods);
    }

    /**
     * Zkontroluje jestli se změnila signatura metody
     */
    private function hasMethodSignatureChanged(array $oldMethod, array $newMethod): bool
    {
        // Porovnej parametry
        $oldParams = array_map(function($p) {
            return ($p['type'] ?? '') . ' $' . $p['name'];
        }, $oldMethod['parameters'] ?? []);
        
        $newParams = array_map(function($p) {
            return ($p['type'] ?? '') . ' $' . $p['name'];
        }, $newMethod['parameters'] ?? []);
        
        if ($oldParams !== $newParams) {
            return true;
        }
        
        // Porovnej návratový typ
        if (($oldMethod['return_type'] ?? '') !== ($newMethod['return_type'] ?? '')) {
            return true;
        }
        
        return false;
    }
}
