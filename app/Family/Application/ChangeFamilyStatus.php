<?php

declare(strict_types=1);

namespace App\Family\Application;

use App\Family\Application\Exception\FamilyNotFound;
use App\Family\Application\Exception\InvalidPersistedFamilyResult;
use App\Family\Domain\FamilyRepository;
use App\Family\Domain\FamilyStatus;
use App\Family\Domain\ValueObject\FamilyId;
use App\Shared\Application\TransactionManager;

final readonly class ChangeFamilyStatus
{
    public function __construct(
        private TransactionManager $transactions,
        private FamilyRepository $families,
    ) {
    }

    public function handle(int $familyId, FamilyStatus $target): bool
    {
        return $this->transactions->transactional(function () use ($familyId, $target): bool {
            $id = new FamilyId($familyId);
            $family = $this->families->findByIdForUpdate($id);
            if ($family === null) {
                throw new FamilyNotFound('Family was not found.');
            }

            $changed = $family->status() !== $target;
            if ($target === FamilyStatus::Active) {
                $family->activate();
            } else {
                $family->deactivate();
            }

            $persisted = $this->families->save($family);
            if ($persisted->id()?->equals($id) !== true || $persisted->status() !== $target) {
                throw new InvalidPersistedFamilyResult(
                    'Family repository did not return the requested status.'
                );
            }

            return $changed;
        });
    }
}
