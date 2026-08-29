<?php

declare(strict_types=1);

namespace App\Shared\Http;

final readonly class ShellViewData
{
    public function __construct(
        public WhiteLabelBranding $branding,
        public string $context,
        public array $navigation,
        public ?string $logoutCsrfToken,
    ) {
    }
}
