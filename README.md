# Digidocs - AI-Powered Laravel Documentation Generator

Digidocs je pokročilý Laravel package pro automatické generování dokumentace PHP kódu pomocí umělé inteligence s využitím NeuronAI frameworku.

## ✨ Funkce

- 🤖 **AI-powered dokumentace** - Využívá OpenAI/GPT-4 pro generování kvalitní dokumentace
- 📊 **Inteligentní analýza** - PHP AST parsing a Git analýza změn
- 💾 **SQLite memory** - Tracking změn souborů pro efektivní regeneraci
- 🛠️ **NeuronAI Tools** - Modulární architektura s Tools a Agents
- 🔍 **Laravel kontext** - Rozpoznává Controllers, Models, Commands, atd.
- ⚡ **Artisan commands** - Snadné použití přes CLI

## 🚀 Instalace

### Z GitHubu (doporučeno)

```bash
composer require karlost/digidocs:dev-main
```

### Nebo s konkrétním tagem

```bash
composer require karlost/digidocs:^1.0
```

## ⚙️ Konfigurace

Publikuj konfigurační soubor:

```bash
php artisan vendor:publish --tag=digidocs-config
```

Nastav environment proměnné v `.env`:

```env
AUTODOCS_AI_KEY=your-openai-api-key
AUTODOCS_AI_MODEL=gpt-4
```

## 📋 Použití

### Základní generování dokumentace

```bash
# Vygeneruje dokumentaci pro všechny PHP soubory v app/
php artisan autodocs

# Force regenerace všech souborů
php artisan autodocs --force

# Dry run - ukáže co by se zpracovalo
php artisan autodocs --dry-run

# Zpracování konkrétních cest
php artisan autodocs --path=app/Models --path=app/Controllers
```

### Správa a statistiky

```bash
# Zobraz statistiky dokumentace
php artisan autodocs --stats

# Vyčisti databázi od neexistujících souborů
php artisan autodocs --cleanup
```

## 🏗️ Architektura

Package využívá **NeuronAI** framework s následující strukturou:

### DocumentationAgent
Hlavní AI agent s SystemPrompt optimalizovaným pro PHP dokumentaci:

```php
use Digihood\Digidocs\Agent\DocumentationAgent;

$agent = app(DocumentationAgent::class);
$documentation = $agent->generateDocumentationForFile('app/Models/User.php');
```

### Tools System

**GitAnalyzerTool** - analýza Git historie a změn
```php
// Automaticky použito agentem pro kontext
- Získá změněné soubory
- Extrahuje commit zprávy
- Analyzuje historii souboru
```

**CodeAnalyzerTool** - PHP AST parsing
```php
// Analyzuje strukturu PHP kódu
- Classes, methods, properties
- Laravel kontext (Controller, Model, atd.)
- Existing docblocks
- Dependencies a imports
```

**FileHashTool** - tracking změn
```php
// Monitoring změn souborů
- SHA256 hash calculation
- File metadata
- Change detection
```

### Memory Service
SQLite databáze pro efektivní tracking:

```php
use Digihood\Digidocs\Services\MemoryService;

$memory = app(MemoryService::class);
$status = $memory->needsDocumentation('app/Models/User.php');
```

## 📁 Konfigurace

Standardní konfigurace v `config/digidocs.php`:

```php
return [
    'ai' => [
        'provider' => 'openai',
        'api_key' => env('AUTODOCS_AI_KEY'),
        'model' => env('AUTODOCS_AI_MODEL', 'gpt-4'),
    ],
    'paths' => [
        'watch' => ['app/', 'routes/'],
        'docs' => base_path('docs/code'),
        'memory' => storage_path('app/autodocs'),
    ],
    'processing' => [
        'extensions' => ['php'],
        'exclude_dirs' => ['vendor', 'node_modules', 'storage'],
        'exclude_files' => ['*.blade.php'],
    ],
];
```

## 📖 Generovaná dokumentace

Agent vytváří strukturovanou Markdown dokumentaci s:

- **Přehled souboru** - účel a funkce
- **Třídy a zodpovědnosti** - popis všech tříd
- **Metody a parametry** - detailní API dokumentace
- **Laravel kontext** - Controller actions, Model relationships
- **Příklady použití** - code examples
- **Závislosti** - imports a relationships

## 🔧 Rozšíření

### Custom Tools

Můžeš vytvořit vlastní NeuronAI Tools:

```php
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class CustomAnalyzerTool extends Tool
{
    public function __construct()
    {
        parent::__construct('my_analyzer', 'Custom analysis tool');
        // ... tool implementation
    }
}
```

### Custom Agent Behavior

Rozšíř DocumentationAgent:

```php
class CustomDocumentationAgent extends DocumentationAgent
{
    protected function tools(): array
    {
        return array_merge(parent::tools(), [
            CustomAnalyzerTool::make(),
        ]);
    }
}
```

## 🐛 Troubleshooting

### Chyby AI generování
```bash
# Zkontroluj API klíč
php artisan config:cache

# Otestuj dry-run
php artisan autodocs --dry-run
```

### Problémy s Git analýzou
```bash
# Ujisti se že je projekt Git repository
git status

# Zkontroluj přístupová práva
ls -la .git/
```

### SQLite databáze
```bash
# Vyčisti memory databázi
php artisan autodocs --cleanup

# Zkontroluj storage oprávnění
ls -la storage/app/
```

## 📊 Příklad výstupu

Pro `app/Models/User.php` vygeneruje dokumentaci:

```markdown
# User Model

## Přehled
Model User reprezentuje uživatele aplikace a poskytuje...

## Třída User
- **Namespace:** App\Models
- **Extends:** Illuminate\Foundation\Auth\User
- **Implements:** Illuminate\Contracts\Auth\Authenticatable

### Properties
- `$fillable` - Hromadně přiřaditelné atributy
- `$hidden` - Skryté atributy pro serializaci

### Methods

#### `posts()`
Vztah k uživatelovým příspěvkům.

**Return:** `HasMany<Post>`

**Příklad:**
```php
$user = User::find(1);
$posts = $user->posts;
```
```

## 🤝 Přispívání

1. Fork repository
2. Vytvoř feature branch
3. Commit změny
4. Push do branch
5. Vytvoř Pull Request

## 📄 Licence

MIT

## 👨‍💻 Autor

**digihood** - info@digihood.com

---

Vytvořeno s ❤️ a NeuronAI frameworkem
