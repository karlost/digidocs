<?php

namespace Digihood\Digidocs\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Digihood\Digidocs\Analyzers\AstComparer;

class AstCompareTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'compare_ast_structures',
            'Compare Abstract Syntax Trees of two PHP code versions to detect structural changes.'
        );

        $this->addProperty(
            new ToolProperty(
                name: 'old_content',
                type: 'string',
                description: 'Content of the old version of PHP code',
                required: true
            )
        )->addProperty(
            new ToolProperty(
                name: 'new_content',
                type: 'string',
                description: 'Content of the new version of PHP code',
                required: true
            )
        )->addProperty(
            new ToolProperty(
                name: 'file_path',
                type: 'string',
                description: 'Path to the file being compared (for context)',
                required: false
            )
        )->setCallable(new AstComparer());
    }

    public static function make(...$arguments): static
    {
        return new static();
    }
}

