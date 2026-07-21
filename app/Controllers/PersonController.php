<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PersonServiceInterface;
use Core\Http\Request;
use InvalidArgumentException;

final class PersonController extends Controller
{
    public function __construct(
        private PersonServiceInterface $personService
    ) {
    }

    public function index(): string
    {
        $persons = $this->personService->list();

        return $this->view('persons.index', [
            'title' => 'Persons',
            'persons' => $persons,
        ]);
    }

    public function show(int $id): string
    {
        $person = $this->personService->find($id);

        if ($person === null) {
            http_response_code(404);

            return $this->view('persons.show', [
                'title' => 'Person not found',
                'person' => null,
            ]);
        }

        return $this->view('persons.show', [
            'title' => 'Person details',
            'person' => $person,
        ]);
    }

    public function create(): string
    {
        return $this->view('persons.create', [
            'title' => 'Create person',
            'errorMessage' => null,
            'old' => [],
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

            return $this->view('persons.create', [
                'title' => 'Create person',
                'errorMessage' => $exception->getMessage(),
                'old' => $input,
            ]);
        }

        header(sprintf('Location: /persons/%d', $personId));
        http_response_code(302);

        return '';
    }

    public function edit(int $id): string
    {
        $person = $this->personService->find($id);

        if ($person === null) {
            http_response_code(404);

            return $this->view('persons.edit', [
                'title' => 'Person not found',
                'person' => null,
                'errorMessage' => null,
            ]);
        }

        return $this->view('persons.edit', [
            'title' => 'Edit person',
            'person' => $person,
            'errorMessage' => null,
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
}
