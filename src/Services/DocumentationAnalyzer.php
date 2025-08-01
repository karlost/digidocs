<?php

namespace Digihood\Digidocs\Services;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

class DocumentationAnalyzer
{
    private $parser;
    private $nodeFinder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder;
    }

    /**
     * Analyzuj existující dokumentaci pro soubor
     */
    public function analyzeExistingDocumentation(string $filePath): ?array
    {
        $docPath = $this->getDocumentationPath($filePath);

        if (!file_exists($docPath)) {
            return null;
        }

        $content = file_get_contents($docPath);
        if (empty($content)) {
            return null;
        }

        return [
            'path' => $docPath,
            'content' => $content,
            'size' => strlen($content),
            'sections' => $this->parseDocumentationSections($content),
            'last_modified' => filemtime($docPath),
            'documented_elements' => $this->extractDocumentedElements($content)
        ];
    }

    /**
     * Vypočítej skóre relevance dokumentace (0-100)
     */
    public function calculateDocumentationRelevance(
        array $codeChanges,
        ?array $existingDoc
    ): int {
        if (!$existingDoc) {
            return 100; // Žádná dokumentace = vždy generuj
        }

        $relevanceScore = 0;
        $maxScore = 100;

        // Kontrola změn ve veřejných API
        if ($this->hasPublicApiChanges($codeChanges)) {
            $relevanceScore += 40;
        }

        // Kontrola změn v dokumentovaných částech
        if ($this->affectsDocumentedParts($codeChanges, $existingDoc)) {
            $relevanceScore += 30;
        }

        // Kontrola strukturálních změn
        if ($this->hasStructuralChanges($codeChanges)) {
            $relevanceScore += 20;
        }

        // Kontrola změn v komentářích/docblocks
        if ($this->hasDocumentationChanges($codeChanges)) {
            $relevanceScore += 10;
        }

        return min($relevanceScore, $maxScore);
    }

    /**
     * Parsuj strukturu kódu z obsahu souboru
     */
    public function parseCodeStructure(string $content): array
    {
        try {
            $ast = $this->parser->parse($content);
            if (!$ast) {
                return [];
            }

            $structure = [
                'classes' => [],
                'functions' => [],
                'interfaces' => [],
                'traits' => []
            ];

            // Najdi třídy
            $classes = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class);
            foreach ($classes as $class) {
                $structure['classes'][] = $this->extractClassInfo($class);
            }

            // Najdi funkce
            $functions = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Function_::class);
            foreach ($functions as $function) {
                $structure['functions'][] = $this->extractFunctionInfo($function);
            }

            // Najdi interface
            $interfaces = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Interface_::class);
            foreach ($interfaces as $interface) {
                $structure['interfaces'][] = $this->extractInterfaceInfo($interface);
            }

            // Najdi traits
            $traits = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Trait_::class);
            foreach ($traits as $trait) {
                $structure['traits'][] = $this->extractTraitInfo($trait);
            }

            return $structure;

        } catch (Error $e) {
            \Log::warning("Failed to parse PHP code: " . $e->getMessage());
            return [
                'classes' => [],
                'functions' => [],
                'interfaces' => [],
                'traits' => []
            ];
        }
    }

    /**
     * Získej cestu k dokumentačnímu souboru
     */
    private function getDocumentationPath(string $filePath): string
    {
        $docsPath = config('digidocs.paths.docs');
        $relativePath = str_replace(['app/', '.php'], ['', '.md'], $filePath);
        return $docsPath . '/' . $relativePath;
    }

    /**
     * Parsuj sekce v dokumentaci
     */
    private function parseDocumentationSections(string $content): array
    {
        $sections = [];
        $lines = explode("\n", $content);
        $currentSection = null;

        foreach ($lines as $line) {
            if (preg_match('/^#+\s+(.+)$/', $line, $matches)) {
                $currentSection = trim($matches[1]);
                $sections[$currentSection] = [];
            } elseif ($currentSection && !empty(trim($line))) {
                $sections[$currentSection][] = $line;
            }
        }

        return $sections;
    }

    /**
     * Extrahuj dokumentované elementy z obsahu dokumentace
     */
    private function extractDocumentedElements(string $content): array
    {
        $elements = [];

        // Najdi zmínky o třídách, metodách, vlastnostech
        if (preg_match_all('/(?:class|třída)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $content, $matches)) {
            foreach ($matches[1] as $className) {
                $elements[] = ['type' => 'class', 'name' => $className];
            }
        }

        if (preg_match_all('/(?:method|metoda)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $content, $matches)) {
            foreach ($matches[1] as $methodName) {
                $elements[] = ['type' => 'method', 'name' => $methodName];
            }
        }

        if (preg_match_all('/`([A-Za-z_][A-Za-z0-9_]*)\([^)]*\)`/', $content, $matches)) {
            foreach ($matches[1] as $functionName) {
                $elements[] = ['type' => 'function', 'name' => $functionName];
            }
        }

        return $elements;
    }

    /**
     * Zkontroluj zda změny ovlivňují veřejné API
     */
    private function hasPublicApiChanges(array $codeChanges): bool
    {
        if (!isset($codeChanges['old_structure']) || !isset($codeChanges['new_structure'])) {
            return false;
        }

        $oldStructure = $codeChanges['old_structure'];
        $newStructure = $codeChanges['new_structure'];

        // Porovnej veřejné metody tříd
        foreach ($newStructure['classes'] ?? [] as $newClass) {
            $oldClass = $this->findClassByName($oldStructure['classes'] ?? [], $newClass['name']);

            if (!$oldClass) {
                return true; // Nová třída
            }

            if ($this->hasPublicMethodChanges($oldClass['methods'] ?? [], $newClass['methods'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zkontroluj zda změny ovlivňují dokumentované části
     */
    private function affectsDocumentedParts(array $codeChanges, array $existingDoc): bool
    {
        if (!isset($existingDoc['documented_elements'])) {
            return false;
        }

        $documentedElements = $existingDoc['documented_elements'];
        $newStructure = $codeChanges['new_structure'] ?? [];

        foreach ($documentedElements as $element) {
            if (!$this->elementExistsInStructure($element, $newStructure)) {
                return true; // Dokumentovaný element byl odstraněn
            }
        }

        return false;
    }

    /**
     * Zkontroluj strukturální změny
     */
    private function hasStructuralChanges(array $codeChanges): bool
    {
        if (!isset($codeChanges['old_structure']) || !isset($codeChanges['new_structure'])) {
            return false;
        }

        $oldStructure = $codeChanges['old_structure'];
        $newStructure = $codeChanges['new_structure'];

        // Porovnej počty elementů
        return (
            count($oldStructure['classes'] ?? []) !== count($newStructure['classes'] ?? []) ||
            count($oldStructure['functions'] ?? []) !== count($newStructure['functions'] ?? []) ||
            count($oldStructure['interfaces'] ?? []) !== count($newStructure['interfaces'] ?? []) ||
            count($oldStructure['traits'] ?? []) !== count($newStructure['traits'] ?? [])
        );
    }

    /**
     * Zkontroluj změny v dokumentaci/komentářích
     */
    private function hasDocumentationChanges(array $codeChanges): bool
    {
        // Jednoduchá kontrola - pokud se změnil obsah, možná se změnily komentáře
        return isset($codeChanges['content_changed']) && $codeChanges['content_changed'];
    }

    /**
     * Extrahuj informace o třídě
     */
    private function extractClassInfo(Node\Stmt\Class_ $class): array
    {
        $methods = [];
        $properties = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[] = [
                    'name' => $stmt->name->toString(),
                    'visibility' => $this->getVisibility($stmt),
                    'parameters' => $this->extractParameters($stmt->params),
                    'return_type' => $stmt->returnType ? $this->getTypeString($stmt->returnType) : null
                ];
            } elseif ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $properties[] = [
                        'name' => $prop->name->toString(),
                        'visibility' => $this->getVisibility($stmt)
                    ];
                }
            }
        }

        return [
            'name' => $class->name->toString(),
            'methods' => $methods,
            'properties' => $properties,
            'extends' => $class->extends ? $class->extends->toString() : null,
            'implements' => array_map(fn($i) => $i->toString(), $class->implements)
        ];
    }

    /**
     * Extrahuj informace o funkci
     */
    private function extractFunctionInfo(Node\Stmt\Function_ $function): array
    {
        return [
            'name' => $function->name->toString(),
            'parameters' => $this->extractParameters($function->params),
            'return_type' => $function->returnType ? $this->getTypeString($function->returnType) : null
        ];
    }

    /**
     * Extrahuj informace o interface
     */
    private function extractInterfaceInfo(Node\Stmt\Interface_ $interface): array
    {
        $methods = [];
        foreach ($interface->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[] = [
                    'name' => $stmt->name->toString(),
                    'parameters' => $this->extractParameters($stmt->params),
                    'return_type' => $stmt->returnType ? $this->getTypeString($stmt->returnType) : null
                ];
            }
        }

        return [
            'name' => $interface->name->toString(),
            'methods' => $methods,
            'extends' => array_map(fn($e) => $e->toString(), $interface->extends)
        ];
    }

    /**
     * Extrahuj informace o trait
     */
    private function extractTraitInfo(Node\Stmt\Trait_ $trait): array
    {
        $methods = [];
        foreach ($trait->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[] = [
                    'name' => $stmt->name->toString(),
                    'visibility' => $this->getVisibility($stmt),
                    'parameters' => $this->extractParameters($stmt->params),
                    'return_type' => $stmt->returnType ? $this->getTypeString($stmt->returnType) : null
                ];
            }
        }

        return [
            'name' => $trait->name->toString(),
            'methods' => $methods
        ];
    }

    /**
     * Získej viditelnost metody/vlastnosti
     */
    private function getVisibility($node): string
    {
        if ($node->isPublic()) return 'public';
        if ($node->isProtected()) return 'protected';
        if ($node->isPrivate()) return 'private';
        return 'public'; // default
    }

    /**
     * Extrahuj parametry funkce/metody
     */
    private function extractParameters(array $params): array
    {
        $parameters = [];
        foreach ($params as $param) {
            $parameters[] = [
                'name' => $param->var->name,
                'type' => $param->type ? $this->getTypeString($param->type) : null,
                'default' => $param->default !== null
            ];
        }
        return $parameters;
    }

    /**
     * Najdi třídu podle jména
     */
    private function findClassByName(array $classes, string $name): ?array
    {
        foreach ($classes as $class) {
            if ($class['name'] === $name) {
                return $class;
            }
        }
        return null;
    }

    /**
     * Zkontroluj změny ve veřejných metodách
     */
    private function hasPublicMethodChanges(array $oldMethods, array $newMethods): bool
    {
        $oldPublicMethods = array_filter($oldMethods, fn($m) => ($m['visibility'] ?? 'public') === 'public');
        $newPublicMethods = array_filter($newMethods, fn($m) => ($m['visibility'] ?? 'public') === 'public');

        return count($oldPublicMethods) !== count($newPublicMethods);
    }

    /**
     * Zkontroluj zda element existuje ve struktuře
     */
    private function elementExistsInStructure(array $element, array $structure): bool
    {
        $type = $element['type'];
        $name = $element['name'];

        switch ($type) {
            case 'class':
                return $this->findClassByName($structure['classes'] ?? [], $name) !== null;
            case 'function':
                foreach ($structure['functions'] ?? [] as $func) {
                    if ($func['name'] === $name) return true;
                }
                return false;
            default:
                return true; // Neznámý typ - předpokládej že existuje
        }
    }

    /**
     * Bezpečně získá string reprezentaci typu z PhpParser Node
     */
    private function getTypeString($type): string
    {
        if ($type === null) {
            return '';
        }

        // Pokud má metodu toString(), použij ji
        if (method_exists($type, 'toString')) {
            return $type->toString();
        }

        // Pro NullableType
        if ($type instanceof \PhpParser\Node\NullableType) {
            return '?' . $this->getTypeString($type->type);
        }

        // Pro UnionType (PHP 8.0+)
        if ($type instanceof \PhpParser\Node\UnionType) {
            $types = array_map(fn($t) => $this->getTypeString($t), $type->types);
            return implode('|', $types);
        }

        // Pro IntersectionType (PHP 8.1+)
        if (class_exists('\PhpParser\Node\IntersectionType') && $type instanceof \PhpParser\Node\IntersectionType) {
            $types = array_map(fn($t) => $this->getTypeString($t), $type->types);
            return implode('&', $types);
        }

        // Pro Identifier
        if ($type instanceof \PhpParser\Node\Identifier) {
            return $type->name;
        }

        // Pro Name (qualified names)
        if ($type instanceof \PhpParser\Node\Name) {
            return $type->toString();
        }

        // Fallback - pokus se převést na string
        if (is_object($type) && method_exists($type, '__toString')) {
            return (string) $type;
        }

        // Poslední možnost - vrať název třídy
        return get_class($type);
    }

    /**
     * Analyzuj existující user dokumentaci pro soubor
     */
    public function analyzeExistingUserDocumentation(string $filePath): ?array
    {
        $userDocPath = $this->getUserDocumentationPath($filePath);

        if (!file_exists($userDocPath)) {
            return null;
        }

        $content = file_get_contents($userDocPath);
        if (empty($content)) {
            return null;
        }

        return [
            'path' => $userDocPath,
            'content' => $content,
            'size' => strlen($content),
            'sections' => $this->parseDocumentationSections($content),
            'last_modified' => filemtime($userDocPath),
            'user_features' => $this->extractUserFeatures($content),
            'workflows' => $this->extractWorkflows($content),
            'user_actions' => $this->extractUserActions($content)
        ];
    }

    /**
     * Získaj cestu k user dokumentácii
     */
    private function getUserDocumentationPath(string $filePath): string
    {
        $userDocsPath = config('digidocs.paths.user_docs', 'docs/user');
        
        // Převeď cestu souboru na cestu user dokumentace
        $relativePath = str_replace(['app/', '.php'], ['', '.md'], $filePath);
        
        return base_path($userDocsPath . '/' . $relativePath);
    }

    /**
     * Extrahuj user features z dokumentace
     */
    private function extractUserFeatures(string $content): array
    {
        $features = [];
        
        // Hledej sekce s user features
        if (preg_match_all('/##\s*([^#\n]+)/i', $content, $matches)) {
            foreach ($matches[1] as $feature) {
                $feature = trim($feature);
                if (!empty($feature)) {
                    $features[] = $feature;
                }
            }
        }
        
        return $features;
    }

    /**
     * Extrahuj workflows z dokumentace
     */
    private function extractWorkflows(string $content): array
    {
        $workflows = [];
        
        // Hledej sekce s kroky
        if (preg_match_all('/###\s*([^#\n]+).*?\n((?:\d+\.\s+[^\n]+\n?)+)/s', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $title = trim($match[1]);
                $stepsText = $match[2];
                
                $steps = [];
                if (preg_match_all('/\d+\.\s+([^\n]+)/', $stepsText, $stepMatches)) {
                    $steps = array_map('trim', $stepMatches[1]);
                }
                
                if (!empty($steps)) {
                    $workflows[] = [
                        'title' => $title,
                        'steps' => $steps
                    ];
                }
            }
        }
        
        return $workflows;
    }

    /**
     * Extrahuj user actions z dokumentace
     */
    private function extractUserActions(string $content): array
    {
        $actions = [];
        
        // Hledej bullet points s akcemi
        if (preg_match_all('/^-\s+([^\n]+)/m', $content, $matches)) {
            foreach ($matches[1] as $action) {
                $action = trim($action);
                if (!empty($action)) {
                    $actions[] = $action;
                }
            }
        }
        
        return $actions;
    }
}
