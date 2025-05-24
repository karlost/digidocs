<?php

namespace Digihood\Digidocs\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use CzProject\GitPhp\Git;
use CzProject\GitPhp\GitRepository;
use Exception;

class GitAnalyzerTool
{
    public static function make(): Tool
    {
        return Tool::make(
            'analyze_git_changes',
            'Analyze Git repository changes to understand what files have been modified.'
        )->addProperty(
            new ToolProperty(
                name: 'since_commit',
                type: 'string',
                description: 'Git commit hash to compare changes from (optional)',
                required: false
            )
        )->addProperty(
            new ToolProperty(
                name: 'file_path',
                type: 'string',
                description: 'Specific file path to analyze (optional)',
                required: false
            )
        )->setCallable(new GitAnalyzer());
    }
}

class GitAnalyzer
{
    public function __invoke(?string $since_commit = null, ?string $file_path = null): array
    {
        try {
            $git = new Git();
            $repo = $git->open(base_path());

            $result = [
                'current_commit' => $this->getCurrentCommit($repo),
                'changed_files' => [],
                'commit_messages' => [],
                'branch' => $this->getCurrentBranch($repo),
                'status' => 'success'
            ];

            if ($since_commit) {
                // Získej změněné soubory
                $changedFiles = $this->getChangedFiles($repo, $since_commit);
                $result['changed_files'] = array_filter(
                    $changedFiles,
                    fn($file) => str_ends_with($file, '.php') && !empty(trim($file))
                );

                // Získej commit zprávy
                $result['commit_messages'] = $this->getCommitMessages($repo, $since_commit);
            }

            if ($file_path && file_exists(base_path($file_path))) {
                $result['file_history'] = $this->getFileHistory($repo, $file_path);
                $result['file_last_modified'] = $this->getFileLastModified($repo, $file_path);
            }

            return $result;

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'changed_files' => [],
                'commit_messages' => []
            ];
        }
    }

    private function getCurrentCommit(GitRepository $repo): ?string
    {
        try {
            // Použiju getLastCommitId() metodu z dokumentace
            $commitId = $repo->getLastCommitId();
            return $commitId ? (string) $commitId : null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function getCurrentBranch(GitRepository $repo): ?string
    {
        try {
            // Použiju getCurrentBranchName() metodu z dokumentace
            return $repo->getCurrentBranchName();
        } catch (Exception $e) {
            return null;
        }
    }

    private function getChangedFiles(GitRepository $repo, string $since_commit): array
    {
        try {
            $output = $repo->execute('diff', '--name-only', "{$since_commit}..HEAD");
            $output = is_array($output) ? implode("\n", $output) : $output;
            return array_filter(
                explode("\n", trim($output)),
                fn($file) => !empty(trim($file))
            );
        } catch (Exception $e) {
            return [];
        }
    }

    private function getCommitMessages(GitRepository $repo, string $since_commit): array
    {
        try {
            $output = $repo->execute('log', '--oneline', '--no-merges', "{$since_commit}..HEAD");
            $output = is_array($output) ? implode("\n", $output) : $output;
            return array_filter(
                explode("\n", trim($output)),
                fn($commit) => !empty(trim($commit))
            );
        } catch (Exception $e) {
            return [];
        }
    }

    private function getFileHistory(GitRepository $repo, string $filePath): array
    {
        try {
            $output = $repo->execute('log', '--oneline', '--no-merges', '-5', $filePath);
            $output = is_array($output) ? implode("\n", $output) : $output;
            return array_filter(
                explode("\n", trim($output)),
                fn($commit) => !empty(trim($commit))
            );
        } catch (Exception $e) {
            return [];
        }
    }

    private function getFileLastModified(GitRepository $repo, string $filePath): ?array
    {
        try {
            $output = $repo->execute('log', '-1', '--pretty=format:%H|%an|%ae|%ad|%s', '--date=iso', $filePath);
            $output = is_array($output) ? implode("\n", $output) : $output;

            if (empty(trim($output))) {
                return null;
            }

            $parts = explode('|', trim($output));
            if (count($parts) >= 5) {
                return [
                    'commit' => $parts[0],
                    'author_name' => $parts[1],
                    'author_email' => $parts[2],
                    'date' => $parts[3],
                    'message' => $parts[4]
                ];
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }
}
