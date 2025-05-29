<?php

namespace Digihood\Digidocs\Services;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\DataLoader\StringDataLoader;
use NeuronAI\RAG\DataLoader\DocumentSplitter;
use Illuminate\Support\Facades\File;
use Digihood\Digidocs\Services\MemoryService;

class RAGDocumentationMemory extends MemoryService
{
    private VectorStoreInterface $vectorStore;
    private EmbeddingsProviderInterface $embeddingsProvider;
    
    public function __construct()
    {
        parent::__construct();
        
        // Initialize embeddings provider
        $this->embeddingsProvider = new OpenAIEmbeddingsProvider(
            key: config('digidocs.ai.api_key'),
            model: config('digidocs.rag.embeddings.model', 'text-embedding-3-small'),
            dimensions: config('digidocs.rag.embeddings.dimensions', 1024)
        );
        
        // Initialize vector store
        $vectorStorePath = config('digidocs.rag.vector_store.path', storage_path('app/autodocs/vectors'));
        if (!File::exists($vectorStorePath)) {
            File::makeDirectory($vectorStorePath, 0755, true);
        }
        
        $this->vectorStore = new FileVectorStore(
            directory: $vectorStorePath
        );
    }

    /**
     * Store documentation with embeddings
     */
    public function storeDocumentationWithEmbeddings(
        string $filePath,
        string $hash,
        string $docPath,
        string $docContent,
        array $metadata = []
    ): void {
        // Store in SQLite as before
        $this->recordUserDocumentation($filePath, $hash, $docPath);
        
        // Split document into chunks
        $document = new Document($docContent);
        $document->sourceName = $filePath;
        $document->sourceType = 'user_documentation';
        
        $maxChunkSize = config('digidocs.rag.chunking.max_chunk_size', 1000);
        $wordOverlap = config('digidocs.rag.chunking.overlap', 100);
        
        $chunks = DocumentSplitter::splitDocument($document, $maxChunkSize, ' ', $wordOverlap);
        
        // Add embeddings to each chunk and set properties
        foreach ($chunks as $index => $chunk) {
            // Set standard Document properties
            $chunk->sourceName = $filePath;
            $chunk->sourceType = 'user_documentation';
            $chunk->hash = $hash;
            $chunk->chunkNumber = $index;
            
            // Generate unique ID for chunk
            $chunk->id = "{$filePath}_{$hash}_{$index}";
            
            // Generate embedding for this chunk
            $chunk->embedding = $this->embeddingsProvider->embedText($chunk->content);
        }
        
        // Store in vector database
        $this->vectorStore->addDocuments($chunks);
        
        // Record in SQLite for tracking
        $this->recordDocumentChunks($filePath, $hash, count($chunks));
    }

    /**
     * Search for similar documentation
     */
    public function searchDocumentation(
        string $query,
        int $topK = 5,
        array $filters = []
    ): array {
        // Generate embedding for the query
        $queryEmbedding = $this->embeddingsProvider->embedText($query);
        
        // Search in vector store
        $results = $this->vectorStore->similaritySearch($queryEmbedding);
        
        // Apply additional filters if needed
        if (!empty($filters)) {
            $results = $this->applyFilters($results, $filters);
        }
        
        // Enhance results with SQLite data
        foreach ($results as &$result) {
            $filePath = $result->metadata['file_path'] ?? null;
            if ($filePath) {
                $sqliteData = $this->getDocumentationInfo($filePath);
                $result->metadata = array_merge($result->metadata, $sqliteData);
            }
        }
        
        return $results;
    }

    /**
     * Get documentation context for a file
     */
    public function getDocumentationContext(string $filePath): array
    {
        // Find related documents
        $relatedDocs = $this->searchDocumentation(
            "documentation for {$filePath}",
            topK: 10,
            filters: ['type' => 'user_documentation']
        );
        
        // Get change history
        $changeHistory = $this->getChangeAnalysisHistory($filePath, limit: 5);
        
        // Get affected features
        $affectedFeatures = $this->getAffectedUserFeatures($filePath);
        
        // Build documentation graph
        $documentationGraph = $this->buildDocumentationGraph($filePath);
        
        // Get similar files documentation
        $similarFiles = $this->findSimilarFilesDocumentation($filePath);
        
        return [
            'related_docs' => $relatedDocs,
            'change_history' => $changeHistory,
            'affected_features' => $affectedFeatures,
            'documentation_graph' => $documentationGraph,
            'similar_files' => $similarFiles,
            'coverage_analysis' => $this->analyzeDocumentationCoverage($filePath)
        ];
    }

    /**
     * Store a documentation chunk (for agents)
     */
    public function storeDocumentationChunk(
        string $content,
        array $metadata = []
    ): string {
        $document = new Document(
            id: uniqid('doc_chunk_'),
            content: $content,
            metadata: array_merge([
                'type' => 'documentation_chunk',
                'created_at' => now()->toIso8601String()
            ], $metadata)
        );
        
        $this->vectorStore->addDocuments([$document]);
        
        return $document->id;
    }

    /**
     * Find documentation that mentions specific features
     */
    public function findDocumentsMentioning(string $feature): array
    {
        return $this->searchDocumentation(
            $feature,
            topK: 20,
            filters: ['type' => 'user_documentation']
        );
    }

    /**
     * Analyze documentation coverage
     */
    public function analyzeDocumentationCoverage(string $filePath): array
    {
        // Get all chunks for this file
        $chunks = $this->searchDocumentation(
            $filePath,
            topK: 50,
            filters: ['file_path' => $filePath]
        );
        
        // Extract covered topics
        $coveredTopics = [];
        foreach ($chunks as $chunk) {
            $topics = $chunk->metadata['tags'] ?? [];
            $coveredTopics = array_merge($coveredTopics, $topics);
        }
        $coveredTopics = array_unique($coveredTopics);
        
        // Get expected topics from code
        $codeAnalysis = $this->analyzeCodeStructure($filePath);
        $expectedTopics = $this->extractExpectedTopics($codeAnalysis);
        
        // Calculate coverage
        $coverage = count(array_intersect($coveredTopics, $expectedTopics)) / max(count($expectedTopics), 1);
        
        return [
            'coverage_percentage' => round($coverage * 100, 2),
            'covered_topics' => $coveredTopics,
            'expected_topics' => $expectedTopics,
            'missing_topics' => array_diff($expectedTopics, $coveredTopics),
            'chunks_count' => count($chunks)
        ];
    }

    /**
     * Update specific documentation sections
     */
    public function updateDocumentationSection(
        string $docPath,
        string $sectionIdentifier,
        string $newContent,
        array $metadata = []
    ): bool {
        // Find chunks matching the section
        $chunks = $this->searchDocumentation(
            $sectionIdentifier,
            topK: 10,
            filters: ['doc_path' => $docPath]
        );
        
        if (empty($chunks)) {
            return false;
        }
        
        // Remove old chunks
        foreach ($chunks as $chunk) {
            $this->vectorStore->deleteDocument($chunk->id);
        }
        
        // Add new content
        $loader = new StringDataLoader($newContent);
        $documents = $loader->load();
        $newChunks = $this->splitter->splitDocuments($documents);
        
        foreach ($newChunks as $index => $chunk) {
            $chunk->metadata = array_merge($metadata, [
                'doc_path' => $docPath,
                'section' => $sectionIdentifier,
                'updated_at' => now()->toIso8601String(),
                'chunk_index' => $index
            ]);
            $chunk->id = "{$docPath}_{$sectionIdentifier}_{$index}_" . time();
        }
        
        $this->vectorStore->addDocuments($newChunks);
        
        return true;
    }

    /**
     * Get documentation similarity score
     */
    public function getDocumentationSimilarity(string $doc1Path, string $doc2Path): float
    {
        $doc1Chunks = $this->searchDocumentation(
            $doc1Path,
            topK: 10,
            filters: ['doc_path' => $doc1Path]
        );
        
        $doc2Chunks = $this->searchDocumentation(
            $doc2Path,
            topK: 10,
            filters: ['doc_path' => $doc2Path]
        );
        
        if (empty($doc1Chunks) || empty($doc2Chunks)) {
            return 0.0;
        }
        
        // Calculate average similarity between chunks
        $similarities = [];
        foreach ($doc1Chunks as $chunk1) {
            foreach ($doc2Chunks as $chunk2) {
                $similarities[] = $this->calculateCosineSimilarity(
                    $chunk1->embedding,
                    $chunk2->embedding
                );
            }
        }
        
        return !empty($similarities) ? array_sum($similarities) / count($similarities) : 0.0;
    }

    /**
     * Build documentation graph
     */
    private function buildDocumentationGraph(string $filePath): array
    {
        $graph = [
            'nodes' => [],
            'edges' => []
        ];
        
        // Add main node
        $graph['nodes'][] = [
            'id' => $filePath,
            'type' => 'source_file',
            'documentation' => $this->getDocumentationPath($filePath)
        ];
        
        // Find related files through embeddings
        $relatedResults = $this->searchDocumentation(
            $filePath,
            topK: 20
        );
        
        $addedFiles = [$filePath];
        foreach ($relatedResults as $result) {
            $relatedFile = $result->metadata['file_path'] ?? null;
            if ($relatedFile && !in_array($relatedFile, $addedFiles)) {
                $graph['nodes'][] = [
                    'id' => $relatedFile,
                    'type' => 'related_file',
                    'documentation' => $result->metadata['doc_path'] ?? null,
                    'similarity' => $result->score ?? 0
                ];
                
                $graph['edges'][] = [
                    'from' => $filePath,
                    'to' => $relatedFile,
                    'weight' => $result->score ?? 0,
                    'relationship' => 'similar_documentation'
                ];
                
                $addedFiles[] = $relatedFile;
            }
        }
        
        return $graph;
    }

    /**
     * Find similar files documentation
     */
    private function findSimilarFilesDocumentation(string $filePath): array
    {
        // Extract file type and category
        $fileInfo = pathinfo($filePath);
        $fileName = $fileInfo['filename'];
        
        // Search for similar file names and types
        $searchTerms = [
            $fileName,
            str_replace(['Controller', 'Model', 'Request'], '', $fileName),
            $fileInfo['extension']
        ];
        
        $similarDocs = [];
        foreach ($searchTerms as $term) {
            $results = $this->searchDocumentation($term, topK: 5);
            foreach ($results as $result) {
                $resultFile = $result->metadata['file_path'] ?? '';
                if ($resultFile !== $filePath) {
                    $similarDocs[$resultFile] = $result;
                }
            }
        }
        
        return array_values($similarDocs);
    }

    /**
     * Extract tags from content
     */
    private function extractTags(string $content): array
    {
        $tags = [];
        
        // Extract common keywords
        $keywords = [
            'authentication', 'authorization', 'payment', 'order', 'product',
            'user', 'admin', 'api', 'validation', 'security', 'settings',
            'profile', 'cart', 'checkout', 'shipping', 'inventory'
        ];
        
        foreach ($keywords as $keyword) {
            if (stripos($content, $keyword) !== false) {
                $tags[] = $keyword;
            }
        }
        
        // Extract from headings
        preg_match_all('/^#{1,6}\s+(.+)$/m', $content, $matches);
        foreach ($matches[1] ?? [] as $heading) {
            $tags[] = strtolower(trim($heading));
        }
        
        return array_unique($tags);
    }

    /**
     * Apply filters to search results
     */
    private function applyFilters(array $results, array $filters): array
    {
        return array_filter($results, function ($result) use ($filters) {
            foreach ($filters as $key => $value) {
                $resultValue = $result->metadata[$key] ?? null;
                if ($resultValue !== $value) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Get documentation info from SQLite
     */
    private function getDocumentationInfo(string $filePath): array
    {
        $stmt = $this->db->prepare("
            SELECT documentation_path, last_documented_at
            FROM user_documented_files
            WHERE file_path = ?
        ");
        $stmt->execute([$filePath]);
        
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Record document chunks in SQLite
     */
    private function recordDocumentChunks(string $filePath, string $hash, int $chunkCount): void
    {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO documentation_chunks
            (file_path, hash, chunk_count, created_at)
            VALUES (?, ?, ?, datetime('now'))
        ");
        $stmt->execute([$filePath, $hash, $chunkCount]);
    }

    /**
     * Get change analysis history
     */
    protected function getChangeAnalysisHistory(string $filePath, int $limit = 5): array
    {
        $stmt = $this->db->prepare("
            SELECT *
            FROM user_change_analysis
            WHERE file_path = ?
            ORDER BY analyzed_at DESC
            LIMIT ?
        ");
        $stmt->execute([$filePath, $limit]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get affected user features
     */
    private function getAffectedUserFeatures(string $filePath): array
    {
        $stmt = $this->db->prepare("
            SELECT affected_user_features
            FROM user_change_analysis
            WHERE file_path = ?
            ORDER BY analyzed_at DESC
            LIMIT 1
        ");
        $stmt->execute([$filePath]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($result && $result['affected_user_features']) {
            return json_decode($result['affected_user_features'], true) ?: [];
        }
        
        return [];
    }

    /**
     * Analyze code structure
     */
    private function analyzeCodeStructure(string $filePath): array
    {
        // This would use CodeAnalyzer in real implementation
        return [
            'classes' => [],
            'methods' => [],
            'properties' => []
        ];
    }

    /**
     * Extract expected topics from code
     */
    private function extractExpectedTopics(array $codeAnalysis): array
    {
        $topics = [];
        
        // Extract from class names
        foreach ($codeAnalysis['classes'] ?? [] as $class) {
            $topics[] = strtolower($class['name'] ?? '');
        }
        
        // Extract from method names
        foreach ($codeAnalysis['methods'] ?? [] as $method) {
            $topics[] = strtolower($method['name'] ?? '');
        }
        
        return array_unique(array_filter($topics));
    }

    /**
     * Calculate cosine similarity
     */
    private function calculateCosineSimilarity(?array $vec1, ?array $vec2): float
    {
        if (!$vec1 || !$vec2 || count($vec1) !== count($vec2)) {
            return 0.0;
        }
        
        $dotProduct = 0;
        $norm1 = 0;
        $norm2 = 0;
        
        for ($i = 0; $i < count($vec1); $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }
        
        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);
        
        if ($norm1 == 0 || $norm2 == 0) {
            return 0.0;
        }
        
        return $dotProduct / ($norm1 * $norm2);
    }

    /**
     * Get documentation path for file
     */
    private function getDocumentationPath(string $filePath): ?string
    {
        $stmt = $this->db->prepare("
            SELECT documentation_path
            FROM user_documented_files
            WHERE file_path = ?
        ");
        $stmt->execute([$filePath]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $result['documentation_path'] ?? null;
    }

    /**
     * Upgrade database schema for RAG
     */
    protected function upgradeDatabase(): void
    {
        parent::upgradeDatabase();
        
        try {
            // Add documentation_chunks table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS documentation_chunks (
                    file_path TEXT PRIMARY KEY,
                    hash TEXT NOT NULL,
                    chunk_count INTEGER NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            $this->db->exec("
                CREATE INDEX IF NOT EXISTS idx_documentation_chunks_updated
                ON documentation_chunks(updated_at)
            ");
        } catch (\Exception $e) {
            // Table might already exist
        }
    }
}