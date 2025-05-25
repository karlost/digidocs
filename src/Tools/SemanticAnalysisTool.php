<?php

namespace Digihood\Digidocs\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Digihood\Digidocs\Analyzers\SemanticAnalyzer;

class SemanticAnalysisTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'analyze_semantic_changes',
            'Analyze semantic significance of code changes to determine if documentation update is needed.'
        );

        $this->addProperty(
            new ToolProperty(
                name: 'diff_analysis',
                type: 'string',
                description: 'JSON string with output from CodeDiffTool analysis',
                required: true
            )
        )->addProperty(
            new ToolProperty(
                name: 'ast_analysis',
                type: 'string',
                description: 'JSON string with output from AstCompareTool analysis',
                required: true
            )
        )->addProperty(
            new ToolProperty(
                name: 'file_path',
                type: 'string',
                description: 'Path to the file being analyzed',
                required: false
            )
        )->setCallable(new SemanticAnalyzer());
    }

    public static function make(...$arguments): static
    {
        return new static();
    }
}
