<?php

declare(strict_types=1);

namespace App\Representative\Application;

use App\Representative\Application\Dto\RepresentativeOutput;
use App\Representative\Application\Exception\RepresentativeNotFound;
use App\Representative\Domain\RepresentativeRepository;
use App\Representative\Domain\ValueObject\RepresentativeId;

final readonly class GetRepresentative
{
    public function __construct(private RepresentativeRepository $representatives)
    {
    }

    public function handle(int $representativeId): RepresentativeOutput
    {
        $id = new RepresentativeId($representativeId);
        $representative = $this->representatives->findById($id);
        if ($representative === null) {
            throw new RepresentativeNotFound('Representative was not found.');
        }

        return RepresentativeOutput::fromRepresentative($representative, $id);
    }
}
