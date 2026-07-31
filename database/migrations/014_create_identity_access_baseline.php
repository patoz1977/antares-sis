<?php

declare(strict_types=1);

use Core\Database\Migration;

final class CreateIdentityAccessBaseline extends Migration
{
    private const LEGACY_LOCKOUT_DURATION_SECONDS = 900;
    private const LEGACY_MAXIMUM_FAILED_ATTEMPTS = 5;
    private const BACKFILL_COMPLETE = 'e0041_legacy_backfill_complete';

    public function up(PDO $connection): void
    {
        $loginBackfillRequired = $this->columnComment(
            $connection,
            'normalized_login_identifier'
        ) !== self::BACKFILL_COMPLETE;
        $lockoutBackfillRequired = $this->columnComment(
            $connection,
            'locked_at'
        ) !== self::BACKFILL_COMPLETE;

        if ($loginBackfillRequired) {
            $this->assertCompatibleLegacyIdentifiers($connection);
        }

        $this->addColumnIfMissing(
            $connection,
            'login_identifier',
            'VARCHAR(254) NULL COMMENT \'e0041_legacy_backfill_pending\' AFTER `email`'
        );
        $this->addColumnIfMissing(
            $connection,
            'normalized_login_identifier',
            'VARCHAR(254) NULL COMMENT \'e0041_legacy_backfill_pending\' AFTER `login_identifier`'
        );
        $this->addColumnIfMissing(
            $connection,
            'last_access_at',
            'TIMESTAMP NULL DEFAULT NULL AFTER `password_changed_at`'
        );
        $this->addColumnIfMissing(
            $connection,
            'failed_login_attempts',
            'SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_access_at`'
        );
        $this->addColumnIfMissing(
            $connection,
            'locked_at',
            'TIMESTAMP NULL DEFAULT NULL COMMENT \'e0041_legacy_backfill_pending\' AFTER `failed_login_attempts`'
        );

        if ($loginBackfillRequired) {
            $this->backfillLoginIdentifiers($connection);
        }
        $this->backfillLastAccess($connection);
        if ($lockoutBackfillRequired) {
            $this->backfillLegacyLockout($connection);
        }
        $this->assertCompatibleData($connection);

        $connection->exec(
            'ALTER TABLE users '
            . 'MODIFY COLUMN login_identifier VARCHAR(254) NOT NULL '
            . 'COMMENT \'e0041_legacy_backfill_complete\', '
            . 'MODIFY COLUMN normalized_login_identifier VARCHAR(254) NOT NULL '
            . 'COMMENT \'e0041_legacy_backfill_complete\', '
            . 'MODIFY COLUMN failed_login_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0, '
            . 'MODIFY COLUMN locked_at TIMESTAMP NULL DEFAULT NULL '
            . 'COMMENT \'e0041_legacy_backfill_complete\''
        );

        $this->addIndexIfMissing(
            $connection,
            'uq_users_normalized_login',
            'UNIQUE KEY `uq_users_normalized_login` (`normalized_login_identifier`)'
        );
        $this->addIndexIfMissing(
            $connection,
            'idx_users_status_locked',
            'KEY `idx_users_status_locked` (`status_id`, `locked_at`)'
        );
    }

    public function down(PDO $connection): void
    {
        throw new RuntimeException(
            'Migration 014 is forward-only and cannot be reversed safely.'
        );
    }

    public function version(): string
    {
        return '014_create_identity_access_baseline';
    }

    private function backfillLoginIdentifiers(PDO $connection): void
    {
        $connection->exec(
            'UPDATE users SET '
            . 'login_identifier = COALESCE(NULLIF(TRIM(username), \'\'), NULLIF(TRIM(email), \'\')), '
            . 'normalized_login_identifier = LOWER('
            . 'COALESCE(NULLIF(TRIM(username), \'\'), NULLIF(TRIM(email), \'\'))'
            . ')'
        );
    }

    private function backfillLastAccess(PDO $connection): void
    {
        if (!$this->columnExists($connection, 'last_login_at')) {
            return;
        }

        $connection->exec(
            'UPDATE users SET last_access_at = last_login_at '
            . 'WHERE last_access_at IS NULL AND last_login_at IS NOT NULL'
        );
    }

    private function backfillLegacyLockout(PDO $connection): void
    {
        if (!$this->columnExists($connection, 'locked_until')) {
            return;
        }

        $connection->exec(
            'UPDATE users SET '
            . 'locked_at = CASE '
            . 'WHEN locked_until IS NULL OR locked_until <= UTC_TIMESTAMP() THEN NULL '
            . 'ELSE LEAST(DATE_SUB(locked_until, INTERVAL '
            . self::LEGACY_LOCKOUT_DURATION_SECONDS
            . ' SECOND), UTC_TIMESTAMP()) END, '
            . 'failed_login_attempts = CASE '
            . 'WHEN locked_until IS NULL OR locked_until <= UTC_TIMESTAMP() THEN 0 '
            . 'ELSE '
            . self::LEGACY_MAXIMUM_FAILED_ATTEMPTS
            . ' END'
        );
    }

    private function assertCompatibleData(PDO $connection): void
    {
        $missingIdentifier = $connection->query(
            'SELECT COUNT(*) FROM users '
            . 'WHERE login_identifier IS NULL OR normalized_login_identifier IS NULL'
        )->fetchColumn();

        if ((int) $missingIdentifier > 0) {
            throw new RuntimeException(
                'Cannot migrate User rows without a deterministic login identifier.'
            );
        }

        $duplicateIdentifier = $connection->query(
            'SELECT normalized_login_identifier FROM users '
            . 'GROUP BY normalized_login_identifier HAVING COUNT(*) > 1 LIMIT 1'
        )->fetchColumn();

        if ($duplicateIdentifier !== false) {
            throw new RuntimeException(
                'Cannot migrate duplicate normalized login identifiers.'
            );
        }
    }

    private function assertCompatibleLegacyIdentifiers(PDO $connection): void
    {
        $identifierExpression = "LOWER(COALESCE(NULLIF(TRIM(username), ''), NULLIF(TRIM(email), '')))";
        $missingIdentifier = $connection->query(
            'SELECT COUNT(*) FROM users WHERE '
            . "COALESCE(NULLIF(TRIM(username), ''), NULLIF(TRIM(email), '')) IS NULL"
        )->fetchColumn();

        if ((int) $missingIdentifier > 0) {
            throw new RuntimeException(
                'Cannot migrate User rows without a deterministic login identifier.'
            );
        }

        $duplicateIdentifier = $connection->query(
            'SELECT ' . $identifierExpression . ' AS candidate FROM users '
            . 'GROUP BY candidate HAVING COUNT(*) > 1 LIMIT 1'
        )->fetchColumn();

        if ($duplicateIdentifier !== false) {
            throw new RuntimeException(
                'Cannot migrate duplicate normalized login identifiers.'
            );
        }
    }

    private function addColumnIfMissing(
        PDO $connection,
        string $column,
        string $definition
    ): void {
        if (!$this->columnExists($connection, $column)) {
            $connection->exec(
                sprintf('ALTER TABLE users ADD COLUMN `%s` %s', $column, $definition)
            );
        }
    }

    private function columnExists(PDO $connection, string $column): bool
    {
        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :tableName '
            . 'AND column_name = :columnName'
        );
        $statement->execute([
            ':tableName' => 'users',
            ':columnName' => $column,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function columnComment(PDO $connection, string $column): ?string
    {
        $statement = $connection->prepare(
            'SELECT column_comment FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = :tableName '
            . 'AND column_name = :columnName'
        );
        $statement->execute([
            ':tableName' => 'users',
            ':columnName' => $column,
        ]);
        $comment = $statement->fetchColumn();

        return is_string($comment) && $comment !== '' ? $comment : null;
    }

    private function addIndexIfMissing(
        PDO $connection,
        string $index,
        string $definition
    ): void {
        if (!$this->indexExists($connection, $index)) {
            $connection->exec(sprintf('ALTER TABLE users ADD %s', $definition));
        }
    }

    private function indexExists(PDO $connection, string $index): bool
    {
        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = :tableName '
            . 'AND index_name = :indexName'
        );
        $statement->execute([
            ':tableName' => 'users',
            ':indexName' => $index,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }
}
