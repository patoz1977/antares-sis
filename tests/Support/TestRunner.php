<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Throwable;

final class TestRunner
{
    private array $tests = [];

    public function add(string $name, callable $test): void
    {
        $this->tests[$name] = $test;
    }

    public function run(): void
    {
        $failures = [];

        foreach ($this->tests as $name => $test) {
            try {
                $test();
                echo sprintf("PASS %s\n", $name);
            } catch (Throwable $exception) {
                $failures[] = sprintf('%s: %s', $name, $exception->getMessage());
                echo sprintf("FAIL %s\n", $name);
            }
        }

        echo sprintf(
            "\n%d tests, %d failures.\n",
            count($this->tests),
            count($failures)
        );

        if ($failures !== []) {
            throw new RuntimeException(implode("\n", $failures));
        }
    }
}
