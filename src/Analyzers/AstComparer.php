<?php

namespace Digihood\Digidocs\Analyzers;

use PhpParser\ParserFactory;
use PhpParser\Error;
use Exception;

class AstComparer
{
    public function __invoke(string $old_content, string $new_content, ?string $file_path = null): array
    {
        try {
            $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);

            // Parse both versions
            $oldAst = $parser->parse($old_content);
            $newAst = $parser->parse($new_content);

            if (!$oldAst || !$newAst) {
                return [
                    'status' => 'error',
                    'error' => 'Failed to parse PHP code',
                    'file_path' => $file_path
                ];
            }

            // Extract structures from both ASTs
            $oldStructure = $this->extractStructure($oldAst);
            $newStructure = $this->extractStructure($newAst);

            // Compare structures
            $comparison = $this->compareStructures($oldStructure, $newStructure);

            return [
                'status' => 'success',
                'file_path' => $file_path,
                'old_structure' => $oldStructure,
                'new_structure' => $newStructure,
                'comparison' => $comparison,
                'has_structural_changes' => $comparison['has_changes'],
                'change_summary' => $this->generateChangeSummary($comparison)
            ];

        } catch (Error $e) {
            return [
                'status' => 'error',
                'error' => 'PHP Parse Error: ' . $e->getMessage(),
                'file_path' => $file_path
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'file_path' => $file_path
            ];
        }
    }

    /**
     * Extrahuje strukturu z AST
     */
    private function extractStructure(array $ast): array
    {
        $structure = [
            'namespace' => null,
            'uses' => [],
            'classes' => [],
            'interfaces' => [],
            'traits' => [],
            'functions' => [],
            'constants' => []
        ];

        $this->traverseNodes($ast, $structure);

        return $structure;
    }

    /**
     * Prochází AST uzly a extrahuje strukturu
     */
    private function traverseNodes(array $nodes, array &$structure, string $currentClass = null): void
    {
        foreach ($nodes as $node) {
            if (!is_object($node)) {
                continue;
            }

            $nodeType = $node->getType();

            switch ($nodeType) {
                case 'Stmt_Namespace':
                    $structure['namespace'] = $node->name ? $node->name->toString() : null;
                    if ($node->stmts) {
                        $this->traverseNodes($node->stmts, $structure, $currentClass);
                    }
                    break;

                case 'Stmt_Use':
                    foreach ($node->uses as $use) {
                        $structure['uses'][] = $use->name->toString();
                    }
                    break;

                case 'Stmt_Class':
                    $className = $node->name->toString();
                    $classInfo = [
                        'name' => $className,
                        'extends' => $node->extends ? $node->extends->toString() : null,
                        'implements' => [],
                        'methods' => [],
                        'properties' => [],
                        'constants' => [],
                        'is_abstract' => $node->isAbstract(),
                        'is_final' => $node->isFinal()
                    ];

                    if ($node->implements) {
                        foreach ($node->implements as $implement) {
                            $classInfo['implements'][] = $implement->toString();
                        }
                    }

                    $this->extractClassMembers($node->stmts, $classInfo);
                    $structure['classes'][$className] = $classInfo;
                    break;

                case 'Stmt_Interface':
                    $interfaceName = $node->name->toString();
                    $interfaceInfo = [
                        'name' => $interfaceName,
                        'extends' => [],
                        'methods' => [],
                        'constants' => []
                    ];

                    if ($node->extends) {
                        foreach ($node->extends as $extend) {
                            $interfaceInfo['extends'][] = $extend->toString();
                        }
                    }

                    $this->extractClassMembers($node->stmts, $interfaceInfo);
                    $structure['interfaces'][$interfaceName] = $interfaceInfo;
                    break;

                case 'Stmt_Trait':
                    $traitName = $node->name->toString();
                    $traitInfo = [
                        'name' => $traitName,
                        'methods' => [],
                        'properties' => []
                    ];

                    $this->extractClassMembers($node->stmts, $traitInfo);
                    $structure['traits'][$traitName] = $traitInfo;
                    break;

                case 'Stmt_Function':
                    $functionInfo = [
                        'name' => $node->name->toString(),
                        'parameters' => $this->extractParameters($node->params),
                        'return_type' => $node->returnType ? $this->getTypeString($node->returnType) : null,
                        'is_reference' => $node->byRef
                    ];
                    $structure['functions'][] = $functionInfo;
                    break;

                case 'Stmt_Const':
                    foreach ($node->consts as $const) {
                        $structure['constants'][] = [
                            'name' => $const->name->toString(),
                            'value' => $this->getValueString($const->value)
                        ];
                    }
                    break;
            }
        }
    }

    /**
     * Extrahuje členy třídy (metody, vlastnosti, konstanty)
     */
    private function extractClassMembers(array $stmts, array &$classInfo): void
    {
        foreach ($stmts as $stmt) {
            if (!is_object($stmt)) {
                continue;
            }

            switch ($stmt->getType()) {
                case 'Stmt_ClassMethod':
                    $methodInfo = [
                        'name' => $stmt->name->toString(),
                        'visibility' => $this->getVisibility($stmt),
                        'is_static' => $stmt->isStatic(),
                        'is_abstract' => $stmt->isAbstract(),
                        'is_final' => $stmt->isFinal(),
                        'parameters' => $this->extractParameters($stmt->params),
                        'return_type' => $stmt->returnType ? $this->getTypeString($stmt->returnType) : null
                    ];
                    $classInfo['methods'][] = $methodInfo;
                    break;

                case 'Stmt_Property':
                    foreach ($stmt->props as $prop) {
                        $propertyInfo = [
                            'name' => $prop->name->toString(),
                            'visibility' => $this->getVisibility($stmt),
                            'is_static' => $stmt->isStatic(),
                            'type' => $stmt->type ? $this->getTypeString($stmt->type) : null,
                            'default' => $prop->default ? $this->getValueString($prop->default) : null
                        ];
                        $classInfo['properties'][] = $propertyInfo;
                    }
                    break;

                case 'Stmt_ClassConst':
                    foreach ($stmt->consts as $const) {
                        $constantInfo = [
                            'name' => $const->name->toString(),
                            'visibility' => $this->getVisibility($stmt),
                            'value' => $this->getValueString($const->value)
                        ];
                        $classInfo['constants'][] = $constantInfo;
                    }
                    break;
            }
        }
    }

    /**
     * Extrahuje parametry funkce/metody
     */
    private function extractParameters(array $params): array
    {
        $parameters = [];

        foreach ($params as $param) {
            $paramInfo = [
                'name' => $param->var->name,
                'type' => $param->type ? $this->getTypeString($param->type) : null,
                'default' => $param->default ? $this->getValueString($param->default) : null,
                'is_reference' => $param->byRef,
                'is_variadic' => $param->variadic
            ];
            $parameters[] = $paramInfo;
        }

        return $parameters;
    }

    /**
     * Získá viditelnost (public, private, protected)
     */
    private function getVisibility($node): string
    {
        if ($node->isPublic()) return 'public';
        if ($node->isPrivate()) return 'private';
        if ($node->isProtected()) return 'protected';
        return 'public'; // default
    }

    /**
     * Převede typ na string
     */
    private function getTypeString($type): string
    {
        if (is_string($type)) {
            return $type;
        }

        if (method_exists($type, 'toString')) {
            return $type->toString();
        }

        if (isset($type->name)) {
            return $type->name;
        }

        return 'mixed';
    }

    /**
     * Převede hodnotu na string
     */
    private function getValueString($value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (method_exists($value, 'toString')) {
            return $value->toString();
        }

        return 'unknown';
    }

    /**
     * Porovná dvě struktury a najde rozdíly
     */
    private function compareStructures(array $oldStructure, array $newStructure): array
    {
        $comparison = [
            'has_changes' => false,
            'namespace_changed' => $oldStructure['namespace'] !== $newStructure['namespace'],
            'uses_changes' => $this->compareArrays($oldStructure['uses'], $newStructure['uses']),
            'classes_changes' => $this->compareClasses($oldStructure['classes'], $newStructure['classes']),
            'interfaces_changes' => $this->compareInterfaces($oldStructure['interfaces'], $newStructure['interfaces']),
            'traits_changes' => $this->compareTraits($oldStructure['traits'], $newStructure['traits']),
            'functions_changes' => $this->compareFunctions($oldStructure['functions'], $newStructure['functions']),
            'constants_changes' => $this->compareConstants($oldStructure['constants'], $newStructure['constants'])
        ];

        // Zkontroluj jestli jsou nějaké změny
        $comparison['has_changes'] = $comparison['namespace_changed'] ||
                                   $comparison['uses_changes']['has_changes'] ||
                                   $comparison['classes_changes']['has_changes'] ||
                                   $comparison['interfaces_changes']['has_changes'] ||
                                   $comparison['traits_changes']['has_changes'] ||
                                   $comparison['functions_changes']['has_changes'] ||
                                   $comparison['constants_changes']['has_changes'];

        return $comparison;
    }

    /**
     * Porovná pole hodnot
     */
    private function compareArrays(array $old, array $new): array
    {
        $added = array_diff($new, $old);
        $removed = array_diff($old, $new);

        return [
            'has_changes' => !empty($added) || !empty($removed),
            'added' => array_values($added),
            'removed' => array_values($removed)
        ];
    }

    /**
     * Porovná třídy
     */
    private function compareClasses(array $oldClasses, array $newClasses): array
    {
        $changes = [
            'has_changes' => false,
            'added' => [],
            'removed' => [],
            'modified' => []
        ];

        // Najdi přidané a odebrané třídy
        $oldNames = array_keys($oldClasses);
        $newNames = array_keys($newClasses);

        $changes['added'] = array_diff($newNames, $oldNames);
        $changes['removed'] = array_diff($oldNames, $newNames);

        // Porovnej existující třídy
        $common = array_intersect($oldNames, $newNames);
        foreach ($common as $className) {
            $classChanges = $this->compareClass($oldClasses[$className], $newClasses[$className]);
            if ($classChanges['has_changes']) {
                $changes['modified'][$className] = $classChanges;
            }
        }

        $changes['has_changes'] = !empty($changes['added']) ||
                                !empty($changes['removed']) ||
                                !empty($changes['modified']);

        return $changes;
    }

    /**
     * Porovná jednu třídu
     */
    private function compareClass(array $oldClass, array $newClass): array
    {
        $changes = [
            'has_changes' => false,
            'extends_changed' => $oldClass['extends'] !== $newClass['extends'],
            'implements_changes' => $this->compareArrays($oldClass['implements'], $newClass['implements']),
            'methods_changes' => $this->compareMethods($oldClass['methods'], $newClass['methods']),
            'properties_changes' => $this->compareProperties($oldClass['properties'], $newClass['properties']),
            'constants_changes' => $this->compareConstants($oldClass['constants'], $newClass['constants']),
            'modifiers_changed' => [
                'abstract' => $oldClass['is_abstract'] !== $newClass['is_abstract'],
                'final' => $oldClass['is_final'] !== $newClass['is_final']
            ]
        ];

        $changes['has_changes'] = $changes['extends_changed'] ||
                                $changes['implements_changes']['has_changes'] ||
                                $changes['methods_changes']['has_changes'] ||
                                $changes['properties_changes']['has_changes'] ||
                                $changes['constants_changes']['has_changes'] ||
                                $changes['modifiers_changed']['abstract'] ||
                                $changes['modifiers_changed']['final'];

        return $changes;
    }

    /**
     * Porovná metody
     */
    private function compareMethods(array $oldMethods, array $newMethods): array
    {
        $changes = [
            'has_changes' => false,
            'added' => [],
            'removed' => [],
            'modified' => []
        ];

        // Vytvoř mapy podle jmen metod
        $oldMap = [];
        $newMap = [];

        foreach ($oldMethods as $method) {
            $oldMap[$method['name']] = $method;
        }

        foreach ($newMethods as $method) {
            $newMap[$method['name']] = $method;
        }

        $oldNames = array_keys($oldMap);
        $newNames = array_keys($newMap);

        $changes['added'] = array_diff($newNames, $oldNames);
        $changes['removed'] = array_diff($oldNames, $newNames);

        // Porovnej existující metody
        $common = array_intersect($oldNames, $newNames);
        foreach ($common as $methodName) {
            if ($this->methodsAreDifferent($oldMap[$methodName], $newMap[$methodName])) {
                $changes['modified'][] = $methodName;
            }
        }

        $changes['has_changes'] = !empty($changes['added']) ||
                                !empty($changes['removed']) ||
                                !empty($changes['modified']);

        return $changes;
    }

    /**
     * Kontroluje jestli se metody liší
     */
    private function methodsAreDifferent(array $oldMethod, array $newMethod): bool
    {
        return $oldMethod['visibility'] !== $newMethod['visibility'] ||
               $oldMethod['is_static'] !== $newMethod['is_static'] ||
               $oldMethod['is_abstract'] !== $newMethod['is_abstract'] ||
               $oldMethod['is_final'] !== $newMethod['is_final'] ||
               $oldMethod['return_type'] !== $newMethod['return_type'] ||
               $this->parametersAreDifferent($oldMethod['parameters'], $newMethod['parameters']);
    }

    /**
     * Kontroluje jestli se parametry liší
     */
    private function parametersAreDifferent(array $oldParams, array $newParams): bool
    {
        if (count($oldParams) !== count($newParams)) {
            return true;
        }

        for ($i = 0; $i < count($oldParams); $i++) {
            $old = $oldParams[$i];
            $new = $newParams[$i];

            if ($old['name'] !== $new['name'] ||
                $old['type'] !== $new['type'] ||
                $old['default'] !== $new['default'] ||
                $old['is_reference'] !== $new['is_reference'] ||
                $old['is_variadic'] !== $new['is_variadic']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Porovná vlastnosti
     */
    private function compareProperties(array $oldProperties, array $newProperties): array
    {
        // Podobná logika jako u metod
        $changes = [
            'has_changes' => false,
            'added' => [],
            'removed' => [],
            'modified' => []
        ];

        $oldMap = [];
        $newMap = [];

        foreach ($oldProperties as $prop) {
            $oldMap[$prop['name']] = $prop;
        }

        foreach ($newProperties as $prop) {
            $newMap[$prop['name']] = $prop;
        }

        $oldNames = array_keys($oldMap);
        $newNames = array_keys($newMap);

        $changes['added'] = array_diff($newNames, $oldNames);
        $changes['removed'] = array_diff($oldNames, $newNames);

        $common = array_intersect($oldNames, $newNames);
        foreach ($common as $propName) {
            if ($this->propertiesAreDifferent($oldMap[$propName], $newMap[$propName])) {
                $changes['modified'][] = $propName;
            }
        }

        $changes['has_changes'] = !empty($changes['added']) ||
                                !empty($changes['removed']) ||
                                !empty($changes['modified']);

        return $changes;
    }

    /**
     * Kontroluje jestli se vlastnosti liší
     */
    private function propertiesAreDifferent(array $oldProp, array $newProp): bool
    {
        return $oldProp['visibility'] !== $newProp['visibility'] ||
               $oldProp['is_static'] !== $newProp['is_static'] ||
               $oldProp['type'] !== $newProp['type'] ||
               $oldProp['default'] !== $newProp['default'];
    }

    /**
     * Porovná rozhraní
     */
    private function compareInterfaces(array $oldInterfaces, array $newInterfaces): array
    {
        // Podobná logika jako u tříd, ale jednodušší
        return $this->compareClasses($oldInterfaces, $newInterfaces);
    }

    /**
     * Porovná traity
     */
    private function compareTraits(array $oldTraits, array $newTraits): array
    {
        // Podobná logika jako u tříd, ale jednodušší
        return $this->compareClasses($oldTraits, $newTraits);
    }

    /**
     * Porovná funkce
     */
    private function compareFunctions(array $oldFunctions, array $newFunctions): array
    {
        return $this->compareMethods($oldFunctions, $newFunctions);
    }

    /**
     * Porovná konstanty
     */
    private function compareConstants(array $oldConstants, array $newConstants): array
    {
        return $this->compareProperties($oldConstants, $newConstants);
    }

    /**
     * Generuje shrnutí změn
     */
    private function generateChangeSummary(array $comparison): array
    {
        $summary = [
            'total_changes' => 0,
            'change_types' => [],
            'severity' => 'none'
        ];

        if ($comparison['namespace_changed']) {
            $summary['change_types'][] = 'namespace';
            $summary['total_changes']++;
        }

        if ($comparison['uses_changes']['has_changes']) {
            $summary['change_types'][] = 'imports';
            $summary['total_changes']++;
        }

        if ($comparison['classes_changes']['has_changes']) {
            $summary['change_types'][] = 'classes';
            $summary['total_changes']++;
        }

        if ($comparison['interfaces_changes']['has_changes']) {
            $summary['change_types'][] = 'interfaces';
            $summary['total_changes']++;
        }

        if ($comparison['traits_changes']['has_changes']) {
            $summary['change_types'][] = 'traits';
            $summary['total_changes']++;
        }

        if ($comparison['functions_changes']['has_changes']) {
            $summary['change_types'][] = 'functions';
            $summary['total_changes']++;
        }

        if ($comparison['constants_changes']['has_changes']) {
            $summary['change_types'][] = 'constants';
            $summary['total_changes']++;
        }

        // Určení závažnosti
        if ($summary['total_changes'] === 0) {
            $summary['severity'] = 'none';
        } elseif (in_array('classes', $summary['change_types']) ||
                  in_array('interfaces', $summary['change_types']) ||
                  in_array('functions', $summary['change_types'])) {
            $summary['severity'] = 'major';
        } elseif (in_array('imports', $summary['change_types']) ||
                  in_array('constants', $summary['change_types'])) {
            $summary['severity'] = 'minor';
        } else {
            $summary['severity'] = 'minimal';
        }

        return $summary;
    }
}
