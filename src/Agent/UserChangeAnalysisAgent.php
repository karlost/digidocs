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
use Exception;

class UserChangeAnalysisAgent extends Agent
{
    private ?DocumentationAnalyzer $documentationAnalyzer = null;
    private ?CostTracker $costTracker = null;

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
                "You are an expert in analyzing PHP code changes for user-facing documentation needs.",
                "You focus specifically on changes that affect how users interact with the application.",
                "Your goal is to identify changes that impact user experience, features, or functionality.",
                "You distinguish between developer-focused changes and user-visible changes."
            ],
            steps: [
                "Analyze changes in PHP files from a user perspective",
                "Focus on user-facing features: controllers, API endpoints, public methods",
                "Identify changes in business logic that affect user experience",
                "Consider changes in authentication, authorization, and data processing",
                "Ignore purely technical changes that don't affect users",
                "Look for new features, modified workflows, or removed functionality",
                "Consider changes in validation rules, error messages, or data formats",
                "Provide clear reasoning focused on user impact"
            ],
            output: [
                "Provide structured analysis in JSON format",
                "Include should_regenerate boolean decision for user documentation",
                "Include confidence score (0.0-1.0)",
                "Include reason focused on user impact",
                "Include detailed reasoning array with user-centric evidence",
                "Include user_impact_score (0-100) indicating significance for users"
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
     * Hlavní metoda - generuje user dokumentaci pouze pokud je potřeba
     */
    public function generateUserDocumentationIfNeeded(string $filePath): ?string
    {
        try {
            \Log::info("UserChangeAnalysisAgent: Processing {$filePath}");

            // Zkontroluj jestli je inteligentní analýza zapnutá
            if (!config('digidocs.intelligent_analysis.enabled', true)) {
                \Log::info("Intelligent analysis disabled, using classic UserDocumentationAgent");
                return $this->generateWithUserAgent($filePath);
            }

            // Získej obsah souborů
            $oldContent = $this->getOldFileContent($filePath);
            $newContent = $this->getCurrentFileContent($filePath);

            if ($newContent === null) {
                \Log::warning("Could not read file content for {$filePath}");
                return null;
            }

            // Získej existující user dokumentaci
            $existingUserDoc = $this->getDocumentationAnalyzer()->analyzeExistingUserDocumentation($filePath);

            // Analyzuj strukturu kódu
            $oldStructure = $oldContent ? $this->getDocumentationAnalyzer()->parseCodeStructure($oldContent) : [];
            $newStructure = $this->getDocumentationAnalyzer()->parseCodeStructure($newContent);

            // Proveď analýzu změn zaměřenou na uživatele
            $analysis = $this->analyzeUserFacingChanges($filePath, $oldContent, $newContent, $oldStructure, $newStructure, $existingUserDoc);

            // Zaznamenej analýzu do databáze
            $this->recordUserAnalysis($filePath, $analysis);

            // Rozhodni na základě analýzy
            if (!$analysis['should_regenerate']) {
                \Log::info("Skipping user documentation for {$filePath}: {$analysis['reason']}");
                return null;
            }

            \Log::info("Generating user documentation for {$filePath}: {$analysis['reason']}");
            return $this->generateWithUserAgent($filePath);

        } catch (Exception $e) {
            \Log::error("UserChangeAnalysisAgent error for {$filePath}: " . $e->getMessage());

            // Fallback na orchestrator při chybě
            if (config('digidocs.intelligent_analysis.fallback_to_classic', true)) {
                \Log::info("Falling back to UserDocumentationOrchestrator");
                return $this->generateWithUserAgent($filePath);
            }

            return null;
        }
    }

    /**
     * Generuje dokumentaci pomocí UserDocumentationOrchestrator
     */
    private function generateWithUserAgent(string $filePath): string
    {
        try {
            // Použij nový orchestrator pro generování user dokumentace
            $orchestrator = app(\Digihood\Digidocs\Agent\UserDocumentationOrchestrator::class);
            
            // Nastav cost tracker pokud je dostupný
            if ($this->costTracker && method_exists($orchestrator, 'setCostTracker')) {
                $orchestrator->setCostTracker($this->costTracker);
            }

            // Spustí kompletní regeneraci dokumentace
            $orchestrator->generateCompleteDocumentation();
            
            return "User documentation regenerated via orchestrator";
            
        } catch (\Exception $e) {
            \Log::error("Failed to generate documentation via orchestrator: " . $e->getMessage());
            return "Error generating documentation: " . $e->getMessage();
        }
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
     * Analýza změn zaměřená na uživatele
     */
    private function analyzeUserFacingChanges(
        string $filePath,
        string $oldContent,
        string $newContent,
        array $oldStructure,
        array $newStructure,
        ?array $existingUserDoc
    ): array {
        // Rychlé kontroly
        if ($oldContent === $newContent) {
            return [
                'should_regenerate' => false,
                'confidence' => 1.0,
                'reason' => 'identical_content',
                'reasoning' => ['Obsah souboru je identický'],
                'change_summary' => [],
                'user_impact_score' => 0,
                'existing_user_doc_path' => $existingUserDoc['path'] ?? null,
                'affected_user_features' => []
            ];
        }

        if (empty(trim($oldContent))) {
            // Nový soubor - zkontroluj jestli má user-facing funkce
            $userImpactScore = $this->calculateUserImpactScore($filePath, $newStructure);
            
            if ($userImpactScore > 20) {
                return [
                    'should_regenerate' => true,
                    'confidence' => 1.0,
                    'reason' => 'new_user_facing_file',
                    'reasoning' => ['Nový soubor s funkcemi ovlivňujícími uživatele'],
                    'change_summary' => [],
                    'user_impact_score' => $userImpactScore,
                    'existing_user_doc_path' => null,
                    'affected_user_features' => $this->identifyUserFeatures($newStructure)
                ];
            } else {
                return [
                    'should_regenerate' => false,
                    'confidence' => 0.8,
                    'reason' => 'new_non_user_facing_file',
                    'reasoning' => ['Nový soubor bez významného dopadu na uživatele'],
                    'change_summary' => [],
                    'user_impact_score' => $userImpactScore,
                    'existing_user_doc_path' => null,
                    'affected_user_features' => []
                ];
            }
        }

        // Použij user-focused heuristiky
        $heuristicResult = $this->userFocusedHeuristicAnalysis($filePath, $oldContent, $newContent, $oldStructure, $newStructure, $existingUserDoc);

        // DEBUG: Log user heuristiky
        \Log::info("UserChangeAnalysisAgent heuristic result for {$filePath}", [
            'should_regenerate' => $heuristicResult['should_regenerate'],
            'reason' => $heuristicResult['reason'],
            'confidence' => $heuristicResult['confidence'] ?? 0.8,
            'user_impact_score' => $heuristicResult['user_impact_score'] ?? 0,
            'existing_user_doc' => $existingUserDoc ? 'exists' : 'missing'
        ]);

        return $heuristicResult;
    }

    /**
     * Zaznamenej user analýzu do databáze
     */
    private function recordUserAnalysis(string $filePath, array $analysis): void
    {
        try {
            $memoryService = app(\Digihood\Digidocs\Services\MemoryService::class);
            $currentHash = hash_file('sha256', base_path($filePath));

            // Použij metodu z MemoryService pro user analýzu
            if (method_exists($memoryService, 'recordUserAnalysis')) {
                $memoryService->recordUserAnalysis($filePath, $currentHash, $analysis);
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to record user analysis for {$filePath}: " . $e->getMessage());
        }
    }

    /**
     * User-focused heuristiky
     */
    private function userFocusedHeuristicAnalysis(
        string $filePath,
        string $oldContent,
        string $newContent,
        array $oldStructure,
        array $newStructure,
        ?array $existingUserDoc
    ): array {
        
        // 1. Kontrola jestli soubor není user-facing
        if (!$this->isUserFacingFile($filePath, $newStructure)) {
            return [
                'should_regenerate' => false,
                'confidence' => 0.9,
                'reason' => 'not_user_facing',
                'reasoning' => ['Soubor neobsahuje user-facing funkce (internal/private kód)'],
                'change_summary' => ['severity' => 'minimal'],
                'user_impact_score' => 0,
                'affected_user_features' => []
            ];
        }

        // 2. Kontrola kosmetických změn
        if ($this->isOnlyCosmeticChange($oldContent, $newContent)) {
            return [
                'should_regenerate' => false,
                'confidence' => 0.9,
                'reason' => 'cosmetic_changes_only',
                'reasoning' => ['Pouze kosmetické změny - žádný dopad na uživatele'],
                'change_summary' => ['severity' => 'minimal'],
                'user_impact_score' => 0,
                'affected_user_features' => []
            ];
        }

        // 3. Žádná user dokumentace = generuj pokud má user impact
        if (!$existingUserDoc) {
            $userImpactScore = $this->calculateUserImpactScore($filePath, $newStructure);
            
            if ($userImpactScore > 30) {
                return [
                    'should_regenerate' => true,
                    'confidence' => 1.0,
                    'reason' => 'no_user_doc_high_impact',
                    'reasoning' => ['Žádná user dokumentace pro soubor s vysokým dopadem na uživatele'],
                    'change_summary' => ['severity' => 'major'],
                    'user_impact_score' => $userImpactScore,
                    'affected_user_features' => $this->identifyUserFeatures($newStructure)
                ];
            } else {
                return [
                    'should_regenerate' => false,
                    'confidence' => 0.8,
                    'reason' => 'no_user_doc_low_impact',
                    'reasoning' => ['Žádná user dokumentace, ale nízký dopad na uživatele'],
                    'change_summary' => ['severity' => 'minimal'],
                    'user_impact_score' => $userImpactScore,
                    'affected_user_features' => []
                ];
            }
        }

        // 4. Změny v user-facing API
        if ($this->hasUserFacingApiChanges($oldStructure, $newStructure)) {
            return [
                'should_regenerate' => true,
                'confidence' => 0.95,
                'reason' => 'user_facing_api_changes',
                'reasoning' => ['Změny v API nebo funkcích ovlivňujících uživatele'],
                'change_summary' => ['severity' => 'major'],
                'user_impact_score' => 80,
                'affected_user_features' => $this->identifyAffectedUserFeatures($oldStructure, $newStructure)
            ];
        }

        // 5. Změny v business logice
        if ($this->hasBusinessLogicChanges($filePath, $oldContent, $newContent)) {
            return [
                'should_regenerate' => true,
                'confidence' => 0.85,
                'reason' => 'business_logic_changes',
                'reasoning' => ['Změny v business logice ovlivňující chování aplikace'],
                'change_summary' => ['severity' => 'major'],
                'user_impact_score' => 70,
                'affected_user_features' => $this->identifyUserFeatures($newStructure)
            ];
        }

        // 6. Změny v validaci nebo zpracování dat
        if ($this->hasValidationOrDataProcessingChanges($oldContent, $newContent)) {
            return [
                'should_regenerate' => true,
                'confidence' => 0.8,
                'reason' => 'validation_data_changes',
                'reasoning' => ['Změny ve validaci nebo zpracování dat ovlivňující uživatele'],
                'change_summary' => ['severity' => 'moderate'],
                'user_impact_score' => 60,
                'affected_user_features' => []
            ];
        }

        // 7. Obecný user impact score
        $userImpactScore = $this->calculateUserImpactScore($filePath, $newStructure);
        
        if ($userImpactScore > 50) {
            return [
                'should_regenerate' => true,
                'confidence' => 0.7,
                'reason' => 'moderate_user_impact',
                'reasoning' => ['Střední dopad na uživatele - doporučuji aktualizaci dokumentace'],
                'change_summary' => ['severity' => 'moderate'],
                'user_impact_score' => $userImpactScore,
                'affected_user_features' => $this->identifyUserFeatures($newStructure)
            ];
        }

        // 8. Fallback - nízký user impact
        return [
            'should_regenerate' => false,
            'confidence' => 0.8,
            'reason' => 'low_user_impact',
            'reasoning' => ['Nízký dopad na uživatele - user dokumentace zůstává aktuální'],
            'change_summary' => ['severity' => 'minor'],
            'user_impact_score' => $userImpactScore,
            'affected_user_features' => []
        ];
    }

    /**
     * Zkontroluj zda je soubor user-facing
     */
    private function isUserFacingFile(string $filePath, array $structure): bool
    {
        // Controllers jsou určitě user-facing
        if (str_contains($filePath, 'Controller')) {
            return true;
        }

        // API routes a middleware
        if (str_contains($filePath, '/Api/') || str_contains($filePath, 'Middleware')) {
            return true;
        }

        // Models s public methodami mohou být user-facing
        if (str_contains($filePath, 'Model')) {
            return $this->hasPublicMethods($structure);
        }

        // Services použité v controllerech
        if (str_contains($filePath, 'Service')) {
            return $this->hasPublicMethods($structure);
        }

        // Commands a Jobs mohou ovlivnit uživatele
        if (str_contains($filePath, 'Command') || str_contains($filePath, 'Job')) {
            return true;
        }

        // Requests a Resources
        if (str_contains($filePath, 'Request') || str_contains($filePath, 'Resource')) {
            return true;
        }

        // Migrations mohou ovlivnit uživatele
        if (str_contains($filePath, 'migration')) {
            return true;
        }

        return false;
    }

    /**
     * Zkontroluj zda má třída public metody
     */
    private function hasPublicMethods(array $structure): bool
    {
        foreach ($structure['classes'] ?? [] as $class) {
            foreach ($class['methods'] ?? [] as $method) {
                if (($method['visibility'] ?? 'public') === 'public' && !str_starts_with($method['name'], '__')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Zkontroluj změny v user-facing API
     */
    private function hasUserFacingApiChanges(array $oldStructure, array $newStructure): bool
    {
        // Kontrola veřejných metod ovlivňujících uživatele
        foreach ($newStructure['classes'] ?? [] as $newClass) {
            $oldClass = $this->findClassByName($oldStructure['classes'] ?? [], $newClass['name']);

            if (!$oldClass) {
                // Nová třída s public metodami
                if ($this->hasPublicMethods(['classes' => [$newClass]])) {
                    return true;
                }
                continue;
            }

            // Kontrola změn v public metodách
            if ($this->hasPublicMethodChanges($oldClass['methods'] ?? [], $newClass['methods'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zkontroluj změny v business logice
     */
    private function hasBusinessLogicChanges(string $filePath, string $oldContent, string $newContent): bool
    {
        // Klíčová slova indikující business logiku
        $businessKeywords = [
            'if (', 'else', 'switch', 'case',
            'validate', 'authorize', 'authenticate',
            'calculate', 'process', 'handle',
            'create', 'update', 'delete', 'save',
            'send', 'notify', 'email', 'sms',
            'payment', 'order', 'invoice', 'billing',
            'permission', 'role', 'access',
            'return', 'response', 'redirect'
        ];

        return $this->hasKeywordChanges($oldContent, $newContent, $businessKeywords);
    }

    /**
     * Zkontroluj změny ve validaci nebo zpracování dat
     */
    private function hasValidationOrDataProcessingChanges(string $oldContent, string $newContent): bool
    {
        // Klíčová slova pro validaci a zpracování dat
        $validationKeywords = [
            'validate', 'rules', 'required', 'optional',
            'min:', 'max:', 'unique:', 'exists:',
            'array', 'string', 'integer', 'boolean',
            'json_', 'serialize', 'unserialize',
            'transform', 'map', 'filter', 'collect',
            'request', 'input', 'get', 'post',
            'sanitize', 'escape', 'clean'
        ];

        return $this->hasKeywordChanges($oldContent, $newContent, $validationKeywords);
    }

    /**
     * Zkontroluj změny v klíčových slovech
     */
    private function hasKeywordChanges(string $oldContent, string $newContent, array $keywords): bool
    {
        $oldContentLower = strtolower($oldContent);
        $newContentLower = strtolower($newContent);

        $changes = 0;
        foreach ($keywords as $keyword) {
            $oldCount = substr_count($oldContentLower, strtolower($keyword));
            $newCount = substr_count($newContentLower, strtolower($keyword));
            if ($oldCount !== $newCount) {
                $changes++;
            }
        }

        return $changes > 2; // Více než 2 klíčová slova změněna
    }

    /**
     * Vypočítej user impact score
     */
    private function calculateUserImpactScore(string $filePath, array $structure): int
    {
        $score = 0;

        // Bodování podle typu souboru
        if (str_contains($filePath, 'Controller')) $score += 40;
        if (str_contains($filePath, '/Api/')) $score += 35;
        if (str_contains($filePath, 'Request')) $score += 25;
        if (str_contains($filePath, 'Resource')) $score += 25;
        if (str_contains($filePath, 'Service')) $score += 20;
        if (str_contains($filePath, 'Model')) $score += 15;
        if (str_contains($filePath, 'Middleware')) $score += 30;
        if (str_contains($filePath, 'Command')) $score += 10;
        if (str_contains($filePath, 'Job')) $score += 10;
        if (str_contains($filePath, 'migration')) $score += 20;

        // Bodování podle obsahu
        foreach ($structure['classes'] ?? [] as $class) {
            // Public metody
            foreach ($class['methods'] ?? [] as $method) {
                if (($method['visibility'] ?? 'public') === 'public') {
                    $score += 5;
                }
            }

            // Public vlastnosti
            foreach ($class['properties'] ?? [] as $property) {
                if (($property['visibility'] ?? 'public') === 'public') {
                    $score += 3;
                }
            }
        }

        return min($score, 100); // Maximum 100
    }

    /**
     * Identifikuj user features
     */
    private function identifyUserFeatures(array $structure): array
    {
        $features = [];

        foreach ($structure['classes'] ?? [] as $class) {
            foreach ($class['methods'] ?? [] as $method) {
                if (($method['visibility'] ?? 'public') === 'public') {
                    $features[] = $class['name'] . '::' . $method['name'];
                }
            }
        }

        return $features;
    }

    /**
     * Identifikuj ovlivněné user features
     */
    private function identifyAffectedUserFeatures(array $oldStructure, array $newStructure): array
    {
        $affected = [];

        // Najdi změněné nebo nové public metody
        foreach ($newStructure['classes'] ?? [] as $newClass) {
            $oldClass = $this->findClassByName($oldStructure['classes'] ?? [], $newClass['name']);

            foreach ($newClass['methods'] ?? [] as $newMethod) {
                if (($newMethod['visibility'] ?? 'public') === 'public') {
                    if (!$oldClass) {
                        // Nová třída
                        $affected[] = $newClass['name'] . '::' . $newMethod['name'] . ' (new)';
                    } else {
                        $oldMethod = $this->findMethodByName($oldClass['methods'] ?? [], $newMethod['name']);
                        if (!$oldMethod) {
                            // Nová metoda
                            $affected[] = $newClass['name'] . '::' . $newMethod['name'] . ' (new)';
                        } elseif (!$this->methodSignaturesMatch($oldMethod, $newMethod)) {
                            // Změněná metoda
                            $affected[] = $newClass['name'] . '::' . $newMethod['name'] . ' (modified)';
                        }
                    }
                }
            }
        }

        return $affected;
    }

    /**
     * Pomocné metody převzaté z ChangeAnalysisAgent
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

    private function findMethodByName(array $methods, string $name): ?array
    {
        foreach ($methods as $method) {
            if ($method['name'] === $name) {
                return $method;
            }
        }
        return null;
    }

    private function hasPublicMethodChanges(array $oldMethods, array $newMethods): bool
    {
        $oldPublicMethods = array_filter($oldMethods, fn($m) => ($m['visibility'] ?? 'public') === 'public');
        $newPublicMethods = array_filter($newMethods, fn($m) => ($m['visibility'] ?? 'public') === 'public');

        if (count($oldPublicMethods) !== count($newPublicMethods)) {
            return true;
        }

        foreach ($newPublicMethods as $newMethod) {
            $oldMethod = $this->findMethodByName($oldPublicMethods, $newMethod['name']);

            if (!$oldMethod || !$this->methodSignaturesMatch($oldMethod, $newMethod)) {
                return true;
            }
        }

        return false;
    }

    private function methodSignaturesMatch(array $oldMethod, array $newMethod): bool
    {
        if (($oldMethod['return_type'] ?? '') !== ($newMethod['return_type'] ?? '')) {
            return false;
        }

        $oldParams = $oldMethod['parameters'] ?? [];
        $newParams = $newMethod['parameters'] ?? [];

        if (count($oldParams) !== count($newParams)) {
            return false;
        }

        for ($i = 0; $i < count($oldParams); $i++) {
            $oldParam = $oldParams[$i];
            $newParam = $newParams[$i];

            if (($oldParam['name'] ?? '') !== ($newParam['name'] ?? '') ||
                ($oldParam['type'] ?? '') !== ($newParam['type'] ?? '') ||
                ($oldParam['default'] ?? null) !== ($newParam['default'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function isOnlyCosmeticChange(string $oldContent, string $newContent): bool
    {
        $oldNormalized = $this->normalizeCodeForComparison($oldContent);
        $newNormalized = $this->normalizeCodeForComparison($newContent);

        return $oldNormalized === $newNormalized;
    }

    private function normalizeCodeForComparison(string $content): string
    {
        // Odstraň komentáře
        $content = preg_replace('/\/\/.*$/m', '', $content);
        $content = preg_replace('/\/\*.*?\*\//s', '', $content);
        $content = preg_replace('/\/\*\*.*?\*\//s', '', $content);

        // Normalizuj whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        return $content;
    }
}