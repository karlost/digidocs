<?php

namespace Digihood\Digidocs\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Digihood\Digidocs\Services\MemoryService;
use Digihood\Digidocs\Agent\DocumentationAgent;
use Exception;

class AutoDocsCommand extends Command
{
    protected $signature = 'autodocs {--force : Force regeneration of all documentation} 
                                    {--dry-run : Show what would be processed without generating documentation}
                                    {--cleanup : Clean up memory database from non-existent files}
                                    {--stats : Show documentation statistics}
                                    {--path=* : Specific paths to process}';
    
    protected $description = 'Generate documentation using AI agent for PHP files';

    public function __construct(
        private MemoryService $memory,
        private DocumentationAgent $agent
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🤖 AutoDocs AI Agent - Starting...');
        
        // Statistiky
        if ($this->option('stats')) {
            return $this->showStats();
        }
        
        // Cleanup
        if ($this->option('cleanup')) {
            return $this->cleanup();
        }
        
        // Získej seznam souborů k analýze
        $files = $this->getFilesToProcess();
        
        if (empty($files)) {
            $this->info('📭 No PHP files found to process.');
            return 0;
        }
        
        $this->line("📋 Found " . count($files) . " PHP files to check");
        
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
            
            $status = $this->memory->needsDocumentation($relativePath);
            
            if (isset($status['error'])) {
                $this->line("   ❌ Error: {$status['error']}");
                return 'error';
            }
            
            if (!$this->option('force') && !$status['needs_update']) {
                $this->line("   ⏭️  Skipped (up to date)");
                return 'skipped';
            }
            
            if ($this->option('dry-run')) {
                $this->line("   🔍 Would process with AI agent");
                return 'processed';
            }
            
            // Generuj dokumentaci
            $documentation = $this->generateDocumentation($relativePath);
            
            // Ulož dokumentaci
            $docPath = $this->saveDocumentation($relativePath, $documentation);
            
            // Zaznamenej do memory
            $this->memory->recordDocumentation(
                $relativePath, 
                $status['current_hash'], 
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
     * Vygeneruje dokumentaci pomocí AI agenta
     */
    private function generateDocumentation(string $filePath): string
    {
        $this->line("   🧠 Generating with AI...");
        
        try {
            return $this->agent->generateDocumentationForFile($filePath);
        } catch (Exception $e) {
            throw new Exception("AI generation failed: " . $e->getMessage());
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
     * Získá seznam souborů k zpracování
     */
    private function getFilesToProcess(): array
    {
        $paths = $this->option('path');
        
        if (empty($paths)) {
            $paths = config('digidocs.paths.watch', ['app/']);
        }
        
        $files = [];
        $extensions = config('digidocs.processing.extensions', ['php']);
        $excludeDirs = config('digidocs.processing.exclude_dirs', []);
        $excludeFiles = config('digidocs.processing.exclude_files', []);
        
        foreach ($paths as $path) {
            $fullPath = base_path($path);
            
            if (!File::exists($fullPath)) {
                $this->line("⚠️  Path not found: {$path}");
                continue;
            }
            
            if (File::isFile($fullPath) && $this->shouldProcessFile($fullPath, $extensions, $excludeFiles)) {
                $files[] = str_replace(base_path() . '/', '', $fullPath);
            } elseif (File::isDirectory($fullPath)) {
                $foundFiles = $this->scanDirectory($fullPath, $extensions, $excludeDirs, $excludeFiles);
                $files = array_merge($files, $foundFiles);
            }
        }
        
        return array_unique($files);
    }
    
    /**
     * Prohledá adresář rekurzivně
     */
    private function scanDirectory(string $directory, array $extensions, array $excludeDirs, array $excludeFiles): array
    {
        $files = [];
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            
            $relativePath = str_replace(base_path() . '/', '', $file->getPathname());
            
            // Zkontroluj vyloučené adresáře
            $shouldExcludeDir = false;
            foreach ($excludeDirs as $excludeDir) {
                if (str_contains($relativePath, $excludeDir)) {
                    $shouldExcludeDir = true;
                    break;
                }
            }
            
            if ($shouldExcludeDir) {
                continue;
            }
            
            if ($this->shouldProcessFile($file->getPathname(), $extensions, $excludeFiles)) {
                $files[] = $relativePath;
            }
        }
        
        return $files;
    }
    
    /**
     * Zkontroluje zda by měl být soubor zpracován
     */
    private function shouldProcessFile(string $filePath, array $extensions, array $excludeFiles): bool
    {
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
