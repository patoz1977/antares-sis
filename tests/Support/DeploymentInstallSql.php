<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

final class DeploymentInstallSql
{
    private function __construct(
        public readonly string $path,
        public readonly string $contents,
        public readonly int $size,
        public readonly string $sha256,
    ) {
    }

    public static function load(
        string $path,
        string $repositoryRoot,
        string $administratorPassword,
    ): self {
        $resolvedPath = realpath($path);
        if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException('DEPLOY001_INSTALL_SQL_PATH must identify one readable local SQL file.');
        }

        if (strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)) !== 'sql') {
            throw new RuntimeException('The deployment installation artifact must use the .sql extension.');
        }

        if (self::isInsideRoot($resolvedPath, $repositoryRoot)) {
            throw new RuntimeException('The institutional install.sql must remain outside the repository.');
        }

        $contents = file_get_contents($resolvedPath);
        if (!is_string($contents) || trim($contents) === '') {
            throw new RuntimeException('The institutional install.sql must not be empty.');
        }

        foreach ([
            '/\bCREATE\s+DATABASE\b/i' => 'CREATE DATABASE',
            '/\bDROP\s+DATABASE\b/i' => 'DROP DATABASE',
            '/\b(?:CREATE|ALTER|DROP)\s+USER\b/i' => 'database-account command',
            '/\b(?:GRANT|REVOKE)\b/i' => 'database-privilege command',
            '/\bSET\s+PASSWORD\b/i' => 'database-account password command',
            '/\bLOAD\s+DATA\b/i' => 'external data-loading command',
            '/^\s*USE\s+[`a-z0-9_]+\s*;/im' => 'USE',
            '/^\s*(?:SOURCE|DELIMITER)\b/im' => 'client-specific command',
            '/\bE0041_DB_[A-Z0-9_]*\b/i' => 'test database environment variable',
            '/\bDB_(?:HOST|DATABASE|USERNAME|PASSWORD)\b/i' => 'database credential label',
        ] as $pattern => $description) {
            if (preg_match($pattern, $contents) === 1) {
                throw new RuntimeException(sprintf(
                    'The institutional install.sql contains a forbidden %s.',
                    $description,
                ));
            }
        }

        if ($administratorPassword !== '' && str_contains($contents, $administratorPassword)) {
            throw new RuntimeException('The institutional install.sql contains the plaintext administrator password.');
        }

        $size = filesize($resolvedPath);
        $sha256 = hash_file('sha256', $resolvedPath);
        if (!is_int($size) || !is_string($sha256)) {
            throw new RuntimeException('Unable to fingerprint the institutional install.sql.');
        }

        return new self($resolvedPath, $contents, $size, $sha256);
    }

    public static function isInsideRoot(string $path, string $root): bool
    {
        $normalizedPath = self::normalizePath($path);
        $normalizedRoot = rtrim(self::normalizePath($root), '/') . '/';

        return str_starts_with($normalizedPath . '/', $normalizedRoot);
    }

    private static function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }
}
