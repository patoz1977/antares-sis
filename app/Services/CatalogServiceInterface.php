<?php

declare(strict_types=1);

namespace App\Services;

interface CatalogServiceInterface
{
    public function getStatuses(): array;

    public function getDocumentTypes(): array;

    public function getGenders(): array;

    public function getNationalities(): array;
}
