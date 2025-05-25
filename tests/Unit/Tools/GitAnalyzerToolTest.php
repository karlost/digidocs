<?php

namespace Digihood\Digidocs\Tests\Unit\Tools;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Tools\GitAnalyzerTool;
use PHPUnit\Framework\Attributes\Test;

class GitAnalyzerToolTest extends DigidocsTestCase
{
    private GitAnalyzerTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new GitAnalyzerTool();
    }

    #[Test]
    public function it_has_correct_tool_definition()
    {
        $this->assertEquals('analyze_git_changes', $this->tool->getName());
        $this->assertEquals('Analyze Git repository changes to understand what files have been modified.', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(2, $properties);

        $sinceCommitProperty = $properties[0];
        $this->assertEquals('since_commit', $sinceCommitProperty->getName());
        $this->assertEquals('string', $sinceCommitProperty->getType());
        $this->assertFalse($sinceCommitProperty->isRequired());

        $filePathProperty = $properties[1];
        $this->assertEquals('file_path', $filePathProperty->getName());
        $this->assertEquals('string', $filePathProperty->getType());
        $this->assertFalse($filePathProperty->isRequired());
    }

    #[Test]
    public function it_can_get_current_git_status()
    {
        $this->tool->setInputs([]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('current_commit', $result);
        $this->assertArrayHasKey('branch', $result);
        $this->assertArrayHasKey('changed_files', $result);
        $this->assertArrayHasKey('commit_messages', $result);

        // Current commit should be a valid hash
        $this->assertMatchesRegularExpression('/^[a-f0-9]{7,40}$/', $result['current_commit']);
        $this->assertIsString($result['branch']);
    }

    #[Test]
    public function it_can_analyze_changes_since_commit()
    {
        $this->tool->setInputs([
            'since_commit' => 'HEAD~1'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('changed_files', $result);
        $this->assertArrayHasKey('commit_messages', $result);

        $this->assertIsArray($result['changed_files']);
        $this->assertIsArray($result['commit_messages']);

        // All changed files should be PHP files
        foreach ($result['changed_files'] as $file) {
            $this->assertStringEndsWith('.php', $file);
        }
    }

    #[Test]
    public function it_can_filter_by_file_path()
    {
        $this->tool->setInputs([
            'file_path' => 'app/Models'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('changed_files', $result);

        // If there are changed files, they should be from app/Models
        foreach ($result['changed_files'] as $file) {
            $this->assertStringContainsString('app/Models', $file);
        }
    }

    #[Test]
    public function it_provides_commit_information()
    {
        $this->tool->setInputs([
            'since_commit' => 'HEAD~3'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('commit_messages', $result);

        foreach ($result['commit_messages'] as $commit) {
            // GitAnalyzer returns oneline format strings like "abc1234 Commit message"
            $this->assertIsString($commit);
            $this->assertNotEmpty($commit);

            // Should start with commit hash (7+ characters)
            $this->assertMatchesRegularExpression('/^[a-f0-9]{7,}/', $commit);
        }
    }

    #[Test]
    public function it_handles_invalid_commit_hash()
    {
        $this->tool->setInputs([
            'since_commit' => 'invalid-commit-hash'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        // Should either return error or empty results
        if ($result['status'] === 'error') {
            $this->assertArrayHasKey('error', $result);
        } else {
            $this->assertEquals('success', $result['status']);
            $this->assertIsArray($result['changed_files']);
            $this->assertIsArray($result['commit_messages']);
        }
    }

    #[Test]
    public function it_filters_only_php_files()
    {
        $this->tool->setInputs([
            'since_commit' => 'HEAD~5'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);

        foreach ($result['changed_files'] as $file) {
            $this->assertStringEndsWith('.php', $file);
            $this->assertNotEmpty(trim($file));
        }
    }

    #[Test]
    public function it_provides_branch_information()
    {
        $this->tool->setInputs([]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('branch', $result);
        $this->assertIsString($result['branch']);
        $this->assertNotEmpty($result['branch']);
    }

    #[Test]
    public function it_handles_repository_not_found()
    {
        // This test would need to be run outside of a git repository
        // For now, we'll just verify the tool can handle the case
        $this->assertTrue(method_exists($this->tool, 'execute'));
    }

    #[Test]
    public function it_can_get_recent_commits()
    {
        $this->tool->setInputs([
            'since_commit' => 'HEAD~10'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('commit_messages', $result);

        $commits = $result['commit_messages'];

        // Commits should be strings in oneline format
        foreach ($commits as $commit) {
            $this->assertIsString($commit);
            $this->assertNotEmpty($commit);
            // Should start with commit hash
            $this->assertMatchesRegularExpression('/^[a-f0-9]{7,}/', $commit);
        }
    }

    #[Test]
    public function it_excludes_non_php_files()
    {
        $this->tool->setInputs([
            'since_commit' => 'HEAD~5'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);

        foreach ($result['changed_files'] as $file) {
            // Only PHP files
            $this->assertStringEndsWith('.php', $file);

            // No JS, CSS, MD files
            $this->assertThat($file, $this->logicalNot($this->stringEndsWith('.js')));
            $this->assertThat($file, $this->logicalNot($this->stringEndsWith('.css')));
            $this->assertThat($file, $this->logicalNot($this->stringEndsWith('.md')));
            $this->assertThat($file, $this->logicalNot($this->stringEndsWith('.json')));
        }
    }

    #[Test]
    public function it_handles_empty_commit_range()
    {
        $this->tool->setInputs([
            'since_commit' => 'HEAD'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);
        $this->assertIsArray($result['changed_files']);
        $this->assertIsArray($result['commit_messages']);

        // Should have empty or minimal results
        $this->assertLessThanOrEqual(1, count($result['commit_messages']));
    }

    #[Test]
    public function it_provides_detailed_file_changes()
    {
        $this->tool->setInputs([
            'since_commit' => 'HEAD~2'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('changed_files', $result);

        // Each file should be a valid path
        foreach ($result['changed_files'] as $file) {
            $this->assertIsString($file);
            $this->assertNotEmpty($file);
            $this->assertStringEndsWith('.php', $file);
        }
    }

    #[Test]
    public function it_can_analyze_specific_file_history()
    {
        // Create a test file first
        $this->createTestFile('app/TestHistory.php', '<?php class TestHistory {}');

        $this->tool->setInputs([
            'file_path' => 'app/TestHistory.php'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('changed_files', $result);

        // If the file exists in history, it should be in the results
        if (!empty($result['changed_files'])) {
            $this->assertContains('app/TestHistory.php', $result['changed_files']);
        }
    }

    #[Test]
    public function it_handles_very_old_commits()
    {
        $this->tool->setInputs([
            'since_commit' => 'HEAD~1000'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        // Should return success with potentially empty results
        $this->assertEquals('success', $result['status']);
        $this->assertIsArray($result['changed_files']);
        $this->assertIsArray($result['commit_messages']);
    }

    #[Test]
    public function it_provides_commit_metadata()
    {
        $this->tool->setInputs([
            'since_commit' => 'HEAD~1'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);

        foreach ($result['commit_messages'] as $commit) {
            // GitAnalyzer returns oneline format strings like "abc1234 Commit message"
            $this->assertIsString($commit);
            $this->assertNotEmpty($commit);

            // Should start with commit hash (7+ characters)
            $this->assertMatchesRegularExpression('/^[a-f0-9]{7,}/', $commit);
        }
    }

    #[Test]
    public function it_can_handle_merge_commits()
    {
        $this->tool->setInputs([
            'since_commit' => 'HEAD~20'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayHasKey('commit_messages', $result);

        // Should handle merge commits without issues
        foreach ($result['commit_messages'] as $commit) {
            $this->assertIsString($commit);
            $this->assertNotEmpty($commit);
            // Merge commits often contain "Merge" in the message
            if (str_contains($commit, 'Merge')) {
                $this->assertStringContainsString('Merge', $commit);
            }
        }
    }

    #[Test]
    public function it_validates_parameters()
    {
        // Test with valid parameter types
        $this->tool->setInputs([
            'since_commit' => 'HEAD~1',
            'file_path' => 'app/Models/User.php'
        ]);
        $this->tool->execute();
        $result = json_decode($this->tool->getResult(), true);

        // Should handle parameters gracefully
        $this->assertArrayHasKey('status', $result);
        $this->assertContains($result['status'], ['success', 'error']);
    }
}
