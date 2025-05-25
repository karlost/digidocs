<?php

namespace Digihood\Digidocs\Tests\Unit\Commands;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Commands\WatchCommand;
use PHPUnit\Framework\Attributes\Test;

class WatchCommandTest extends DigidocsTestCase
{
    #[Test]
    public function it_can_be_instantiated()
    {
        $command = $this->app->make(WatchCommand::class);
        $this->assertInstanceOf(WatchCommand::class, $command);
    }

    #[Test]
    public function it_has_correct_signature()
    {
        $command = $this->app->make(WatchCommand::class);
        $this->assertEquals('digidocs:watch', $command->getName());
    }

    #[Test]
    public function it_has_correct_description()
    {
        $command = $this->app->make(WatchCommand::class);
        $this->assertEquals(
            'Watch for Git commits and automatically generate documentation for changed files',
            $command->getDescription()
        );
    }

    #[Test]
    public function it_has_interval_option()
    {
        $command = $this->app->make(WatchCommand::class);
        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('interval'));
        $this->assertEquals('5', $definition->getOption('interval')->getDefault());
        $this->assertEquals('Check interval in seconds', $definition->getOption('interval')->getDescription());
    }

    #[Test]
    public function it_has_path_option()
    {
        $command = $this->app->make(WatchCommand::class);
        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('path'));
        $pathOption = $definition->getOption('path');
        $this->assertTrue($pathOption->isArray());
        $this->assertEquals('Specific paths to watch', $pathOption->getDescription());
    }

    #[Test]
    public function it_has_signal_handling()
    {
        $command = $this->app->make(WatchCommand::class);
        $this->assertTrue(method_exists($command, 'handleSignal'));
    }

}
