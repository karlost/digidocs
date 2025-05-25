<?php

namespace Digihood\Digidocs\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Digihood\Digidocs\Analyzers\CodeDiffer;

class CodeDiffTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'analyze_code_diff',
            'Analyze differences between old and new code content to identify changes.'
        );

        $this->addProperty(
            new ToolProperty(
                name: 'file_path',
                type: 'string',
                description: 'Path to the file being analyzed',
                required: true
            )
        )->addProperty(
            new ToolProperty(
                name: 'old_content',
                type: 'string',
                description: 'Old content of the file',
                required: true
            )
        )->addProperty(
            new ToolProperty(
                name: 'new_content',
                type: 'string',
                description: 'New content of the file',
                required: true
            )
        )->setCallable(new CodeDiffer());
    }

    public static function make(...$arguments): static
    {
        return new static();
    }
}
