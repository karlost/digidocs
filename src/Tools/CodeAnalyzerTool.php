<?php

namespace Digihood\Digidocs\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Digihood\Digidocs\Analyzers\CodeAnalyzer;

class CodeAnalyzerTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'analyze_php_code',
            'Analyze PHP file structure, extract classes, methods, and existing documentation.'
        );

        $this->addProperty(
            new ToolProperty(
                name: 'file_path',
                type: 'string',
                description: 'Path to the PHP file to analyze',
                required: true
            )
        )->addProperty(
            new ToolProperty(
                name: 'include_context',
                type: 'boolean',
                description: 'Whether to include Laravel context (routes, models, etc.)',
                required: false
            )
        )->setCallable(new CodeAnalyzer());
    }

    public static function make(...$arguments): static
    {
        return new static();
    }
}
