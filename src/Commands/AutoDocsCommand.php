<?php

namespace Digihood\Digidocs\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Digihood\Digidocs\Services\MemoryService;
use Digihood\Digidocs\Agent\DocumentationAgent;
use Digihood\Digidocs\Agent\ChangeAnalysisAgent;
use Digihood\Digidocs\Services\GitWatcherService;
use Exception;

class AutoDocsCommand extends Command
{
    protected $signature = 'digidocs:autodocs {--force : Force regeneration of all documentation}
                                    {--dry-run : Show what would be processed without generating documentation}
                                    {--cleanup : Clean up memory database from non-existent files}
                                    {--stats : Show documentation statistics}
                                    {--path=* : Specific paths to process}';

    protected $description = 'Generate documentation using AI agent for PHP files changed in Git commits';

    public function __construct(
        private MemoryService $memory,
        private DocumentationAgent $agent,
        private ChangeAnalysisAgent $changeAgent,
        private GitWatcherService $gitWatcher
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🤖 AutoDocs AI Agent v1.2.0 - Starting...');

        // Statistiky
        if ($this->option('stats')) {
            return $this->showStats();
        }

        // Cleanup
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }

        // Zkontroluj Git dostupnost
        if (!$this->gitWatcher->isGitAvailable()) {
            $this->error("❌ Git repository not available. Ensure you're in a Git repository.");
            return 1;
        }

        // Získej seznam souborů k analýze
        $files = $this->getFilesToProcess();

        if (empty($files)) {
            $this->info('📭 No changed PHP files found in recent Git commits.');
            return 0;
        }

        $this->line("📋 Found " . count($files) . " PHP files to check (mode: Git changes)");

        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($files as $filePath) {
            $result = $this->processFile($filePath);

            switch ($result) {
                case 'processed':
                    $processed++;
                    break;
                case 'skipped':
                    $skipped++;
                    break;
                case 'error':
                    $errors++;
                    break;
            }
        }

        // Shrnutí
        $this->newLine();
        $this->info("✅ Dokončeno!");
        $this->line("📊 Zpracováno: {$processed}, Přeskočeno: {$skipped}, Chyb: {$errors}");

        return $errors > 0 ? 1 : 0;
    }

    /**
     * Zpracuje jednotlivý soubor
     */
    private function processFile(string $filePath): string
    {
        $this->line("📄 Processing: {$filePath}");

        try {
            // Převeď absolutní cestu na relativní
            $relativePath = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $filePath);
            $relativePath = str_replace('\\', '/', $relativePath);

            if ($this->option('dry-run')) {
                $this->line("   🔍 Would process with ChangeAnalysisAgent");
                return 'processed';
            }

            // Použij ChangeAnalysisAgent pro inteligentní rozhodování
            $documentation = $this->changeAgent->generateDocumentationIfNeeded($relativePath);

            if ($documentation === null) {
                $this->line("   ⏭️  Skipped (no significant changes)");
                return 'skipped';
            }

            // Ulož dokumentaci
            $docPath = $this->saveDocumentation($relativePath, $documentation);

            // Zaznamenej do memory
            $currentHash = hash_file('sha256', base_path($relativePath));
            $this->memory->recordDocumentation(
                $relativePath,
                $currentHash,
                $docPath
            );

            // NOVÉ: Zaznamenej dokumentované části kódu
            $this->recordDocumentedCodeParts($relativePath, $documentation);

            $this->line("   ✅ Generated: {$docPath}");
            return 'processed';

        } catch (Exception $e) {
            $this->line("   ❌ Failed: " . $e->getMessage());
            return 'error';
        }
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
     * Zaznamenej dokumentované části kódu
     */
    private function recordDocumentedCodeParts(string $filePath, string $documentation): void
    {
        try {
            // Parsuj současný kód pro získání struktury
            $documentationAnalyzer = new \Digihood\Digidocs\Services\DocumentationAnalyzer();
            $currentContent = file_get_contents(base_path($filePath));

            if (!$currentContent) {
                return;
            }

            $codeStructure = $documentationAnalyzer->parseCodeStructure($currentContent);
            $codeParts = [];

            // Extrahuj třídy
            foreach ($codeStructure['classes'] ?? [] as $class) {
                $codeParts[] = [
                    'type' => 'class',
                    'name' => $class['name'],
                    'signature' => $this->buildClassSignature($class),
                    'section' => 'Classes'
                ];

                // Extrahuj veřejné metody
                foreach ($class['methods'] ?? [] as $method) {
                    if (($method['visibility'] ?? 'public') === 'public') {
                        $codeParts[] = [
                            'type' => 'method',
                            'name' => $class['name'] . '::' . $method['name'],
                            'signature' => $this->buildMethodSignature($method),
                            'section' => 'Methods'
                        ];
                    }
                }

                // Extrahuj veřejné vlastnosti
                foreach ($class['properties'] ?? [] as $property) {
                    if (($property['visibility'] ?? 'public') === 'public') {
                        $codeParts[] = [
                            'type' => 'property',
                            'name' => $class['name'] . '::$' . $property['name'],
                            'signature' => $property['visibility'] . ' $' . $property['name'],
                            'section' => 'Properties'
                        ];
                    }
                }
            }

            // Extrahuj funkce
            foreach ($codeStructure['functions'] ?? [] as $function) {
                $codeParts[] = [
                    'type' => 'function',
                    'name' => $function['name'],
                    'signature' => $this->buildFunctionSignature($function),
                    'section' => 'Functions'
                ];
            }

            // Zaznamenej do databáze
            $this->memory->recordDocumentedCodeParts($filePath, $codeParts);

        } catch (\Exception $e) {
            \Log::warning("Failed to record documented code parts for {$filePath}: " . $e->getMessage());
        }
    }

    /**
     * Vytvoř signaturu třídy
     */
    private function buildClassSignature(array $class): string
    {
        $signature = 'class ' . $class['name'];

        if (!empty($class['extends'])) {
            $signature .= ' extends ' . $class['extends'];
        }

        if (!empty($class['implements'])) {
            $signature .= ' implements ' . implode(', ', $class['implements']);
        }

        return $signature;
    }

    /**
     * Vytvoř signaturu metody
     */
    private function buildMethodSignature(array $method): string
    {
        $params = [];
        foreach ($method['parameters'] ?? [] as $param) {
            $paramStr = '';
            if ($param['type']) {
                $paramStr .= $param['type'] . ' ';
            }
            $paramStr .= '$' . $param['name'];
            if ($param['default']) {
                $paramStr .= ' = ...';
            }
            $params[] = $paramStr;
        }

        $signature = ($method['visibility'] ?? 'public') . ' function ' . $method['name'] . '(' . implode(', ', $params) . ')';

        if ($method['return_type']) {
            $signature .= ': ' . $method['return_type'];
        }

        return $signature;
    }

    /**
     * Vytvoř signaturu funkce
     */
    private function buildFunctionSignature(array $function): string
    {
        $params = [];
        foreach ($function['parameters'] ?? [] as $param) {
            $paramStr = '';
            if ($param['type']) {
                $paramStr .= $param['type'] . ' ';
            }
            $paramStr .= '$' . $param['name'];
            if ($param['default']) {
                $paramStr .= ' = ...';
            }
            $params[] = $paramStr;
        }

        $signature = 'function ' . $function['name'] . '(' . implode(', ', $params) . ')';

        if ($function['return_type']) {
            $signature .= ': ' . $function['return_type'];
        }

        return $signature;
    }

    /**
     * Získá seznam souborů k zpracování
     */
    private function getFilesToProcess(): array
    {
        return $this->getGitChangedFiles();
    }

    /**
     * Získá soubory změněné v Git commitech
     */
    private function getGitChangedFiles(): array
    {
        if (!$this->gitWatcher->isGitAvailable()) {
            return [];
        }

        $watchPaths = $this->option('path') ?: config('digidocs.paths.watch', ['app/']);

        // Získej posledně zpracovaný commit z memory
        $lastProcessedCommit = $this->memory->getLastProcessedCommit();
        $currentCommit = $this->gitWatcher->getCurrentCommitHashes();

        if (empty($currentCommit)) {
            return [];
        }

        $currentCommitHash = array_values($currentCommit)[0];

        // Pokud je to první spuštění nebo force, zpracuj soubory z posledního commitu
        if (!$lastProcessedCommit || $this->option('force')) {
            $this->line("🔍 Processing files from current commit...");
            $changedFiles = $this->gitWatcher->getChangedFilesInCommit($currentCommitHash, $currentCommitHash . '~1');
        } else if ($lastProcessedCommit !== $currentCommitHash) {
            $this->line("🔍 Processing files changed since last run...");
            $changedFiles = $this->gitWatcher->getChangedFilesInCommit($currentCommitHash, $lastProcessedCommit);
        } else {
            $this->line("📭 No new commits since last run.");
            return [];
        }

        // Filtruj soubory podle konfigurace
        $filteredFiles = $this->filterChangedFiles($changedFiles, $watchPaths);

        // Uložit aktuální commit jako zpracovaný
        $this->memory->setLastProcessedCommit($currentCommitHash);

        return $filteredFiles;
    }

    /**
     * Filtruje změněné soubory podle sledovaných cest a PHP rozšíření
     */
    private function filterChangedFiles(array $files, array $watchPaths): array
    {
        $filtered = [];

        foreach ($files as $file) {
            // Zkontroluj jestli je validní PHP soubor
            if (!$this->isValidPhpFile(base_path($file))) {
                continue;
            }

            // Zkontroluj jestli je v sledovaných cestách
            $inWatchPath = false;
            foreach ($watchPaths as $watchPath) {
                if (str_starts_with($file, rtrim($watchPath, '/'))) {
                    $inWatchPath = true;
                    break;
                }
            }

            if ($inWatchPath && file_exists(base_path($file))) {
                $filtered[] = $file;
            }
        }

        return $filtered;
    }

    /**
     * Zkontroluje zda je soubor validní PHP soubor k zpracování
     */
    private function isValidPhpFile(string $filePath): bool
    {
        $extensions = config('digidocs.processing.extensions', ['php']);
        $excludeFiles = config('digidocs.processing.exclude_files', ['*.blade.php']);

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (!in_array($extension, $extensions)) {
            return false;
        }

        $fileName = basename($filePath);
        foreach ($excludeFiles as $pattern) {
            if (fnmatch($pattern, $fileName)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Zobrazí statistiky
     */
    private function showStats(): int
    {
        $stats = $this->memory->getStats();

        $this->info('📊 AutoDocs Statistics');
        $this->line("Total documented files: {$stats['total_files']}");
        $this->line("Files updated in last 7 days: {$stats['recent_updates']}");

        // Statistiky inteligentní analýzy
        if (isset($stats['analysis_enabled']) && $stats['analysis_enabled']) {
            $this->newLine();
            $this->info('🧠 Intelligent Analysis Statistics');
            $this->line("Total analyses performed: {$stats['total_analyses']}");
            $this->line("Documentation regenerations recommended: {$stats['regeneration_recommended']}");
            $this->line("Documentation regenerations skipped: {$stats['regeneration_skipped']}");
            $this->line("Skip rate: {$stats['skip_rate']}%");
            $this->line("Average confidence: {$stats['avg_confidence']}");
            $this->line("Average semantic score: {$stats['avg_semantic_score']}");
            $this->line("Recent analyses (24h): {$stats['recent_analyses']}");
        } else {
            $this->newLine();
            $this->comment('🤖 Intelligent analysis is disabled');
        }

        return 0;
    }

    /**
     * Vyčistí databázi od neexistujících souborů
     */
    private function cleanup(): int
    {
        $this->info('🧹 Cleaning up memory database...');

        $deleted = $this->memory->cleanup();

        $this->line("Removed {$deleted} records for non-existent files");

        return 0;
    }
}
