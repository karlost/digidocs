# Digidocs - AI-Powered Laravel Documentation Generator

[![Version](https://img.shields.io/badge/version-1.2.0-blue.svg)](https://github.com/karlost/digidocs)
[![Laravel](https://img.shields.io/badge/Laravel-10%2B-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://php.net)

Digidocs je pokročilý Laravel package pro automatické generování dokumentace PHP kódu pomocí umělé inteligence s využitím NeuronAI frameworku.

> **🆕 Verze 1.2.0** - Nový Git commit monitoring! AutoDocs nyní automaticky sleduje Git commity a zpracovává pouze změněné soubory místo celého projektu.

## ✨ Funkce

- 🤖 **AI-powered dokumentace** - Využívá OpenAI/GPT-4 pro generování kvalitní dokumentace
- 🔄 **Git commit monitoring** - Automatické sledování Git commitů a generování dokumentace pouze pro změněné soubory
- 📊 **Inteligentní analýza** - PHP AST parsing a Git analýza změn
- 💾 **SQLite memory** - Tracking změn souborů a commitů pro efektivní regeneraci
- 🛠️ **NeuronAI Tools** - Modulární architektura s Tools a Agents
- 🔍 **Laravel kontext** - Rozpoznává Controllers, Models, Commands, atd.
- ⚡ **Artisan commands** - Snadné použití přes CLI
- 🎯 **Efektivní zpracování** - Zpracovává pouze změněné soubory místo celého projektu

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

### 🔄 Git Commit Monitoring (Výchozí režim)

**Nový výchozí režim** - AutoDocs nyní automaticky sleduje Git commity a zpracovává pouze změněné soubory:

```bash
# Zpracuje pouze soubory změněné v Git commitech od posledního spuštění
php artisan autodocs

# Force regenerace i pro Git změny
php artisan autodocs --force

# Dry run - ukáže co by se zpracovalo z Git změn
php artisan autodocs --dry-run

# Zpracování konkrétních cest (pouze Git změny)
php artisan autodocs --path=app/Models --path=app/Controllers
```

**Jak to funguje:**
1. 🔍 Detekuje nové Git commity od posledního spuštění
2. 📁 Analyzuje změněné PHP soubory v commitech
3. 🎯 Filtruje pouze soubory v sledovaných cestách (`app/`, `routes/`)
4. 🤖 Generuje dokumentaci pouze pro změněné soubory
5. 💾 Ukládá poslední zpracovaný commit do databáze

### 📁 Režim všech souborů

Pro zpracování všech souborů (původní chování) použijte `--all`:

```bash
# Zpracuje všechny PHP soubory v sledovaných cestách
php artisan autodocs --all

# Force regenerace všech souborů
php artisan autodocs --all --force

# Dry run pro všechny soubory
php artisan autodocs --all --dry-run
```

### Správa a statistiky

```bash
# Zobraz statistiky dokumentace
php artisan autodocs --stats

# Vyčisti databázi od neexistujících souborů
php artisan autodocs --cleanup
```

### 🔍 Watch Mode - Real-time sledování Git commitů

Pro kontinuální sledování změn v real-time použijte watch mode:

```bash
# Spusť watch mode - sleduje Git commity v real-time
php artisan autodocs:watch

# Nastav interval kontroly (výchozí 5 sekund)
php artisan autodocs:watch --interval=10

# Sleduj konkrétní cesty
php artisan autodocs:watch --path=app/Models --path=app/Services
```

**Watch mode automaticky:**
- 🔄 Sleduje Git commity v real-time (každých 5 sekund)
- 📁 Detekuje změněné PHP soubory v nových commitech
- 🎯 Filtruje pouze soubory v sledovaných cestách
- 🤖 Automaticky generuje dokumentaci pro změněné soubory
- 💾 Ukládá stav do SQLite databáze pro optimalizaci
- ⚡ Přeskakuje nezměněné soubory
- 🛑 Graceful shutdown pomocí Ctrl+C

**Rozdíl mezi režimy:**
- **`php artisan autodocs`** - Jednorázové spuštění, zpracuje změny od posledního spuštění
- **`php artisan autodocs:watch`** - Kontinuální sledování, automaticky reaguje na nové commity

**Workflow:**
1. Spustíte watch mode: `php artisan autodocs:watch`
2. Uděláte změny v kódu
3. Commitnete změny: `git commit -m "feat: nová funkcionalita"`
4. Watch mode automaticky detekuje nový commit a vygeneruje dokumentaci

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
SQLite databáze pro efektivní tracking souborů a Git commitů:

```php
use Digihood\Digidocs\Services\MemoryService;

$memory = app(MemoryService::class);

// Tracking souborů
$status = $memory->needsDocumentation('app/Models/User.php');

// Tracking Git commitů
$lastCommit = $memory->getLastProcessedCommit();
$memory->setLastProcessedCommit('abc123def456');
```

### GitWatcherService
Služba pro Git integraci a monitoring commitů:

```php
use Digihood\Digidocs\Services\GitWatcherService;

$gitWatcher = app(GitWatcherService::class);

// Kontrola Git dostupnosti
if ($gitWatcher->isGitAvailable()) {
    // Získání aktuálních commit hashů
    $commits = $gitWatcher->getCurrentCommitHashes();

    // Získání změněných souborů mezi commity
    $changedFiles = $gitWatcher->getChangedFilesInCommit($newCommit, $oldCommit);

    // Informace o posledním commitu
    $commitInfo = $gitWatcher->getLastCommitInfo();
}
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

# Pokud Git není dostupný, použij --all režim
php artisan autodocs --all
```

### Git commit monitoring
```bash
# Zkontroluj poslední zpracovaný commit
php artisan autodocs --stats

# Reset Git tracking (vymaže poslední commit z databáze)
php artisan autodocs --cleanup

# Force zpracování aktuálního commitu
php artisan autodocs --force
```

### SQLite databáze
```bash
# Vyčisti memory databázi
php artisan autodocs --cleanup

# Zkontroluj storage oprávnění
ls -la storage/app/
```

## 💡 Příklady použití

### Typický workflow s Git monitoring

```bash
# 1. Inicializace - první spuštění zpracuje aktuální commit
php artisan autodocs
# Output: "🔍 Processing files from current commit..."

# 2. Uděláte změny v kódu
echo "// Nová metoda" >> app/Models/User.php

# 3. Commitnete změny
git add app/Models/User.php
git commit -m "feat: add new method to User model"

# 4. Spustíte autodocs - zpracuje pouze změněné soubory
php artisan autodocs
# Output: "🔍 Processing files changed since last run..."
# Output: "📋 Found 1 PHP files to check (mode: Git changes)"

# 5. Další spuštění bez změn
php artisan autodocs
# Output: "📭 No new commits since last run."
```

### Kombinace s watch mode

```bash
# Spustíte watch mode na pozadí
php artisan autodocs:watch &

# Pracujete na kódu...
# Každý commit automaticky spustí generování dokumentace
git commit -m "fix: update validation rules"
# Watch mode automaticky detekuje a zpracuje změny
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
