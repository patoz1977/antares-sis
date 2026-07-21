<?php

declare(strict_types=1);

namespace App\Repositories;

class UserRepository extends BaseRepository
{
    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, person_id, status_id, username, email, password_hash, password_changed_at, last_login_at, failed_login_attempts, locked_until, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM users WHERE id = :id';

        return $this->fetchOne($sql, [':id' => $id]);
    }

    public function findByUsername(string $username): ?array
    {
        $sql = 'SELECT id, person_id, status_id, username, email, password_hash, password_changed_at, last_login_at, failed_login_attempts, locked_until, created_at, updated_at, deleted_at, created_by, updated_by, deleted_by FROM users WHERE username = :username';

        return $this->fetchOne($sql, [':username' => $username]);
    }

    public function existsUsername(string $username): bool
    {
        $sql = 'SELECT id FROM users WHERE username = :username';
        $result = $this->fetchOne($sql, [':username' => $username]);

        return $result !== null;
    }

    public function updateLastLogin(int $id): void
    {
        $sql = 'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id';
        $this->execute($sql, [':id' => $id]);
    }
}
