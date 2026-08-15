<?php

declare(strict_types=1);

namespace App\InstitutionalDocuments\Http;

use RuntimeException;

final class InvalidFormInput extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid form input.');
    }
}
