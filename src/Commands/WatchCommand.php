<?php

namespace Digihood\Digidocs\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Digihood\Digidocs\Services\MemoryService;
use Digihood\Digidocs\Agent\DocumentationAgent;
use Digihood\Digidocs\Agent\ChangeAnalysisAgent;
use Digihood\Digidocs\Services\GitWatcherService;
use Exception;

class WatchCommand extends Command
{
    protected $signature = 'digidocs:watch {--interval=5 : Check interval in seconds}
                                          {--path=* : Specific paths to watch}';

    protected $description = 'Watch for Git commits and automatically generate documentation for changed files';

    private bool $shouldStop = false;
    private array $lastCommitHashes = [];

    public function __construct(
        private MemoryService $memory,
        private DocumentationAgent $agent,
        private ChangeAnalysisAgent $changeAnalysisAgent,
        private GitWatcherService $gitWatcher
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🔍 AutoDocs Git Watcher - Starting...');

        // Zkontroluj jestli je Git dostupný
        if (!$this->gitWatcher->isGitAvailable()) {
            $this->error("❌ Git repository not available. Make sure you're in a Git repository.");
            return 1;
        }

        $interval = (int) $this->option('interval');
        $watchPaths = $this->option('path') ?: config('digidocs.paths.watch', ['app/']);

        $this->line("📋 Watching paths: " . implode(', ', $watchPaths));
        $this->line("⏱️  Check interval: {$interval} seconds");
        $this->line("🔧 Mode: Git commits only");
        $this->line("💡 Press Ctrl+C to stop watching");
        $this->newLine();

        // Inicializace
        $this->initializeWatcher();

        // Registrace signal handleru pro graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }

        // Hlavní watch loop
        while (!$this->shouldStop) {
            try {
                // Sledování Git commitů
                $changedFiles = $this->checkGitChanges($watchPaths);

                // Zpracování změn
                if (!empty($changedFiles)) {
                    $this->processChanges($changedFiles);
                }

                // Čekání
                sleep($interval);

                // Zpracování signálů (pokud je dostupné)
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }

            } catch (Exception $e) {
                $this->error("❌ Error during watching: " . $e->getMessage());
                sleep($interval);
            }
        }

        $this->info("🛑 AutoDocs Watcher stopped.");
        return 0;
    }

    /**
     * Inicializuje watcher - uloží aktuální stav
     */
    private function initializeWatcher(): void
    {
        // Inicializace Git hash
        $this->lastCommitHashes = $this->gitWatcher->getCurrentCommitHashes();

        $this->line("✅ Git Watcher initialized");

        // Zobraz aktuální commit info
        $lastCommit = $this->gitWatcher->getLastCommitInfo();
        if ($lastCommit) {
            $this->line("📍 Current commit: " . substr($lastCommit['id'], 0, 8) . " - " . $lastCommit['subject']);
        }
    }

    /**
     * Zkontroluje Git změny a vrátí změněné PHP soubory
     */
    private function checkGitChanges(array $watchPaths): array
    {
        $currentHashes = $this->gitWatcher->getCurrentCommitHashes();
        $changedFiles = [];

        foreach ($currentHashes as $branch => $hash) {
            $lastHash = $this->lastCommitHashes[$branch] ?? null;

            if ($lastHash && $lastHash !== $hash) {
                $this->newLine();
                $this->info("🔄 New commit detected on branch '{$branch}'");

                // Získej commit info
                $commitInfo = $this->gitWatcher->getLastCommitInfo();
                if ($commitInfo) {
                    $this->line("📝 " . substr($commitInfo['id'], 0, 8) . " - " . $commitInfo['subject']);
                    $this->line("👤 " . $commitInfo['author_name'] . " (" . $commitInfo['date'] . ")");
                }

                // Získej změněné soubory z commitu
                $allChangedFiles = $this->gitWatcher->getChangedFilesInCommit($hash, $lastHash);

                // Filtruj pouze PHP soubory v sledovaných cestách
                $filteredFiles = $this->filterChangedFiles($allChangedFiles, $watchPaths);

                $this->line("📁 Total changed files: " . count($allChangedFiles));
                $this->line("🎯 PHP files to document: " . count($filteredFiles));

                if (!empty($filteredFiles)) {
                    foreach ($filteredFiles as $file) {
                        $this->line("   • {$file}");
                    }
                }

                $changedFiles = array_merge($changedFiles, $filteredFiles);
            }
        }

        $this->lastCommitHashes = $currentHashes;
        return $changedFiles;
    }

    /**
     * Filtruje změněné soubory podle sledovaných cest a PHP rozšíření
     */
    private function filterChangedFiles(array $files, array $watchPaths): array
    {
        $extensions = config('digidocs.processing.extensions', ['php']);
        $excludeFiles = config('digidocs.processing.exclude_files', ['*.blade.php']);

        $filtered = [];

        foreach ($files as $file) {
            // Normalizuj cestu pro Windows kompatibilitu
            $normalizedFile = str_replace('\\', '/', $file);

            // Zkontroluj rozšíření
            $extension = strtolower(pathinfo($normalizedFile, PATHINFO_EXTENSION));
            if (!in_array($extension, $extensions)) {
                continue;
            }

            // Zkontroluj vyloučené soubory
            $fileName = basename($normalizedFile);
            $shouldExclude = false;
            foreach ($excludeFiles as $pattern) {
                if (fnmatch($pattern, $fileName)) {
                    $shouldExclude = true;
                    break;
                }
            }
            if ($shouldExclude) {
                continue;
            }

            // Zkontroluj jestli je v sledovaných cestách
            $inWatchPath = false;
            foreach ($watchPaths as $watchPath) {
                // Normalizuj watch path pro Windows kompatibilitu
                $normalizedWatchPath = str_replace('\\', '/', rtrim($watchPath, '/\\'));
                if (str_starts_with($normalizedFile, $normalizedWatchPath)) {
                    $inWatchPath = true;
                    break;
                }
            }

            if ($inWatchPath && file_exists(base_path($normalizedFile))) {
                $filtered[] = $normalizedFile;
            }
        }

        return $filtered;
    }



    /**
     * Zpracuje detekované změny
     */
    private function processChanges(array $changedFiles): void
    {
        $this->newLine();
        $this->info("🚀 Processing " . count($changedFiles) . " changed files...");

        $processed = 0;
        $errors = 0;

        foreach ($changedFiles as $filePath) {
            try {
                $this->line("📄 Processing: {$filePath}");

                // Použij ChangeAnalysisAgent pro inteligentní rozhodování
                $documentation = $this->changeAnalysisAgent->generateDocumentationIfNeeded($filePath);

                if ($documentation !== null) {
                    // Ulož dokumentaci
                    $docPath = $this->saveDocumentation($filePath, $documentation);

                    // Zaznamenej do memory
                    $currentHash = hash_file('sha256', base_path($filePath));
                    $this->memory->recordDocumentation(
                        $filePath,
                        $currentHash,
                        $docPath
                    );

                    $this->line("   ✅ Generated: {$docPath}");
                    $processed++;
                } else {
                    $this->line("   ⏭️  Skipped (no significant changes)");
                }

            } catch (Exception $e) {
                $this->line("   ❌ Failed: " . $e->getMessage());
                $errors++;
            }
        }

        $this->newLine();
        $this->info("📊 Processed: {$processed}, Errors: {$errors}");
        $this->newLine();
    }

    /**
     * Uloží dokumentaci do souboru
     */
    private function saveDocumentation(string $filePath, string $documentation): string
    {
        $docsPath = config('digidocs.paths.docs');

        // Převeď cestu souboru na cestu dokumentace
        $relativePath = str_replace(['app/', '.php'], ['', '.md'], $filePath);
        $docPath = $docsPath . '/' . $relativePath;

        // Zajisti existenci adresáře
        $directory = dirname($docPath);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // Uloř dokumentaci
        File::put($docPath, $documentation);

        return str_replace(base_path() . '/', '', $docPath);
    }

    /**
     * Signal handler pro graceful shutdown
     */
    public function handleSignal(int $signal): void
    {
        $this->shouldStop = true;
        $this->newLine();
        $this->line("🛑 Received stop signal, shutting down gracefully...");
    }

}
