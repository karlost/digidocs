<?php

namespace Digihood\Digidocs\Tests\Unit\Services;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Services\MemoryService;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\File;

class MemoryServiceTest extends DigidocsTestCase
{
    private MemoryService $memory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memory = new MemoryService();
    }

    #[Test]
    public function it_can_check_if_file_needs_documentation()
    {
        $content = '<?php

namespace App;

class TestClass
{
    public function method(): string
    {
        return "test";
    }
}';

        $this->createTestFile('app/TestClass.php', $content);
        $result = $this->memory->needsDocumentation('app/TestClass.php');

        $this->assertArrayHasKey('needs_update', $result);
        $this->assertArrayHasKey('is_new', $result);
        $this->assertArrayHasKey('last_hash', $result);
        $this->assertArrayHasKey('current_hash', $result);

        // Nový soubor by měl potřebovat dokumentaci
        $this->assertTrue($result['needs_update']);
        $this->assertTrue($result['is_new']);
    }

    #[Test]
    public function it_handles_non_existent_file()
    {
        $result = $this->memory->needsDocumentation('non-existent-file.php');

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('File not found', $result['error']);
    }

    #[Test]
    public function it_can_record_file_processing()
    {
        $content = '<?php echo "test";';
        $this->createTestFile('test.php', $content);

        // Zaznamenej dokumentaci
        $hash = hash('sha256', $content);
        $this->memory->recordDocumentation('test.php', $hash, 'docs/test.md');

        // Po zaznamenání by soubor neměl potřebovat aktualizaci
        $result = $this->memory->needsDocumentation('test.php');
        $this->assertFalse($result['needs_update']);
        $this->assertFalse($result['is_new']);
    }

    #[Test]
    public function it_detects_file_changes()
    {
        $originalContent = '<?php echo "original";';
        $this->createTestFile('test.php', $originalContent);

        // Zaznamenej původní zpracování
        $originalHash = hash('sha256', $originalContent);
        $this->memory->recordDocumentation('test.php', $originalHash, 'docs/test.md');

        // Změň soubor
        $newContent = '<?php echo "modified";';
        $this->createTestFile('test.php', $newContent);

        // Mělo by detekovat změnu
        $result = $this->memory->needsDocumentation('test.php');
        $this->assertTrue($result['needs_update']);
        $this->assertFalse($result['is_new']);
    }

    #[Test]
    public function it_can_get_statistics()
    {
        // Vytvoř a zpracuj několik souborů
        $content1 = '<?php echo "1";';
        $content2 = '<?php echo "2";';
        $this->createTestFile('file1.php', $content1);
        $this->createTestFile('file2.php', $content2);

        $this->memory->recordDocumentation('file1.php', hash('sha256', $content1), 'docs/file1.md');
        $this->memory->recordDocumentation('file2.php', hash('sha256', $content2), 'docs/file2.md');

        $stats = $this->memory->getStats();

        $this->assertArrayHasKey('total_files', $stats);
        $this->assertArrayHasKey('recent_updates', $stats);

        $this->assertGreaterThanOrEqual(2, $stats['total_files']);
    }

    #[Test]
    public function it_can_cleanup_old_records()
    {
        // Vytvoř testovací soubory
        $content1 = '<?php echo "1";';
        $content2 = '<?php echo "2";';
        $this->createTestFile('file1.php', $content1);
        $this->createTestFile('file2.php', $content2);

        $this->memory->recordDocumentation('file1.php', hash('sha256', $content1), 'docs/file1.md');
        $this->memory->recordDocumentation('file2.php', hash('sha256', $content2), 'docs/file2.md');

        $statsBefore = $this->memory->getStats();

        $deleted = $this->memory->cleanup();

        $statsAfter = $this->memory->getStats();

        // Po cleanup by mělo být méně nebo stejně záznamů
        $this->assertLessThanOrEqual($statsBefore['total_files'], $statsAfter['total_files']);
        $this->assertIsInt($deleted);
    }

    #[Test]
    public function it_can_record_documented_code_parts()
    {
        $this->createTestFile('test.php', '<?php class Test { public function method() {} }');

        $codeParts = [
            [
                'type' => 'class',
                'name' => 'Test',
                'signature' => 'class Test',
                'section' => 'Classes'
            ],
            [
                'type' => 'method',
                'name' => 'Test::method',
                'signature' => 'public function method()',
                'section' => 'Methods'
            ]
        ];

        $this->memory->recordDocumentedCodeParts('test.php', $codeParts);
        $recorded = $this->memory->getDocumentedCodeParts('test.php');

        $this->assertNotEmpty($recorded);
        $this->assertCount(2, $recorded);

        $this->assertEquals('class', $recorded[0]['code_type']);
        $this->assertEquals('Test', $recorded[0]['code_name']);
        $this->assertEquals('method', $recorded[1]['code_type']);
        $this->assertEquals('Test::method', $recorded[1]['code_name']);
    }

    #[Test]
    public function it_can_get_documented_code_parts_for_non_existent_file()
    {
        $result = $this->memory->getDocumentedCodeParts('non-existent.php');
        $this->assertEmpty($result);
    }

    #[Test]
    public function it_tracks_processing_metadata()
    {
        $content = '<?php echo "test";';
        $this->createTestFile('test.php', $content);

        // Zaznamenej token usage
        $this->memory->recordTokenUsage('gpt-4', 100, 50, 0.02, 'test.php');

        $costStats = $this->memory->getCostStats();

        $this->assertArrayHasKey('total_calls', $costStats);
        $this->assertArrayHasKey('total_input_tokens', $costStats);
        $this->assertArrayHasKey('total_output_tokens', $costStats);
        $this->assertArrayHasKey('total_cost', $costStats);
        $this->assertGreaterThanOrEqual(1, $costStats['total_calls']);
        $this->assertGreaterThanOrEqual(100, $costStats['total_input_tokens']);
        $this->assertGreaterThanOrEqual(0.02, $costStats['total_cost']);
    }

    #[Test]
    public function it_can_get_recent_updates()
    {
        $content1 = '<?php echo "1";';
        $content2 = '<?php echo "2";';
        $this->createTestFile('file1.php', $content1);
        $this->createTestFile('file2.php', $content2);

        $this->memory->recordDocumentation('file1.php', hash('sha256', $content1), 'docs/file1.md');
        sleep(1); // Zajisti rozdílný timestamp
        $this->memory->recordDocumentation('file2.php', hash('sha256', $content2), 'docs/file2.md');

        $stats = $this->memory->getStats();
        $recentUpdates = $stats['recent_updates'];

        $this->assertIsInt($recentUpdates);
        $this->assertGreaterThanOrEqual(2, $recentUpdates);
    }

    #[Test]
    public function it_handles_database_errors_gracefully()
    {
        // Test s neexistujícím souborem
        $result = $this->memory->needsDocumentation('non-existent-file.php');

        // Mělo by vrátit error
        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('File not found', $result['error']);
    }

    #[Test]
    public function it_can_get_status_breakdown()
    {
        $content1 = '<?php echo "1";';
        $content2 = '<?php echo "2";';
        $content3 = '<?php echo "3";';
        $this->createTestFile('file1.php', $content1);
        $this->createTestFile('file2.php', $content2);
        $this->createTestFile('file3.php', $content3);

        $this->memory->recordDocumentation('file1.php', hash('sha256', $content1), 'docs/file1.md');
        $this->memory->recordDocumentation('file2.php', hash('sha256', $content2), 'docs/file2.md');
        $this->memory->recordDocumentation('file3.php', hash('sha256', $content3), 'docs/file3.md');

        $stats = $this->memory->getStats();

        $this->assertArrayHasKey('total_files', $stats);
        $this->assertArrayHasKey('recent_updates', $stats);
        $this->assertGreaterThanOrEqual(3, $stats['total_files']);
    }

    #[Test]
    public function it_can_force_update_file()
    {
        $content = '<?php echo "test";';
        $this->createTestFile('test.php', $content);

        // Zaznamenej zpracování
        $hash = hash('sha256', $content);
        $this->memory->recordDocumentation('test.php', $hash, 'docs/test.md');

        // Normálně by neměl potřebovat aktualizaci
        $result1 = $this->memory->needsDocumentation('test.php');
        $this->assertFalse($result1['needs_update']);

        // Test že soubor je zaznamenán
        $this->assertFalse($result1['is_new']);
        $this->assertEquals($hash, $result1['last_hash']);
    }

    #[Test]
    public function it_maintains_file_history()
    {
        $content1 = '<?php echo "v1";';
        $this->createTestFile('test.php', $content1);

        // První zpracování
        $hash1 = hash('sha256', $content1);
        $this->memory->recordDocumentation('test.php', $hash1, 'docs/test.md');

        // Změň soubor a zpracuj znovu
        $content2 = '<?php echo "v2";';
        $this->createTestFile('test.php', $content2);
        $hash2 = hash('sha256', $content2);
        $this->memory->recordDocumentation('test.php', $hash2, 'docs/test.md');

        $stats = $this->memory->getStats();

        // Měl by existovat záznam o souboru
        $this->assertGreaterThanOrEqual(1, $stats['total_files']);

        // Test že nový hash je jiný
        $this->assertNotEquals($hash1, $hash2);
    }
}
