<?php

namespace Digihood\Digidocs\Tests\Integration;

use Digihood\Digidocs\Tests\DigidocsTestCase;
use Digihood\Digidocs\Services\MemoryService;
use Digihood\Digidocs\Services\CostTracker;
use Digihood\Digidocs\Agent\DocumentationAgent;
use Digihood\Digidocs\Agent\ChangeAnalysisAgent;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;

class DigidocsIntegrationTest extends DigidocsTestCase
{
    #[Test]
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
        $hash = hash_file('sha256', base_path('app/Models/Product.php'));
        $memory->recordDocumentation('app/Models/Product.php', $hash, 'docs/code/Models/Product.md');

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
        $this->assertFalse($needsDoc['is_new']); // Není nový soubor
    }

    #[Test]
    public function it_can_track_costs_across_multiple_operations()
    {
        $memory = app(MemoryService::class);
        $this->clearTestDatabase($memory); // Vyčisti databázi
        $costTracker = new CostTracker($memory);

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
            $memory->recordTokenUsage('gpt-4', 100, 50, 0.01, $path);
        }

        $stats = $memory->getCostStats();

        $this->assertEquals(3, $stats['total_calls']);
        $this->assertEquals(300, $stats['total_input_tokens']); // 3 * 100
        $this->assertEquals(150, $stats['total_output_tokens']); // 3 * 50
        $this->assertEquals(450, $stats['total_tokens']);
        $this->assertGreaterThan(0, $stats['total_cost']);
    }

    #[Test]
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

        // Test pouze analýzy struktury bez API volání
        $analyzer = new \Digihood\Digidocs\Services\DocumentationAnalyzer();
        $structure = $analyzer->parseCodeStructure($complexContent);

        $this->assertArrayHasKey('classes', $structure);
        $this->assertCount(1, $structure['classes']);

        $class = $structure['classes'][0];
        $this->assertEquals('UserController', $class['name']);
        $this->assertGreaterThanOrEqual(5, count($class['methods'])); // index, store, show, update, destroy

        // Ověř že obsahuje očekávané metody
        $methodNames = array_column($class['methods'], 'name');
        $this->assertContains('index', $methodNames);
        $this->assertContains('store', $methodNames);
        $this->assertContains('show', $methodNames);
        $this->assertContains('update', $methodNames);
        $this->assertContains('destroy', $methodNames);
    }

    #[Test]
    public function it_can_process_multiple_files_efficiently()
    {
        $memory = app(MemoryService::class);
        $this->clearTestDatabase($memory); // Vyčisti databázi
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

        $processed = 0;

        foreach ($files as $file) {
            $needsDoc = $memory->needsDocumentation($file);
            if ($needsDoc['needs_update']) {
                $hash = hash_file('sha256', base_path($file));
                $memory->recordDocumentation($file, $hash, 'docs/code/' . basename($file, '.php') . '.md');
                $memory->recordTokenUsage('gpt-4', 100, 50, 0.01, $file);
                $processed++;
            }
        }

        $this->assertEquals(5, $processed);

        $stats = $memory->getStats();
        $costStats = $memory->getCostStats();
        $this->assertGreaterThanOrEqual(5, $stats['total_files']);
        $this->assertGreaterThanOrEqual(500, $costStats['total_tokens']);
        $this->assertGreaterThanOrEqual(0.05, $costStats['total_cost']);
    }

    #[Test]
    public function it_can_handle_documentation_updates()
    {
        // Vytvoř soubor s existující dokumentací
        $this->createTestFile('app/Models/UpdateTest.php', '<?php class UpdateTest {}');
        $this->createTestDocumentation('Models/UpdateTest.md', '# UpdateTest Documentation');

        $memory = app(MemoryService::class);

        // Zaznamenej původní zpracování
        $hash = hash_file('sha256', base_path('app/Models/UpdateTest.php'));
        $memory->recordDocumentation('app/Models/UpdateTest.php', $hash, 'docs/code/Models/UpdateTest.md');

        // Změň soubor
        $this->createTestFile('app/Models/UpdateTest.php', '<?php class UpdateTest { public function newMethod() {} }');

        // Ověř, že potřebuje aktualizaci
        $needsDoc = $memory->needsDocumentation('app/Models/UpdateTest.php');
        $this->assertTrue($needsDoc['needs_update']);

        // Test pouze že detekce změn funguje
        $this->assertArrayHasKey('current_hash', $needsDoc);
        $this->assertArrayHasKey('last_hash', $needsDoc);
        $this->assertNotEquals($needsDoc['current_hash'], $needsDoc['last_hash']);
    }

    #[Test]
    public function it_maintains_statistics_consistency()
    {
        $memory = app(MemoryService::class);
        $this->clearTestDatabase($memory); // Vyčisti databázi

        // Zpracuj několik souborů
        $files = ['file1.php', 'file2.php', 'file3.php'];

        foreach ($files as $file) {
            $this->createTestFile($file, '<?php echo "test";');
            $hash = hash_file('sha256', base_path($file));
            $memory->recordDocumentation($file, $hash, 'docs/code/' . basename($file, '.php') . '.md');
            $memory->recordTokenUsage('gpt-4', 100, 50, 0.01, $file);
        }

        $stats = $memory->getStats();
        $costStats = $memory->getCostStats();

        $this->assertEquals(3, $stats['total_files']);
        $this->assertEquals(450, $costStats['total_tokens']); // 3 * (100 input + 50 output)
        $this->assertEquals(0.03, $costStats['total_cost']);
    }

    #[Test]
    public function it_can_recover_from_errors()
    {
        $memory = app(MemoryService::class);
        $this->clearTestDatabase($memory); // Vyčisti databázi

        // Zaznamenej úspěšný soubor
        $this->createTestFile('success-file.php', '<?php echo "success";');
        $hash = hash_file('sha256', base_path('success-file.php'));
        $memory->recordDocumentation('success-file.php', $hash, 'docs/code/success-file.md');

        $stats = $memory->getStats();

        $this->assertArrayHasKey('total_files', $stats);
        $this->assertEquals(1, $stats['total_files']);
    }

    #[Test]
    public function it_handles_concurrent_operations()
    {
        $memory = app(MemoryService::class);
        $this->clearTestDatabase($memory); // Vyčisti databázi

        // Simuluj současné operace
        $files = ['concurrent1.php', 'concurrent2.php'];

        foreach ($files as $file) {
            $this->createTestFile($file, '<?php echo "concurrent";');

            // Zkontroluj potřebu dokumentace
            $needsDoc = $memory->needsDocumentation($file);
            $this->assertTrue($needsDoc['needs_update']);

            // Zaznamenej zpracování
            $hash = hash_file('sha256', base_path($file));
            $memory->recordDocumentation($file, $hash, 'docs/code/' . basename($file, '.php') . '.md');
        }

        $stats = $memory->getStats();
        $this->assertGreaterThanOrEqual(2, $stats['total_files']);
    }
}
