# Digidocs - AI-Powered Laravel Documentation Generator

[![Version](https://img.shields.io/badge/version-0.3.0-blue.svg)](https://github.com/karlost/digidocs)
[![Laravel](https://img.shields.io/badge/Laravel-10%2B-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://php.net)

Digidocs is an advanced Laravel package that automatically generates comprehensive documentation using AI. It creates both **developer documentation** (technical API docs) and **user documentation** (end-user guides) with intelligent change analysis and real-time Git monitoring.


## ✨ Key Features

### 📚 Dual Documentation Types
- 👨‍💻 **Developer Documentation** - Technical API docs for code maintainers
- 👥 **User Documentation** - End-user guides for application users
- 🧠 **Intelligent routing** - Automatically determines which type to generate

### 🤖 AI-Powered Generation
- 🤖 **Advanced AI models** - Uses OpenAI/GPT-4, Claude, Gemini, and more
- 🧠 **Intelligent change analysis** - Separate agents for developer vs user impact
- 📊 **Context-aware** - Understands Laravel patterns and user workflows

### 🔄 Smart Change Detection
- 🎯 **UserChangeAnalysisAgent** - Analyzes UI/UX impact for user docs
- 🛠️ **ChangeAnalysisAgent** - Analyzes API changes for developer docs
- 📋 **Documented parts tracking** - Tracks which code parts are documented
- 🔄 **Git commit monitoring** - Processes only changed files

### ⚡ Advanced Features
- 🌍 **Universal Language Support** - Any ISO language code (cs-CZ, ja-JP, ar-SA, zh-CN, etc.)
- 👁️ **Real-time watch mode** - Continuous monitoring with automatic regeneration
- 📊 **Semantic analysis** - Distinguishes between public API and private changes
- 💾 **SQLite tracking** - Efficient tracking of changes, analyses and documentation
- 🛠️ **NeuronAI architecture** - Modular system with specialized Agents and Tools
- 🔍 **Laravel context** - Recognizes Controllers, Models, Blade templates, Routes
- 💰 **Cost tracking** - Detailed token usage and cost statistics
- 🎯 **High efficiency** - Skip rate up to 85% thanks to intelligent analysis

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

# 3. Configure languages in .env (optional)
DIGIDOCS_LANGUAGES=cs-CZ,en-US,ja-JP
DIGIDOCS_DEFAULT_LANGUAGE=cs-CZ
```

### 🌍 Language Configuration

DigiDocs supports **any ISO language code** without code modifications:

```php
// config/digidocs.php
'languages' => [
    'enabled' => ['cs-CZ', 'en-US', 'ja-JP', 'de-DE', 'pl-PL', 'zh-CN'],
    'default' => 'cs-CZ',
],
```

**Supported examples:**
- `cs-CZ` (Czech), `en-US` (English), `de-DE` (German)
- `ja-JP` (Japanese), `ko-KR` (Korean), `zh-CN` (Chinese)
- `ar-SA` (Arabic), `hi-IN` (Hindi), `th-TH` (Thai)
- `pt-BR` (Portuguese Brazil), `es-MX` (Spanish Mexico)
- **Any ISO 639-1 + ISO 3166-1 combination!**

AI automatically recognizes and generates documentation in the specified language.

## 📋 Usage

### �‍💻 Developer Documentation (Technical API Docs)

```bash
php artisan digidocs:autodocs [options]
```

**Available options:**
- `--force` - Force regeneration even for unchanged files
- `--dry-run` - Show what would be processed without generating
- `--path=PATH` - Process specific paths (can be used multiple times)
- `--stats` - Show documentation statistics
- `--cost` - Show token usage and cost statistics
- `--cleanup` - Clean database from non-existent files

**Intelligent analysis:**
- 🧠 **ChangeAnalysisAgent** decides whether to regenerate documentation
- ✅ **Public API changes** → Regenerates documentation
- ⏭️ **Private changes/whitespace** → Skips regeneration
- 📊 **Tracks documented parts** of code for more precise decisions

### 👥 User Documentation (End-User Guides)

```bash
php artisan digidocs:userdocs [options]
```

**Available options:**
- `--lang=ISO_CODE` - Generate in specific language (e.g., `--lang=ja-JP`)
- `--force` - Force regeneration of all user documentation
- `--dry-run` - Show what would be processed without generating
- `--stats` - Show documentation statistics
- `--cost` - Show token usage and cost statistics

**Language examples:**
```bash
php artisan digidocs:userdocs --lang=cs-CZ    # Czech
php artisan digidocs:userdocs --lang=ja-JP    # Japanese
php artisan digidocs:userdocs --lang=ar-SA    # Arabic
```

**User documentation features:**
- 🎯 **UserChangeAnalysisAgent** - Analyzes changes from user perspective
- 🖥️ **UI/UX focus** - Prioritizes Blade templates, routes, and user-facing changes
- 📱 **Application-level docs** - Generates structured guides with sections
- 🔄 **Smart regeneration** - Only updates when user experience is affected
- 📊 **Impact scoring** - Rates changes by user impact (0-100)
- 📂 **Section-based organization** - Organizes content by user workflows
- 🔗 **Cross-references** - Links between related sections

### 🚀 All Documentation (Combined Generation)

```bash
php artisan digidocs:alldocs [options]
```

**Available options:**
- `--lang=ISO_CODE` - Generate user docs in specific language (e.g., `--lang=pl-PL`)
- `--code-only` - Generate only developer documentation
- `--user-only` - Generate only user documentation
- `--all` - Process all files for code documentation (not just Git changes)
- `--force` - Force regeneration of all documentation
- `--path=PATH` - Specific paths to process for code docs

**Examples:**
```bash
php artisan digidocs:alldocs                    # Both types, default language
php artisan digidocs:alldocs --lang=ja-JP       # Both types in Japanese
php artisan digidocs:alldocs --code-only --all  # Only developer docs, all files
```

### 👁️ Watch Mode - Real-time Git Commit Monitoring

```bash
php artisan digidocs:watch [options]
```

**Available options:**
- `--interval=SECONDS` - Check interval in seconds (default: 5)
- `--path=PATH` - Specific paths to watch (can be used multiple times)
- `--code-only` - Generate only developer documentation
- `--user-only` - Generate only user documentation

**Watch mode features:**
- 🔄 **Real-time monitoring** - Monitors Git commits continuously
- 🧠 **Intelligent analysis** - Separate agents for dev vs user docs
- 🎯 **Smart filtering** - Only processes relevant changes
- 🛑 **Graceful shutdown** - Ctrl+C for clean exit
- 📚 **Unified processing** - Both documentation types in one command

## 🤖 Supported AI Providers

**Tested and verified:**
- ✅ **OpenAI GPT-4.1 Nano** - Fully tested and optimized (recommended)

**Supported but not extensively tested:**
- 🔶 **OpenAI** - GPT-4o, GPT-4, GPT-3.5, O3, O4-mini
- 🔶 **Anthropic** - Claude 4, Claude 3.7, Claude 3.5, Claude 3
- 🔶 **Gemini** - Gemini 1.5 Pro/Flash, Gemini 2.0 Flash
- 🔶 **Deepseek** - Deepseek Chat/Coder
- 🔶 **Mistral** - Mistral Large/Medium/Small
- 🔶 **Ollama** - Local models (free)

**Note:** All testing was performed with GPT-4.1 Nano model. Other providers are supported through NeuronAI framework but may require additional configuration or testing.

**Automatic pricing:**
Model prices are configurable in `config/digidocs/pricing.php`.

## 💡 Quick Start

```bash
# 1. Install and configure
composer require karlost/digidocs
php artisan vendor:publish --tag=digidocs-config

# 2. Set API key in .env
AUTODOCS_AI_KEY=your-openai-api-key

# 3. Generate documentation
php artisan digidocs:autodocs              # Developer documentation
php artisan digidocs:userdocs              # User documentation  
php artisan digidocs:alldocs               # Both types combined
php artisan digidocs:watch                 # Watch mode for both types

# 4. Multi-language generation
php artisan digidocs:userdocs --lang=ja-JP # Japanese user docs
php artisan digidocs:alldocs --lang=de-DE  # German docs (both types)

# 5. View statistics and costs
php artisan digidocs:autodocs --stats --cost
```

## 🧠 Intelligent Analysis Examples

**Developer documentation:**
- ⏭️ **Private changes** → Skipped (no significant changes)
- ✅ **Public API changes** → Generated documentation

**User documentation:**
- ⏭️ **Backend changes** → Skipped (low user impact)
- ✅ **UI/UX changes** → Updated relevant sections

## 📊 Documentation Quality

### 👨‍💻 Developer Documentation
Generates comprehensive technical documentation with:
- **File overview** and its purpose
- **Complete API documentation** - all public methods and properties
- **Laravel context** - relationships, scopes, middleware, etc.
- **Practical usage examples** with working code
- **Dependencies and relationships** - clear integration points

### 👥 User Documentation
Creates user-friendly application guides with:
- **Application overview** - what the app does for users
- **Feature sections** - organized by user workflows
- **Step-by-step guides** - how to accomplish tasks
- **Cross-referenced sections** - linked content organization
- **User-focused language** - non-technical explanations

## 📁 Output Structure

**Developer documentation:**
```
docs/code/
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   └── ...
├── Models/
├── Services/
├── config/
└── routes/
```

**User documentation:**
```
docs/user/
├── index.md              # Main application guide
└── sections/
    ├── uživatelské-rozhraní.md
    ├── navigace.md
    ├── formuláře.md
    ├── funkcionality.md
    └── ...
```



## 📈 Performance & Testing

**Proven efficiency:**
- ⚡ **56.5% skip rate** - Intelligent analysis avoids unnecessary regeneration
- 🎯 **90.5% average confidence** - High-quality analysis decisions
- 💰 **Cost-effective** - Only ~$0.26 for 350 API calls and 2M+ tokens
- 🚀 **Real-time processing** - Watch mode with instant Git commit detection

**Comprehensive testing:**
- ✅ **21 documented files** across Models, Controllers, Services, Middleware
- ✅ **Complex commits** with multiple file types and changes
- ✅ **Watch mode** tested with real-time Git monitoring
- ✅ **Both documentation types** verified for quality and accuracy
- ✅ **Cost tracking** validated for precise token and pricing calculations

## 📋 Changelog

### 🌍 v0.3.0 - Universal Language Support (2025-05-29)

**🆕 New Features:**
- 🌍 **Universal ISO Language Support** - Any ISO language code now supported (cs-CZ, ja-JP, ar-SA, zh-CN, etc.)
- 🚀 **New `alldocs` Command** - Combined generation of both developer and user documentation
- 🤖 **AI-Powered Language Detection** - AI automatically recognizes and generates in specified language
- 🔧 **Simplified Configuration** - No hardcoded languages, pure config-based language management

**✨ Improvements:**
- 🧹 **Removed 70+ hardcoded languages** from PHP code - now config-only
- 📝 **Enhanced Language Instructions** - Simplified AI prompts for better language recognition
- 🛠️ **Fixed Memory System** - Added missing `remember()` and `search()` methods
- 🔗 **Updated Cross-Reference Manager** - Dynamic language support across all agents

**🧪 Testing:**
- ✅ **Comprehensive Multi-Language Testing** - Czech, English, and Polish fully tested
- ✅ **Memory System Validation** - SQLite database and vector storage verified
- ✅ **17 Generated Documents** - 5 code docs + 12 user docs across multiple languages
- ✅ **Zero-Config Language Addition** - New languages work instantly without code changes

**📚 Examples of Supported Languages:**
- European: cs-CZ (Czech), en-US (English), de-DE (German), fr-FR (French)
- Asian: ja-JP (Japanese), ko-KR (Korean), zh-CN (Chinese), hi-IN (Hindi)
- Middle East: ar-SA (Arabic), he-IL (Hebrew), fa-IR (Persian)
- African: sw-KE (Swahili), am-ET (Amharic), zu-ZA (Zulu)
- **And many more - any ISO 639-1 + ISO 3166-1 combination!**

### v0.2.0 - User Documentation & Advanced Analysis (2025-05-27)
- Added UserDocumentationOrchestrator with complete user-focused documentation generation
- Implemented UserChangeAnalysisAgent for user-impact analysis  
- Added comprehensive testing with 100% success rate across 24 test scenarios
- Enhanced memory systems with RAG implementation

### v0.1.0 - Initial Release (2025-05-25)
- Core DigiDocs functionality with AI-powered documentation generation
- Developer documentation with intelligent change analysis
- Git commit monitoring and real-time watch mode
- Cost tracking and performance optimization

