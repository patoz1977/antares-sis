<?php

declare(strict_types=1);

namespace App\Family\Infrastructure\Persistence;

use App\Family\Http\FamilyMemberLabels;
use App\Family\Http\FamilyMemberLabelsProvider;
use Core\Database\ConnectionManager;
use PDO;

final class PdoFamilyMemberLabelsProvider implements FamilyMemberLabelsProvider
{
    private PDO $connection;

    public function __construct(ConnectionManager $connections)
    {
        $this->connection = $connections->connection();
    }

    public function forFamily(int $familyId): FamilyMemberLabels
    {
        $statement = $this->connection->prepare(
            "SELECT 'representative' AS member_kind, fr.representative_id AS member_id, "
            . 'p.first_name, p.middle_name, p.first_surname, p.second_surname, '
            . 'fr.relationship_type_id, rt.name AS relationship_name '
            . 'FROM family_representatives fr '
            . 'INNER JOIN representatives r ON r.id = fr.representative_id '
            . 'INNER JOIN persons p ON p.id = r.person_id '
            . 'INNER JOIN relationship_types rt ON rt.id = fr.relationship_type_id '
            . 'WHERE fr.family_id = :representative_family_id '
            . 'UNION ALL '
            . "SELECT 'student' AS member_kind, fs.student_id AS member_id, "
            . 'p.first_name, p.middle_name, p.first_surname, p.second_surname, '
            . 'NULL AS relationship_type_id, NULL AS relationship_name '
            . 'FROM family_students fs '
            . 'INNER JOIN students s ON s.id = fs.student_id '
            . 'INNER JOIN persons p ON p.id = s.person_id '
            . 'WHERE fs.family_id = :student_family_id '
            . 'ORDER BY member_kind, member_id'
        );
        $statement->execute([
            'representative_family_id' => $familyId,
            'student_family_id' => $familyId,
        ]);

        $representatives = [];
        $students = [];
        $relationships = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim(implode(' ', array_filter([
                $row['first_name'], $row['middle_name'],
                $row['first_surname'], $row['second_surname'],
            ], static fn (mixed $part): bool => is_string($part) && $part !== '')));
            $id = (int) $row['member_id'];
            if ($row['member_kind'] === 'representative') {
                $representatives[$id] = $name;
                $relationships[(int) $row['relationship_type_id']] = (string) $row['relationship_name'];
            } else {
                $students[$id] = $name;
            }
        }

        return new FamilyMemberLabels($representatives, $students, $relationships);
    }
}
