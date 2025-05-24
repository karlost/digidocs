# Digidocs - AI-Powered Laravel Documentation Generator

[![Version](https://img.shields.io/badge/version-1.3.0-blue.svg)](https://github.com/karlost/digidocs)
[![Laravel](https://img.shields.io/badge/Laravel-10%2B-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)](https://php.net)

Digidocs je pokročilý Laravel package pro automatické generování dokumentace PHP kódu pomocí umělé inteligence s využitím NeuronAI frameworku.

> **🆕 Verze 1.3.0** - Vylepšená inteligentní analýza! ChangeAnalysisAgent nyní používá DocumentationAnalyzer pro sledování dokumentovaných částí kódu a pokročilé heuristiky pro rozhodování o regeneraci dokumentace.

## ✨ Klíčové funkce

- 🤖 **AI-powered dokumentace** - Využívá OpenAI/GPT-4 pro generování kvalitní dokumentace
- 🧠 **Inteligentní analýza změn** - ChangeAnalysisAgent s pokročilými heuristikami pro rozhodování o regeneraci
- 📋 **Tracking dokumentovaných částí** - DocumentationAnalyzer sleduje které části kódu jsou dokumentované
- 🔄 **Git commit monitoring** - Automatické sledování Git commitů a zpracování pouze změněných souborů
- 👁️ **Real-time watch mode** - Kontinuální sledování Git commitů s automatickou regenerací
- 📊 **Sémantická analýza** - Rozlišuje mezi veřejnými API změnami a privátními implementačními detaily
- 💾 **SQLite tracking** - Efektivní sledování změn souborů, commitů, analýz a dokumentovaných částí
- 🛠️ **NeuronAI architektura** - Modulární systém s Agents a Tools
- 🔍 **Laravel kontext** - Rozpoznává Controllers, Models, Commands, Services, atd.
- ⚡ **Artisan commands** - Snadné použití přes CLI
- 🎯 **Efektivní zpracování** - Skip rate až 19% díky inteligentní analýze

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

```bash
# 1. Publikuj konfigurační soubor
php artisan vendor:publish --tag=digidocs-config

# 2. Nastav API klíč v .env
AUTODOCS_AI_KEY=your-openai-api-key
```

## 📋 Použití

### 🔄 Automatické generování dokumentace

**Hlavní příkaz** - Zpracovává pouze soubory změněné v Git commitech s inteligentní analýzou:

```bash
# Zpracuje pouze soubory změněné v Git commitech od posledního spuštění
php artisan digidocs:autodocs

# Force regenerace i pro nezměněné soubory
php artisan digidocs:autodocs --force

# Dry run - ukáže co by se zpracovalo
php artisan digidocs:autodocs --dry-run

# Zpracování konkrétních cest
php artisan digidocs:autodocs --path=app/Models --path=app/Services
```

**Inteligentní analýza:**
- 🧠 **ChangeAnalysisAgent** rozhoduje zda regenerovat dokumentaci
- ✅ **Veřejné API změny** → Regeneruje dokumentaci
- ⏭️ **Privátní změny/whitespace** → Přeskakuje regeneraci
- 📊 **Sleduje dokumentované části** kódu pro přesnější rozhodování

### Správa a statistiky

```bash
# Zobraz statistiky dokumentace a inteligentní analýzy
php artisan digidocs:autodocs --stats

# Vyčisti databázi od neexistujících souborů
php artisan digidocs:autodocs --cleanup
```

### 👁️ Watch Mode - Real-time sledování Git commitů

Pro kontinuální sledování změn v real-time použijte watch mode s inteligentní analýzou:

```bash
# Spusť watch mode - sleduje Git commity v real-time
php artisan digidocs:watch

# Nastav interval kontroly (výchozí 5 sekund)
php artisan digidocs:watch --interval=10

# Sleduj konkrétní cesty
php artisan digidocs:watch --path=app/Models --path=app/Services
```

**Watch mode:**
- 🔄 Sleduje Git commity v real-time (každých 5 sekund)
- 🧠 Používá stejnou inteligentní analýzu jako `autodocs`
- 🛑 Graceful shutdown pomocí Ctrl+C

**Rozdíl mezi režimy:**
- **`autodocs`** - Jednorázové spuštění, zpracuje Git commity od posledního spuštění
- **`watch`** - Kontinuální sledování, automaticky zpracovává nové Git commity

## 🐛 Troubleshooting

### Základní problémy
```bash
# Zkontroluj API klíč a konfiguraci
php artisan config:cache

# Otestuj bez generování dokumentace
php artisan digidocs:autodocs --dry-run

# Zobraz statistiky a stav
php artisan digidocs:autodocs --stats

# Vyčisti databázi od neexistujících souborů
php artisan digidocs:autodocs --cleanup
```

### Git problémy
```bash
# Ujisti se že jsi v Git repository
git status

# Force zpracování aktuálního commitu
php artisan digidocs:autodocs --force
```

## 💡 Rychlý start

```bash
# 1. Nainstaluj a nakonfiguruj
composer require karlost/digidocs:dev-main
php artisan vendor:publish --tag=digidocs-config

# 2. Nastav API klíč v .env
AUTODOCS_AI_KEY=your-openai-api-key

# 3. Vygeneruj dokumentaci pro změněné soubory
php artisan digidocs:autodocs

# 4. Nebo spusť watch mode pro automatické sledování
php artisan digidocs:watch
```

### Inteligentní analýza v akci

```bash
# Privátní změny se přeskočí
git commit -m "refactor: improve private method"
# → "⏭️ Skipped (no significant changes)"

# Veřejné API změny se zpracují
git commit -m "feat: add public getData method"
# → "✅ Generated: docs/code/Models/User.md"
```

## 📊 Příklad výstupu

Vygeneruje strukturovanou Markdown dokumentaci s:
- **Přehled souboru** a jeho účel
- **API dokumentace** - veřejné metody a vlastnosti
- **Laravel kontext** - relationships, scopes, atd.
- **Příklady použití** s code examples

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
