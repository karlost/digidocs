<?php

namespace Digihood\Digidocs\Tests\Unit\Analyzers;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Analyzers\FileHasher;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\File;

class FileHasherTest extends DigidocsTestCase
{
    private FileHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new FileHasher();
    }

    #[Test]
    public function it_can_calculate_file_hash()
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

        $filePath = $this->createTestFile('app/TestClass.php', $content);
        $result = ($this->hasher)('app/TestClass.php');

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('app/TestClass.php', $result['file_path']);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('algorithm', $result);
        $this->assertArrayHasKey('file_info', $result);

        // Hash by měl být validní SHA256 (default)
        $this->assertEquals('sha256', $result['algorithm']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['hash']);

        // Velikost souboru
        $this->assertEquals(strlen($content), $result['file_info']['size']);
    }

    #[Test]
    public function it_handles_file_not_found()
    {
        $result = ($this->hasher)('non-existent-file.php');

        $this->assertEquals('error', $result['status']);
        $this->assertEquals('File not found', $result['error']);
        $this->assertEquals('non-existent-file.php', $result['file_path']);
    }

    #[Test]
    public function it_can_use_different_algorithms()
    {
        $content = '<?php echo "test";';
        $this->createTestFile('test.php', $content);

        // Test MD5
        $resultMd5 = ($this->hasher)('test.php', 'md5');
        $this->assertEquals('md5', $resultMd5['algorithm']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $resultMd5['hash']);

        // Test SHA1
        $resultSha1 = ($this->hasher)('test.php', 'sha1');
        $this->assertEquals('sha1', $resultSha1['algorithm']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $resultSha1['hash']);

        // Test SHA256
        $resultSha256 = ($this->hasher)('test.php', 'sha256');
        $this->assertEquals('sha256', $resultSha256['algorithm']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $resultSha256['hash']);
    }

    #[Test]
    public function it_detects_file_changes()
    {
        $originalContent = '<?php echo "original";';
        $this->createTestFile('test.php', $originalContent);

        $originalResult = ($this->hasher)('test.php');
        $originalHash = $originalResult['hash'];

        // Změň obsah souboru
        $newContent = '<?php echo "modified";';
        $this->createTestFile('test.php', $newContent);

        $newResult = ($this->hasher)('test.php');
        $newHash = $newResult['hash'];

        $this->assertNotEquals($originalHash, $newHash);
        $this->assertEquals('success', $originalResult['status']);
        $this->assertEquals('success', $newResult['status']);
    }

    #[Test]
    public function it_provides_consistent_hashes()
    {
        $content = '<?php echo "consistent test";';
        $this->createTestFile('test.php', $content);

        $result1 = ($this->hasher)('test.php');
        $result2 = ($this->hasher)('test.php');

        $this->assertEquals($result1['hash'], $result2['hash']);
        $this->assertEquals($result1['file_info']['size'], $result2['file_info']['size']);
    }

    #[Test]
    public function it_handles_empty_file()
    {
        $this->createTestFile('empty.php', '');
        $result = ($this->hasher)('empty.php');

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('hash', $result);
        $this->assertEquals(0, $result['file_info']['size']);

        // SHA256 prázdného souboru
        $this->assertEquals(hash('sha256', ''), $result['hash']);
    }

    #[Test]
    public function it_handles_large_file()
    {
        // Vytvoř větší soubor
        $content = '<?php' . PHP_EOL;
        $content .= str_repeat('// This is a comment line' . PHP_EOL, 1000);
        $content .= 'echo "large file";';

        $this->createTestFile('large.php', $content);
        $result = ($this->hasher)('large.php');

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('hash', $result);
        $this->assertEquals(strlen($content), $result['file_info']['size']);
        $this->assertGreaterThan(1000, $result['file_info']['size']);
    }

    #[Test]
    public function it_includes_file_metadata()
    {
        $content = '<?php echo "metadata test";';
        $filePath = $this->createTestFile('metadata.php', $content);

        $result = ($this->hasher)('metadata.php');

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('file_info', $result);
        $this->assertArrayHasKey('file_path', $result);

        // File info by měl obsahovat metadata
        $fileInfo = $result['file_info'];
        $this->assertArrayHasKey('modified_time', $fileInfo);
        $this->assertArrayHasKey('size', $fileInfo);
        $this->assertIsNumeric($fileInfo['modified_time']);
        $this->assertGreaterThan(0, $fileInfo['modified_time']);

        // File size by měl odpovídat skutečné velikosti
        $actualSize = filesize($filePath);
        $this->assertEquals($actualSize, $fileInfo['size']);
    }

    #[Test]
    public function it_handles_binary_content()
    {
        // Vytvoř soubor s binárním obsahem
        $binaryContent = "\x00\x01\x02\x03\xFF\xFE\xFD";
        $filePath = base_path('binary.test');
        File::put($filePath, $binaryContent);

        try {
            $result = ($this->hasher)('binary.test');

            $this->assertEquals('success', $result['status']);
            $this->assertArrayHasKey('hash', $result);
            $this->assertEquals(strlen($binaryContent), $result['file_info']['size']);
        } finally {
            File::delete($filePath);
        }
    }

    #[Test]
    public function it_handles_unicode_content()
    {
        $unicodeContent = '<?php
// Český text: příliš žluťoučký kůň úpěl ďábelské ódy
// Emoji: 🚀 🎉 💻
echo "Unicode test";';

        $this->createTestFile('unicode.php', $unicodeContent);
        $result = ($this->hasher)('unicode.php');

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('hash', $result);
        $this->assertEquals(strlen($unicodeContent), $result['file_info']['size']);
    }

    #[Test]
    public function it_handles_permission_denied()
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Permission test skipped on Windows');
        }

        $content = '<?php echo "permission test";';
        $filePath = $this->createTestFile('permission.php', $content);

        // Odeber read permission
        chmod($filePath, 0000);

        try {
            $result = ($this->hasher)('permission.php');

            $this->assertEquals('error', $result['status']);
            $this->assertArrayHasKey('error', $result);
        } finally {
            // Obnov permissions
            chmod($filePath, 0644);
        }
    }

    #[Test]
    public function it_can_compare_file_hashes()
    {
        $content1 = '<?php echo "file1";';
        $content2 = '<?php echo "file2";';
        $content3 = '<?php echo "file1";'; // Stejný jako content1

        $this->createTestFile('file1.php', $content1);
        $this->createTestFile('file2.php', $content2);
        $this->createTestFile('file3.php', $content3);

        $result1 = ($this->hasher)('file1.php');
        $result2 = ($this->hasher)('file2.php');
        $result3 = ($this->hasher)('file3.php');

        // file1 a file3 by měly mít stejný hash
        $this->assertEquals($result1['hash'], $result3['hash']);

        // file1 a file2 by měly mít různé hashe
        $this->assertNotEquals($result1['hash'], $result2['hash']);
    }

    #[Test]
    public function it_handles_unsupported_algorithm()
    {
        $this->createTestFile('test.php', '<?php echo "test";');

        $result = ($this->hasher)('test.php', 'unsupported_algorithm');

        $this->assertEquals('error', $result['status']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Unsupported hash algorithm', $result['error']);
    }
}
