<?php

namespace Digihood\Digidocs\Services;

use Illuminate\Support\Facades\File;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\Node;
use Digihood\Digidocs\Analyzers\CodeAnalyzer;

class DocumentationStructureAnalyzer
{
    private CodeAnalyzer $codeAnalyzer;
    private array $appStructure = [];

    public function __construct()
    {
        $this->codeAnalyzer = new CodeAnalyzer();
    }

    /**
     * Analyze the entire application structure
     */
    public function analyzeApplication(): array
    {
        $this->appStructure = [
            'features' => $this->identifyFeatures(),
            'user_roles' => $this->identifyUserRoles(),
            'workflows' => $this->identifyWorkflows(),
            'integrations' => $this->identifyIntegrations(),
            'settings' => $this->identifySettings(),
            'routes' => $this->analyzeRoutes(),
            'models' => $this->analyzeModels(),
        ];

        return $this->appStructure;
    }

    /**
     * Identify application features from controllers and routes
     */
    private function identifyFeatures(): array
    {
        $features = [];
        
        // Analyze controllers
        $controllersPath = app_path('Http/Controllers');
        $controllers = $this->findPhpFiles($controllersPath);
        
        foreach ($controllers as $controllerFile) {
            $relativePath = str_replace(base_path() . '/', '', $controllerFile);
            $analysis = ($this->codeAnalyzer)($relativePath);
            
            if (!empty($analysis['classes'])) {
                foreach ($analysis['classes'] as $class) {
                    $feature = $this->extractFeatureFromController($class, $relativePath);
                    if ($feature) {
                        $features[] = $feature;
                    }
                }
            }
        }

        // Group related features
        $features = $this->groupRelatedFeatures($features);
        
        return $features;
    }

    /**
     * Extract feature information from controller class
     */
    private function extractFeatureFromController(array $classInfo, string $filePath): ?array
    {
        // Skip base controllers
        if (in_array($classInfo['name'], ['Controller', 'BaseController'])) {
            return null;
        }

        $featureName = $this->humanizeControllerName($classInfo['name']);
        $userActions = [];
        $endpoints = [];

        foreach ($classInfo['methods'] ?? [] as $method) {
            // Skip constructor and private methods
            if ($method['name'] === '__construct' || ($method['visibility'] ?? 'public') !== 'public') {
                continue;
            }

            // Standard REST actions
            $restActions = [
                'index' => 'zobrazit seznam',
                'create' => 'vytvořit nový',
                'store' => 'uložit nový',
                'show' => 'zobrazit detail',
                'edit' => 'upravit',
                'update' => 'aktualizovat',
                'destroy' => 'smazat',
                'search' => 'vyhledávat',
                'filter' => 'filtrovat'
            ];

            $actionName = $restActions[$method['name']] ?? $this->humanizeMethodName($method['name']);
            
            $userActions[] = [
                'name' => $actionName,
                'method' => $method['name'],
                'description' => $this->extractMethodDescription($method),
                'parameters' => $this->extractMethodParameters($method),
            ];

            $endpoints[] = [
                'method' => $this->guessHttpMethod($method['name']),
                'action' => $method['name'],
                'path' => $this->guessEndpointPath($classInfo['name'], $method['name']),
            ];
        }

        return [
            'name' => $featureName,
            'controller' => $classInfo['name'],
            'file_path' => $filePath,
            'description' => $this->generateFeatureDescription($classInfo['name'], $userActions),
            'user_actions' => $userActions,
            'endpoints' => $endpoints,
            'category' => $this->categorizeFeature($classInfo['name'], $filePath),
            'user_facing' => $this->isUserFacingController($classInfo['name'], $filePath),
        ];
    }

    /**
     * Identify user roles from the application
     */
    private function identifyUserRoles(): array
    {
        $roles = [
            [
                'name' => 'guest',
                'title' => 'Nepřihlášený uživatel',
                'description' => 'Návštěvník bez účtu',
                'capabilities' => ['browse_products', 'search', 'view_public_content']
            ],
            [
                'name' => 'buyer',
                'title' => 'Kupující',
                'description' => 'Registrovaný uživatel nakupující produkty',
                'capabilities' => ['make_purchases', 'manage_orders', 'write_reviews', 'manage_profile']
            ],
            [
                'name' => 'seller',
                'title' => 'Prodejce',
                'description' => 'Uživatel prodávající produkty',
                'capabilities' => ['manage_products', 'process_orders', 'view_analytics', 'manage_inventory']
            ],
            [
                'name' => 'admin',
                'title' => 'Administrátor',
                'description' => 'Správce systému',
                'capabilities' => ['manage_users', 'system_settings', 'view_reports', 'moderate_content']
            ]
        ];

        // Try to find actual roles from config or database
        if (File::exists(config_path('roles.php'))) {
            $configRoles = include config_path('roles.php');
            if (is_array($configRoles)) {
                $roles = array_merge($roles, $configRoles);
            }
        }

        return $roles;
    }

    /**
     * Identify main workflows in the application
     */
    private function identifyWorkflows(): array
    {
        return [
            [
                'name' => 'purchase_flow',
                'title' => 'Nákupní proces',
                'steps' => [
                    'browse_products' => 'Procházení produktů',
                    'add_to_cart' => 'Přidání do košíku',
                    'checkout' => 'Přechod k platbě',
                    'payment' => 'Platba',
                    'confirmation' => 'Potvrzení objednávky',
                    'tracking' => 'Sledování objednávky'
                ]
            ],
            [
                'name' => 'registration_flow',
                'title' => 'Registrační proces',
                'steps' => [
                    'fill_form' => 'Vyplnění registračního formuláře',
                    'email_verification' => 'Ověření e-mailu',
                    'profile_setup' => 'Nastavení profilu',
                    'welcome' => 'Uvítací obrazovka'
                ]
            ],
            [
                'name' => 'product_management',
                'title' => 'Správa produktů',
                'steps' => [
                    'create_product' => 'Vytvoření produktu',
                    'add_details' => 'Doplnění detailů',
                    'set_pricing' => 'Nastavení cen',
                    'publish' => 'Publikování',
                    'manage_stock' => 'Správa skladu'
                ]
            ]
        ];
    }

    /**
     * Identify external integrations
     */
    private function identifyIntegrations(): array
    {
        $integrations = [];
        
        // Check composer.json for common packages
        $composerPath = base_path('composer.json');
        if (File::exists($composerPath)) {
            $composer = json_decode(File::get($composerPath), true);
            $packages = array_merge(
                array_keys($composer['require'] ?? []),
                array_keys($composer['require-dev'] ?? [])
            );

            // Common integrations
            $integrationMap = [
                'stripe' => ['name' => 'Stripe', 'type' => 'payment', 'description' => 'Platební brána'],
                'paypal' => ['name' => 'PayPal', 'type' => 'payment', 'description' => 'Platební systém'],
                'mailgun' => ['name' => 'Mailgun', 'type' => 'email', 'description' => 'E-mailová služba'],
                'aws' => ['name' => 'AWS', 'type' => 'cloud', 'description' => 'Cloud služby'],
                'pusher' => ['name' => 'Pusher', 'type' => 'realtime', 'description' => 'Real-time notifikace'],
            ];

            foreach ($packages as $package) {
                foreach ($integrationMap as $key => $integration) {
                    if (str_contains($package, $key)) {
                        $integrations[] = $integration;
                    }
                }
            }
        }

        return $integrations;
    }

    /**
     * Identify available settings
     */
    private function identifySettings(): array
    {
        $settings = [
            [
                'category' => 'user_preferences',
                'title' => 'Uživatelské předvolby',
                'settings' => [
                    'language' => 'Jazyk rozhraní',
                    'timezone' => 'Časová zóna',
                    'notifications' => 'Nastavení notifikací',
                    'privacy' => 'Soukromí a viditelnost profilu'
                ]
            ],
            [
                'category' => 'security',
                'title' => 'Zabezpečení',
                'settings' => [
                    'two_factor' => 'Dvoufaktorové ověření',
                    'sessions' => 'Správa aktivních relací',
                    'api_tokens' => 'API tokeny',
                    'password_policy' => 'Pravidla pro hesla'
                ]
            ]
        ];

        // Check for config files
        $configFiles = File::files(config_path());
        foreach ($configFiles as $file) {
            if (str_contains($file->getFilename(), 'settings')) {
                // Parse settings from config
                $config = include $file->getPathname();
                if (is_array($config)) {
                    $settings[] = [
                        'category' => str_replace('.php', '', $file->getFilename()),
                        'title' => $this->humanizeFileName($file->getFilename()),
                        'settings' => $this->flattenConfig($config)
                    ];
                }
            }
        }

        return $settings;
    }

    /**
     * Analyze routes
     */
    private function analyzeRoutes(): array
    {
        $routes = [];
        
        // Analyze web routes
        $webRoutes = base_path('routes/web.php');
        if (File::exists($webRoutes)) {
            $routes['web'] = $this->parseRouteFile($webRoutes);
        }

        // Analyze API routes
        $apiRoutes = base_path('routes/api.php');
        if (File::exists($apiRoutes)) {
            $routes['api'] = $this->parseRouteFile($apiRoutes);
        }

        return $routes;
    }

    /**
     * Analyze models
     */
    private function analyzeModels(): array
    {
        $models = [];
        $modelsPath = app_path('Models');
        
        if (File::isDirectory($modelsPath)) {
            $modelFiles = $this->findPhpFiles($modelsPath);
            
            foreach ($modelFiles as $modelFile) {
                $relativePath = str_replace(base_path() . '/', '', $modelFile);
                $analysis = ($this->codeAnalyzer)($relativePath);
                
                if (!empty($analysis['classes'])) {
                    foreach ($analysis['classes'] as $class) {
                        $models[] = [
                            'name' => $class['name'],
                            'table' => $this->guessTableName($class['name']),
                            'relationships' => $this->extractRelationships($class),
                            'fillable' => $this->extractFillable($class),
                            'business_logic' => $this->extractBusinessLogic($class),
                        ];
                    }
                }
            }
        }

        return $models;
    }

    /**
     * Helper methods
     */
    private function findPhpFiles(string $directory): array
    {
        $files = [];
        
        if (File::isDirectory($directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        
        return $files;
    }

    private function humanizeControllerName(string $name): string
    {
        $name = str_replace('Controller', '', $name);
        $name = preg_replace('/(?<!^)[A-Z]/', ' $0', $name);
        return trim($name);
    }

    private function humanizeMethodName(string $name): string
    {
        $name = preg_replace('/(?<!^)[A-Z]/', ' $0', $name);
        return strtolower(trim($name));
    }

    private function humanizeFileName(string $filename): string
    {
        $name = str_replace(['.php', '_', '-'], ['', ' ', ' '], $filename);
        return ucfirst(trim($name));
    }

    private function categorizeFeature(string $controllerName, string $filePath): string
    {
        if (str_contains($filePath, '/Api/')) {
            return 'api';
        }
        if (str_contains($controllerName, 'Admin')) {
            return 'admin';
        }
        if (str_contains($controllerName, 'Product')) {
            return 'products';
        }
        if (str_contains($controllerName, 'Order')) {
            return 'orders';
        }
        if (str_contains($controllerName, 'User') || str_contains($controllerName, 'Profile')) {
            return 'user';
        }
        
        return 'general';
    }

    private function isUserFacingController(string $controllerName, string $filePath): bool
    {
        // Admin and API controllers might not be directly user-facing
        if (str_contains($filePath, '/Admin/') || str_contains($controllerName, 'Admin')) {
            return false;
        }
        
        // Internal controllers
        if (in_array($controllerName, ['BaseController', 'Controller', 'ApiController'])) {
            return false;
        }
        
        return true;
    }

    private function groupRelatedFeatures(array $features): array
    {
        $grouped = [];
        
        foreach ($features as $feature) {
            $category = $feature['category'];
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $feature;
        }
        
        // Flatten back with category info
        $result = [];
        foreach ($grouped as $category => $categoryFeatures) {
            foreach ($categoryFeatures as $feature) {
                $feature['feature_group'] = $category;
                $result[] = $feature;
            }
        }
        
        return $result;
    }

    private function guessHttpMethod(string $methodName): string
    {
        $methodMap = [
            'index' => 'GET',
            'show' => 'GET',
            'create' => 'GET',
            'store' => 'POST',
            'edit' => 'GET',
            'update' => 'PUT',
            'destroy' => 'DELETE',
            'search' => 'GET',
        ];
        
        return $methodMap[$methodName] ?? 'GET';
    }

    private function guessEndpointPath(string $controllerName, string $methodName): string
    {
        $resource = strtolower(str_replace('Controller', '', $controllerName));
        $resource = \Illuminate\Support\Str::plural($resource);
        
        $pathMap = [
            'index' => "/{$resource}",
            'show' => "/{$resource}/{id}",
            'create' => "/{$resource}/create",
            'store' => "/{$resource}",
            'edit' => "/{$resource}/{id}/edit",
            'update' => "/{$resource}/{id}",
            'destroy' => "/{$resource}/{id}",
        ];
        
        return $pathMap[$methodName] ?? "/{$resource}/{$methodName}";
    }

    private function extractMethodDescription(array $method): string
    {
        // This would parse PHPDoc comments in a real implementation
        return "Funkce pro " . $this->humanizeMethodName($method['name']);
    }

    private function extractMethodParameters(array $method): array
    {
        return $method['parameters'] ?? [];
    }

    private function generateFeatureDescription(string $controllerName, array $actions): string
    {
        $name = $this->humanizeControllerName($controllerName);
        $actionNames = array_map(fn($a) => $a['name'], $actions);
        
        return "Správa {$name} - umožňuje " . implode(', ', array_slice($actionNames, 0, 3)) . 
               (count($actionNames) > 3 ? ' a další' : '');
    }

    private function parseRouteFile(string $file): array
    {
        // Simple parsing - in real implementation would use Route facade
        $content = File::get($file);
        $routes = [];
        
        // Match Route:: definitions
        preg_match_all('/Route::(\w+)\([\'"]([^\'"]+)[\'"]/', $content, $matches);
        
        for ($i = 0; $i < count($matches[0]); $i++) {
            $routes[] = [
                'method' => strtoupper($matches[1][$i]),
                'path' => $matches[2][$i],
            ];
        }
        
        return $routes;
    }

    private function guessTableName(string $modelName): string
    {
        return \Illuminate\Support\Str::snake(\Illuminate\Support\Str::plural($modelName));
    }

    private function extractRelationships(array $class): array
    {
        $relationships = [];
        
        foreach ($class['methods'] ?? [] as $method) {
            $relationTypes = ['belongsTo', 'hasMany', 'hasOne', 'belongsToMany'];
            
            foreach ($relationTypes as $type) {
                if (str_contains($method['body'] ?? '', $type)) {
                    $relationships[] = [
                        'name' => $method['name'],
                        'type' => $type,
                    ];
                }
            }
        }
        
        return $relationships;
    }

    private function extractFillable(array $class): array
    {
        foreach ($class['properties'] ?? [] as $property) {
            if ($property['name'] === 'fillable') {
                // Would parse the actual array in real implementation
                return ['name', 'email', 'password']; // placeholder
            }
        }
        
        return [];
    }

    private function extractBusinessLogic(array $class): array
    {
        $businessMethods = [];
        
        foreach ($class['methods'] ?? [] as $method) {
            // Skip Laravel magic methods and accessors
            if (!str_starts_with($method['name'], 'get') && 
                !str_starts_with($method['name'], 'set') &&
                !str_starts_with($method['name'], 'scope') &&
                !in_array($method['name'], ['__construct', '__destruct'])) {
                
                $businessMethods[] = [
                    'name' => $method['name'],
                    'description' => $this->humanizeMethodName($method['name']),
                ];
            }
        }
        
        return $businessMethods;
    }

    private function flattenConfig(array $config, string $prefix = ''): array
    {
        $result = [];
        
        foreach ($config as $key => $value) {
            $fullKey = $prefix ? "{$prefix}.{$key}" : $key;
            
            if (is_array($value) && !isset($value[0])) {
                $result = array_merge($result, $this->flattenConfig($value, $fullKey));
            } else {
                $result[$fullKey] = is_array($value) ? json_encode($value) : $value;
            }
        }
        
        return $result;
    }
}