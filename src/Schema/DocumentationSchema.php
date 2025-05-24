<?php

namespace Digihood\Digidocs\Schema;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Schema pro strukturovaný výstup dokumentace z AI agenta
 */
class DocumentationSchema
{
    #[SchemaProperty(description: 'Název souboru nebo hlavní komponenty')]
    public string $title;

    #[SchemaProperty(description: 'Stručný popis účelu a funkce souboru')]
    public string $overview;

    #[SchemaProperty(description: 'Seznam sekcí dokumentace - každá sekce má title (string) a content (string), volitelně methods, properties, code_examples (arrays)')]
    public array $sections;

    /**
     * Převede strukturovaný výstup na Markdown
     */
    public function toMarkdown(): string
    {
        $markdown = "# {$this->title}\n\n";
        $markdown .= "{$this->overview}\n\n";

        foreach ($this->sections as $section) {
            if (isset($section['title'])) {
                $markdown .= "## {$section['title']}\n\n";
            }

            if (isset($section['content'])) {
                $markdown .= "{$section['content']}\n\n";
            }

            // Zpracuj metody pokud existují
            if (isset($section['methods']) && is_array($section['methods'])) {
                foreach ($section['methods'] as $method) {
                    if (isset($method['name'])) {
                        $markdown .= "### {$method['name']}()\n\n";
                    }
                    if (isset($method['description'])) {
                        $markdown .= "{$method['description']}\n\n";
                    }
                    if (isset($method['parameters'])) {
                        $markdown .= "**Parametry:** {$method['parameters']}\n\n";
                    }
                    if (isset($method['returns'])) {
                        $markdown .= "**Návratová hodnota:** {$method['returns']}\n\n";
                    }
                    if (isset($method['example'])) {
                        $markdown .= "**Příklad použití:**\n```php\n{$method['example']}\n```\n\n";
                    }
                }
            }

            // Zpracuj vlastnosti pokud existují
            if (isset($section['properties']) && is_array($section['properties'])) {
                foreach ($section['properties'] as $property) {
                    if (isset($property['name'])) {
                        $markdown .= "### \${$property['name']}\n\n";
                    }
                    if (isset($property['description'])) {
                        $markdown .= "{$property['description']}\n\n";
                    }
                    if (isset($property['type'])) {
                        $markdown .= "**Typ:** {$property['type']}\n\n";
                    }
                }
            }

            // Zpracuj příklady kódu pokud existují
            if (isset($section['code_examples']) && is_array($section['code_examples'])) {
                foreach ($section['code_examples'] as $example) {
                    if (isset($example['title'])) {
                        $markdown .= "### {$example['title']}\n\n";
                    }
                    if (isset($example['description'])) {
                        $markdown .= "{$example['description']}\n\n";
                    }
                    if (isset($example['code'])) {
                        $markdown .= "```php\n{$example['code']}\n```\n\n";
                    }
                }
            }
        }

        return $markdown;
    }
}

/**
 * Schema pro sekci dokumentace
 */
class DocumentationSection
{
    #[SchemaProperty(description: 'Název sekce')]
    public string $title;

    #[SchemaProperty(description: 'Obsah sekce v Markdown formátu')]
    public string $content;

    #[SchemaProperty(description: 'Seznam metod v této sekci (volitelné)')]
    public ?array $methods = null;

    #[SchemaProperty(description: 'Seznam vlastností v této sekci (volitelné)')]
    public ?array $properties = null;

    #[SchemaProperty(description: 'Příklady kódu pro tuto sekci (volitelné)')]
    public ?array $code_examples = null;
}

/**
 * Schema pro metodu
 */
class MethodSchema
{
    #[SchemaProperty(description: 'Název metody')]
    public string $name;

    #[SchemaProperty(description: 'Popis funkce metody')]
    public string $description;

    #[SchemaProperty(description: 'Popis parametrů metody')]
    public ?string $parameters = null;

    #[SchemaProperty(description: 'Popis návratové hodnoty')]
    public ?string $returns = null;

    #[SchemaProperty(description: 'Příklad použití metody')]
    public ?string $example = null;

    #[SchemaProperty(description: 'Viditelnost metody (public, private, protected)')]
    public ?string $visibility = 'public';
}

/**
 * Schema pro vlastnost
 */
class PropertySchema
{
    #[SchemaProperty(description: 'Název vlastnosti')]
    public string $name;

    #[SchemaProperty(description: 'Popis vlastnosti')]
    public string $description;

    #[SchemaProperty(description: 'Typ vlastnosti')]
    public ?string $type = null;

    #[SchemaProperty(description: 'Viditelnost vlastnosti (public, private, protected)')]
    public ?string $visibility = 'public';

    #[SchemaProperty(description: 'Výchozí hodnota vlastnosti')]
    public ?string $default_value = null;
}

/**
 * Schema pro příklad kódu
 */
class CodeExampleSchema
{
    #[SchemaProperty(description: 'Název příkladu')]
    public string $title;

    #[SchemaProperty(description: 'Popis příkladu')]
    public ?string $description = null;

    #[SchemaProperty(description: 'Kód příkladu')]
    public string $code;

    #[SchemaProperty(description: 'Výstup nebo výsledek příkladu')]
    public ?string $output = null;
}
