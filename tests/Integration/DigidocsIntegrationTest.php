<?php

namespace Digihood\Digidocs\Tests\Integration;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Services\MemoryService;
use Digihood\Digidocs\Services\CostTracker;
use Digihood\Digidocs\Agent\DocumentationAgent;
use Digihood\Digidocs\Agent\ChangeAnalysisAgent;
use Illuminate\Support\Facades\File;

class DigidocsIntegrationTest extends DigidocsTestCase
{
    /** @test */
    public function it_can_process_complete_documentation_workflow()
    {
        // Vytvoř testovací PHP soubor
        $content = '<?php

namespace App\Services;

use App\Models\User;

/**
 * User management service
 */
class UserService
{
    /**
     * Create new user
     */
    public function create(array $data): User
    {
        return User::create($data);
    }
    
    /**
     * Update existing user
     */
    public function update(User $user, array $data): bool
    {
        return $user->update($data);
    }
    
    /**
     * Delete user
     */
    public function delete(User $user): bool
    {
        return $user->delete();
    }
}';

        $this->createTestFile('app/Services/UserService.php', $content);

        // Test celého workflow
        $memory = app(MemoryService::class);
        $changeAgent = new ChangeAnalysisAgent();
        
        // 1. Zkontroluj, zda soubor potřebuje dokumentaci
        $needsDoc = $memory->needsDocumentation('app/Services/UserService.php');
        $this->assertTrue($needsDoc['needs_update']);
        $this->assertEquals('new_file', $needsDoc['reason']);
        
        // 2. Vygeneruj dokumentaci pomocí agenta
        $documentation = $changeAgent->generateDocumentationIfNeeded('app/Services/UserService.php');
        $this->assertNotNull($documentation);
        $this->assertStringContainsString('UserService', $documentation);
        
        // 3. Zaznamenej zpracování
        $memory->recordProcessing('app/Services/UserService.php', 'generated', [
            'tokens_used' => 150,
            'cost' => 0.02
        ]);
        
        // 4. Ověř, že soubor už nepotřebuje aktualizaci
        $needsDocAfter = $memory->needsDocumentation('app/Services/UserService.php');
        $this->assertFalse($needsDocAfter['needs_update']);
        $this->assertEquals('up_to_date', $needsDocAfter['reason']);
    }

    /** @test */
    public function it_can_detect_and_process_file_changes()
    {
        // Vytvoř původní soubor
        $originalContent = '<?php

namespace App\Models;

class Product
{
    protected $fillable = [\'name\'];
    
    public function getName(): string
    {
        return $this->name;
    }
}';

        $this->createTestFile('app/Models/Product.php', $originalContent);
        
        $memory = app(MemoryService::class);
        
        // Zaznamenej původní zpracování
        $memory->recordProcessing('app/Models/Product.php', 'generated');
        
        // Změň soubor
        $modifiedContent = '<?php

namespace App\Models;

class Product
{
    protected $fillable = [\'name\', \'price\'];
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function getPrice(): float
    {
        return $this->price;
    }
}';

        $this->createTestFile('app/Models/Product.php', $modifiedContent);
        
        // Ověř detekci změny
        $needsDoc = $memory->needsDocumentation('app/Models/Product.php');
        $this->assertTrue($needsDoc['needs_update']);
        $this->assertEquals('file_changed', $needsDoc['reason']);
    }

    /** @test */
    public function it_can_track_costs_across_multiple_operations()
    {
        $costTracker = new CostTracker();
        
        // Simuluj několik operací
        $files = [
            'app/Models/User.php' => '<?php class User {}',
            'app/Models/Product.php' => '<?php class Product {}',
            'app/Services/UserService.php' => '<?php class UserService {}'
        ];
        
        foreach ($files as $path => $content) {
            $this->createTestFile($path, $content);
            
            // Simuluj AI operaci
            $agent = new DocumentationAgent();
            $agent->setCostTracker($costTracker);
            
            // Zde by normálně proběhla AI operace
            // Pro test jen přidáme mock data
            $costTracker->addTokens(100, 50, 'gpt-4');
        }
        
        $stats = $costTracker->getStats();
        
        $this->assertEquals(3, $stats['total_requests']);
        $this->assertEquals(300, $stats['input_tokens']); // 3 * 100
        $this->assertEquals(150, $stats['output_tokens']); // 3 * 50
        $this->assertEquals(450, $stats['total_tokens']);
        $this->assertGreaterThan(0, $stats['estimated_cost']);
    }

    /** @test */
    public function it_can_handle_complex_class_structures()
    {
        $complexContent = '<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\User;
use App\Services\UserService;
use App\Http\Requests\CreateUserRequest;

/**
 * User controller for managing users
 */
class UserController extends Controller
{
    private UserService $userService;
    
    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }
    
    /**
     * Display users list
     */
    public function index(Request $request): Response
    {
        $users = User::paginate(10);
        return response()->json($users);
    }
    
    /**
     * Store new user
     */
    public function store(CreateUserRequest $request): Response
    {
        $user = $this->userService->create($request->validated());
        return response()->json($user, 201);
    }
    
    /**
     * Show specific user
     */
    public function show(User $user): Response
    {
        return response()->json($user);
    }
    
    /**
     * Update user
     */
    public function update(CreateUserRequest $request, User $user): Response
    {
        $updated = $this->userService->update($user, $request->validated());
        return response()->json($updated);
    }
    
    /**
     * Delete user
     */
    public function destroy(User $user): Response
    {
        $this->userService->delete($user);
        return response()->json(null, 204);
    }
    
    /**
     * Private helper method
     */
    private function validateUser(array $data): bool
    {
        return !empty($data[\'email\']);
    }
}';

        $this->createTestFile('app/Http/Controllers/UserController.php', $complexContent);
        
        $changeAgent = new ChangeAnalysisAgent();
        $documentation = $changeAgent->generateDocumentationIfNeeded('app/Http/Controllers/UserController.php');
        
        $this->assertNotNull($documentation);
        $this->assertStringContainsString('UserController', $documentation);
        $this->assertStringContainsString('index', $documentation);
        $this->assertStringContainsString('store', $documentation);
        $this->assertStringContainsString('show', $documentation);
        $this->assertStringContainsString('update', $documentation);
        $this->assertStringContainsString('destroy', $documentation);
    }

    /** @test */
    public function it_can_process_multiple_files_efficiently()
    {
        $files = [];
        
        // Vytvoř více testovacích souborů
        for ($i = 1; $i <= 5; $i++) {
            $content = "<?php

namespace App\\Models;

class TestModel{$i}
{
    protected \$fillable = ['name'];
    
    public function getName(): string
    {
        return \$this->name;
    }
    
    public function method{$i}(): string
    {
        return 'method{$i}';
    }
}";
            
            $path = "app/Models/TestModel{$i}.php";
            $this->createTestFile($path, $content);
            $files[] = $path;
        }
        
        $memory = app(MemoryService::class);
        $processed = 0;
        
        foreach ($files as $file) {
            $needsDoc = $memory->needsDocumentation($file);
            if ($needsDoc['needs_update']) {
                $memory->recordProcessing($file, 'generated', [
                    'tokens_used' => 100,
                    'cost' => 0.01
                ]);
                $processed++;
            }
        }
        
        $this->assertEquals(5, $processed);
        
        $stats = $memory->getStats();
        $this->assertGreaterThanOrEqual(5, $stats['total_files']);
        $this->assertGreaterThanOrEqual(500, $stats['total_tokens']);
        $this->assertGreaterThanOrEqual(0.05, $stats['total_cost']);
    }

    /** @test */
    public function it_can_handle_documentation_updates()
    {
        // Vytvoř soubor s existující dokumentací
        $this->createTestFile('app/Models/UpdateTest.php', '<?php class UpdateTest {}');
        $this->createTestDocumentation('Models/UpdateTest.md', '# UpdateTest Documentation');
        
        $memory = app(MemoryService::class);
        
        // Zaznamenej původní zpracování
        $memory->recordProcessing('app/Models/UpdateTest.php', 'generated');
        
        // Změň soubor
        $this->createTestFile('app/Models/UpdateTest.php', '<?php class UpdateTest { public function newMethod() {} }');
        
        // Ověř, že potřebuje aktualizaci
        $needsDoc = $memory->needsDocumentation('app/Models/UpdateTest.php');
        $this->assertTrue($needsDoc['needs_update']);
        
        // Aktualizuj dokumentaci
        $changeAgent = new ChangeAnalysisAgent();
        $newDoc = $changeAgent->generateDocumentationIfNeeded('app/Models/UpdateTest.php');
        
        $this->assertNotNull($newDoc);
        $this->assertStringContainsString('UpdateTest', $newDoc);
    }

    /** @test */
    public function it_maintains_statistics_consistency()
    {
        $memory = app(MemoryService::class);
        
        // Zpracuj několik souborů
        $files = ['file1.php', 'file2.php', 'file3.php'];
        
        foreach ($files as $file) {
            $this->createTestFile($file, '<?php echo "test";');
            $memory->recordProcessing($file, 'generated', [
                'tokens_used' => 100,
                'cost' => 0.01
            ]);
        }
        
        $stats = $memory->getStats();
        
        $this->assertEquals(3, $stats['total_files']);
        $this->assertEquals(300, $stats['total_tokens']);
        $this->assertEquals(0.03, $stats['total_cost']);
        
        // Ověř breakdown
        $this->assertArrayHasKey('status_breakdown', $stats);
        $this->assertEquals(3, $stats['status_breakdown']['generated']);
    }

    /** @test */
    public function it_can_recover_from_errors()
    {
        $memory = app(MemoryService::class);
        
        // Zaznamenej chybný soubor
        $memory->recordProcessing('error-file.php', 'error', [
            'error' => 'Parse error'
        ]);
        
        // Zaznamenej úspěšný soubor
        $this->createTestFile('success-file.php', '<?php echo "success";');
        $memory->recordProcessing('success-file.php', 'generated');
        
        $stats = $memory->getStats();
        
        $this->assertArrayHasKey('status_breakdown', $stats);
        $this->assertEquals(1, $stats['status_breakdown']['error']);
        $this->assertEquals(1, $stats['status_breakdown']['generated']);
    }

    /** @test */
    public function it_handles_concurrent_operations()
    {
        $memory = app(MemoryService::class);
        
        // Simuluj současné operace
        $files = ['concurrent1.php', 'concurrent2.php'];
        
        foreach ($files as $file) {
            $this->createTestFile($file, '<?php echo "concurrent";');
            
            // Zkontroluj potřebu dokumentace
            $needsDoc = $memory->needsDocumentation($file);
            $this->assertTrue($needsDoc['needs_update']);
            
            // Zaznamenej zpracování
            $memory->recordProcessing($file, 'generated');
        }
        
        $stats = $memory->getStats();
        $this->assertGreaterThanOrEqual(2, $stats['total_files']);
    }
}
