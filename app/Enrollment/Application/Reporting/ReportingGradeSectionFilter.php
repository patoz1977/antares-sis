<?php

declare(strict_types=1);

namespace App\Enrollment\Application\Reporting;

use App\Enrollment\Application\Reporting\Dto\ReportingGradeSectionOption;
use InvalidArgumentException;

final readonly class ReportingGradeSectionFilter
{
    /** @param list<ReportingGradeSectionOption> $options */
    private function __construct(
        public int $academicPeriodId,
        private array $options,
    ) {
        if ($academicPeriodId <= 0) {
            throw new InvalidArgumentException('Reporting filter requires a positive AcademicPeriod identity.');
        }
    }

    public static function all(int $academicPeriodId): self
    {
        return new self($academicPeriodId, []);
    }

    /** @param list<ReportingGradeSectionOption> $options */
    public static function selected(int $academicPeriodId, array $options): self
    {
        if ($options === []) {
            throw new InvalidArgumentException('A selected reporting filter requires at least one option.');
        }

        $seen = [];
        foreach ($options as $option) {
            $key = $option->key();
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Reporting filter contains a duplicate Grade/Section option.');
            }
            $seen[$key] = true;
        }

        return new self($academicPeriodId, array_values($options));
    }

    public function isAll(): bool
    {
        return $this->options === [];
    }

    /** @return list<ReportingGradeSectionOption> */
    public function options(): array
    {
        return $this->options;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(
            static fn (ReportingGradeSectionOption $option): string => $option->key(),
            $this->options,
        );
    }

    public function assertAcademicPeriod(int $academicPeriodId): void
    {
        if ($this->academicPeriodId !== $academicPeriodId) {
            throw new InvalidArgumentException('Reporting filter belongs to a different AcademicPeriod.');
        }
    }
}
