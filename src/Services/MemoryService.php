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
    }

    /**
     * Zkontroluje zda soubor potřebuje novou dokumentaci
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
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_files' => 0, 'recent_updates' => 0];
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

        } catch (PDOException $e) {
            throw new \RuntimeException("Nelze vytvořit AutoDocs databázi: " . $e->getMessage());
        }
    }
}
