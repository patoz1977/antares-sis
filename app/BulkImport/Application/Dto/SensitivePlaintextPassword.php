<?php

declare(strict_types=1);

namespace App\BulkImport\Application\Dto;

use LogicException;
use WeakMap;

final class SensitivePlaintextPassword
{
    /** @var ?WeakMap<self, string> */
    private static ?WeakMap $values = null;

    public function __construct(string $value)
    {
        self::$values ??= new WeakMap();
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        return self::$values[$this] ?? '';
    }

    public function clear(): void
    {
        if (self::$values !== null && isset(self::$values[$this])) {
            unset(self::$values[$this]);
        }
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->reveal(), $other->reveal());
    }

    public function __destruct()
    {
        $this->clear();
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Plaintext passwords cannot be serialized.');
    }
}
