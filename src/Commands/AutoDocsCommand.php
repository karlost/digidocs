<?php

namespace Digihood\Digidocs\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Digihood\Digidocs\Services\MemoryService;
use Digihood\Digidocs\Agent\DocumentationAgent;
use Digihood\Digidocs\Agent\ChangeAnalysisAgent;
use Digihood\Digidocs\Services\GitWatcherService;
use Digihood\Digidocs\Services\CostTracker;
use Exception;

class AutoDocsCommand extends Command
{
    protected $signature = 'digidocs:autodocs {--force : Force regeneration of all documentation}
                                    {--dry-run : Show what would be processed without generating documentation}
                                    {--cleanup : Clean up memory database from non-existent files}
                                    {--stats : Show documentation statistics}
                                    {--cost : Show token usage and cost statistics}
                                    {--path=* : Specific paths to process}';

    protected $description = 'Generate documentation using AI agent for PHP files changed in Git commits';

    public function __construct(
        private MemoryService $memory,
        private DocumentationAgent $agent,
        private ChangeAnalysisAgent $changeAgent,
        private GitWatcherService $gitWatcher,
        private CostTracker $costTracker
    ) {
        parent::__construct();

        // Nastaví cost tracking pro agenty
        $this->agent->setCostTracker($this->costTracker);
        $this->changeAgent->setCostTracker($this->costTracker);
    }

    public function handle(): int
    {
        $this->info('🤖 AutoDocs AI Agent - Starting...');

        // Statistiky
        if ($this->option('stats')) {
            return $this->showStats();
        }

        // Statistiky nákladů
        if ($this->option('cost')) {
            return $this->showCostStats();
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

            // Použij ChangeAnalysisAgent pro inteligentní rozhodování s retry logikou
            $documentation = $this->generateDocumentationWithRetry($relativePath);

            if ($documentation === null) {
                $this->line("   ⏭️  Skipped (no significant changes)");
                return 'skipped';
            }

            // Ulož dokumentaci
            $docPath = $this->saveDocumentation($relativePath, $documentation);

            // NOVÉ: Zaznamenej dokumentované části kódu
            $this->recordDocumentedCodeParts($relativePath, $documentation);

            // Zaznamenej do memory pouze pokud se vše podařilo
            $currentHash = hash_file('sha256', base_path($relativePath));
            $this->memory->recordDocumentation(
                $relativePath,
                $currentHash,
                $docPath
            );

            $this->line("   ✅ Generated: {$docPath}");
            return 'processed';

        } catch (Exception $e) {
            $this->line("   ❌ Failed: " . $e->getMessage());
            return 'error';
        }
    }

    /**
     * Generuje dokumentaci s retry logikou pro 429 chyby
     */
    private function generateDocumentationWithRetry(string $relativePath): ?string
    {
        $maxRetries = 3;
        $retryDelays = [10, 30, 60]; // sekundy

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                // KLÍČOVÁ OPRAVA: Vytvoř novou instanci agenta pro každý pokus
                // Tím se vyčistí stav konverzace a vyřeší se problém s Tools
                $changeAgent = new \Digihood\Digidocs\Agent\ChangeAnalysisAgent();

                // Nastav cost tracker pokud je dostupný
                if ($this->costTracker) {
                    $changeAgent->setCostTracker($this->costTracker);
                }

                return $changeAgent->generateDocumentationIfNeeded($relativePath);
            } catch (\Exception $e) {
                // Zkontroluj jestli je to retryable chyba
                if ($this->isRetryableError($e)) {
                    if ($attempt < $maxRetries) {
                        $delay = $retryDelays[$attempt];
                        $errorType = $this->getErrorType($e);
                        $this->line("   ⏳ {$errorType}, retrying in {$delay}s (attempt " . ($attempt + 2) . "/" . ($maxRetries + 1) . ")");
                        sleep($delay);
                        continue;
                    } else {
                        $errorType = $this->getErrorType($e);
                        $this->line("   ❌ {$errorType} exceeded after {$maxRetries} retries");
                        throw $e;
                    }
                } else {
                    // Pro jiné chyby neprovádíme retry
                    $errorType = $this->getErrorType($e);
                    $this->line("   ❌ Non-retryable error ({$errorType}): " . substr($e->getMessage(), 0, 100) . "...");
                    throw $e;
                }
            }
        }

        return null;
    }

    /**
     * Zkontroluje jestli je chyba retryable (rate limit nebo tool message chyby)
     */
    private function isRetryableError(\Exception $e): bool
    {
        return $this->isRateLimitError($e) || $this->isToolMessageError($e);
    }

    /**
     * Zkontroluje jestli je chyba způsobená rate limitem
     */
    private function isRateLimitError(\Exception $e): bool
    {
        $message = $e->getMessage();

        // Zkontroluj různé typy rate limit chyb
        return str_contains($message, '429 Too Many Requests') ||
               str_contains($message, 'Rate limit reached') ||
               str_contains($message, 'rate_limit_exceeded') ||
               str_contains($message, 'quota_exceeded');
    }

    /**
     * Zkontroluje jestli je chyba způsobená nesprávnou sekvencí tool messages
     */
    private function isToolMessageError(\Exception $e): bool
    {
        $message = $e->getMessage();

        // Zkontroluj chyby s tool messages
        return str_contains($message, 'messages with role') ||
               str_contains($message, 'tool\' must be a response') ||
               str_contains($message, 'Invalid parameter: messages');
    }

    /**
     * Určí typ chyby pro lepší debugging
     */
    private function getErrorType(\Exception $e): string
    {
        $message = $e->getMessage();

        if (str_contains($message, '400 Bad Request')) {
            return '400 Bad Request';
        } elseif (str_contains($message, '401 Unauthorized')) {
            return '401 Unauthorized';
        } elseif (str_contains($message, '403 Forbidden')) {
            return '403 Forbidden';
        } elseif (str_contains($message, '429 Too Many Requests')) {
            return '429 Rate Limit';
        } elseif (str_contains($message, '500 Internal Server Error')) {
            return '500 Server Error';
        } elseif (str_contains($message, 'Invalid parameter')) {
            return 'Invalid Parameter';
        } elseif (str_contains($message, 'messages with role') || str_contains($message, 'tool\' must be a response')) {
            return 'Tool Message Sequence Error';
        } else {
            return 'Unknown';
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

        // Zkontroluj jestli už byly nějaké soubory zpracovány
        $hasDocumentedFiles = $this->memory->hasAnyDocumentedFiles();

        // Pokud je to první spuštění (žádné zpracované soubory), zpracuj všechny commity
        if (!$hasDocumentedFiles) {
            if (empty($currentCommit)) {
                $this->line("📭 No Git repository available for first run.");
                return [];
            }

            $this->line("🔍 First run detected - processing all commits in Git history...");
            $changedFiles = $this->getAllChangedFilesFromHistory($watchPaths);

            $currentCommitHash = array_values($currentCommit)[0];
            $this->memory->setLastProcessedCommit($currentCommitHash);
        } else {
            // Pro další spuštění potřebujeme Git
            if (empty($currentCommit)) {
                $this->line("📭 No Git commits available for change detection.");
                return [];
            }

            $currentCommitHash = array_values($currentCommit)[0];

            if ($this->option('force')) {
                $this->line("🔍 Force mode - processing files from current commit...");
                $changedFiles = $this->gitWatcher->getChangedFilesInCommit($currentCommitHash, $currentCommitHash . '~1');
            } else if ($lastProcessedCommit !== $currentCommitHash) {
                $this->line("🔍 Processing files changed since last run...");
                $changedFiles = $this->gitWatcher->getChangedFilesInCommit($currentCommitHash, $lastProcessedCommit);
            } else {
                // Zkontroluj jestli jsou nějaké soubory, které selhaly při předchozím zpracování
                $failedFiles = $this->getFailedFiles($watchPaths);
                if (!empty($failedFiles)) {
                    $this->line("🔄 Retrying " . count($failedFiles) . " files that failed in previous runs...");
                    $changedFiles = $failedFiles;
                } else {
                    $this->line("📭 No new commits since last run.");
                    return [];
                }
            }

            // Uložit aktuální commit jako zpracovaný
            $this->memory->setLastProcessedCommit($currentCommitHash);
        }

        // Filtruj soubory podle konfigurace (pouze pokud nejsou už předfiltrované)
        if (!$hasDocumentedFiles) {
            // Pro první spuštění už máme správné soubory
            $filteredFiles = $changedFiles;
        } else {
            // Pro ostatní případy filtruj podle konfigurace
            $filteredFiles = $this->filterChangedFiles($changedFiles, $watchPaths);
        }

        return $filteredFiles;
    }

    /**
     * Získá všechny soubory změněné v celé Git historii (pro první spuštění)
     */
    private function getAllChangedFilesFromHistory(array $watchPaths): array
    {
        if (!$this->gitWatcher->isGitAvailable()) {
            return [];
        }

        try {
            // Získej všechny PHP soubory změněné v celé historii
            $allChangedFiles = $this->gitWatcher->getAllChangedFilesInHistory();

            // Filtruj podle sledovaných cest a PHP rozšíření
            $filteredFiles = $this->filterChangedFiles($allChangedFiles, $watchPaths);

            $this->line("📊 Found " . count($filteredFiles) . " PHP files changed in Git history");

            return $filteredFiles;
        } catch (\Exception $e) {
            $this->error("❌ Error getting files from Git history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Získá soubory, které selhaly při předchozím zpracování
     */
    private function getFailedFiles(array $watchPaths): array
    {
        if (!$this->gitWatcher->isGitAvailable()) {
            return [];
        }

        try {
            // Získej všechny soubory z historie
            $allHistoryFiles = $this->gitWatcher->getAllChangedFilesInHistory();
            $filteredHistoryFiles = $this->filterChangedFiles($allHistoryFiles, $watchPaths);

            // Získej soubory, které už byly zpracovány
            $documentedFiles = $this->memory->getDocumentedFiles();

            // Najdi soubory, které jsou v historii, ale nejsou zpracované
            $failedFiles = [];
            foreach ($filteredHistoryFiles as $file) {
                if (!in_array($file, $documentedFiles)) {
                    $failedFiles[] = $file;
                }
            }

            return $failedFiles;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Získá všechny PHP soubory v sledovaných cestách (fallback metoda)
     */
    private function getAllPhpFilesInWatchPaths(array $watchPaths): array
    {
        $allFiles = [];

        foreach ($watchPaths as $watchPath) {
            $fullPath = base_path($watchPath);

            if (!is_dir($fullPath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $this->isValidPhpFile($file->getPathname())) {
                    // Převeď na relativní cestu
                    $relativePath = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $relativePath = str_replace('\\', '/', $relativePath);
                    $allFiles[] = $relativePath;
                }
            }
        }

        return $allFiles;
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

    /**
     * Zobrazí statistiky nákladů a tokenů
     */
    private function showCostStats(): int
    {
        $stats = $this->memory->getCostStats();

        $this->info('💰 AutoDocs Cost & Token Statistics');
        $this->newLine();

        // Celkové statistiky
        $this->info('📊 Overall Statistics');
        $this->line("Total API calls: {$stats['total_calls']}");
        $this->line("Total input tokens: " . number_format($stats['total_input_tokens']));
        $this->line("Total output tokens: " . number_format($stats['total_output_tokens']));
        $this->line("Total tokens: " . number_format($stats['total_tokens']));
        $this->line("Total cost: $" . number_format($stats['total_cost'], 4));

        // Statistiky podle modelů
        if (!empty($stats['by_model'])) {
            $this->newLine();
            $this->info('🤖 Statistics by Model');
            foreach ($stats['by_model'] as $model => $modelStats) {
                $pricingSource = $this->costTracker->getPricingSource($model);
                $sourceIcon = match($pricingSource) {
                    'config' => '⚙️',
                    default => '📋'
                };

                $this->line("  {$model} {$sourceIcon}:");
                $this->line("    Calls: {$modelStats['calls']}");
                $this->line("    Input tokens: " . number_format($modelStats['input_tokens']));
                $this->line("    Output tokens: " . number_format($modelStats['output_tokens']));
                $this->line("    Cost: $" . number_format($modelStats['cost'], 4));
                $this->line("    Pricing source: {$pricingSource}");
            }
        }

        // Nedávná aktivita
        if (!empty($stats['recent_activity'])) {
            $this->newLine();
            $this->info('📅 Recent Activity (Last 7 days)');
            $this->line("API calls: {$stats['recent_activity']['calls']}");
            $this->line("Tokens used: " . number_format($stats['recent_activity']['tokens']));
            $this->line("Cost: $" . number_format($stats['recent_activity']['cost'], 4));
        }

        // Zobrazit aktuální ceny pro použité modely
        if (!empty($stats['by_model'])) {
            $this->newLine();
            $this->info('💰 Current Model Rates (per 1M tokens)');
            foreach (array_keys($stats['by_model']) as $model) {
                $rates = $this->costTracker->getModelRates($model);
                $source = $this->costTracker->getPricingSource($model);
                $sourceIcon = match($source) {
                    'config' => '⚙️',
                    default => '📋'
                };

                $this->line("  {$model} {$sourceIcon}:");
                $this->line("    Input: $" . number_format($rates['input'], 2) . " / 1M tokens");
                $this->line("    Output: $" . number_format($rates['output'], 2) . " / 1M tokens");
            }
        }

        return 0;
    }
}
