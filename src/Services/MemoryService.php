<?php

namespace Digihood\Digidocs\Services;

use PDO;
use PDOException;
use Illuminate\Support\Facades\File;

class MemoryService
{
    private PDO $db;
    private string $dbPath;

    public function __construct()
    {
        $this->dbPath = config('digidocs.paths.memory') . '/memory.sqlite';
        $this->ensureDatabase();
        $this->db = new PDO("sqlite:" . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->upgradeDatabase();
    }

    /**
     * Zkontroluje zda soubor potřebuje novou dokumentaci (klasická metoda)
     */
    public function needsDocumentation(string $filePath): array
    {
        $fullPath = base_path($filePath);

        if (!file_exists($fullPath)) {
            return [
                'needs_update' => false,
                'is_new' => false,
                'error' => 'File not found'
            ];
        }

        $currentHash = hash_file('sha256', $fullPath);

        $stmt = $this->db->prepare("
            SELECT file_hash, last_documented_at, documentation_path
            FROM documented_files
            WHERE file_path = ?
        ");
        $stmt->execute([$filePath]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'needs_update' => !$existing || $existing['file_hash'] !== $currentHash,
            'is_new' => !$existing,
            'current_hash' => $currentHash,
            'last_hash' => $existing['file_hash'] ?? null,
            'doc_path' => $existing['documentation_path'] ?? null,
            'last_documented_at' => $existing['last_documented_at'] ?? null
        ];
    }

    /**
     * Zaznamenej analýzu do databáze pro debugging a statistiky
     * (používá se z ChangeAnalysisAgent)
     */
    public function recordAnalysis(string $filePath, string $hash, array $analysis): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT OR REPLACE INTO change_analysis
                (file_path, file_hash, should_regenerate, confidence, reason, semantic_score, analysis_data, analyzed_at, existing_doc_path, doc_relevance_score, affected_doc_sections)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'), ?, ?, ?)
            ");

            $stmt->execute([
                $filePath,
                $hash,
                $analysis['should_regenerate'] ? 1 : 0,
                $analysis['confidence'] ?? 0,
                $analysis['reason'] ?? 'unknown',
                $analysis['semantic_score'] ?? 0,
                json_encode($analysis),
                $analysis['existing_doc_path'] ?? null,
                $analysis['doc_relevance_score'] ?? null,
                isset($analysis['affected_doc_sections']) ? json_encode($analysis['affected_doc_sections']) : null
            ]);
        } catch (\Exception $e) {
            // Ignoruj chyby při záznamu analýzy
            \Log::warning("Failed to record analysis: " . $e->getMessage());
        }
    }

    /**
     * Zaznamenej dokumentované části kódu
     */
    public function recordDocumentedCodeParts(string $filePath, array $codeParts): void
    {
        try {
            // Nejprve smaž staré záznamy pro tento soubor
            $stmt = $this->db->prepare("DELETE FROM documented_code_parts WHERE file_path = ?");
            $stmt->execute([$filePath]);

            // Vlož nové záznamy
            $stmt = $this->db->prepare("
                INSERT INTO documented_code_parts
                (file_path, code_type, code_name, code_signature, documented_in_section, last_updated_at)
                VALUES (?, ?, ?, ?, ?, datetime('now'))
            ");

            foreach ($codeParts as $part) {
                $stmt->execute([
                    $filePath,
                    $part['type'] ?? 'unknown',
                    $part['name'] ?? '',
                    $part['signature'] ?? null,
                    $part['section'] ?? null
                ]);
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to record documented code parts for {$filePath}: " . $e->getMessage());
        }
    }

    /**
     * Získej dokumentované části kódu pro soubor
     */
    public function getDocumentedCodeParts(string $filePath): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT code_type, code_name, code_signature, documented_in_section, last_updated_at
                FROM documented_code_parts
                WHERE file_path = ?
                ORDER BY code_type, code_name
            ");
            $stmt->execute([$filePath]);

            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Exception $e) {
            \Log::warning("Failed to get documented code parts for {$filePath}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Zaznamená vygenerovanou dokumentaci
     */
    public function recordDocumentation(string $filePath, string $hash, string $docPath): void
    {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO documented_files
            (file_path, file_hash, documentation_path, last_documented_at)
            VALUES (?, ?, ?, datetime('now'))
        ");
        $stmt->execute([$filePath, $hash, $docPath]);
    }

    /**
     * Získá statistiky dokumentace
     */
    public function getStats(): array
    {
        $stmt = $this->db->query("
            SELECT
                COUNT(*) as total_files,
                COUNT(CASE WHEN datetime(last_documented_at) > datetime('now', '-7 days') THEN 1 END) as recent_updates
            FROM documented_files
        ");

        $basicStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_files' => 0, 'recent_updates' => 0];

        // Přidej statistiky inteligentní analýzy
        $analysisStats = $this->getAnalysisStats();

        return array_merge($basicStats, $analysisStats);
    }

    /**
     * Získá statistiky inteligentní analýzy
     */
    public function getAnalysisStats(): array
    {
        try {
            $stmt = $this->db->query("
                SELECT
                    COUNT(*) as total_analyses,
                    COUNT(CASE WHEN should_regenerate = 1 THEN 1 END) as regeneration_recommended,
                    COUNT(CASE WHEN should_regenerate = 0 THEN 1 END) as regeneration_skipped,
                    AVG(confidence) as avg_confidence,
                    AVG(semantic_score) as avg_semantic_score,
                    COUNT(CASE WHEN datetime(analyzed_at) > datetime('now', '-24 hours') THEN 1 END) as recent_analyses
                FROM change_analysis
            ");

            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$stats || $stats['total_analyses'] == 0) {
                return [
                    'analysis_enabled' => config('digidocs.intelligent_analysis.enabled', true),
                    'total_analyses' => 0,
                    'regeneration_recommended' => 0,
                    'regeneration_skipped' => 0,
                    'skip_rate' => 0.0,
                    'avg_confidence' => 0.0,
                    'avg_semantic_score' => 0.0,
                    'recent_analyses' => 0
                ];
            }

            $skipRate = $stats['total_analyses'] > 0
                ? round(($stats['regeneration_skipped'] / $stats['total_analyses']) * 100, 1)
                : 0.0;

            return [
                'analysis_enabled' => config('digidocs.intelligent_analysis.enabled', true),
                'total_analyses' => (int) $stats['total_analyses'],
                'regeneration_recommended' => (int) $stats['regeneration_recommended'],
                'regeneration_skipped' => (int) $stats['regeneration_skipped'],
                'skip_rate' => $skipRate,
                'avg_confidence' => round((float) $stats['avg_confidence'], 3),
                'avg_semantic_score' => round((float) $stats['avg_semantic_score'], 1),
                'recent_analyses' => (int) $stats['recent_analyses']
            ];

        } catch (\Exception $e) {
            return [
                'analysis_enabled' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Smaže záznamy pro neexistující soubory
     */
    public function cleanup(): int
    {
        $stmt = $this->db->query("SELECT file_path FROM documented_files");
        $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $deleted = 0;
        foreach ($files as $file) {
            if (!file_exists(base_path($file))) {
                $deleteStmt = $this->db->prepare("DELETE FROM documented_files WHERE file_path = ?");
                $deleteStmt->execute([$file]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Získá posledně zpracovaný Git commit
     */
    public function getLastProcessedCommit(): ?string
    {
        $stmt = $this->db->prepare("
            SELECT commit_hash
            FROM git_commits
            ORDER BY processed_at DESC
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? $result['commit_hash'] : null;
    }

    /**
     * Uloží posledně zpracovaný Git commit
     */
    public function setLastProcessedCommit(string $commitHash): void
    {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO git_commits
            (commit_hash, processed_at)
            VALUES (?, datetime('now'))
        ");
        $stmt->execute([$commitHash]);
    }

    /**
     * Zajistí existenci databáze a vytvoří tabulky
     */
    private function ensureDatabase(): void
    {
        $dir = dirname($this->dbPath);
        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if (!file_exists($this->dbPath)) {
            $this->createDatabase();
        }
    }

    /**
     * Vytvoří databázi a tabulky
     */
    private function createDatabase(): void
    {
        try {
            $db = new PDO("sqlite:" . $this->dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $db->exec("
                CREATE TABLE IF NOT EXISTS documented_files (
                    file_path TEXT PRIMARY KEY,
                    file_hash TEXT NOT NULL,
                    documentation_path TEXT,
                    last_documented_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $db->exec("
                CREATE INDEX IF NOT EXISTS idx_last_documented
                ON documented_files(last_documented_at)
            ");

            $db->exec("
                CREATE TABLE IF NOT EXISTS git_commits (
                    commit_hash TEXT PRIMARY KEY,
                    processed_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $db->exec("
                CREATE INDEX IF NOT EXISTS idx_processed_at
                ON git_commits(processed_at)
            ");

            // Tabulka pro analýzu změn
            $db->exec("
                CREATE TABLE IF NOT EXISTS change_analysis (
                    file_path TEXT,
                    file_hash TEXT,
                    should_regenerate INTEGER NOT NULL,
                    confidence REAL NOT NULL,
                    reason TEXT,
                    semantic_score INTEGER,
                    analysis_data TEXT,
                    analyzed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    existing_doc_path TEXT,
                    doc_relevance_score INTEGER,
                    affected_doc_sections TEXT,
                    PRIMARY KEY (file_path, file_hash)
                )
            ");

            $db->exec("
                CREATE INDEX IF NOT EXISTS idx_change_analysis_analyzed_at
                ON change_analysis(analyzed_at)
            ");

            // Nová tabulka pro tracking dokumentovaných částí kódu
            $db->exec("
                CREATE TABLE IF NOT EXISTS documented_code_parts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    file_path TEXT NOT NULL,
                    code_type TEXT NOT NULL,
                    code_name TEXT NOT NULL,
                    code_signature TEXT,
                    documented_in_section TEXT,
                    last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(file_path, code_type, code_name)
                )
            ");

            $db->exec("
                CREATE INDEX IF NOT EXISTS idx_documented_code_parts_file_path
                ON documented_code_parts(file_path)
            ");

            $db->exec("
                CREATE INDEX IF NOT EXISTS idx_documented_code_parts_updated_at
                ON documented_code_parts(last_updated_at)
            ");

        } catch (PDOException $e) {
            throw new \RuntimeException("Nelze vytvořit AutoDocs databázi: " . $e->getMessage());
        }
    }

    /**
     * Upgraduje existující databázi (přidá nové tabulky)
     */
    private function upgradeDatabase(): void
    {
        try {
            // Zkontroluj jestli git_commits tabulka existuje
            $stmt = $this->db->query("
                SELECT name FROM sqlite_master
                WHERE type='table' AND name='git_commits'
            ");

            if (!$stmt->fetch()) {
                // Vytvoř git_commits tabulku
                $this->db->exec("
                    CREATE TABLE git_commits (
                        commit_hash TEXT PRIMARY KEY,
                        processed_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )
                ");

                $this->db->exec("
                    CREATE INDEX idx_processed_at
                    ON git_commits(processed_at)
                ");
            }

            // Zkontroluj jestli change_analysis tabulka existuje
            $stmt = $this->db->query("
                SELECT name FROM sqlite_master
                WHERE type='table' AND name='change_analysis'
            ");

            if (!$stmt->fetch()) {
                // Vytvoř change_analysis tabulku s novými sloupci
                $this->db->exec("
                    CREATE TABLE change_analysis (
                        file_path TEXT,
                        file_hash TEXT,
                        should_regenerate INTEGER NOT NULL,
                        confidence REAL NOT NULL,
                        reason TEXT,
                        semantic_score INTEGER,
                        analysis_data TEXT,
                        analyzed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        existing_doc_path TEXT,
                        doc_relevance_score INTEGER,
                        affected_doc_sections TEXT,
                        PRIMARY KEY (file_path, file_hash)
                    )
                ");

                $this->db->exec("
                    CREATE INDEX idx_change_analysis_analyzed_at
                    ON change_analysis(analyzed_at)
                ");
            } else {
                // Upgrade existující tabulky - přidej nové sloupce pokud neexistují
                try {
                    $this->db->exec("ALTER TABLE change_analysis ADD COLUMN existing_doc_path TEXT");
                } catch (\Exception $e) {
                    // Sloupec už existuje
                }
                try {
                    $this->db->exec("ALTER TABLE change_analysis ADD COLUMN doc_relevance_score INTEGER");
                } catch (\Exception $e) {
                    // Sloupec už existuje
                }
                try {
                    $this->db->exec("ALTER TABLE change_analysis ADD COLUMN affected_doc_sections TEXT");
                } catch (\Exception $e) {
                    // Sloupec už existuje
                }
            }

            // Zkontroluj a vytvoř documented_code_parts tabulku
            $stmt = $this->db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='documented_code_parts'");
            $stmt->execute();

            if (!$stmt->fetch()) {
                $this->db->exec("
                    CREATE TABLE documented_code_parts (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        file_path TEXT NOT NULL,
                        code_type TEXT NOT NULL,
                        code_name TEXT NOT NULL,
                        code_signature TEXT,
                        documented_in_section TEXT,
                        last_updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE(file_path, code_type, code_name)
                    )
                ");

                $this->db->exec("
                    CREATE INDEX idx_documented_code_parts_file_path
                    ON documented_code_parts(file_path)
                ");

                $this->db->exec("
                    CREATE INDEX idx_documented_code_parts_updated_at
                    ON documented_code_parts(last_updated_at)
                ");
            }
        } catch (PDOException $e) {
            // Ignoruj chyby při upgrade - databáze může být již aktuální
        }
    }
}
