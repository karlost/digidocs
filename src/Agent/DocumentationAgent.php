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
use Digihood\Digidocs\Schema\SimpleDocumentationSchema;

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
                "Generate structured documentation data that will be converted to Markdown",
                "Create a comprehensive title that describes the file's main purpose",
                "Provide a clear overview explaining what the file does and its role in the application",
                "Organize content into logical sections (Overview, Classes, Methods, Usage Examples, etc.)",
                "Document all public classes, methods, and properties with detailed descriptions",
                "Include parameter descriptions, return types, and usage examples for methods",
                "Add Laravel-specific context (Controller actions, Model relationships, middleware, etc.)",
                "Use professional Czech language for descriptions, but keep code examples in English",
                "Focus on public APIs and interfaces that other developers will use",
                "Include practical code examples for complex methods or Laravel-specific functionality",
                "Provide information about dependencies and relationships with other parts of the application",
                "Always use the available tools to gather accurate information before generating documentation",
                "Be comprehensive but concise - include all important information without unnecessary verbosity",
                "Structure the output to match the DocumentationSchema format for consistent results"
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

        // Použij regular chat s vylepšeným promptem
        $response = $this->chat(new UserMessage($prompt));
        $content = $response->getContent();

        // Validuj a oprav formát výstupu
        $content = $this->validateAndFixMarkdownOutput($content);

        // Resetuj aktuální soubor
        if ($this->costTracker) {
            $this->costTracker->setCurrentFile(null);
        }

        return $content;
    }

    /**
     * Vytvoří prompt pro analýzu konkrétního souboru
     */
    private function buildPromptForFile(string $filePath): string
    {
        return "Prosím analyzuj PHP soubor: {$filePath} a vygeneruj kompletní Markdown dokumentaci.

KRITICKÉ: Výstup musí být validní Markdown formát, ne JSON! Začni přímo nadpisem # a pokračuj strukturovanými sekcemi.

Postupuj takto:
1. Použij dostupné nástroje pro analýzu souboru, Git historie a kódové struktury
2. Pochop účel a funkci souboru v kontextu Laravel aplikace
3. Vygeneruj kompletní Markdown dokumentaci v tomto formátu:

# [Název souboru/komponenty]

[Stručný ale kompletní popis účelu a role souboru v aplikaci]

## Přehled tříd a zodpovědností

[Detailní popis všech tříd v souboru a jejich zodpovědností]

## Veřejné metody

[Pro každou veřejnou metodu zahrň:
- Název metody a její účel
- Parametry (typy, názvy, popisy)
- Návratové hodnoty
- Příklady volání s kódem]

## Vlastnosti a konstanty

[Popis veřejných vlastností a konstant]

## Laravel funkcionality

[Laravel-specifické funkcionality jako relationships, scopes, middleware, events, atd.]

## Příklady použití

[Praktické příklady použití s kódem v PHP]

## Závislosti a vztahy

[Závislosti a vztahy s dalšími částmi aplikace]

POŽADAVKY:
- Výstup musí být validní Markdown s nadpisy #, ##, ###
- Použij češtinu pro popisy, angličtinu pro kód
- Zaměř se na veřejné API pro vývojáře
- Každá sekce musí obsahovat užitečné informace
- Zahrň code bloky s ```php pro příklady";
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

    /**
     * Validuje a opravuje formát výstupu - zajistí, že je to Markdown, ne JSON
     */
    private function validateAndFixMarkdownOutput(string $content): string
    {
        $trimmedContent = trim($content);

        // Zkontroluj, zda výstup začína JSON objektem nebo polem
        if (str_starts_with($trimmedContent, '{') || str_starts_with($trimmedContent, '[')) {
            \Log::warning("DocumentationAgent: Detected JSON output instead of Markdown, attempting to fix");

            try {
                $jsonData = json_decode($trimmedContent, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    // Pokud je to validní JSON, pokus se převést na Markdown
                    return $this->convertJsonToMarkdown($jsonData);
                }
            } catch (\Exception $e) {
                \Log::error("DocumentationAgent: Failed to parse JSON: " . $e->getMessage());
            }
        }

        // Zkontroluj, zda výstup obsahuje základní Markdown strukturu
        if (!str_contains($content, '#') && !str_contains($content, '##')) {
            \Log::warning("DocumentationAgent: Output doesn't contain Markdown headers, might be malformed");
        }

        return $content;
    }

    /**
     * Převede JSON strukturu na Markdown
     */
    private function convertJsonToMarkdown(array $data): string
    {
        $markdown = '';

        // Název dokumentu
        if (isset($data['name'])) {
            $markdown .= "# " . $data['name'] . "\n\n";
        }

        // Popis
        if (isset($data['description'])) {
            $markdown .= $data['description'] . "\n\n";
        }

        // Sekce
        if (isset($data['sections']) && is_array($data['sections'])) {
            foreach ($data['sections'] as $section) {
                if (isset($section['title'])) {
                    $markdown .= "## " . $section['title'] . "\n\n";
                }

                if (isset($section['content'])) {
                    $markdown .= $section['content'] . "\n\n";
                }

                // Metody
                if (isset($section['methods']) && is_array($section['methods'])) {
                    foreach ($section['methods'] as $method) {
                        if (isset($method['název'])) {
                            $markdown .= "### " . $method['název'] . "()\n\n";
                        }
                        if (isset($method['popis'])) {
                            $markdown .= $method['popis'] . "\n\n";
                        }
                        if (isset($method['parametry'])) {
                            $markdown .= "**Parametry:** " . $method['parametry'] . "\n\n";
                        }
                        if (isset($method['návratová_hodnota'])) {
                            $markdown .= "**Návratová hodnota:** " . $method['návratová_hodnota'] . "\n\n";
                        }
                    }
                }
            }
        }

        // Poznámka
        if (isset($data['poznámka'])) {
            $markdown .= "## Poznámka\n\n" . $data['poznámka'] . "\n\n";
        }

        return $markdown;
    }
}
