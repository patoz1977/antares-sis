<?php

declare(strict_types=1);

namespace App\IdentityAccess\Application;

use App\IdentityAccess\Application\Contract\SessionManager;
use InvalidArgumentException;

final readonly class RepresentativeFamilyContextSession
{
    private const FAMILY_ID_KEY = 'representative_family_context_id';

    public function __construct(private SessionManager $session)
    {
    }

    public function selectedFamilyId(): ?int
    {
        $familyId = $this->session->get(self::FAMILY_ID_KEY);
        if ($familyId === null) {
            return null;
        }
        if (!is_int($familyId) || $familyId <= 0) {
            $this->clear();

            return null;
        }

        return $familyId;
    }

    public function select(int $familyId): void
    {
        if ($familyId <= 0) {
            throw new InvalidArgumentException('Selected Family identity must be positive.');
        }

        $this->session->put(self::FAMILY_ID_KEY, $familyId);
    }

    public function clear(): void
    {
        $this->session->remove(self::FAMILY_ID_KEY);
    }
}
