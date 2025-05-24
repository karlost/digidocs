<?php

namespace Digihood\Digidocs\Tests\Feature;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Agent\ChangeAnalysisAgent;
use Digihood\Digidocs\Services\DocumentationAnalyzer;
use Digihood\Digidocs\Services\MemoryService;
use Illuminate\Support\Facades\File;

class ChangeAnalysisAgentTest extends DigidocsTestCase
{
    private ChangeAnalysisAgent $agent;
    private DocumentationAnalyzer $analyzer;
    private MemoryService $memory;
    private string $testFilePath;
    private string $testDocPath;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->agent = new ChangeAnalysisAgent();
        $this->analyzer = new DocumentationAnalyzer();
        $this->memory = new MemoryService();
        
        $this->testFilePath = 'app/Models/TestModel.php';
        $this->testDocPath = base_path('docs/code/Models/TestModel.md');
        
        // Zajisti existenci testovacích adresářů
        File::makeDirectory(dirname(base_path($this->testFilePath)), 0755, true, true);
        File::makeDirectory(dirname($this->testDocPath), 0755, true, true);
    }

    protected function tearDown(): void
    {
        // Vyčisti testovací soubory
        if (File::exists(base_path($this->testFilePath))) {
            File::delete(base_path($this->testFilePath));
        }
        if (File::exists($this->testDocPath)) {
            File::delete($this->testDocPath);
        }
        
        parent::tearDown();
    }

    /** @test */
    public function it_can_analyze_new_file()
    {
        // Vytvoř nový PHP soubor
        $phpContent = '<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TestModel extends Model
{
    protected $fillable = [\'name\', \'email\'];
    
    public function getName(): string
    {
        return $this->name;
    }
    
    private function internalMethod(): void
    {
        // private logic
    }
}';

        File::put(base_path($this->testFilePath), $phpContent);

        // Test analýzy nového souboru
        $result = $this->agent->generateDocumentationIfNeeded($this->testFilePath);

        $this->assertNotNull($result, 'Nový soubor by měl vygenerovat dokumentaci');
        $this->assertStringContainsString('TestModel', $result);
    }

    /** @test */
    public function it_can_parse_code_structure()
    {
        $phpContent = '<?php

namespace App\Models;

class TestModel
{
    public $publicProperty;
    private $privateProperty;
    
    public function publicMethod(string $param): string
    {
        return $param;
    }
    
    private function privateMethod(): void
    {
        // private logic
    }
}';

        $structure = $this->analyzer->parseCodeStructure($phpContent);

        $this->assertArrayHasKey('classes', $structure);
        $this->assertCount(1, $structure['classes']);
        
        $class = $structure['classes'][0];
        $this->assertEquals('TestModel', $class['name']);
        $this->assertCount(2, $class['methods']);
        $this->assertCount(2, $class['properties']);
    }

    /** @test */
    public function it_detects_public_api_changes()
    {
        $oldContent = '<?php
class TestClass
{
    public function oldMethod(): string
    {
        return "old";
    }
}';

        $newContent = '<?php
class TestClass
{
    public function oldMethod(): string
    {
        return "old";
    }
    
    public function newMethod(): string
    {
        return "new";
    }
}';

        File::put(base_path($this->testFilePath), $newContent);

        // Simuluj existující dokumentaci
        $existingDoc = [
            'path' => $this->testDocPath,
            'content' => '# TestClass Documentation',
            'documented_elements' => [
                ['type' => 'class', 'name' => 'TestClass'],
                ['type' => 'method', 'name' => 'oldMethod']
            ]
        ];

        File::put($this->testDocPath, $existingDoc['content']);

        // Test detekce změn ve veřejném API
        $oldStructure = $this->analyzer->parseCodeStructure($oldContent);
        $newStructure = $this->analyzer->parseCodeStructure($newContent);

        $codeChanges = [
            'old_structure' => $oldStructure,
            'new_structure' => $newStructure,
            'content_changed' => true
        ];

        $relevanceScore = $this->analyzer->calculateDocumentationRelevance($codeChanges, $existingDoc);

        $this->assertGreaterThan(50, $relevanceScore, 'Přidání nové veřejné metody by mělo mít vysoké skóre relevance');
    }

    /** @test */
    public function it_skips_private_only_changes()
    {
        $oldContent = '<?php
class TestClass
{
    public function publicMethod(): string
    {
        return $this->privateMethod();
    }
    
    private function privateMethod(): string
    {
        return "old private";
    }
}';

        $newContent = '<?php
class TestClass
{
    public function publicMethod(): string
    {
        return $this->privateMethod();
    }
    
    private function privateMethod(): string
    {
        return "new private logic";
    }
    
    private function anotherPrivateMethod(): void
    {
        // new private method
    }
}';

        File::put(base_path($this->testFilePath), $newContent);

        // Simuluj existující dokumentaci
        $existingDoc = [
            'path' => $this->testDocPath,
            'content' => '# TestClass Documentation',
            'documented_elements' => [
                ['type' => 'class', 'name' => 'TestClass'],
                ['type' => 'method', 'name' => 'publicMethod']
            ]
        ];

        File::put($this->testDocPath, $existingDoc['content']);

        $oldStructure = $this->analyzer->parseCodeStructure($oldContent);
        $newStructure = $this->analyzer->parseCodeStructure($newContent);

        $codeChanges = [
            'old_structure' => $oldStructure,
            'new_structure' => $newStructure,
            'content_changed' => true
        ];

        $relevanceScore = $this->analyzer->calculateDocumentationRelevance($codeChanges, $existingDoc);

        // Pouze privátní změny by měly mít nižší skóre relevance
        $this->assertLessThan(50, $relevanceScore, 'Pouze privátní změny by měly mít nízké skóre relevance');
    }

    /** @test */
    public function it_handles_missing_documentation()
    {
        $phpContent = '<?php
class TestClass
{
    public function testMethod(): string
    {
        return "test";
    }
}';

        File::put(base_path($this->testFilePath), $phpContent);

        // Test bez existující dokumentace
        $existingDoc = $this->analyzer->analyzeExistingDocumentation($this->testFilePath);
        $this->assertNull($existingDoc, 'Neexistující dokumentace by měla vrátit null');

        $structure = $this->analyzer->parseCodeStructure($phpContent);
        $codeChanges = [
            'old_structure' => [],
            'new_structure' => $structure,
            'content_changed' => true
        ];

        $relevanceScore = $this->analyzer->calculateDocumentationRelevance($codeChanges, null);
        $this->assertEquals(100, $relevanceScore, 'Chybějící dokumentace by měla mít maximální skóre');
    }

    /** @test */
    public function it_records_documented_code_parts()
    {
        $phpContent = '<?php
namespace App\Models;

class TestModel
{
    public $name;
    private $internal;
    
    public function getName(): string
    {
        return $this->name;
    }
    
    private function privateMethod(): void
    {
        // private
    }
}';

        File::put(base_path($this->testFilePath), $phpContent);

        $structure = $this->analyzer->parseCodeStructure($phpContent);
        
        // Simuluj zaznamenání dokumentovaných částí
        $codeParts = [];
        foreach ($structure['classes'] as $class) {
            $codeParts[] = [
                'type' => 'class',
                'name' => $class['name'],
                'signature' => 'class ' . $class['name'],
                'section' => 'Classes'
            ];
            
            foreach ($class['methods'] as $method) {
                if (($method['visibility'] ?? 'public') === 'public') {
                    $codeParts[] = [
                        'type' => 'method',
                        'name' => $class['name'] . '::' . $method['name'],
                        'signature' => 'public function ' . $method['name'] . '()',
                        'section' => 'Methods'
                    ];
                }
            }
        }

        $this->memory->recordDocumentedCodeParts($this->testFilePath, $codeParts);
        $recorded = $this->memory->getDocumentedCodeParts($this->testFilePath);

        $this->assertNotEmpty($recorded, 'Dokumentované části by měly být zaznamenané');
        $this->assertCount(2, $recorded, 'Měly by být zaznamenané 2 části (třída + veřejná metoda)');
    }
}
