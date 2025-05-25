<?php

namespace Digihood\Digidocs\Tests\Unit\Commands;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Commands\WatchCommand;
use Digihood\Digidocs\Services\MemoryService;
use Digihood\Digidocs\Agent\DocumentationAgent;
use Digihood\Digidocs\Agent\ChangeAnalysisAgent;
use Digihood\Digidocs\Services\GitWatcherService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

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

    #[Test]
    public function it_normalizes_windows_paths_correctly()
    {
        // Vytvoř testovací soubory
        $this->createTestFile('app/Models/User.php', '<?php class User {}');
        $this->createTestFile('app/Controllers/UserController.php', '<?php class UserController {}');
        $this->createTestFile('app/Services/UserService.php', '<?php class UserService {}');
        $this->createTestFile('routes/web.php', '<?php // routes');
        $this->createTestFile('app/Models/Post.php', '<?php class Post {}');
        $this->createTestFile('config/app.php', '<?php return [];');

        // Vytvoř mock objekty
        $memory = $this->createMock(MemoryService::class);
        $documentationAgent = $this->createMock(DocumentationAgent::class);
        $changeAnalysisAgent = $this->createMock(ChangeAnalysisAgent::class);
        $gitWatcher = $this->createMock(GitWatcherService::class);

        // Vytvoř WatchCommand instanci
        $command = new WatchCommand($memory, $documentationAgent, $changeAnalysisAgent, $gitWatcher);

        // Použij reflection pro přístup k private metodě
        $reflection = new ReflectionClass($command);
        $filterMethod = $reflection->getMethod('filterChangedFiles');
        $filterMethod->setAccessible(true);

        // Testovací data s Windows path separátory
        $files = [
            'app\\Models\\User.php',
            'app\\Controllers\\UserController.php',
            'app/Services/UserService.php',  // Mixed separators
            'routes\\web.php',
            'app\\Models\\Post.php',
            'config\\app.php',  // Should be excluded (not in watch paths)
        ];

        $watchPaths = ['app/', 'routes/'];

        // Spusť filtrování
        $result = $filterMethod->invoke($command, $files, $watchPaths);

        // Ověř výsledky
        $this->assertCount(5, $result, 'Should filter 5 files from watch paths');

        // Ověř že všechny cesty jsou normalizované (forward slashes)
        foreach ($result as $path) {
            $this->assertStringNotContainsString('\\', $path, 'Path should not contain backslashes: ' . $path);
            $this->assertStringContainsString('/', $path, 'Path should contain forward slashes: ' . $path);
        }

        // Ověř konkrétní soubory
        $this->assertContains('app/Models/User.php', $result);
        $this->assertContains('app/Controllers/UserController.php', $result);
        $this->assertContains('app/Services/UserService.php', $result);
        $this->assertContains('routes/web.php', $result);
        $this->assertContains('app/Models/Post.php', $result);

        // Ověř že config/app.php není zahrnut (není v watch paths)
        $this->assertNotContains('config/app.php', $result);
    }

    #[Test]
    public function it_handles_mixed_path_separators()
    {
        // Vytvoř testovací soubory
        $this->createTestFile('app/Models/User.php', '<?php class User {}');
        $this->createTestFile('app/Controllers/UserController.php', '<?php class UserController {}');

        // Vytvoř mock objekty
        $memory = $this->createMock(MemoryService::class);
        $documentationAgent = $this->createMock(DocumentationAgent::class);
        $changeAnalysisAgent = $this->createMock(ChangeAnalysisAgent::class);
        $gitWatcher = $this->createMock(GitWatcherService::class);

        $command = new WatchCommand($memory, $documentationAgent, $changeAnalysisAgent, $gitWatcher);

        // Použij reflection
        $reflection = new ReflectionClass($command);
        $filterMethod = $reflection->getMethod('filterChangedFiles');
        $filterMethod->setAccessible(true);

        // Testovací data s mixed separátory
        $files = [
            'app\\Models/User.php',  // Mixed: backslash then forward slash
            'app/Controllers\\UserController.php',  // Mixed: forward slash then backslash
        ];

        $watchPaths = ['app\\', 'routes/'];  // Mixed watch paths

        $result = $filterMethod->invoke($command, $files, $watchPaths);

        // Ověř že oba soubory jsou správně zpracované
        $this->assertCount(2, $result);
        $this->assertContains('app/Models/User.php', $result);
        $this->assertContains('app/Controllers/UserController.php', $result);
    }

}
