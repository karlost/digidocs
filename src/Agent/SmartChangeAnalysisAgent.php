<?php

namespace Digihood\Digidocs\Agent;

use NeuronAI\Agent;
use NeuronAI\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Chat\Messages\UserMessage;
use Digihood\Digidocs\Tools\CodeDiffTool;
use Digihood\Digidocs\Tools\AstCompareTool;
use Digihood\Digidocs\Services\DocumentationAnalyzer;
use Digihood\Digidocs\Services\CostTracker;
use Exception;

class SmartChangeAnalysisAgent extends Agent
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
        $language = config('digidocs.languages.default', 'cs-CZ');
        $promptKey = str_replace('-', '_', strtolower($language));
        $promptData = config("digidocs.prompts.change_analysis_agent.system.{$promptKey}");
        
        if (!$promptData) {
            $promptData = [
                'background' => ['You are an expert in code change analysis'],
                'steps' => ['Compare old and new code', 'Evaluate significance'],
                'output' => ['Return boolean decision on regeneration']
            ];
        }
        
        return new SystemPrompt(
            background: $promptData['background'],
            steps: $promptData['steps'],
            output: $promptData['output']
        );
    }

    protected function tools(): array
    {
        return [
            CodeDiffTool::make(),
            AstCompareTool::make(),
        ];
    }

    /**
     * Inteligentní rozhodování o regeneraci dokumentace pomocí AI
     */
    public function shouldRegenerateDocumentation(
        string $filePath,
        string $oldContent,
        string $newContent,
        ?array $existingDocumentation = null
    ): array {
        try {
            // Nastaví aktuální soubor pro cost tracking
            if ($this->costTracker) {
                $this->costTracker->setCurrentFile($filePath);
            }

            $prompt = $this->buildAnalysisPrompt($filePath, $oldContent, $newContent, $existingDocumentation);
            
            $response = $this->chat(new UserMessage($prompt));
            $content = $response->getContent();

            // Pokus se parsovat JSON odpověď
            $result = $this->parseAiResponse($content);

            // Resetuj aktuální soubor
            if ($this->costTracker) {
                $this->costTracker->setCurrentFile(null);
            }

            return $result;

        } catch (Exception $e) {
            \Log::error("SmartChangeAnalysisAgent error for {$filePath}: " . $e->getMessage());
            
            // Fallback - při chybě raději generuj
            return [
                'should_regenerate' => true,
                'confidence' => 0.5,
                'reason' => 'ai_analysis_failed',
                'reasoning' => ['AI analýza selhala - pro jistotu regeneruji dokumentaci'],
                'impact_level' => 'unknown',
                'affected_areas' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Vytvoří prompt pro AI analýzu
     */
    private function buildAnalysisPrompt(
        string $filePath,
        string $oldContent,
        string $newContent,
        ?array $existingDocumentation
    ): string {
        $variables = [
            'filePath' => $filePath,
            'oldContent' => $oldContent ?: "(file didn't exist or was empty)",
            'newContent' => $newContent,
            'existingDocumentationPath' => $existingDocumentation['path'] ?? 'unknown',
            'existingDocumentationSize' => $existingDocumentation['size'] ?? 0,
            'existingDocumentationPreview' => isset($existingDocumentation['content']) 
                ? substr($existingDocumentation['content'], 0, 500) 
                : '(no existing documentation found)'
        ];

        return "Analyze changes in file: {$filePath}

OLD CONTENT:
{$oldContent}

NEW CONTENT:
{$newContent}

Existing documentation: " . ($existingDocumentation['content'] ?? 'none') . "

Determine if documentation needs regeneration based on change significance.";
    }

    /**
     * Parsuje AI odpověď a vrátí strukturovaný výsledek
     */
    private function parseAiResponse(string $content): array
    {
        // Pokus se najít JSON v odpovědi
        $content = trim($content);
        
        // Odstraň markdown kód bloky
        $content = preg_replace('/```json\s*/', '', $content);
        $content = preg_replace('/```\s*$/', '', $content);
        $content = trim($content);
        
        $decoded = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from AI: " . json_last_error_msg() . ". Content: " . substr($content, 0, 200));
        }
        
        // Validuj required fields
        $required = ['should_regenerate', 'confidence', 'reason'];
        foreach ($required as $field) {
            if (!isset($decoded[$field])) {
                throw new Exception("Missing required field '{$field}' in AI response");
            }
        }
        
        // Nastav defaults pro optional fields
        $decoded['reasoning'] = $decoded['reasoning'] ?? [$decoded['reason']];
        $decoded['impact_level'] = $decoded['impact_level'] ?? 'unknown';
        $decoded['affected_areas'] = $decoded['affected_areas'] ?? [];
        
        return $decoded;
    }

    /**
     * Hlavní metoda kompatibilní se stávajícím kódem
     */
    public function generateDocumentationIfNeeded(string $filePath): ?string
    {
        try {
            \Log::info("SmartChangeAnalysisAgent: Processing {$filePath}");

            // Získej obsah souborů
            $oldContent = $this->getOldFileContent($filePath);
            $newContent = $this->getCurrentFileContent($filePath);

            if ($newContent === null) {
                \Log::warning("Could not read file content for {$filePath}");
                return null;
            }

            // Získej existující dokumentaci
            $existingDoc = $this->getDocumentationAnalyzer()->analyzeExistingDocumentation($filePath);

            // AI rozhodnutí
            $analysis = $this->shouldRegenerateDocumentation($filePath, $oldContent, $newContent, $existingDoc);

            // Zaznamenej analýzu do databáze
            $this->recordAnalysis($filePath, $analysis);

            // Rozhodni na základě AI analýzy
            if (!$analysis['should_regenerate']) {
                \Log::info("Skipping documentation for {$filePath}: {$analysis['reason']}");
                return null;
            }

            \Log::info("Generating documentation for {$filePath}: {$analysis['reason']}");
            return $this->generateWithClassicAgent($filePath);

        } catch (Exception $e) {
            \Log::error("SmartChangeAnalysisAgent error for {$filePath}: " . $e->getMessage());
            
            // Fallback na klasický agent při chybě
            if (config('digidocs.intelligent_analysis.fallback_to_classic', true)) {
                \Log::info("Falling back to classic DocumentationAgent");
                return $this->generateWithClassicAgent($filePath);
            }

            return null;
        }
    }

    /**
     * Helper metody (převzaté z původního ChangeAnalysisAgent)
     */
    private function getCurrentFileContent(string $filePath): ?string
    {
        $fullPath = base_path($filePath);
        if (!file_exists($fullPath)) {
            return null;
        }
        return file_get_contents($fullPath);
    }

    private function getOldFileContent(string $filePath): string
    {
        try {
            $gitWatcher = app(\Digihood\Digidocs\Services\GitWatcherService::class);
            $currentCommitHashes = $gitWatcher->getCurrentCommitHashes();
            
            if (!empty($currentCommitHashes)) {
                $currentCommit = array_values($currentCommitHashes)[0];
                $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
                $command = "git show {$currentCommit}~1:\"{$filePath}\" 2>{$nullDevice}";
                $output = [];
                $returnCode = 0;

                exec($command, $output, $returnCode);

                if ($returnCode === 0 && !empty($output)) {
                    return implode("\n", $output);
                }
            }
        } catch (\Exception $e) {
            \Log::info("Git content retrieval failed for {$filePath}: " . $e->getMessage());
        }

        return ''; // Nový soubor
    }

    private function generateWithClassicAgent(string $filePath): string
    {
        $documentationAgent = new \Digihood\Digidocs\Agent\DocumentationAgent();
        
        if ($this->costTracker) {
            $documentationAgent->setCostTracker($this->costTracker);
        }

        return $documentationAgent->generateDocumentationForFile($filePath);
    }

    private function recordAnalysis(string $filePath, array $analysis): void
    {
        try {
            $memoryService = app(\Digihood\Digidocs\Services\MemoryService::class);
            $currentHash = hash_file('sha256', base_path($filePath));

            if (method_exists($memoryService, 'recordAnalysis')) {
                $memoryService->recordAnalysis($filePath, $currentHash, $analysis);
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to record analysis for {$filePath}: " . $e->getMessage());
        }
    }
}