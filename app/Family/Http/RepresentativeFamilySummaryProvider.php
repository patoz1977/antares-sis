<?php

declare(strict_types=1);

namespace App\Family\Http;

use App\Family\Application\GetFamilyMembership;
use App\IdentityAccess\Application\FamilyContext;

/**
 * Read-only, Family-scoped presentation of active members.
 */
final readonly class RepresentativeFamilySummaryProvider
{
    public function __construct(
        private GetFamilyMembership $memberships,
        private FamilyMemberLabelsProvider $labels,
    ) {
    }

    /**
     * @return array{self: array{name: string, relationship: string}, others: list<array{name: string, relationship: string}>, students: list<array{name: string}>}|null
     */
    public function forContext(FamilyContext $context): ?array
    {
        $family = $this->memberships->handle($context->familyId);
        $labels = $this->labels->forFamily($context->familyId);
        $self = null;
        $others = [];
        foreach ($family->representatives as $membership) {
            if (!$membership->isActive) {
                continue;
            }
            $entry = [
                'name' => $labels->representative($membership->representativeId),
                'relationship' => $labels->relationship($membership->relationshipTypeId),
            ];
            if ($membership->representativeId === $context->representativeId) {
                $self = $entry;
            } else {
                $others[] = $entry;
            }
        }
        if ($self === null) {
            return null;
        }

        $students = [];
        foreach ($family->students as $membership) {
            if ($membership->isActive) {
                $students[] = ['name' => $labels->student($membership->studentId)];
            }
        }

        return ['self' => $self, 'others' => $others, 'students' => $students];
    }
}
