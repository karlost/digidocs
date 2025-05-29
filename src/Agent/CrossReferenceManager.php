<?php

namespace Digihood\Digidocs\Agent;

use NeuronAI\Agent;
use NeuronAI\SystemPrompt;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Chat\Messages\UserMessage;
use Digihood\Digidocs\Services\SimpleDocumentationMemory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CrossReferenceManager extends Agent
{
    private SimpleDocumentationMemory $ragMemory;
    private array $documentMap = [];
    private array $processedDocs = [];
    private string $language = 'cs-CZ';
    
    public function __construct(SimpleDocumentationMemory $ragMemory)
    {
        $this->ragMemory = $ragMemory;
    }

    protected function provider(): AIProviderInterface
    {
        return new OpenAI(
            key: config('digidocs.ai.api_key'),
            model: config('digidocs.ai.model', 'gpt-4'),
        );
    }
    
    public function setLanguage(string $language): self
    {
        $this->language = $language;
        return $this;
    }

    public function instructions(): string
    {
        return new SystemPrompt(
            background: [
                "You are an expert in documentation structure and cross-referencing",
                "You analyze documentation to find relationships and connections",
                "You create intuitive navigation and linking between documents",
                "You understand user documentation best practices",
                "You work with Markdown files in multiple languages"
            ],
            steps: [
                "Analyze all documentation files to understand structure",
                "Identify mentions of features, concepts, and related topics",
                "Create appropriate cross-references and links",
                "Add navigation elements (breadcrumbs, prev/next)",
                "Ensure consistency in naming and references",
                "Maintain document hierarchy and relationships",
                "Use RAG system to find related content"
            ],
            output: [
                "Generate updated Markdown with proper links",
                "Create navigation structures",
                "Suggest related topics sections",
                "Maintain link integrity",
                "Use relative paths for internal links"
            ]
        );
    }
    
    private function getTargetLanguage(): string
    {
        // Simply pass the ISO code to AI - it understands them directly
        return "ISO {$this->language}";
    }

    /**
     * Process all documents and create cross-references
     */
    public function linkDocuments(?string $basePath = null): void
    {
        if (!$basePath) {
            $basePath = base_path('docs/user');
        }
        
        // Build document map
        $this->buildDocumentMap($basePath);
        
        // Process each document
        foreach ($this->documentMap as $docPath => $docInfo) {
            if (!in_array($docPath, $this->processedDocs)) {
                $this->processDocument($docPath, $docInfo);
                $this->processedDocs[] = $docPath;
            }
        }
        
        // Create navigation index
        $this->createNavigationIndex();
        
        // Verify link integrity
        $this->verifyLinks();
    }

    /**
     * Build a map of all documentation files
     */
    private function buildDocumentMap(string $basePath): void
    {
        $files = File::allFiles($basePath);
        
        foreach ($files as $file) {
            if ($file->getExtension() === 'md') {
                $relativePath = str_replace($basePath . '/', '', $file->getPathname());
                $content = File::get($file->getPathname());
                
                $this->documentMap[$file->getPathname()] = [
                    'path' => $file->getPathname(),
                    'relative' => $relativePath,
                    'title' => $this->extractTitle($content),
                    'topics' => $this->extractTopics($content),
                    'content' => $content
                ];
                
                // Store in RAG memory
                $this->ragMemory->remember(
                    $relativePath,
                    $content,
                    [
                        'type' => 'documentation',
                        'title' => $this->extractTitle($content),
                        'topics' => $this->extractTopics($content)
                    ]
                );
            }
        }
    }

    /**
     * Extract title from markdown content
     */
    private function extractTitle(string $content): string
    {
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }
        
        if (preg_match('/title:\s*(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }
        
        return 'Untitled';
    }

    /**
     * Extract main topics from content
     */
    private function extractTopics(string $content): array
    {
        $topics = [];
        
        // Extract headers
        preg_match_all('/^#{1,3}\s+(.+)$/m', $content, $matches);
        if (!empty($matches[1])) {
            $topics = array_merge($topics, $matches[1]);
        }
        
        // Extract key terms
        $keyTerms = ['feature', 'guide', 'tutorial', 'how to', 'setup', 'configuration'];
        foreach ($keyTerms as $term) {
            if (stripos($content, $term) !== false) {
                $topics[] = $term;
            }
        }
        
        return array_unique($topics);
    }

    /**
     * Process a single document to add cross-references
     */
    private function processDocument(string $docPath, array $docInfo): void
    {
        $content = $docInfo['content'];
        
        // Find related documents
        $relatedDocs = $this->findRelatedDocuments($docInfo);
        
        // Add cross-references
        $updatedContent = $this->addCrossReferences($content, $relatedDocs, $docInfo);
        
        // Add navigation
        $updatedContent = $this->addNavigation($updatedContent, $docInfo);
        
        // Save updated content
        File::put($docPath, $updatedContent);
    }

    /**
     * Find documents related to the current one
     */
    private function findRelatedDocuments(array $docInfo): array
    {
        $query = implode(' ', array_merge([$docInfo['title']], $docInfo['topics']));
        $results = $this->ragMemory->search($query, 5);
        
        // Filter out self-reference
        return array_filter($results, function($result) use ($docInfo) {
            return $result['metadata']['path'] ?? '' !== $docInfo['relative'];
        });
    }

    /**
     * Add cross-references to content
     */
    private function addCrossReferences(string $content, array $relatedDocs, array $docInfo): string
    {
        if (empty($relatedDocs)) {
            return $content;
        }

        $targetLang = $this->getTargetLanguage();
        $prompt = "Add cross-references to this documentation content.

Current document: {$docInfo['title']}
Current path: {$docInfo['relative']}

Related documents: " . json_encode($relatedDocs) . "

Content to update:
{$content}

Instructions:
1. Add natural inline links where topics are mentioned
2. Create a 'Related Topics' section at the end if appropriate
3. Use relative markdown links (e.g., [Text](../path/to/doc.md))
4. Maintain the original content structure
5. Keep all existing content
6. Write section headers and link text in {$targetLang} language

Return the updated content with cross-references:";

        $response = $this->chat(new UserMessage($prompt));
        return $response->getContent();
    }

    /**
     * Add navigation elements to content
     */
    private function addNavigation(string $content, array $docInfo): string
    {
        $pathParts = explode('/', $docInfo['relative']);
        $section = $pathParts[0] ?? '';
        
        // Find previous and next documents in the same section
        $sectionDocs = array_filter($this->documentMap, function($doc) use ($section) {
            return str_starts_with($doc['relative'], $section . '/');
        });
        
        // Sort by path
        uasort($sectionDocs, function($a, $b) {
            return strcmp($a['relative'], $b['relative']);
        });
        
        // Find current position
        $currentIndex = array_search($docInfo['path'], array_column($sectionDocs, 'path'));
        $prevDoc = null;
        $nextDoc = null;
        
        if ($currentIndex !== false) {
            $keys = array_keys($sectionDocs);
            if ($currentIndex > 0) {
                $prevDoc = $sectionDocs[$keys[$currentIndex - 1]];
            }
            if ($currentIndex < count($sectionDocs) - 1) {
                $nextDoc = $sectionDocs[$keys[$currentIndex + 1]];
            }
        }
        
        // Add breadcrumbs at the top
        $breadcrumbs = $this->generateBreadcrumbs($docInfo['relative']);
        if (!str_starts_with($content, $breadcrumbs)) {
            $content = $breadcrumbs . "\n\n" . $content;
        }
        
        // Add navigation at the bottom
        $navigation = $this->generateNavigation($prevDoc, $nextDoc);
        if (!str_contains($content, $navigation) && $navigation) {
            $content = $content . "\n\n---\n\n" . $navigation;
        }
        
        return $content;
    }

    /**
     * Generate breadcrumb navigation
     */
    private function generateBreadcrumbs(string $relativePath): string
    {
        $parts = explode('/', $relativePath);
        $breadcrumbs = ['[Home](../index.md)'];
        
        $currentPath = '';
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                // Last part is current page
                $breadcrumbs[] = $this->formatBreadcrumbPart($part);
            } else {
                $currentPath .= ($currentPath ? '/' : '') . $part;
                $breadcrumbs[] = '[' . $this->formatBreadcrumbPart($part) . '](../' . $currentPath . '/index.md)';
            }
        }
        
        return implode(' > ', $breadcrumbs);
    }

    /**
     * Format breadcrumb part
     */
    private function formatBreadcrumbPart(string $part): string
    {
        $part = str_replace('.md', '', $part);
        $part = str_replace('-', ' ', $part);
        return ucwords($part);
    }

    /**
     * Generate previous/next navigation
     */
    private function generateNavigation(?array $prevDoc, ?array $nextDoc): string
    {
        $nav = [];
        
        if ($prevDoc) {
            $nav[] = '⬅️ [' . $prevDoc['title'] . '](' . $this->getRelativePath($prevDoc['relative']) . ')';
        }
        
        if ($nextDoc) {
            $nav[] = '[' . $nextDoc['title'] . '](' . $this->getRelativePath($nextDoc['relative']) . ') ➡️';
        }
        
        if (empty($nav)) {
            return '';
        }
        
        return implode(' | ', $nav);
    }

    /**
     * Get relative path from current document
     */
    private function getRelativePath(string $targetPath): string
    {
        // Simple relative path calculation
        $targetParts = explode('/', $targetPath);
        $depth = count($targetParts) - 1;
        
        $prefix = str_repeat('../', $depth);
        return $prefix . $targetPath;
    }

    /**
     * Create a navigation index/sitemap
     */
    private function createNavigationIndex(): void
    {
        $targetLang = $this->getTargetLanguage();
        $prompt = "Create a well-organized sitemap.md file in {$targetLang} that:

1. Lists all documentation pages hierarchically
2. Groups by sections (getting-started, features, guides, etc.)
3. Shows the document structure clearly
4. Includes brief descriptions for each document
5. Uses proper markdown formatting
6. Write all content in {$targetLang} language

Document map: " . json_encode($this->documentMap) . "

Generate the sitemap content:";

        $response = $this->chat(new UserMessage($prompt));
        $sitemapContent = $response->getContent();
        
        // Save sitemap
        $sitemapPath = base_path('docs/user/sitemap.md');
        File::put($sitemapPath, $sitemapContent);
        
        // Store in memory
        $this->ragMemory->remember('sitemap.md', $sitemapContent, [
            'type' => 'navigation',
            'title' => 'Documentation Sitemap'
        ]);
    }

    /**
     * Verify all links are valid
     */
    private function verifyLinks(): array
    {
        $brokenLinks = [];
        
        foreach ($this->documentMap as $docInfo) {
            $content = File::get($docInfo['path']);
            
            // Find all markdown links
            preg_match_all('/\[([^\]]+)\]\(([^\)]+)\)/', $content, $matches);
            
            foreach ($matches[2] as $link) {
                // Skip external links
                if (str_starts_with($link, 'http')) {
                    continue;
                }
                
                // Skip anchors
                if (str_starts_with($link, '#')) {
                    continue;
                }
                
                // Resolve relative path
                $linkPath = dirname($docInfo['path']) . '/' . $link;
                $linkPath = realpath($linkPath);
                
                if (!$linkPath || !File::exists($linkPath)) {
                    $brokenLinks[] = [
                        'document' => $docInfo['relative'],
                        'link' => $link
                    ];
                }
            }
        }
        
        if (!empty($brokenLinks)) {
            // Log broken links
            $logContent = "Broken links found:\n" . json_encode($brokenLinks, JSON_PRETTY_PRINT);
            File::put(base_path('docs/user/broken-links.log'), $logContent);
        }
        
        return $brokenLinks;
    }
}