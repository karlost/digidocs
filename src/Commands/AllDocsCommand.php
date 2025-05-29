<?php

namespace Digihood\Digidocs\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;

class AllDocsCommand extends Command
{
    protected $signature = 'digidocs:alldocs 
                            {--lang=* : Specific languages to generate (e.g., cs-CZ,en-US)}
                            {--code-only : Generate only code documentation}
                            {--user-only : Generate only user documentation}
                            {--all : Process all PHP files, not just Git changes}
                            {--force : Force regeneration of all documentation}
                            {--path=* : Specific paths to process for code docs}';

    protected $description = 'Generate both code and user documentation in sequence';

    public function handle()
    {
        $this->info('🚀 Starting Complete Documentation Generation...');
        $this->newLine();
        
        $startTime = microtime(true);
        $results = [];
        
        // Check what to generate
        $generateCode = !$this->option('user-only');
        $generateUser = !$this->option('code-only');
        
        if (!$generateCode && !$generateUser) {
            $this->error('❌ Cannot use both --code-only and --user-only together');
            return 1;
        }
        
        // Step 1: Generate Code Documentation
        if ($generateCode) {
            $this->info('📝 Step 1: Generating Code Documentation...');
            $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            
            $codeResult = $this->generateCodeDocs();
            $results['code'] = $codeResult;
            
            if ($codeResult['success']) {
                $this->info("✅ Code documentation completed");
            } else {
                $this->warn("⚠️  Code documentation completed with issues");
            }
            $this->newLine();
        }
        
        // Step 2: Generate User Documentation
        if ($generateUser) {
            $this->info('📚 Step 2: Generating User Documentation...');
            $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            
            $userResult = $this->generateUserDocs();
            $results['user'] = $userResult;
            
            if ($userResult['success']) {
                $this->info("✅ User documentation completed");
            } else {
                $this->warn("⚠️  User documentation completed with issues");
            }
            $this->newLine();
        }
        
        // Summary
        $duration = round(microtime(true) - $startTime, 2);
        $this->showSummary($results, $duration);
        
        // Return error code if any step failed
        foreach ($results as $result) {
            if (!$result['success']) {
                return 1;
            }
        }
        
        return 0;
    }
    
    private function generateCodeDocs(): array
    {
        $arguments = ['command' => 'digidocs:autodocs'];
        
        // Pass through relevant options
        if ($this->option('all')) {
            $arguments['--all'] = true;
        }
        
        if ($this->option('force')) {
            $arguments['--force'] = true;
        }
        
        if ($this->option('path')) {
            $arguments['--path'] = $this->option('path');
            $arguments['--all'] = true; // --path requires --all flag
        }
        
        // If no specific options, default to --all for comprehensive generation
        if (!$this->option('path') && !$this->option('all') && !$this->option('force')) {
            $arguments['--all'] = true;
        }
        
        $input = new ArrayInput($arguments);
        $exitCode = $this->getApplication()->find('digidocs:autodocs')->run($input, $this->output);
        
        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'type' => 'code'
        ];
    }
    
    private function generateUserDocs(): array
    {
        $arguments = ['command' => 'digidocs:userdocs'];
        
        // Pass through language options
        if ($this->option('lang')) {
            $languages = $this->option('lang');
            if (!empty($languages)) {
                $arguments['--lang'] = $languages[0]; // Take first language
            }
        }
        
        $input = new ArrayInput($arguments);
        $exitCode = $this->getApplication()->find('digidocs:userdocs')->run($input, $this->output);
        
        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
            'type' => 'user'
        ];
    }
    
    private function showSummary(array $results, float $duration): void
    {
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('📊 Generation Summary');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        foreach ($results as $type => $result) {
            $status = $result['success'] ? '✅ Success' : '❌ Failed';
            $this->line("  {$type} documentation: {$status}");
        }
        
        $this->newLine();
        $this->info("⏱️  Total generation time: {$duration}s");
        
        // Show documentation statistics
        $this->showDocumentationStats();
        
        $this->newLine();
        $this->info('🎉 Complete documentation generation finished!');
        $this->line('📁 Documentation location: ' . base_path('docs/'));
    }
    
    private function showDocumentationStats(): void
    {
        $codeFiles = glob(base_path('docs/code/**/*.md'), GLOB_BRACE) ?: [];
        $userFiles = glob(base_path('docs/user/**/*.md'), GLOB_BRACE) ?: [];
        
        $this->newLine();
        $this->info('📈 Documentation Statistics:');
        $this->line("   • Code documentation: " . count($codeFiles) . " files");
        $this->line("   • User documentation: " . count($userFiles) . " files");
        $this->line("   • Total: " . (count($codeFiles) + count($userFiles)) . " files");
        
        // Show language breakdown
        $languages = config('digidocs.languages.enabled', ['cs-CZ']);
        $this->newLine();
        $this->info('🌍 Languages:');
        foreach ($languages as $lang) {
            // Count files in all docs directories since we no longer have specific language dirs
            $codeFiles = glob(base_path("docs/code/**/*.md"), GLOB_BRACE) ?: [];
            $userFiles = glob(base_path("docs/user/**/*.md"), GLOB_BRACE) ?: [];
            $totalFiles = count($codeFiles) + count($userFiles);
            $this->line("   • {$lang}: {$totalFiles} files");
        }
    }
}