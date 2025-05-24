<?php

namespace Digihood\Digidocs\Schema;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Jednoduchá schema pro strukturovaný výstup dokumentace
 */
class SimpleDocumentationSchema
{
    #[SchemaProperty(description: 'Název souboru nebo hlavní komponenty')]
    public string $title;

    #[SchemaProperty(description: 'Stručný popis účelu a funkce souboru')]
    public string $overview;

    #[SchemaProperty(description: 'Detailní popis tříd a jejich zodpovědností')]
    public string $classes_description;

    #[SchemaProperty(description: 'Dokumentace veřejných metod s parametry a návratovými hodnotami')]
    public string $methods_documentation;

    #[SchemaProperty(description: 'Popis veřejných vlastností a konstant')]
    public string $properties_description;

    #[SchemaProperty(description: 'Laravel-specifické funkcionality (relationships, scopes, middleware, atd.)')]
    public string $laravel_features;

    #[SchemaProperty(description: 'Praktické příklady použití s kódem')]
    public string $usage_examples;

    #[SchemaProperty(description: 'Závislosti a vztahy s dalšími částmi aplikace')]
    public string $dependencies;

    /**
     * Převede strukturovaný výstup na Markdown
     */
    public function toMarkdown(): string
    {
        $markdown = "# {$this->title}\n\n";
        $markdown .= "{$this->overview}\n\n";

        if (!empty($this->classes_description)) {
            $markdown .= "## Třídy a jejich zodpovědnosti\n\n";
            $markdown .= "{$this->classes_description}\n\n";
        }

        if (!empty($this->methods_documentation)) {
            $markdown .= "## Veřejné metody\n\n";
            $markdown .= "{$this->methods_documentation}\n\n";
        }

        if (!empty($this->properties_description)) {
            $markdown .= "## Vlastnosti a konstanty\n\n";
            $markdown .= "{$this->properties_description}\n\n";
        }

        if (!empty($this->laravel_features)) {
            $markdown .= "## Laravel funkcionality\n\n";
            $markdown .= "{$this->laravel_features}\n\n";
        }

        if (!empty($this->usage_examples)) {
            $markdown .= "## Příklady použití\n\n";
            $markdown .= "{$this->usage_examples}\n\n";
        }

        if (!empty($this->dependencies)) {
            $markdown .= "## Závislosti a vztahy\n\n";
            $markdown .= "{$this->dependencies}\n\n";
        }

        return $markdown;
    }
}
