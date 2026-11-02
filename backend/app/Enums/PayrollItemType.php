<?php

namespace App\Enums;

enum PayrollItemType: string
{
    case BasicSalary = 'basic_salary';
    case Allowance = 'allowance';
    case Bonus = 'bonus';
    case Overtime = 'overtime';
    case Deduction = 'deduction';
    case Bpjs = 'bpjs';
    case Pph21 = 'pph21';

    public function isDeduction(): bool
    {
        return in_array($this, [self::Deduction, self::Bpjs, self::Pph21], true);
    }

    /** @return list<string> */
    public static function earnings(): array
    {
        return self::valuesWhere(fn (self $type) => ! $type->isDeduction());
    }

    /** @return list<string> */
    public static function deductions(): array
    {
        return self::valuesWhere(fn (self $type) => $type->isDeduction());
    }

    /** Jenis komponen yang boleh diubah oleh sebuah role. */
    public static function editableBy(Role $role): array
    {
        return match ($role) {
            Role::Hr => self::cases(),
            Role::Finance => [self::Bpjs, self::Pph21],
            default => [],
        };
    }

    private static function valuesWhere(callable $filter): array
    {
        return array_values(array_map(
            fn (self $type) => $type->value,
            array_filter(self::cases(), $filter)
        ));
    }
}
