<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CatalogService;
use App\Services\PersonServiceInterface;
use Core\Http\Request;
use InvalidArgumentException;

final class PersonController extends Controller
{
    public function __construct(
        private PersonServiceInterface $personService,
        private CatalogService $catalogService
    ) {
    }

    public function index(): string
    {
        $persons = $this->personService->list();
        $catalogs = $this->loadCatalogs();

        return $this->view('persons.index', [
            'title' => 'Persons',
            'persons' => $persons,
            'catalogs' => $catalogs,
        ]);
    }

    public function show(int $id): string
    {
        $person = $this->personService->find($id);
        $catalogs = $this->loadCatalogs();

        if ($person === null) {
            http_response_code(404);

            return $this->view('persons.show', [
                'title' => 'Person not found',
                'person' => null,
                'catalogs' => $catalogs,
            ]);
        }

        return $this->view('persons.show', [
            'title' => 'Person details',
            'person' => $person,
            'catalogs' => $catalogs,
        ]);
    }

    public function create(): string
    {
        $catalogs = $this->loadCatalogs();

        return $this->view('persons.create', [
            'title' => 'Create person',
            'errorMessage' => null,
            'old' => [],
            'catalogs' => $catalogs,
        ]);
    }

    public function store(): string
    {
        $request = new Request();
        $input = $request->input();

        try {
            $personId = $this->personService->create($input);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $catalogs = $this->loadCatalogs();

            return $this->view('persons.create', [
                'title' => 'Create person',
                'errorMessage' => $exception->getMessage(),
                'old' => $input,
                'catalogs' => $catalogs,
            ]);
        }

        header(sprintf('Location: /persons/%d', $personId));
        http_response_code(302);

        return '';
    }

    public function edit(int $id): string
    {
        $person = $this->personService->find($id);
        $catalogs = $this->loadCatalogs();

        if ($person === null) {
            http_response_code(404);

            return $this->view('persons.edit', [
                'title' => 'Person not found',
                'person' => null,
                'errorMessage' => null,
                'catalogs' => $catalogs,
            ]);
        }

        return $this->view('persons.edit', [
            'title' => 'Edit person',
            'person' => $person,
            'errorMessage' => null,
            'catalogs' => $catalogs,
        ]);
    }

    public function update(int $id): string
    {
        $request = new Request();
        $input = $request->input();

        try {
            $this->personService->update($id, $input);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $catalogs = $this->loadCatalogs();

            $person = $this->personService->find($id);

            if ($person === null) {
                $person = ['id' => $id];
            }

            foreach ($input as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $person[$key] = $value;
                }
            }

            return $this->view('persons.edit', [
                'title' => 'Edit person',
                'person' => $person,
                'errorMessage' => $exception->getMessage(),
                'catalogs' => $catalogs,
            ]);
        }

        header(sprintf('Location: /persons/%d', $id));
        http_response_code(302);

        return '';
    }

    public function deactivate(int $id): string
    {
        try {
            $this->personService->deactivate($id);
        } catch (InvalidArgumentException) {
            http_response_code(404);

            return 'Person not found';
        }

        header('Location: /persons');
        http_response_code(302);

        return '';
    }

    private function loadCatalogs(): array
    {
        return [
            'statuses' => $this->catalogService->getStatuses(),
            'documentTypes' => $this->catalogService->getDocumentTypes(),
            'genders' => $this->catalogService->getGenders(),
            'nationalities' => $this->catalogService->getNationalities(),
        ];
    }
}
