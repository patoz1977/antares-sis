<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\IdentityAccess\Application\Contract\CsrfTokenManager;
use App\IdentityAccess\Application\GetAuthenticatedRepresentative;
use App\IdentityAccess\Application\GetAuthenticatedUser;
use Core\Http\Request;

final readonly class SharedShellDataFactory
{
    public function __construct(
        private GetAuthenticatedUser $getAuthenticatedUser,
        private GetAuthenticatedRepresentative $getAuthenticatedRepresentative,
        private CsrfTokenManager $csrf,
        private WhiteLabelBranding $branding,
    ) {
    }

    public function forRequest(Request $request): ShellViewData
    {
        $user = $this->getAuthenticatedUser->handle();
        if ($user === null) {
            return new ShellViewData($this->branding, 'public', [], null);
        }

        if ($this->getAuthenticatedRepresentative->handle() !== null) {
            return new ShellViewData(
                $this->branding,
                'representative',
                $this->representativeNavigation($request->uri()),
                $this->csrf->token(),
            );
        }

        if ($user->loginIdentifier === 'admin') {
            return new ShellViewData(
                $this->branding,
                'admin',
                $this->adminNavigation($request->uri()),
                $this->csrf->token(),
            );
        }

        return new ShellViewData($this->branding, 'authenticated', [], $this->csrf->token());
    }

    private function adminNavigation(string $path): array
    {
        return [
            $this->item('Inicio', '/', 'bi-house-door', $path, ['/'], true),
            $this->item('Personas', '/persons', 'bi-person-vcard', $path, ['/persons']),
            $this->item('Familias', '/families', 'bi-people', $path, ['/families', '/representative-users']),
            $this->item('Confirmaciones institucionales', '/institutional-acknowledgements', 'bi-file-earmark-check', $path, ['/institutional-acknowledgements']),
            $this->item('Matrículas', '/enrollments', 'bi-journal-check', $path, ['/enrollments']),
            $this->item('Reportes', '/reports/enrollments', 'bi-bar-chart', $path, ['/reports/enrollments']),
        ];
    }

    private function representativeNavigation(string $path): array
    {
        return [
            $this->item('Inicio', '/representative', 'bi-house-door', $path, ['/representative'], true),
            $this->item('Matrícula', '/representative/enrollment', 'bi-journal-check', $path, ['/representative/enrollment']),
            $this->item('Recursos familiares', '/representative/resources', 'bi-house-heart', $path, ['/representative/resources']),
            $this->item('Confirmaciones', '/representative/acknowledgements', 'bi-file-earmark-check', $path, ['/representative/acknowledgements']),
        ];
    }

    private function item(
        string $label,
        string $href,
        string $icon,
        string $currentPath,
        array $activePrefixes,
        bool $exact = false,
    ): array {
        $active = false;
        foreach ($activePrefixes as $prefix) {
            if ($exact ? $currentPath === $prefix : $this->matchesPrefix($currentPath, $prefix)) {
                $active = true;
                break;
            }
        }

        return compact('label', 'href', 'icon', 'active');
    }

    private function matchesPrefix(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
