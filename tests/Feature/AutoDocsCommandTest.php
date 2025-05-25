<?php

namespace Digihood\Digidocs\Tests\Feature;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Services\MemoryService;
use Digihood\Digidocs\Agent\DocumentationAgent;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

class AutoDocsCommandTest extends DigidocsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Vyčisti test adresář
        if (File::exists(base_path('docs/code'))) {
            File::deleteDirectory(base_path('docs/code'));
        }
    }

    #[Test]
    public function it_can_show_stats()
    {
        $this->artisan('digidocs:autodocs --stats')
            ->expectsOutput('📊 AutoDocs Statistics')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_can_run_cleanup()
    {
        $this->artisan('digidocs:autodocs --cleanup')
            ->expectsOutput('🧹 Cleaning up memory database...')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_can_run_dry_run()
    {
        // Vytvoř dummy PHP soubor
        $testFile = base_path('app/TestModel.php');
        File::put($testFile, '<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TestModel extends Model
{
    protected $fillable = [\'name\'];

    public function getData(): array
    {
        return [\'test\' => \'data\'];
    }
}');

        $this->artisan('digidocs:autodocs --dry-run')
            ->expectsOutput('🤖 AutoDocs AI Agent - Starting...')
            ->assertExitCode(0);

        // Vyčisti test soubor
        File::delete($testFile);
    }

    #[Test]
    public function memory_service_works()
    {
        $memory = app(MemoryService::class);

        // Test non-existent file
        $status = $memory->needsDocumentation('non-existent-file.php');
        $this->assertArrayHasKey('error', $status);
        $this->assertEquals('File not found', $status['error']);

        // Test stats
        $stats = $memory->getStats();
        $this->assertArrayHasKey('total_files', $stats);
        $this->assertArrayHasKey('recent_updates', $stats);
    }

    #[Test]
    public function documentation_agent_can_be_instantiated()
    {
        $agent = app(DocumentationAgent::class);
        $this->assertInstanceOf(DocumentationAgent::class, $agent);

        // Test že má správné tools
        $reflection = new \ReflectionClass($agent);
        $method = $reflection->getMethod('tools');
        $method->setAccessible(true);
        $tools = $method->invoke($agent);

        $this->assertIsArray($tools);
        $this->assertNotEmpty($tools);
    }

    protected function tearDown(): void
    {
        // Vyčisti test data
        if (File::exists(base_path('docs/code'))) {
            File::deleteDirectory(base_path('docs/code'));
        }

        parent::tearDown();
    }
}
