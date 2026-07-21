<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CatalogRepository;

class CatalogService implements CatalogServiceInterface
{
    public function __construct(
        private CatalogRepository $catalogRepository
    ) {
    }

    public function getStatuses(): array
    {
        return $this->catalogRepository->getStatuses();
    }

    public function getDocumentTypes(): array
    {
        return $this->catalogRepository->getDocumentTypes();
    }

    public function getGenders(): array
    {
        return $this->catalogRepository->getGenders();
    }

    public function getNationalities(): array
    {
        return $this->catalogRepository->getNationalities();
    }
}
