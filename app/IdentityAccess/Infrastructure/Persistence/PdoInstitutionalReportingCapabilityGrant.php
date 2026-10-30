<?php

declare(strict_types=1);

namespace App\IdentityAccess\Infrastructure\Persistence;

use App\IdentityAccess\Application\CanAccessAllReports;
use App\IdentityAccess\Application\InstitutionalReportingCapabilityGrant;
use App\IdentityAccess\Domain\ValueObject\UserId;
use Core\Database\ConnectionManager;
use PDO;
use PDOException;

final class PdoInstitutionalReportingCapabilityGrant implements InstitutionalReportingCapabilityGrant
{
    private PDO $connection;

    public function __construct(ConnectionManager $connectionManager)
    {
        $this->connection = $connectionManager->connection();
    }

    public function grant(UserId $userId): void
    {
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO user_capabilities (user_id, capability_code) '
                . 'VALUES (:userId, :capabilityCode)'
            );
            $statement->execute([
                ':userId' => $userId->value(),
                ':capabilityCode' => CanAccessAllReports::CAPABILITY,
            ]);
        } catch (PDOException $exception) {
            if (!$this->hasExactAssignment($userId)) {
                throw $exception;
            }
        }
    }

    private function hasExactAssignment(UserId $userId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM user_capabilities '
            . 'WHERE user_id = :userId AND capability_code = :capabilityCode LIMIT 1'
        );
        $statement->execute([
            ':userId' => $userId->value(),
            ':capabilityCode' => CanAccessAllReports::CAPABILITY,
        ]);

        return $statement->fetchColumn() !== false;
    }
}
