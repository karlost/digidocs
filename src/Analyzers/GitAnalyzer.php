<?php

namespace Digihood\Digidocs\Analyzers;

use CzProject\GitPhp\Git;
use CzProject\GitPhp\GitRepository;
use Exception;

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
            $output = is_array($output) ? implode("\n", $output) : (string) $output;

            if (empty($output)) {
                return [];
            }

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
            $output = is_array($output) ? implode("\n", $output) : (string) $output;

            if (empty($output)) {
                return [];
            }

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
            $output = is_array($output) ? implode("\n", $output) : (string) $output;

            if (empty($output)) {
                return [];
            }

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
            $output = is_array($output) ? implode("\n", $output) : (string) $output;

            if (empty($output) || empty(trim($output))) {
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
