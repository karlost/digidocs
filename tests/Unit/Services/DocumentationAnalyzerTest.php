<?php

namespace Digihood\Digidocs\Tests\Unit\Services;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Services\DocumentationAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Support\Facades\File;

class DocumentationAnalyzerTest extends DigidocsTestCase
{
    private DocumentationAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyzer = new DocumentationAnalyzer();
    }

    #[Test]
    public function it_can_parse_code_structure()
    {
        $phpContent = '<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = [\'name\', \'email\'];
    private $internal = null;

    public function getName(): string
    {
        return $this->name;
    }

    private function helper(): void
    {
        // helper logic
    }
}';

        $structure = $this->analyzer->parseCodeStructure($phpContent);

        $this->assertArrayHasKey('classes', $structure);
        $this->assertCount(1, $structure['classes']);

        $class = $structure['classes'][0];
        $this->assertEquals('User', $class['name']);
        $this->assertEquals('Model', $class['extends']);
        $this->assertCount(2, $class['methods']);
        $this->assertCount(2, $class['properties']);
    }

    #[Test]
    public function it_can_analyze_existing_documentation()
    {
        $filePath = 'app/Models/User.php';
        $this->createTestFile($filePath, '<?php class User {}');

        // Vytvoř existující dokumentaci
        $docContent = '# User Model

This is the User model documentation.

## Methods

### getName()
Returns the user name.
';
        $this->createTestDocumentation('Models/User.md', $docContent);

        $result = $this->analyzer->analyzeExistingDocumentation($filePath);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayHasKey('content', $result);
        $this->assertArrayHasKey('documented_elements', $result);

        $this->assertStringContainsString('User Model', $result['content']);
    }

    #[Test]
    public function it_returns_null_for_missing_documentation()
    {
        $filePath = 'app/Models/NonExistent.php';
        $this->createTestFile($filePath, '<?php class NonExistent {}');

        $result = $this->analyzer->analyzeExistingDocumentation($filePath);

        $this->assertNull($result);
    }

    #[Test]
    public function it_can_calculate_documentation_relevance()
    {
        $oldStructure = [
            'classes' => [
                [
                    'name' => 'TestClass',
                    'methods' => [
                        ['name' => 'oldMethod', 'visibility' => 'public']
                    ]
                ]
            ]
        ];

        $newStructure = [
            'classes' => [
                [
                    'name' => 'TestClass',
                    'methods' => [
                        ['name' => 'oldMethod', 'visibility' => 'public'],
                        ['name' => 'newMethod', 'visibility' => 'public']
                    ]
                ]
            ]
        ];

        $codeChanges = [
            'old_structure' => $oldStructure,
            'new_structure' => $newStructure,
            'content_changed' => true
        ];

        $existingDoc = [
            'documented_elements' => [
                ['type' => 'class', 'name' => 'TestClass'],
                ['type' => 'method', 'name' => 'oldMethod']
            ]
        ];

        $relevanceScore = $this->analyzer->calculateDocumentationRelevance($codeChanges, $existingDoc);

        $this->assertGreaterThanOrEqual(50, $relevanceScore);
    }

    #[Test]
    public function it_gives_low_relevance_for_private_only_changes()
    {
        $oldStructure = [
            'classes' => [
                [
                    'name' => 'TestClass',
                    'methods' => [
                        ['name' => 'publicMethod', 'visibility' => 'public'],
                        ['name' => 'privateMethod', 'visibility' => 'private']
                    ]
                ]
            ]
        ];

        $newStructure = [
            'classes' => [
                [
                    'name' => 'TestClass',
                    'methods' => [
                        ['name' => 'publicMethod', 'visibility' => 'public'],
                        ['name' => 'privateMethod', 'visibility' => 'private'],
                        ['name' => 'anotherPrivateMethod', 'visibility' => 'private']
                    ]
                ]
            ]
        ];

        $codeChanges = [
            'old_structure' => $oldStructure,
            'new_structure' => $newStructure,
            'content_changed' => true
        ];

        $existingDoc = [
            'documented_elements' => [
                ['type' => 'method', 'name' => 'publicMethod']
            ]
        ];

        $relevanceScore = $this->analyzer->calculateDocumentationRelevance($codeChanges, $existingDoc);

        $this->assertLessThan(50, $relevanceScore);
    }

    #[Test]
    public function it_gives_maximum_relevance_for_missing_documentation()
    {
        $newStructure = [
            'classes' => [
                [
                    'name' => 'NewClass',
                    'methods' => [
                        ['name' => 'newMethod', 'visibility' => 'public']
                    ]
                ]
            ]
        ];

        $codeChanges = [
            'old_structure' => [],
            'new_structure' => $newStructure,
            'content_changed' => true
        ];

        $relevanceScore = $this->analyzer->calculateDocumentationRelevance($codeChanges, null);

        $this->assertEquals(100, $relevanceScore);
    }

    #[Test]
    public function it_can_extract_documented_elements()
    {
        $filePath = 'app/Models/TestClass.php';
        $this->createTestFile($filePath, '<?php class TestClass {}');

        $docContent = '# TestClass Documentation

## Classes

### TestClass
Main test class.

## Methods

### publicMethod()
Public method description.

### anotherMethod()
Another method description.

## Properties

### $property
Property description.
';
        $this->createTestDocumentation('Models/TestClass.md', $docContent);

        $result = $this->analyzer->analyzeExistingDocumentation($filePath);

        $this->assertNotNull($result);
        $this->assertArrayHasKey('documented_elements', $result);
        $this->assertNotEmpty($result['documented_elements']);
    }

    #[Test]
    public function it_handles_empty_documentation()
    {
        $filePath = 'app/Models/EmptyDoc.php';
        $this->createTestFile($filePath, '<?php class EmptyDoc {}');
        $this->createTestDocumentation('Models/EmptyDoc.md', '');

        $result = $this->analyzer->analyzeExistingDocumentation($filePath);
        $this->assertNull($result); // Prázdná dokumentace vrací null
    }

    #[Test]
    public function it_can_parse_complex_structures()
    {
        $phpContent = '<?php

namespace App\Services;

class ComplexService
{
    private $config;
    protected $logger;
    public $status;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function process(string $data): array
    {
        return [];
    }

    private function validate($input): bool
    {
        return true;
    }
}';

        $structure = $this->analyzer->parseCodeStructure($phpContent);

        $this->assertArrayHasKey('classes', $structure);
        $this->assertCount(1, $structure['classes']);

        $class = $structure['classes'][0];
        $this->assertEquals('ComplexService', $class['name']);
        $this->assertCount(3, $class['methods']);
        $this->assertCount(3, $class['properties']);
    }

    #[Test]
    public function it_can_analyze_interface_structures()
    {
        $phpContent = '<?php

namespace App\Contracts;

interface RepositoryInterface
{
    public function find(int $id): ?object;
    public function save(object $entity): bool;
}';

        $structure = $this->analyzer->parseCodeStructure($phpContent);

        $this->assertArrayHasKey('interfaces', $structure);
        $this->assertCount(1, $structure['interfaces']);

        $interface = $structure['interfaces'][0];
        $this->assertEquals('RepositoryInterface', $interface['name']);
        $this->assertCount(2, $interface['methods']);
    }

    #[Test]
    public function it_can_analyze_trait_structures()
    {
        $phpContent = '<?php

namespace App\Traits;

trait Timestampable
{
    public function touch(): void
    {
        $this->updated_at = now();
    }

    protected function getFormattedDate(): string
    {
        return $this->created_at->format(\'Y-m-d\');
    }
}';

        $structure = $this->analyzer->parseCodeStructure($phpContent);

        $this->assertArrayHasKey('traits', $structure);
        $this->assertCount(1, $structure['traits']);

        $trait = $structure['traits'][0];
        $this->assertEquals('Timestampable', $trait['name']);
        $this->assertCount(2, $trait['methods']);
    }

    #[Test]
    public function it_can_parse_functions()
    {
        $phpContent = '<?php

function globalFunction(string $param): array
{
    return [];
}

function anotherFunction(): void
{
    // do something
}';

        $structure = $this->analyzer->parseCodeStructure($phpContent);

        $this->assertArrayHasKey('functions', $structure);
        $this->assertCount(2, $structure['functions']);

        $function = $structure['functions'][0];
        $this->assertEquals('globalFunction', $function['name']);
        $this->assertArrayHasKey('parameters', $function);
    }
}
