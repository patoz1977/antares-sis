<?php

declare(strict_types=1);

namespace App\Repositories;

class CatalogRepository extends BaseRepository
{
    public function getStatuses(): array
    {
        $sql = "SELECT s.id, COALESCE(NULLIF(TRIM(s.description), ''), s.name) AS description "
            . 'FROM statuses s '
            . 'INNER JOIN status_types st ON st.id = s.status_type_id '
            . 'WHERE st.code = :statusTypeCode AND s.deleted_at IS NULL '
            . 'ORDER BY s.id ASC';

        return $this->fetchAll($sql, [':statusTypeCode' => 'PERSON_STATUS']);
    }

    public function getDocumentTypes(): array
    {
        $sql = "SELECT id, COALESCE(NULLIF(TRIM(description), ''), name) AS description "
            . 'FROM document_types '
            . 'WHERE deleted_at IS NULL '
            . 'ORDER BY id ASC';

        return $this->fetchAll($sql);
    }

    public function getGenders(): array
    {
        $sql = "SELECT id, COALESCE(NULLIF(TRIM(description), ''), name) AS description "
            . 'FROM genders '
            . 'WHERE deleted_at IS NULL '
            . 'ORDER BY id ASC';

        return $this->fetchAll($sql);
    }

    public function getNationalities(): array
    {
        $sql = "SELECT id, COALESCE(NULLIF(TRIM(description), ''), name) AS description "
            . 'FROM nationalities '
            . 'WHERE deleted_at IS NULL '
            . 'ORDER BY id ASC';

        return $this->fetchAll($sql);
    }
}
