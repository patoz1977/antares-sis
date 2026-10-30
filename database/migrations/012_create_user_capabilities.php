<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaMigration.php';

final class CreateUserCapabilities extends SchemaMigration
{
    private const REPORTS_VIEW_ALL = 'REPORTS_VIEW_ALL';

    public function up(PDO $connection): void
    {
        $created = false;

        try {
            $this->createTables($connection, [
                <<<'SQL'
                    CREATE TABLE `user_capabilities` (
                        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `user_id` BIGINT UNSIGNED NOT NULL,
                        `capability_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                        `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `uq_user_capabilities_user_code` (`user_id`, `capability_code`),
                        CONSTRAINT `fk_user_capabilities_user` FOREIGN KEY (`user_id`)
                            REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    SQL,
            ]);
            $created = true;

            $administrator = $this->legacyAdministrator($connection);
            if ($administrator === null) {
                return;
            }

            $statement = $connection->prepare(
                'INSERT INTO `user_capabilities` (`user_id`, `capability_code`) '
                . 'VALUES (:userId, :capabilityCode)'
            );
            $statement->execute([
                ':userId' => $administrator,
                ':capabilityCode' => self::REPORTS_VIEW_ALL,
            ]);
        } catch (Throwable $exception) {
            if ($created) {
                $connection->exec('DROP TABLE IF EXISTS `user_capabilities`');
            }

            throw new RuntimeException(
                'Unable to create and backfill user capabilities.',
                previous: $exception,
            );
        }
    }

    public function down(PDO $connection): void
    {
        $this->dropTables($connection, ['user_capabilities']);
    }

    public function version(): string
    {
        return '012_create_user_capabilities';
    }

    private function legacyAdministrator(PDO $connection): ?int
    {
        $statement = $connection->prepare(
            'SELECT `id`, `login_identifier`, `normalized_login_identifier` FROM `users` '
            . 'WHERE BINARY `login_identifier` = BINARY :loginIdentifier '
            . 'OR BINARY `normalized_login_identifier` = BINARY :normalizedLoginIdentifier '
            . 'ORDER BY `id` LIMIT 2'
        );
        $statement->execute([
            ':loginIdentifier' => 'admin',
            ':normalizedLoginIdentifier' => 'admin',
        ]);
        $candidates = $statement->fetchAll(PDO::FETCH_ASSOC);

        if ($candidates === []) {
            return null;
        }

        if (count($candidates) !== 1
            || $candidates[0]['login_identifier'] !== 'admin'
            || $candidates[0]['normalized_login_identifier'] !== 'admin') {
            throw new RuntimeException('Legacy administrator identity is incoherent.');
        }

        $id = (int) $candidates[0]['id'];
        if ($id <= 0) {
            throw new RuntimeException('Legacy administrator identity is invalid.');
        }

        return $id;
    }
}
