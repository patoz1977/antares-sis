<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CatalogService;
use App\Services\FamilyServiceInterface;
use Core\Http\Request;
use InvalidArgumentException;

final class FamilyController extends Controller
{
    public function __construct(
        private FamilyServiceInterface $familyService,
        private CatalogService $catalogService
    ) {
    }

    public function index(): string
    {
        $families = $this->familyService->list();

        return $this->view('families.index', [
            'title' => 'Families',
            'families' => $families,
            'catalogs' => $this->loadCatalogs(),
        ]);
    }

    public function show(int $id): string
    {
        $family = $this->familyService->find($id);

        if ($family === null) {
            http_response_code(404);

            return $this->view('families.show', [
                'title' => 'Family not found',
                'family' => null,
                'catalogs' => $this->loadCatalogs(),
            ]);
        }

        return $this->view('families.show', [
            'title' => 'Family details',
            'family' => $family,
            'catalogs' => $this->loadCatalogs(),
        ]);
    }

    public function create(): string
    {
        return $this->view('families.create', [
            'title' => 'Create family',
            'old' => [],
            'errorMessage' => null,
            'catalogs' => $this->loadCatalogs(),
        ]);
    }

    public function store(): string
    {
        $request = new Request();
        $input = $request->input();

        try {
            $familyId = $this->familyService->create($input);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);

            return $this->view('families.create', [
                'title' => 'Create family',
                'old' => $input,
                'errorMessage' => $exception->getMessage(),
                'catalogs' => $this->loadCatalogs(),
            ]);
        }

        header(sprintf('Location: /families/%d', $familyId));
        http_response_code(302);

        return '';
    }

    public function edit(int $id): string
    {
        $family = $this->familyService->find($id);

        if ($family === null) {
            http_response_code(404);

            return $this->view('families.edit', [
                'title' => 'Family not found',
                'family' => null,
                'errorMessage' => null,
                'catalogs' => $this->loadCatalogs(),
            ]);
        }

        return $this->view('families.edit', [
            'title' => 'Edit family',
            'family' => $family,
            'errorMessage' => null,
            'catalogs' => $this->loadCatalogs(),
        ]);
    }

    public function update(int $id): string
    {
        $request = new Request();
        $input = $request->input();

        try {
            $this->familyService->update($id, $input);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);

            $family = $this->familyService->find($id);

            if ($family === null) {
                $family = ['id' => $id];
            }

            foreach ($input as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $family[$key] = $value;
                }
            }

            return $this->view('families.edit', [
                'title' => 'Edit family',
                'family' => $family,
                'errorMessage' => $exception->getMessage(),
                'catalogs' => $this->loadCatalogs(),
            ]);
        }

        header(sprintf('Location: /families/%d', $id));
        http_response_code(302);

        return '';
    }

    public function deactivate(int $id): string
    {
        try {
            $this->familyService->deactivate($id);
        } catch (InvalidArgumentException) {
            http_response_code(404);

            return 'Family not found';
        }

        header('Location: /families');
        http_response_code(302);

        return '';
    }

    private function loadCatalogs(): array
    {
        return [
            'statuses' => $this->catalogService->getStatuses(),
        ];
    }
}
