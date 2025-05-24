<?php

namespace Digihood\Digidocs\Services;

use CzProject\GitPhp\Git;
use CzProject\GitPhp\GitRepository;
use Exception;

class GitWatcherService
{
    private ?GitRepository $repo = null;

    public function __construct()
    {
        $this->initializeRepository();
    }

    /**
     * Inicializuje Git repository
     */
    private function initializeRepository(): void
    {
        try {
            $git = new Git();
            $this->repo = $git->open(base_path());
        } catch (Exception $e) {
            // Repository není Git repo nebo není dostupné
            $this->repo = null;
        }
    }

    /**
     * Zkontroluje jestli je Git repository dostupné
     */
    public function isGitAvailable(): bool
    {
        return $this->repo !== null;
    }

    /**
     * Získá aktuální commit hash pro všechny branches
     */
    public function getCurrentCommitHashes(): array
    {
        if (!$this->isGitAvailable()) {
            return [];
        }

        try {
            $hashes = [];
            
            // Získej aktuální branch
            $currentBranch = $this->repo->getCurrentBranchName();
            if ($currentBranch) {
                $commitId = $this->repo->getLastCommitId();
                $hashes[$currentBranch] = $commitId ? (string) $commitId : null;
            }

            return $hashes;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Získá soubory změněné mezi dvěma commity
     */
    public function getChangedFilesInCommit(string $newCommit, string $oldCommit): array
    {
        if (!$this->isGitAvailable()) {
            return [];
        }

        try {
            $output = $this->repo->execute('diff', '--name-only', "{$oldCommit}..{$newCommit}");
            $output = is_array($output) ? implode("\n", $output) : $output;
            
            $files = array_filter(
                explode("\n", trim($output)),
                fn($file) => !empty(trim($file)) && str_ends_with($file, '.php')
            );

            return array_values($files);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Získá informace o posledním commitu
     */
    public function getLastCommitInfo(): ?array
    {
        if (!$this->isGitAvailable()) {
            return null;
        }

        try {
            $commit = $this->repo->getLastCommit();
            
            return [
                'id' => (string) $commit->getId(),
                'subject' => $commit->getSubject(),
                'author_name' => $commit->getAuthorName(),
                'author_email' => $commit->getAuthorEmail(),
                'date' => $commit->getDate()->format('Y-m-d H:i:s'),
                'message' => $commit->getBody()
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Získá seznam commitů od určitého commitu
     */
    public function getCommitsSince(string $sinceCommit, int $limit = 10): array
    {
        if (!$this->isGitAvailable()) {
            return [];
        }

        try {
            $output = $this->repo->execute('log', '--oneline', '--no-merges', "-{$limit}", "{$sinceCommit}..HEAD");
            $output = is_array($output) ? implode("\n", $output) : $output;
            
            return array_filter(
                explode("\n", trim($output)),
                fn($commit) => !empty(trim($commit))
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Zkontroluje jestli jsou nějaké uncommitted změny
     */
    public function hasUncommittedChanges(): bool
    {
        if (!$this->isGitAvailable()) {
            return false;
        }

        try {
            return $this->repo->hasChanges();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Získá status repository
     */
    public function getRepositoryStatus(): array
    {
        if (!$this->isGitAvailable()) {
            return [
                'available' => false,
                'error' => 'Git repository not available'
            ];
        }

        try {
            $lastCommit = $this->getLastCommitInfo();
            $currentBranch = $this->repo->getCurrentBranchName();
            $hasChanges = $this->hasUncommittedChanges();

            return [
                'available' => true,
                'current_branch' => $currentBranch,
                'last_commit' => $lastCommit,
                'has_uncommitted_changes' => $hasChanges,
                'repository_path' => $this->repo->getRepositoryPath()
            ];
        } catch (Exception $e) {
            return [
                'available' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Sleduje změny v real-time (pomocí Git hooks by bylo lepší, ale toto je fallback)
     */
    public function watchForChanges(callable $callback, int $interval = 5): void
    {
        if (!$this->isGitAvailable()) {
            throw new Exception('Git repository not available for watching');
        }

        $lastCommitHash = null;
        $currentCommitHashes = $this->getCurrentCommitHashes();
        
        if (!empty($currentCommitHashes)) {
            $lastCommitHash = array_values($currentCommitHashes)[0];
        }

        while (true) {
            try {
                $currentHashes = $this->getCurrentCommitHashes();
                
                if (!empty($currentHashes)) {
                    $currentHash = array_values($currentHashes)[0];
                    
                    if ($lastCommitHash && $currentHash !== $lastCommitHash) {
                        // Nový commit detekován
                        $changedFiles = $this->getChangedFilesInCommit($currentHash, $lastCommitHash);
                        
                        $callback([
                            'type' => 'git_commit',
                            'old_commit' => $lastCommitHash,
                            'new_commit' => $currentHash,
                            'changed_files' => $changedFiles,
                            'commit_info' => $this->getLastCommitInfo()
                        ]);
                        
                        $lastCommitHash = $currentHash;
                    }
                }
                
                sleep($interval);
            } catch (Exception $e) {
                // Log error ale pokračuj ve sledování
                error_log("GitWatcher error: " . $e->getMessage());
                sleep($interval);
            }
        }
    }

    /**
     * Vytvoří Git hook pro automatické spuštění dokumentace
     */
    public function installPostCommitHook(): bool
    {
        if (!$this->isGitAvailable()) {
            return false;
        }

        try {
            $hookPath = $this->repo->getRepositoryPath() . '/.git/hooks/post-commit';
            
            $hookContent = <<<'BASH'
#!/bin/bash
# AutoDocs post-commit hook
# Automatically generates documentation after each commit

echo "🤖 AutoDocs: Generating documentation for committed changes..."

# Spusť autodocs pro změněné soubory
php artisan autodocs --force

echo "✅ AutoDocs: Documentation generation completed"
BASH;

            file_put_contents($hookPath, $hookContent);
            chmod($hookPath, 0755);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Odstraní post-commit hook
     */
    public function uninstallPostCommitHook(): bool
    {
        if (!$this->isGitAvailable()) {
            return false;
        }

        try {
            $hookPath = $this->repo->getRepositoryPath() . '/.git/hooks/post-commit';
            
            if (file_exists($hookPath)) {
                unlink($hookPath);
            }
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
