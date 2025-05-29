<?php

namespace Digihood\Digidocs\Services;

use Illuminate\Support\Facades\File;

/**
 * Simplified documentation memory for testing
 */
class SimpleDocumentationMemory extends MemoryService
{
    private array $documentCache = [];
    
    /**
     * Store documentation (simplified version)
     */
    public function storeDocumentationWithEmbeddings(
        string $filePath,
        string $hash,
        string $docPath,
        string $docContent,
        array $metadata = []
    ): void {
        // Store in SQLite
        $this->recordUserDocumentation($filePath, $hash, $docPath);
        
        // Store in cache
        $this->documentCache[$docPath] = [
            'content' => $docContent,
            'metadata' => $metadata,
            'file_path' => $filePath,
            'hash' => $hash
        ];
    }
    
    /**
     * Search documentation (simplified)
     */
    public function searchDocumentation(
        string $query,
        int $topK = 5,
        array $filters = []
    ): array {
        // Simple keyword search in cache
        $results = [];
        
        foreach ($this->documentCache as $path => $doc) {
            if (stripos($doc['content'], $query) !== false) {
                $results[] = (object)[
                    'id' => $path,
                    'content' => substr($doc['content'], 0, 500),
                    'metadata' => $doc['metadata'],
                    'score' => 0.8
                ];
            }
        }
        
        return array_slice($results, 0, $topK);
    }
    
    /**
     * Get documentation context
     */
    public function getDocumentationContext(string $filePath): array
    {
        return [
            'related_docs' => $this->searchDocumentation($filePath, 5),
            'change_history' => [],
            'affected_features' => [],
            'documentation_graph' => [],
            'similar_files' => [],
            'coverage_analysis' => ['coverage_percentage' => 0]
        ];
    }
    
    /**
     * Store documentation chunk
     */
    public function storeDocumentationChunk(
        string $content,
        array $metadata = []
    ): string {
        $id = uniqid('chunk_');
        $this->documentCache[$id] = [
            'content' => $content,
            'metadata' => $metadata
        ];
        return $id;
    }
    
    /**
     * Find documents mentioning feature
     */
    public function findDocumentsMentioning(string $feature): array
    {
        return $this->searchDocumentation($feature, 10);
    }
    
    /**
     * Update documentation section
     */
    public function updateDocumentationSection(
        string $docPath,
        string $sectionIdentifier,
        string $newContent,
        array $metadata = []
    ): bool {
        if (isset($this->documentCache[$docPath])) {
            $this->documentCache[$docPath]['content'] = $newContent;
            $this->documentCache[$docPath]['metadata'] = array_merge(
                $this->documentCache[$docPath]['metadata'] ?? [],
                $metadata
            );
            return true;
        }
        return false;
    }
    
    /**
     * Store document in memory - alias for storeDocumentationChunk
     */
    public function remember(string $path, string $content, array $metadata = []): void
    {
        $this->documentCache[$path] = [
            'content' => $content,
            'metadata' => $metadata,
            'path' => $path
        ];
    }
    
    /**
     * Search documents - alias for searchDocumentation  
     */
    public function search(string $query, int $limit = 5): array
    {
        return $this->searchDocumentation($query, $limit);
    }
}