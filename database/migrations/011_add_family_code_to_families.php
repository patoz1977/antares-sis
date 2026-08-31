<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigration.php';

final class CreateAddFamilyCodeToFamilies extends SchemaMigration
{
    public function up(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE `families` ADD COLUMN `family_code` '
            . 'CHAR(9) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `id`'
        );

        $maximumId = $connection->query('SELECT MAX(`id`) FROM `families`')->fetchColumn();
        if ($maximumId !== null && (int) $maximumId > 99_999_999) {
            throw new RuntimeException('FamilyCode backfill cannot represent an existing FamilyId.');
        }

        $connection->exec(
            "UPDATE `families` SET `family_code` = CONCAT('F', LPAD(CAST(`id` AS CHAR), 8, '0'))"
        );

        $invalidCount = (int) $connection->query(
            "SELECT COUNT(*) FROM `families` WHERE `family_code` IS NULL "
            . "OR CHAR_LENGTH(`family_code`) <> 9 OR BINARY `family_code` NOT REGEXP '^F[0-9]{8}$'"
        )->fetchColumn();
        if ($invalidCount !== 0) {
            throw new RuntimeException('FamilyCode backfill produced null or malformed values.');
        }

        $duplicateCount = (int) $connection->query(
            'SELECT COUNT(*) FROM ('
            . 'SELECT `family_code` FROM `families` GROUP BY `family_code` HAVING COUNT(*) > 1'
            . ') AS `duplicate_family_codes`'
        )->fetchColumn();
        if ($duplicateCount !== 0) {
            throw new RuntimeException('FamilyCode backfill produced duplicate values.');
        }

        $connection->exec(
            'ALTER TABLE `families` MODIFY COLUMN `family_code` '
            . 'CHAR(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL'
        );
        $connection->exec(
            'ALTER TABLE `families` ADD UNIQUE KEY `uq_families_family_code` (`family_code`)'
        );
    }

    public function down(PDO $connection): void
    {
        $connection->exec('ALTER TABLE `families` DROP INDEX `uq_families_family_code`');
        $connection->exec('ALTER TABLE `families` DROP COLUMN `family_code`');
    }

    public function version(): string
    {
        return '011_add_family_code_to_families';
    }
}
