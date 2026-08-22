<?php

declare(strict_types=1);

namespace App\Enrollment\Http;

use App\Enrollment\Application\RepresentativePortal\Dto\RepresentativeEnrollmentPortalState;
use Core\Http\Request;
use JsonException;

final class RepresentativeEnrollmentAutosaveResponder
{
    public function isRequested(): bool
    {
        $accept = (new Request())->server()['HTTP_ACCEPT'] ?? '';

        return is_scalar($accept)
            && str_contains(strtolower((string) $accept), 'application/json');
    }

    public function success(
        string $section,
        RepresentativeEnrollmentPortalState $state,
        string $csrfToken,
    ): string {
        $payload = [
            'ok' => true,
            'state' => 'saved',
            'section' => $section,
            'sectionStatus' => $this->sectionStatus($state, $section),
            'csrfToken' => $csrfToken,
        ];

        return $this->json($payload, 200);
    }

    /** @param list<string> $errors */
    public function failure(
        string $section,
        array $errors,
        int $status,
        ?string $csrfToken = null,
        bool $reloadRequired = false,
        ?string $redirect = null,
    ): string {
        $payload = [
            'ok' => false,
            'state' => 'error',
            'section' => $section,
            'errors' => $errors,
        ];
        if ($csrfToken !== null) {
            $payload['csrfToken'] = $csrfToken;
        }
        if ($reloadRequired) {
            $payload['reloadRequired'] = true;
        }
        if ($redirect !== null) {
            $payload['redirect'] = $redirect;
        }

        return $this->json($payload, $status);
    }

    private function sectionStatus(RepresentativeEnrollmentPortalState $state, string $section): string
    {
        return match ($section) {
            'representative-personal' => $state->progress->representativePersonal->value,
            'representative-contact' => $state->progress->representativeContact->value,
            'representative-employment' => $state->progress->employment->value,
            'student-personal' => $state->progress->studentPersonal->value,
            'billing' => $state->progress->billing->value,
            'medical' => $state->progress->medical->value,
            'transport' => $state->progress->transport->value,
            'leave-alone' => $state->progress->pickupOrLeaveAlone->value,
            default => throw new \LogicException('Unsupported Representative Enrollment autosave section.'),
        };
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status): string
    {
        try {
            $body = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');

            return '{"ok":false,"state":"error","errors":["The information could not be saved."]}';
        }

        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);

        return $body;
    }
}
