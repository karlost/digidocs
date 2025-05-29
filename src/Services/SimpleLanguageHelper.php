<?php

namespace Digihood\Digidocs\Services;

/**
 * Simple multi-language helper for DigiDocs
 */
class SimpleLanguageHelper
{
    public function getCurrentLanguage(): string
    {
        return config('digidocs.languages.default', 'cs-CZ');
    }
    
    public function shouldGenerateAll(): bool
    {
        return count($this->getEnabledLanguages()) > 1;
    }
    
    public function getEnabledLanguages(): array
    {
        return config('digidocs.languages.enabled', ['cs-CZ']);
    }
    
    public function getLanguagesToGenerate(): array
    {
        return $this->getEnabledLanguages();
    }
    
    public function isLanguageEnabled(string $language): bool
    {
        return in_array($language, $this->getEnabledLanguages());
    }
    
    public function setCurrentLanguage(string $language): void
    {
        // Simplified - use config
    }
    
    public function setGenerateAll(bool $generateAll): void
    {
        // Simplified - ignore
    }
    
    public function convertFileToDocPath(string $filePath, string $language = null): string
    {
        // Convert: app/Models/User.php -> docs/code/Models/User.md
        // Language is now handled by AI during generation, not by directory structure
        $docPath = str_replace(['app/', '.php'], ['docs/code/', '.md'], $filePath);
        return base_path($docPath);
    }
    
    public function ensureDirectoryStructure(): void
    {
        // Create basic documentation directories
        // Language is handled by AI during generation, not by directory structure
        $codeDir = base_path("docs/code");
        $userDir = base_path("docs/user");
        
        if (!file_exists($codeDir)) {
            mkdir($codeDir, 0755, true);
        }
        
        if (!file_exists($userDir)) {
            mkdir($userDir, 0755, true);
        }
    }
    
    public function getDocumentationStats(): array
    {
        $stats = ['by_language' => [], 'total_files' => 0];
        
        // Count all files since language is handled by AI, not directory structure
        $codeFiles = glob(base_path("docs/code/**/*.md"), GLOB_BRACE) ?: [];
        $userFiles = glob(base_path("docs/user/**/*.md"), GLOB_BRACE) ?: [];
        $totalFiles = count($codeFiles) + count($userFiles);
        
        foreach ($this->getEnabledLanguages() as $language) {
            $stats['by_language'][$language] = [
                'name' => $language, // Use ISO code directly
                'code_files' => count($codeFiles),
                'user_files' => count($userFiles),
                'total_files' => $totalFiles
            ];
        }
        
        $stats['total_files'] = $totalFiles;
        return $stats;
    }
    
    public function migrateExistingDocumentation(): array
    {
        return []; // No migration needed with simplified approach
    }
}