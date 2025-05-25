<?php

namespace Digihood\Digidocs\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Digihood\Digidocs\Analyzers\FileHasher;

class FileHashTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            'calculate_file_hash',
            'Calculate hash of files for change detection and tracking.'
        );

        $this->addProperty(
            new ToolProperty(
                name: 'file_path',
                type: 'string',
                description: 'Path to the file to calculate hash for',
                required: true
            )
        )->addProperty(
            new ToolProperty(
                name: 'algorithm',
                type: 'string',
                description: 'Hash algorithm to use (sha256, md5, etc.)',
                required: false
            )
        )->setCallable(new FileHasher());
    }

    public static function make(...$arguments): static
    {
        return new static();
    }
}

