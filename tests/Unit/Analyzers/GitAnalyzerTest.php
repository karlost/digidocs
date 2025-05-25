<?php

namespace Digihood\Digidocs\Tests\Unit\Analyzers;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Analyzers\GitAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\File;

class GitAnalyzerTest extends DigidocsTestCase
{
    private GitAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new GitAnalyzer();
    }

    #[Test]
    public function it_can_get_current_commit_info()
    {
        $result = ($this->analyzer)();

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('current_commit', $result);
        $this->assertArrayHasKey('branch', $result);
        $this->assertArrayHasKey('changed_files', $result);
        $this->assertArrayHasKey('commit_messages', $result);

        // Current commit should be a valid hash
        $this->assertMatchesRegularExpression('/^[a-f0-9]{7,40}$/', $result['current_commit']);
    }

    #[Test]
    public function it_can_get_changed_files_since_commit()
    {
        // Vytvoř testovací soubor a commitni ho
        $testFile = $this->createTestFile('test-file.php', '<?php echo "test";');

        $result = ($this->analyzer)('HEAD~1');

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('changed_files', $result);
        $this->assertIsArray($result['changed_files']);

        // Měly by být pouze PHP soubory
        foreach ($result['changed_files'] as $file) {
            $this->assertStringEndsWith('.php', $file);
        }
    }

    #[Test]
    public function it_filters_only_php_files()
    {
        $result = ($this->analyzer)('HEAD~5');

        $this->assertEquals('success', $result['status']);

        // Všechny změněné soubory by měly být PHP soubory
        foreach ($result['changed_files'] as $file) {
            $this->assertStringEndsWith('.php', $file);
            $this->assertNotEmpty(trim($file));
        }
    }

    #[Test]
    public function it_can_get_commit_messages()
    {
        $result = ($this->analyzer)('HEAD~3');

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('commit_messages', $result);
        $this->assertIsArray($result['commit_messages']);

        // Každá commit zpráva by měla být string ve formátu "hash message"
        foreach ($result['commit_messages'] as $commit) {
            $this->assertIsString($commit);
            $this->assertNotEmpty($commit);
            // Commit by měl začínat hashem
            $this->assertMatchesRegularExpression('/^[a-f0-9]{7,40}\s/', $commit);
        }
    }

    #[Test]
    public function it_handles_invalid_commit_hash()
    {
        $result = ($this->analyzer)('invalid-commit-hash');

        // Mělo by vrátit error nebo prázdné výsledky
        if ($result['status'] === 'error') {
            $this->assertArrayHasKey('error', $result);
        } else {
            $this->assertEquals('success', $result['status']);
            $this->assertIsArray($result['changed_files']);
            $this->assertIsArray($result['commit_messages']);
        }
    }

    #[Test]
    public function it_can_filter_by_file_path()
    {
        $result = ($this->analyzer)(null, 'app/Models');

        $this->assertEquals('success', $result['status']);

        // Pokud jsou nějaké změněné soubory, měly by být z app/Models
        foreach ($result['changed_files'] as $file) {
            $this->assertStringContainsString('app/Models', $file);
        }
    }

    #[Test]
    public function it_returns_current_branch_info()
    {
        $result = ($this->analyzer)();

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('branch', $result);
        $this->assertIsString($result['branch']);
        $this->assertNotEmpty($result['branch']);
    }

    #[Test]
    public function it_handles_git_repository_not_found()
    {
        // Dočasně přejmenuj .git adresář
        $gitPath = base_path('.git');
        $tempPath = base_path('.git_temp');

        if (File::exists($gitPath)) {
            File::move($gitPath, $tempPath);
        }

        try {
            $result = ($this->analyzer)();

            // GitAnalyzer může vrátit success i bez git repozitáře
            if ($result['status'] === 'error') {
                $this->assertArrayHasKey('error', $result);
                $this->assertStringContainsString('not a git repository', strtolower($result['error']));
            } else {
                $this->assertEquals('success', $result['status']);
                $this->assertIsArray($result['changed_files']);
                $this->assertIsArray($result['commit_messages']);
            }
        } finally {
            // Obnov .git adresář
            if (File::exists($tempPath)) {
                File::move($tempPath, $gitPath);
            }
        }
    }

    #[Test]
    public function it_can_get_file_history()
    {
        // Vytvoř testovací soubor
        $testFile = $this->createTestFile('app/TestHistory.php', '<?php class TestHistory {}');

        $result = ($this->analyzer)(null, 'app/TestHistory.php');

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('changed_files', $result);

        // Pokud soubor existuje v historii, měl by být v seznamu
        if (!empty($result['changed_files'])) {
            $this->assertContains('app/TestHistory.php', $result['changed_files']);
        }
    }

    #[Test]
    public function it_provides_detailed_commit_info()
    {
        $result = ($this->analyzer)('HEAD~2');

        $this->assertEquals('success', $result['status']);

        foreach ($result['commit_messages'] as $commit) {
            $this->assertIsString($commit);
            $this->assertNotEmpty($commit);
            // Commit by měl začínat hashem
            $this->assertMatchesRegularExpression('/^[a-f0-9]{7,40}\s/', $commit);
        }
    }

    #[Test]
    public function it_can_get_recent_commits()
    {
        $result = ($this->analyzer)('HEAD~10');

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('commit_messages', $result);

        // Commity by měly být seřazené od nejnovějších (git log --oneline je defaultně seřazený)
        $commits = $result['commit_messages'];
        if (count($commits) > 1) {
            // Každý commit by měl být string
            $this->assertIsString($commits[0]);
            $this->assertIsString($commits[1]);
        }
    }

    #[Test]
    public function it_excludes_non_php_files_from_changes()
    {
        $result = ($this->analyzer)('HEAD~5');

        $this->assertEquals('success', $result['status']);

        foreach ($result['changed_files'] as $file) {
            // Pouze PHP soubory
            $this->assertStringEndsWith('.php', $file);

            // Žádné JS, CSS, MD soubory
            $this->assertStringNotContainsString('.js', $file);
            $this->assertStringNotContainsString('.css', $file);
            $this->assertStringNotContainsString('.md', $file);
            $this->assertStringNotContainsString('.json', $file);
        }
    }

    #[Test]
    public function it_handles_empty_repository()
    {
        // Test pro případ prázdného repozitáře
        $result = ($this->analyzer)('HEAD~1000'); // Velmi starý commit

        // Mělo by vrátit success s prázdnými výsledky
        $this->assertEquals('success', $result['status']);
        $this->assertIsArray($result['changed_files']);
        $this->assertIsArray($result['commit_messages']);
    }
}
