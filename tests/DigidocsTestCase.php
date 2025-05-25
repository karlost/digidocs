<?php

namespace Digihood\Digidocs\Tests;

use Digihood\Digidocs\DigidocsServiceProvider;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config;
use Digihood\Digidocs\Services\MemoryService;
use ReflectionClass;

abstract class DigidocsTestCase extends TestCase
{
    protected string $testDataPath;
    protected string $testDocsPath;
    protected string $testDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDataPath = base_path('tests/data');
        $this->testDocsPath = base_path('docs/test');

        // Vytvoř unikátní databázi pro každý test
        $this->testDbPath = storage_path('digidocs_test_' . uniqid() . '.sqlite');
        config(['digidocs.memory.database_path' => $this->testDbPath]);

        // Vyčisti cache služeb
        $this->app->forgetInstance(MemoryService::class);

        // Vytvoř testovací adresáře
        File::makeDirectory($this->testDataPath, 0755, true, true);
        File::makeDirectory($this->testDocsPath, 0755, true, true);

        // Nastavení testovací konfigurace
        Config::set('digidocs.ai.api_key', 'test-api-key');
        Config::set('digidocs.ai.model', 'gpt-4');
        Config::set('digidocs.paths.docs', $this->testDocsPath);
        Config::set('digidocs.paths.watch', [$this->testDataPath]);
    }

    protected function tearDown(): void
    {
        // Smaž testovací databázi
        if (isset($this->testDbPath) && file_exists($this->testDbPath)) {
            unlink($this->testDbPath);
        }

        // Vyčisti testovací adresáře
        if (File::exists($this->testDataPath)) {
            File::deleteDirectory($this->testDataPath);
        }
        if (File::exists($this->testDocsPath)) {
            File::deleteDirectory($this->testDocsPath);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            DigidocsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Nastavení testovacího prostředí
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Digidocs konfigurace
        $app['config']->set('digidocs.ai.api_key', 'test-api-key');
        $app['config']->set('digidocs.ai.model', 'gpt-4');
        $app['config']->set('digidocs.paths.docs', base_path('docs/test'));
        $app['config']->set('digidocs.paths.watch', [base_path('tests/data')]);
        $app['config']->set('digidocs.memory.database_path', ':memory:');
    }

    /**
     * Vytvoří testovací PHP soubor s daným obsahem
     */
    protected function createTestFile(string $relativePath, string $content): string
    {
        $fullPath = base_path($relativePath);
        File::makeDirectory(dirname($fullPath), 0755, true, true);
        File::put($fullPath, $content);
        return $fullPath;
    }

    /**
     * Vytvoří testovací PHP třídu
     */
    protected function createTestClass(string $className, array $methods = [], string $namespace = 'App\\Test'): string
    {
        $methodsCode = '';
        foreach ($methods as $method) {
            $visibility = $method['visibility'] ?? 'public';
            $name = $method['name'];
            $params = $method['params'] ?? '';
            $returnType = $method['return'] ?? 'void';
            $body = $method['body'] ?? '// method body';

            $methodsCode .= "\n    {$visibility} function {$name}({$params}): {$returnType}\n    {\n        {$body}\n    }\n";
        }

        $content = "<?php\n\nnamespace {$namespace};\n\nclass {$className}\n{{$methodsCode}}\n";

        $path = "tests/data/{$className}.php";
        return $this->createTestFile($path, $content);
    }

    /**
     * Vytvoří testovací dokumentaci
     */
    protected function createTestDocumentation(string $relativePath, string $content): string
    {
        $fullPath = $this->testDocsPath . '/' . $relativePath;
        File::makeDirectory(dirname($fullPath), 0755, true, true);
        File::put($fullPath, $content);
        return $fullPath;
    }

    /**
     * Vyčisti testovací databázi
     */
    protected function clearTestDatabase(MemoryService $memory): void
    {
        $reflection = new ReflectionClass($memory);
        $dbProperty = $reflection->getProperty('db');
        $dbProperty->setAccessible(true);
        $db = $dbProperty->getValue($memory);

        $db->exec("DELETE FROM documented_files");
        $db->exec("DELETE FROM token_usage");
        $db->exec("DELETE FROM change_analysis");
        $db->exec("DELETE FROM documented_code_parts");
        $db->exec("DELETE FROM git_commits");
    }
}
