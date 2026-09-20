<?php

declare(strict_types=1);

namespace App\Family\Http;

final readonly class FamilyMemberLabels
{
    /**
     * @param array<int, string> $representatives
     * @param array<int, string> $students
     * @param array<int, string> $relationships
     */
    public function __construct(
        private array $representatives,
        private array $students,
        private array $relationships,
    ) {
    }

    public function representative(int $id): string
    {
        return $this->representatives[$id] ?? 'Representante no disponible';
    }

    public function student(int $id): string
    {
        return $this->students[$id] ?? 'Estudiante no disponible';
    }

    public function relationship(int $id): string
    {
        return $this->relationships[$id] ?? 'Relación no disponible';
    }
}
