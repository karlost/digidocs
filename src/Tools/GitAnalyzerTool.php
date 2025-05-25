<?php

namespace Digihood\Digidocs\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Digihood\Digidocs\Analyzers\GitAnalyzer;

class GitAnalyzerTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'analyze_git_changes',
            'Analyze Git repository changes to understand what files have been modified.'
        );

        $this->addProperty(
            new ToolProperty(
                name: 'since_commit',
                type: 'string',
                description: 'Git commit hash to compare changes from (optional)',
                required: false
            )
        )->addProperty(
            new ToolProperty(
                name: 'file_path',
                type: 'string',
                description: 'Specific file path to analyze (optional)',
                required: false
            )
        )->setCallable(new GitAnalyzer());
    }

    public static function make(...$arguments): static
    {
        return new static();
    }
}