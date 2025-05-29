<?php

namespace Digihood\Digidocs\Agent;

use NeuronAI\Agent;
use NeuronAI\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Chat\Messages\UserMessage;
use Digihood\Digidocs\Tools\GitAnalyzerTool;
use Digihood\Digidocs\Tools\CodeAnalyzerTool;
use Digihood\Digidocs\Tools\FileHashTool;
use Digihood\Digidocs\Services\CostTracker;
use Digihood\Digidocs\Services\CodeDocumentationMemory;

class DocumentationAgent extends Agent
{
    private ?CostTracker $costTracker = null;
    private ?CodeDocumentationMemory $memory = null;

    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            key: config('digidocs.ai.api_key'),
            model: config('digidocs.ai.model', 'gpt-4'),
        );
    }

    public function setCostTracker(CostTracker $costTracker): self
    {
        $this->costTracker = $costTracker;
        $this->observe($costTracker);
        return $this;
    }

    public function setMemory(CodeDocumentationMemory $memory): self
    {
        $this->memory = $memory;
        return $this;
    }

    public function instructions(): string
    {
        // All prompts are now in English
        $promptData = config('digidocs.prompts.documentation_agent.system');
        
        if (!$promptData) {
            $promptData = [
                'background' => ['You are a technical documentation expert'],
                'steps' => ['Analyze code structure', 'Generate clear documentation'],
                'output' => ['Create markdown documentation']
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
            GitAnalyzerTool::make(),
            CodeAnalyzerTool::make(),
            FileHashTool::make(),
        ];
    }

    public function generateDocumentationForFile(string $filePath): string
    {
        $language = config('digidocs.languages.default', 'cs-CZ');
        return $this->generateDocumentationForFileInLanguage($filePath, $language);
    }

    public function generateDocumentationForFileInAllLanguages(string $filePath): array
    {
        $results = [];
        $languages = config('digidocs.languages.enabled', ['cs-CZ']);

        foreach ($languages as $language) {
            try {
                $content = $this->generateDocumentationForFileInLanguage($filePath, $language);
                $results[$language] = [
                    'success' => true,
                    'content' => $content,
                    'language' => $language
                ];
            } catch (\Exception $e) {
                $results[$language] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'language' => $language
                ];
            }
        }

        return $results;
    }

    public function generateDocumentationForFileInLanguage(string $filePath, string $language): string
    {
        // Read file content
        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) {
            throw new \Exception("Cannot read file: {$filePath}");
        }

        $relatedDocs = [];
        if ($this->memory) {
            $relatedDocs = $this->memory->getRelatedCodeDocs($filePath, 5);
        }

        // Convert ISO language code to full language instruction
        $languageInstruction = $this->getLanguageInstruction($language);
        
        // Build comprehensive prompt
        $prompt = "Analyze this PHP Laravel file and generate comprehensive technical documentation.

FILE PATH: {$filePath}

FILE CONTENT:
{$fileContent}

Related documentation context: " . json_encode($relatedDocs) . "

IMPORTANT INSTRUCTIONS:
1. Generate documentation in {$languageInstruction}
2. Output MUST be in pure Markdown format (no JSON)
3. Start with a # heading
4. Include these sections:
   - General information/overview
   - Class/file structure
   - Properties/attributes
   - Methods/functions with parameters and return types
   - Usage examples
   - Best practices
5. Use proper markdown syntax throughout
6. Keep code examples but add comments in the target language
7. Include metadata (date, version) at the beginning

Generate the documentation now:";

        $response = $this->chat(new UserMessage($prompt));
        
        // Ensure we get clean markdown output
        $content = $response->getContent();
        
        // If the content starts with JSON, try to extract markdown from it
        if (str_starts_with(trim($content), '{')) {
            $decoded = json_decode($content, true);
            if ($decoded && isset($decoded['content'])) {
                $content = $decoded['content'];
            }
        }
        
        return $content;
    }
    
    /**
     * Convert ISO language code to instruction for AI
     * AI models understand ISO codes directly
     */
    private function getLanguageInstruction(string $languageCode): string
    {
        // Simply pass the ISO code to AI - it understands them directly
        return "language with ISO code {$languageCode}";
    }

    public function analyzeFileChanges(string $filePath, string $oldContent, string $newContent): array
    {
        $prompt = "Analyze changes in this PHP file and determine if documentation needs regeneration.

FILE: {$filePath}

OLD CONTENT:
{$oldContent}

NEW CONTENT:
{$newContent}

Analyze:
1. Are there structural changes (new/removed methods, properties)?
2. Are there semantic changes (logic modifications, parameter changes)?
3. Do changes affect API or public interface?
4. What is the severity of changes (minor, moderate, major)?

Determine if documentation needs to be regenerated based on the significance of changes.";

        $response = $this->chat(new UserMessage($prompt));
        
        return [
            'needs_regeneration' => true,
            'analysis' => $response->getContent(),
            'change_type' => 'modification'
        ];
    }
}