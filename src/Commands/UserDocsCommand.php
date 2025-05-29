<?php

namespace Digihood\Digidocs\Commands;

use Illuminate\Console\Command;
use Digihood\Digidocs\Agent\UserDocumentationOrchestrator;

class UserDocsCommand extends Command
{
    protected $signature = 'digidocs:userdocs 
                            {--lang=cs-CZ : Documentation language (cs-CZ, en-US, sk-SK, de-DE, es-ES, fr-FR)}
                            {--tone=friendly : Documentation tone}
                            {--tech-level=beginner : Technical level}';

    protected $description = 'Generate user documentation';

    public function handle()
    {
        $this->info('🚀 Starting User Documentation Generation...');
        
        $startTime = microtime(true);
        
        try {
            // Get options
            $language = $this->option('lang');
            $tone = $this->option('tone');
            $techLevel = $this->option('tech-level');
            
            // Update config temporarily
            config([
                'digidocs.user_documentation.formatting.language' => $language,
                'digidocs.user_documentation.formatting.tone' => $tone,
                'digidocs.user_documentation.formatting.technical_level' => $techLevel,
            ]);
            
            $this->info("📝 Configuration:");
            $this->info("   Language: $language");
            $this->info("   Tone: $tone");
            $this->info("   Technical Level: $techLevel");
            $this->newLine();
            
            // Create orchestrator and set language
            $orchestrator = app(UserDocumentationOrchestrator::class);
            $orchestrator->setLanguage($language);
            
            // Generate documentation
            $this->info('🔍 Analyzing application structure...');
            
            $sections = ['index', 'getting-started', 'features', 'guides', 'troubleshooting', 'reference'];
            $result = [
                'statistics' => [
                    'total_documents' => 0,
                    'sections_created' => 0,
                    'documents_by_section' => []
                ],
                'errors' => []
            ];
            
            foreach ($sections as $section) {
                try {
                    $this->info("📝 Generating $section section...");
                    $orchestrator->generateSection($section);
                    $result['statistics']['sections_created']++;
                    
                    // Count files in section
                    $sectionPath = base_path("docs/user/$section");
                    if (is_dir($sectionPath)) {
                        $files = glob("$sectionPath/*.md") ?: [];
                        $count = count($files);
                        $result['statistics']['documents_by_section'][$section] = $count;
                        $result['statistics']['total_documents'] += $count;
                    } else {
                        // For index.md at root
                        if ($section === 'index' && file_exists(base_path('docs/user/index.md'))) {
                            $result['statistics']['documents_by_section'][$section] = 1;
                            $result['statistics']['total_documents']++;
                        }
                    }
                } catch (\Exception $e) {
                    $result['errors'][] = "Error in $section: " . $e->getMessage();
                    $this->warn("⚠️  Error generating $section: " . $e->getMessage());
                }
            }
            
            // Show results
            $this->newLine();
            $this->info('✅ Documentation generation completed!');
            $this->newLine();
            
            // Statistics
            $stats = $result['statistics'] ?? [];
            $this->info('📊 Generation Statistics:');
            $this->info('   Total documents: ' . ($stats['total_documents'] ?? 0));
            $this->info('   Sections created: ' . ($stats['sections_created'] ?? 0));
            
            if (isset($stats['documents_by_section'])) {
                $this->newLine();
                $this->info('📁 Documents by section:');
                foreach ($stats['documents_by_section'] as $section => $count) {
                    $this->info("   - $section: $count documents");
                }
            }
            
            $duration = round(microtime(true) - $startTime, 2);
            $this->newLine();
            $this->info("⏱️  Total time: {$duration}s");
            
            // Show any errors
            if (!empty($result['errors'])) {
                $this->newLine();
                $this->warn('⚠️  Some errors occurred:');
                foreach ($result['errors'] as $error) {
                    $this->warn("   - $error");
                }
            }
            
            $this->newLine();
            $this->info('📚 Documentation has been generated in: ' . base_path('docs/user/'));
            
        } catch (\Exception $e) {
            $this->error('❌ Error generating documentation: ' . $e->getMessage());
            $this->error('Stack trace:');
            $this->error($e->getTraceAsString());
            return 1;
        }
        
        return 0;
    }
}