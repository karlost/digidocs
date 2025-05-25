# Digidocs - AI-Powered Laravel Documentation Generator

[![Version](https://img.shields.io/badge/version-1.3.1-blue.svg)](https://github.com/karlost/digidocs)
[![Laravel](https://img.shields.io/badge/Laravel-10%2B-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://php.net)

Digidocs is an advanced Laravel package for automatic PHP code documentation generation using artificial intelligence with the NeuronAI framework.


## ✨ Key Features

- 🤖 **AI-powered documentation** - Uses OpenAI/GPT-4 for generating high-quality documentation
- 🧠 **Intelligent change analysis** - ChangeAnalysisAgent with advanced heuristics for regeneration decisions
- 📋 **Documented parts tracking** - DocumentationAnalyzer tracks which parts of code are documented
- 🔄 **Git commit monitoring** - Automatic Git commit tracking and processing only changed files
- 👁️ **Real-time watch mode** - Continuous Git commit monitoring with automatic regeneration
- 📊 **Semantic analysis** - Distinguishes between public API changes and private implementation details
- 💾 **SQLite tracking** - Efficient tracking of file changes, commits, analyses and documented parts
- 🛠️ **NeuronAI architecture** - Modular system with Agents and Tools
- 🔍 **Laravel context** - Recognizes Controllers, Models, Commands, Services, etc.
- ⚡ **Artisan commands** - Easy usage through CLI
- 🎯 **Efficient processing** - Skip rate up to 19% thanks to intelligent analysis

## 🚀 Installation

```bash
composer require karlost/digidocs
```

## ⚙️ Configuration

```bash
# 1. Publish configuration file
php artisan vendor:publish --tag=digidocs-config

# 2. Set API key in .env
AUTODOCS_AI_KEY=your-openai-api-key
```

## 📋 Usage

### 🔄 Automatic Documentation Generation

**Main command** - Processes only files changed in Git commits with intelligent analysis:

```bash
# Process only files changed in Git commits since last run
php artisan digidocs:autodocs

# Force regeneration even for unchanged files
php artisan digidocs:autodocs --force

# Dry run - shows what would be processed
php artisan digidocs:autodocs --dry-run

# Process specific paths
php artisan digidocs:autodocs --path=app/Models --path=app/Services
```

**Intelligent analysis:**
- 🧠 **ChangeAnalysisAgent** decides whether to regenerate documentation
- ✅ **Public API changes** → Regenerates documentation
- ⏭️ **Private changes/whitespace** → Skips regeneration
- 📊 **Tracks documented parts** of code for more precise decisions

### Management and Statistics

```bash
# Show documentation and intelligent analysis statistics
php artisan digidocs:autodocs --stats

# Show cost and token statistics
php artisan digidocs:autodocs --cost

# Clean database from non-existent files
php artisan digidocs:autodocs --cleanup
```

### 👁️ Watch Mode - Real-time Git Commit Monitoring

For continuous real-time change monitoring use watch mode with intelligent analysis:

```bash
# Start watch mode - monitors Git commits in real-time
php artisan digidocs:watch

# Set check interval (default 5 seconds)
php artisan digidocs:watch --interval=10

# Watch specific paths
php artisan digidocs:watch --path=app/Models --path=app/Services
```

**Watch mode:**
- 🔄 Monitors Git commits in real-time (every 5 seconds)
- 🧠 Uses the same intelligent analysis as `autodocs`
- 🛑 Graceful shutdown using Ctrl+C

**Difference between modes:**
- **`autodocs`** - One-time execution, processes Git commits since last run
- **`watch`** - Continuous monitoring, automatically processes new Git commits

**Output includes:**
- 📊 **Overall statistics** - call count, tokens, costs
- 🤖 **Model-specific statistics** - details for each AI model used
- 📅 **Recent activity** - consumption for the last 7 days
- 💰 **Current prices** - model prices per 1M tokens

**Supported AI providers:**
- ✅ **OpenAI** - GPT-4.1, GPT-4o, GPT-4, GPT-3.5, O3, O4-mini
- ✅ **Anthropic** - Claude 4, Claude 3.7, Claude 3.5, Claude 3
- ✅ **Gemini** - Gemini 1.5 Pro/Flash, Gemini 2.0 Flash
- ✅ **Deepseek** - Deepseek Chat/Coder
- ✅ **Mistral** - Mistral Large/Medium/Small
- ✅ **Ollama** - Local models (free)

**Price configuration:**
Model prices are configurable in `config/digidocs/pricing.php` and automatically update according to official provider prices.

## 🐛 Troubleshooting

### Basic Issues
```bash
# Check API key and configuration
php artisan config:cache

# Test without generating documentation
php artisan digidocs:autodocs --dry-run

# Show statistics and status
php artisan digidocs:autodocs --stats

# Clean database from non-existent files
php artisan digidocs:autodocs --cleanup
```

### Git Issues
```bash
# Make sure you're in a Git repository
git status

# Force processing of current commit
php artisan digidocs:autodocs --force
```

### ⚠️ Known Issues

**Version 1.3.1 contains the following known issues:**

1. **WatchCommand - path handling**
   - Issues with duplicate paths on Windows
   - Does not affect documentation generation
   - Workaround: Restart watch command

**These issues do not affect the basic documentation generation functionality and will be fixed in the next version.**

## 💡 Quick Start

```bash
# 1. Install and configure
composer require karlost/digidocs
php artisan vendor:publish --tag=digidocs-config

# 2. Set API key in .env
AUTODOCS_AI_KEY=your-openai-api-key

# 3. Generate documentation for changed files
php artisan digidocs:autodocs

# 4. Or start watch mode for automatic monitoring
php artisan digidocs:watch
```

### Intelligent Analysis in Action

```bash
# Private changes are skipped
git commit -m "refactor: improve private method"
# → "⏭️ Skipped (no significant changes)"

# Public API changes are processed
git commit -m "feat: add public getData method"
# → "✅ Generated: docs/code/Models/User.md"
```

## 📊 Example Output

Generates structured Markdown documentation with:
- **File overview** and its purpose
- **API documentation** - public methods and properties
- **Laravel context** - relationships, scopes, etc.
- **Usage examples** with code examples

