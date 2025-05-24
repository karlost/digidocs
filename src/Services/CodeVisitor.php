<?php

namespace Digihood\Digidocs\Services;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Use_;

class CodeVisitor extends NodeVisitorAbstract
{
    private ?string $namespace = null;
    private array $classes = [];
    private array $methods = [];
    private array $properties = [];
    private array $imports = [];
    private array $existingDocs = [];

    public function enterNode(Node $node)
    {
        // Namespace
        if ($node instanceof Namespace_) {
            $this->namespace = $node->name ? $node->name->toString() : null;
        }

        // Imports (use statements)
        if ($node instanceof Use_) {
            foreach ($node->uses as $use) {
                $this->imports[] = [
                    'name' => $use->name->toString(),
                    'alias' => $use->alias ? $use->alias->toString() : null
                ];
            }
        }

        // Classes
        if ($node instanceof Class_) {
            $className = $node->name->toString();
            
            $classInfo = [
                'name' => $className,
                'extends' => $node->extends ? $node->extends->toString() : null,
                'implements' => array_map(fn($interface) => $interface->toString(), $node->implements),
                'is_abstract' => $node->isAbstract(),
                'is_final' => $node->isFinal(),
                'docblock' => $this->extractDocComment($node),
                'line' => $node->getStartLine(),
            ];

            $this->classes[] = $classInfo;
        }

        // Methods
        if ($node instanceof ClassMethod) {
            $methodInfo = [
                'name' => $node->name->toString(),
                'is_public' => $node->isPublic(),
                'is_protected' => $node->isProtected(),
                'is_private' => $node->isPrivate(),
                'is_static' => $node->isStatic(),
                'is_abstract' => $node->isAbstract(),
                'is_final' => $node->isFinal(),
                'parameters' => $this->extractParameters($node),
                'return_type' => $node->returnType ? $node->returnType->toString() : null,
                'docblock' => $this->extractDocComment($node),
                'line' => $node->getStartLine(),
            ];

            $this->methods[] = $methodInfo;
        }

        // Properties
        if ($node instanceof Property) {
            foreach ($node->props as $prop) {
                $propertyInfo = [
                    'name' => $prop->name->toString(),
                    'is_public' => $node->isPublic(),
                    'is_protected' => $node->isProtected(),
                    'is_private' => $node->isPrivate(),
                    'is_static' => $node->isStatic(),
                    'type' => $node->type ? $node->type->toString() : null,
                    'default' => $prop->default ? $this->nodeToString($prop->default) : null,
                    'docblock' => $this->extractDocComment($node),
                    'line' => $node->getStartLine(),
                ];

                $this->properties[] = $propertyInfo;
            }
        }

        return null;
    }

    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    public function getClasses(): array
    {
        return $this->classes;
    }

    public function getMethods(): array
    {
        return $this->methods;
    }

    public function getProperties(): array
    {
        return $this->properties;
    }

    public function getImports(): array
    {
        return $this->imports;
    }

    public function getExistingDocs(): array
    {
        return $this->existingDocs;
    }

    /**
     * Extrahuje parametry metody
     */
    private function extractParameters(ClassMethod $method): array
    {
        $parameters = [];
        
        foreach ($method->params as $param) {
            $paramInfo = [
                'name' => $param->var->name,
                'type' => $param->type ? $param->type->toString() : null,
                'is_nullable' => $param->type && $param->type instanceof Node\NullableType,
                'has_default' => $param->default !== null,
                'default' => $param->default ? $this->nodeToString($param->default) : null,
                'is_variadic' => $param->variadic,
                'is_reference' => $param->byRef,
            ];

            $parameters[] = $paramInfo;
        }

        return $parameters;
    }

    /**
     * Extrahuje doc comment z nodu
     */
    private function extractDocComment(Node $node): ?array
    {
        $docComment = $node->getDocComment();
        
        if (!$docComment) {
            return null;
        }

        $text = $docComment->getText();
        
        return [
            'raw' => $text,
            'summary' => $this->extractSummary($text),
            'description' => $this->extractDescription($text),
            'tags' => $this->extractTags($text),
        ];
    }

    /**
     * Extrahuje summary z docblocku
     */
    private function extractSummary(string $docblock): ?string
    {
        $lines = explode("\n", $docblock);
        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");
            if (!empty($line) && !str_starts_with($line, '@')) {
                return $line;
            }
        }
        return null;
    }

    /**
     * Extrahuje description z docblocku
     */
    private function extractDescription(string $docblock): ?string
    {
        $lines = explode("\n", $docblock);
        $description = [];
        $inDescription = false;

        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");
            
            if (empty($line)) {
                if ($inDescription) {
                    $description[] = '';
                }
                continue;
            }

            if (str_starts_with($line, '@')) {
                break;
            }

            if ($inDescription || !empty($description)) {
                $description[] = $line;
                $inDescription = true;
            } else {
                // První neprázdný řádek je summary, druhý začíná description
                $inDescription = true;
            }
        }

        return !empty($description) ? implode("\n", $description) : null;
    }

    /**
     * Extrahuje tagy z docblocku
     */
    private function extractTags(string $docblock): array
    {
        $lines = explode("\n", $docblock);
        $tags = [];

        foreach ($lines as $line) {
            $line = trim($line, " \t\n\r\0\x0B/*");
            
            if (str_starts_with($line, '@')) {
                if (preg_match('/^@(\w+)(?:\s+(.*))?$/', $line, $matches)) {
                    $tagName = $matches[1];
                    $tagValue = $matches[2] ?? '';
                    
                    if (!isset($tags[$tagName])) {
                        $tags[$tagName] = [];
                    }
                    
                    $tags[$tagName][] = $tagValue;
                }
            }
        }

        return $tags;
    }

    /**
     * Převede AST node na string reprezentaci
     */
    private function nodeToString(Node $node): string
    {
        // Jednoduchá implementace pro základní typy
        if ($node instanceof Node\Scalar\String_) {
            return "'{$node->value}'";
        }
        
        if ($node instanceof Node\Scalar\LNumber) {
            return (string) $node->value;
        }
        
        if ($node instanceof Node\Scalar\DNumber) {
            return (string) $node->value;
        }
        
        if ($node instanceof Node\Expr\ConstFetch) {
            return $node->name->toString();
        }

        if ($node instanceof Node\Expr\Array_) {
            return 'array(...)';
        }

        return 'mixed';
    }
}
