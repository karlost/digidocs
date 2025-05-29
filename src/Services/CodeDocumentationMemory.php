<?php

namespace Digihood\Digidocs\Services;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\DataLoader\DocumentSplitter;

class CodeDocumentationMemory extends RAGDocumentationMemory
{
    /**
     * Get related code documentation for a file
     */
    public function getRelatedCodeDocs(string $filePath, int $topK = 5): array
    {
        // Extract class/interface name from file path
        $fileName = basename($filePath, '.php');
        
        // Search for related documentation
        $results = $this->searchDocumentation(
            "code documentation for {$fileName} class interface implementation",
            topK: $topK,
            filters: ['type' => 'code_documentation']
        );
        
        // Also search by file path
        $pathResults = $this->searchDocumentation(
            $filePath,
            topK: $topK,
            filters: ['type' => 'code_documentation']
        );
        
        // Merge and deduplicate results
        $allResults = array_merge($results, $pathResults);
        $uniqueResults = [];
        $seenIds = [];
        
        foreach ($allResults as $result) {
            if (!in_array($result->id, $seenIds)) {
                $uniqueResults[] = $result;
                $seenIds[] = $result->id;
            }
        }
        
        // Sort by relevance score
        usort($uniqueResults, function($a, $b) {
            return ($b->score ?? 0) <=> ($a->score ?? 0);
        });
        
        return array_slice($uniqueResults, 0, $topK);
    }
    
    /**
     * Find similar code implementations
     */
    public function findSimilarImplementations(string $codeSnippet, int $topK = 3): array
    {
        // Search for similar code patterns
        $results = $this->searchDocumentation(
            $codeSnippet,
            topK: $topK * 2, // Get more results to filter
            filters: ['type' => 'code_documentation']
        );
        
        // Filter to only highly relevant results
        $filtered = array_filter($results, function($result) {
            return ($result->score ?? 0) > 0.7; // High similarity threshold
        });
        
        return array_slice($filtered, 0, $topK);
    }
    
    /**
     * Get historical documentation changes for a class
     */
    public function getHistoricalChanges(string $className, int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                ca.file_path,
                ca.analyzed_at,
                ca.change_type,
                ca.change_impact,
                ca.should_regenerate,
                df.documentation_path,
                df.last_documented_at
            FROM change_analysis ca
            LEFT JOIN documented_files df ON ca.file_path = df.file_path
            WHERE ca.file_path LIKE ?
            ORDER BY ca.analyzed_at DESC
            LIMIT ?
        ");
        
        $pattern = "%{$className}%";
        $stmt->execute([$pattern, $limit]);
        
        $changes = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        
        // Enrich with documentation content snippets
        foreach ($changes as &$change) {
            if ($change['documentation_path']) {
                $docs = $this->searchDocumentation(
                    $change['file_path'],
                    topK: 1,
                    filters: ['file_path' => $change['file_path']]
                );
                
                if (!empty($docs)) {
                    $change['documentation_snippet'] = substr($docs[0]->content ?? '', 0, 200) . '...';
                }
            }
        }
        
        return $changes;
    }
    
    /**
     * Get code context for documentation generation
     */
    public function getCodeDocumentationContext(string $filePath): array
    {
        // Get related code documentation
        $relatedDocs = $this->getRelatedCodeDocs($filePath, topK: 5);
        
        // Extract class/method names from file
        $fileName = basename($filePath, '.php');
        
        // Find parent classes or interfaces
        $parentDocs = $this->searchDocumentation(
            "extends {$fileName} implements {$fileName}",
            topK: 3,
            filters: ['type' => 'code_documentation']
        );
        
        // Find child classes
        $childDocs = $this->searchDocumentation(
            "{$fileName} extends implements",
            topK: 3,
            filters: ['type' => 'code_documentation']
        );
        
        // Get method implementations in similar files
        $similarPatterns = $this->findSimilarCodePatterns($filePath);
        
        // Get recent changes context
        $changeHistory = $this->getChangeAnalysisHistory($filePath, limit: 3);
        
        return [
            'related_docs' => $relatedDocs,
            'parent_classes' => $parentDocs,
            'child_classes' => $childDocs,
            'similar_patterns' => $similarPatterns,
            'change_history' => $changeHistory,
            'documentation_suggestions' => $this->generateDocumentationSuggestions($filePath, $relatedDocs)
        ];
    }
    
    /**
     * Store code documentation with enhanced metadata
     */
    public function storeCodeDocumentation(
        string $filePath,
        string $hash,
        string $docPath,
        string $docContent,
        array $codeStructure = []
    ): void {
        // Extract metadata from code structure
        $metadata = [
            'type' => 'code_documentation',
            'classes' => $codeStructure['classes'] ?? [],
            'methods' => $codeStructure['methods'] ?? [],
            'interfaces' => $codeStructure['interfaces'] ?? [],
            'traits' => $codeStructure['traits'] ?? [],
            'namespace' => $codeStructure['namespace'] ?? null,
            'uses' => $codeStructure['uses'] ?? [],
            'laravel_type' => $this->detectLaravelType($filePath, $codeStructure),
            'complexity' => $codeStructure['complexity'] ?? null
        ];
        
        // Store with embeddings
        $this->storeDocumentationWithEmbeddings(
            $filePath,
            $hash,
            $docPath,
            $docContent,
            $metadata
        );
        
        // Store additional code-specific tracking
        $this->storeCodeMetrics($filePath, $hash, $codeStructure);
    }
    
    /**
     * Find code patterns similar to given file
     */
    private function findSimilarCodePatterns(string $filePath): array
    {
        // Extract key patterns from filename
        $fileName = basename($filePath, '.php');
        $patterns = [];
        
        // Common Laravel patterns
        $laravelPatterns = [
            'Controller' => 'controller request response route',
            'Model' => 'model eloquent database relationship',
            'Request' => 'validation rules authorize request',
            'Service' => 'service business logic',
            'Repository' => 'repository pattern data access',
            'Middleware' => 'middleware request handling',
            'Job' => 'job queue async processing',
            'Event' => 'event listener broadcasting',
            'Observer' => 'observer model events',
            'Policy' => 'policy authorization gates'
        ];
        
        foreach ($laravelPatterns as $pattern => $searchTerms) {
            if (str_contains($fileName, $pattern)) {
                $results = $this->searchDocumentation(
                    $searchTerms,
                    topK: 3,
                    filters: ['type' => 'code_documentation']
                );
                
                foreach ($results as $result) {
                    if ($result->metadata['file_path'] ?? '' !== $filePath) {
                        $patterns[] = [
                            'pattern' => $pattern,
                            'file' => $result->metadata['file_path'] ?? '',
                            'relevance' => $result->score ?? 0
                        ];
                    }
                }
            }
        }
        
        return $patterns;
    }
    
    /**
     * Generate documentation suggestions based on similar files
     */
    private function generateDocumentationSuggestions(string $filePath, array $relatedDocs): array
    {
        $suggestions = [];
        
        // Analyze common sections in related documentation
        $commonSections = [];
        foreach ($relatedDocs as $doc) {
            // Extract section headers from content
            preg_match_all('/^##\s+(.+)$/m', $doc->content ?? '', $matches);
            foreach ($matches[1] ?? [] as $section) {
                $commonSections[] = trim($section);
            }
        }
        
        // Count section frequency
        $sectionCounts = array_count_values($commonSections);
        arsort($sectionCounts);
        
        // Suggest commonly used sections
        foreach ($sectionCounts as $section => $count) {
            if ($count >= 2) { // Section appears in at least 2 related docs
                $suggestions[] = [
                    'type' => 'section',
                    'value' => $section,
                    'confidence' => $count / count($relatedDocs)
                ];
            }
        }
        
        // Suggest documentation patterns based on file type
        $fileType = $this->detectLaravelType($filePath, []);
        if ($fileType) {
            $suggestions[] = [
                'type' => 'pattern',
                'value' => "Laravel {$fileType} documentation pattern",
                'confidence' => 0.8
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Detect Laravel component type from file path and structure
     */
    private function detectLaravelType(string $filePath, array $codeStructure): ?string
    {
        $pathParts = explode('/', $filePath);
        $fileName = basename($filePath, '.php');
        
        // Check by directory structure
        $typeMap = [
            'Controllers' => 'Controller',
            'Models' => 'Model',
            'Requests' => 'Request',
            'Middleware' => 'Middleware',
            'Jobs' => 'Job',
            'Events' => 'Event',
            'Listeners' => 'Listener',
            'Policies' => 'Policy',
            'Providers' => 'Provider',
            'Services' => 'Service',
            'Repositories' => 'Repository'
        ];
        
        foreach ($typeMap as $dir => $type) {
            if (in_array($dir, $pathParts)) {
                return $type;
            }
        }
        
        // Check by file name suffix
        foreach ($typeMap as $dir => $type) {
            if (str_ends_with($fileName, $type)) {
                return $type;
            }
        }
        
        // Check by class inheritance (if available in code structure)
        if (!empty($codeStructure['extends'])) {
            $extends = $codeStructure['extends'];
            if (str_contains($extends, 'Controller')) return 'Controller';
            if (str_contains($extends, 'Model')) return 'Model';
            if (str_contains($extends, 'Request')) return 'Request';
        }
        
        return null;
    }
    
    /**
     * Store code-specific metrics
     */
    private function storeCodeMetrics(string $filePath, string $hash, array $codeStructure): void
    {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO code_metrics
            (file_path, hash, class_count, method_count, line_count, complexity, created_at)
            VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        
        $stmt->execute([
            $filePath,
            $hash,
            count($codeStructure['classes'] ?? []),
            count($codeStructure['methods'] ?? []),
            $codeStructure['line_count'] ?? 0,
            $codeStructure['complexity'] ?? 0
        ]);
    }
    
    /**
     * Update related documentation when code changes
     */
    public function updateRelatedDocumentation(string $changedFilePath, array $changes): array
    {
        $affectedDocs = [];
        
        // Find documentation that references this file
        $referencingDocs = $this->searchDocumentation(
            $changedFilePath,
            topK: 20,
            filters: ['type' => 'code_documentation']
        );
        
        foreach ($referencingDocs as $doc) {
            $docPath = $doc->metadata['file_path'] ?? '';
            if ($docPath && $docPath !== $changedFilePath) {
                $affectedDocs[] = [
                    'file_path' => $docPath,
                    'doc_path' => $doc->metadata['doc_path'] ?? '',
                    'relevance' => $doc->score ?? 0,
                    'reason' => 'references_changed_file'
                ];
            }
        }
        
        // Find documentation for classes that extend/implement changed classes
        if (!empty($changes['modified_classes'])) {
            foreach ($changes['modified_classes'] as $className) {
                $relatedClasses = $this->searchDocumentation(
                    "extends {$className} implements {$className}",
                    topK: 10,
                    filters: ['type' => 'code_documentation']
                );
                
                foreach ($relatedClasses as $doc) {
                    $affectedDocs[] = [
                        'file_path' => $doc->metadata['file_path'] ?? '',
                        'doc_path' => $doc->metadata['doc_path'] ?? '',
                        'relevance' => $doc->score ?? 0,
                        'reason' => 'inheritance_relationship'
                    ];
                }
            }
        }
        
        // Deduplicate affected docs
        $uniqueDocs = [];
        $seen = [];
        foreach ($affectedDocs as $doc) {
            $key = $doc['file_path'];
            if (!isset($seen[$key])) {
                $uniqueDocs[] = $doc;
                $seen[$key] = true;
            }
        }
        
        return $uniqueDocs;
    }
    
    /**
     * Upgrade database schema for code documentation
     */
    protected function upgradeDatabase(): void
    {
        parent::upgradeDatabase();
        
        try {
            // Add code_metrics table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS code_metrics (
                    file_path TEXT PRIMARY KEY,
                    hash TEXT NOT NULL,
                    class_count INTEGER DEFAULT 0,
                    method_count INTEGER DEFAULT 0,
                    line_count INTEGER DEFAULT 0,
                    complexity INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_code_metrics_complexity
                ON code_metrics(complexity)
            ");
        } catch (\Exception $e) {
            // Table might already exist
        }
    }
}