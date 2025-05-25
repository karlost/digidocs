<?php

namespace Digihood\Digidocs\Analyzers;

use Exception;

class FileHasher
{
    public function __invoke(string $file_path, string $algorithm = 'sha256'): array
    {
        $fullPath = base_path($file_path);

        if (!file_exists($fullPath)) {
            return [
                'status' => 'error',
                'error' => 'File not found',
                'file_path' => $file_path
            ];
        }

        if (!is_readable($fullPath)) {
            return [
                'status' => 'error',
                'error' => 'File is not readable',
                'file_path' => $file_path
            ];
        }

        // Validace algoritmu
        if (!in_array($algorithm, hash_algos())) {
            return [
                'status' => 'error',
                'error' => "Unsupported hash algorithm: {$algorithm}",
                'file_path' => $file_path,
                'supported_algorithms' => $this->getSupportedAlgorithms()
            ];
        }

        try {
            $hash = hash_file($algorithm, $fullPath);

            if ($hash === false) {
                return [
                    'status' => 'error',
                    'error' => 'Failed to calculate hash',
                    'file_path' => $file_path
                ];
            }

            $fileStats = stat($fullPath);

            return [
                'status' => 'success',
                'file_path' => $file_path,
                'hash' => $hash,
                'algorithm' => $algorithm,
                'file_info' => [
                    'size' => $fileStats['size'],
                    'modified_time' => $fileStats['mtime'],
                    'modified_date' => date('Y-m-d H:i:s', $fileStats['mtime']),
                    'permissions' => substr(sprintf('%o', $fileStats['mode']), -4),
                    'is_executable' => is_executable($fullPath),
                    'mime_type' => $this->getMimeType($fullPath)
                ],
                'hash_info' => [
                    'length' => strlen($hash),
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ];

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => 'Exception occurred: ' . $e->getMessage(),
                'file_path' => $file_path
            ];
        }
    }

    /**
     * Získá seznam podporovaných hash algoritmů
     */
    private function getSupportedAlgorithms(): array
    {
        $common = ['md5', 'sha1', 'sha256', 'sha512'];
        $available = hash_algos();

        return [
            'common' => array_intersect($common, $available),
            'all' => $available
        ];
    }

    /**
     * Získá MIME type souboru
     */
    private function getMimeType(string $filePath): ?string
    {
        if (function_exists('mime_content_type')) {
            return mime_content_type($filePath);
        }

        if (function_exists('finfo_file')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                return $mimeType ?: null;
            }
        }

        // Fallback based on extension
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $mimeTypes = [
            'php' => 'text/x-php',
            'js' => 'text/javascript',
            'css' => 'text/css',
            'html' => 'text/html',
            'txt' => 'text/plain',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'md' => 'text/markdown'
        ];

        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
}
