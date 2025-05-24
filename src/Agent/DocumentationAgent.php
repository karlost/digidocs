<?php

namespace Digihood\Digidocs\Agent;

use NeuronAI\Agent;
use NeuronAI\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use Digihood\Digidocs\Tools\GitAnalyzerTool;
use Digihood\Digidocs\Tools\CodeAnalyzerTool;
use Digihood\Digidocs\Tools\FileHashTool;
use Digihood\Digidocs\Services\CostTracker;

class DocumentationAgent extends Agent
{
    private ?CostTracker $costTracker = null;

    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            key: config('digidocs.ai.api_key'),
            model: config('digidocs.ai.model', 'gpt-4'),
        );
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

    public function instructions(): string
    {
        return new SystemPrompt(
            background: [
                "You are an AI Agent specialized in generating high-quality PHP code documentation.",
                "You analyze Laravel PHP files and create comprehensive Markdown documentation.",
                "You understand Laravel conventions, design patterns, and best practices.",
                "You have access to tools for Git analysis, code parsing, and file tracking.",
                "Your goal is to create developer-friendly documentation that is clear, accurate, and useful."
            ],
            steps: [
                "Use the available tools to gather comprehensive information about the PHP file",
                "Analyze the code structure, including classes, methods, properties, and their relationships",
                "Extract and understand existing docblocks and comments",
                "Identify the file's purpose within the Laravel application context",
                "Consider Git history and recent changes for context",
                "Generate structured Markdown documentation that follows consistent formatting"
            ],
            output: [
                "Create well-structured Markdown documentation with clear headings and sections",
                "Include a comprehensive overview of the file's purpose and functionality",
                "Document all public classes, methods, and properties with descriptions",
                "Provide parameter descriptions, return types, and usage examples where helpful",
                "Add Laravel-specific context (Controller actions, Model relationships, etc.)",
                "Use professional language suitable for developers",
                "Format code examples using proper Markdown code blocks",
                "Include relevant information about dependencies and relationships",
                "Always use the tools to gather accurate information before generating documentation",
                "Focus on public APIs and interfaces that other developers will use",
                "Include examples for complex methods or Laravel-specific functionality",
                "Maintain consistent formatting and structure across all documentation",
                "Be concise but comprehensive - avoid unnecessary verbosity",
                "Use Czech language for documentation text, but keep code examples in English"
            ]
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

    /**
     * Vygeneruje dokumentaci pro konkrétní soubor
     */
    public function generateDocumentationForFile(string $filePath): string
    {
        // Nastaví aktuální soubor pro cost tracking
        if ($this->costTracker) {
            $this->costTracker->setCurrentFile($filePath);
        }

        $prompt = $this->buildPromptForFile($filePath);

        $response = $this->chat(
            new \NeuronAI\Chat\Messages\UserMessage($prompt)
        );

        // Resetuj aktuální soubor
        if ($this->costTracker) {
            $this->costTracker->setCurrentFile(null);
        }

        return $response->getContent();
    }

    /**
     * Vytvoří prompt pro analýzu konkrétního souboru
     */
    private function buildPromptForFile(string $filePath): string
    {
        return "Prosím analyzuj a vygeneruj kompletní Markdown dokumentaci pro PHP soubor: {$filePath}

Postupuj takto:
1. Použij nástroje pro analýzu souboru, Git historie a hash informací
2. Pochop účel a funkci souboru v kontextu Laravel aplikace
3. Vygeneruj strukturovanou dokumentaci v češtině s následujícími sekcemi:
   - Přehled souboru a jeho účelu
   - Popis tříd a jejich zodpovědností
   - Dokumentace veřejných metod s parametry a návratovými hodnotami
   - Příklady použití (kde je to vhodné)
   - Závislosti a vztahy s dalšími částmi aplikace

Dokumentace by měla být užitečná pro vývojáře, kteří budou tento kód používat nebo udržovat.";
    }

    /**
     * Vygeneruje souhrnnou dokumentaci pro více souborů
     */
    public function generateSummaryDocumentation(array $filePaths): string
    {
        $filesList = implode("\n- ", $filePaths);

        $prompt = "Prosím vygeneruj souhrnnou dokumentaci pro následující PHP soubory:
- {$filesList}

Použij dostupné nástroje pro analýzu všech souborů a vytvoř přehlednou dokumentaci, která:
1. Popíše účel a vztahy mezi soubory
2. Zdůrazní klíčové komponenty a jejich funkcionalitu
3. Poskytne přehled architektury a design patterns
4. Bude strukturovaná a snadno čitelná

Dokumentace by měla sloužit jako úvod do této části kódové základny.";

        $response = $this->chat(
            new \NeuronAI\Chat\Messages\UserMessage($prompt)
        );

        return $response->getContent();
    }
}
