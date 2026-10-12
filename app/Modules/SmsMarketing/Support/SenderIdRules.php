<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Support;

use App\Modules\SmsMarketing\Enums\SenderIdType;

/**
 * Shape rules for a sender id, as most carriers apply them:
 *  - masking (brand) ids are alphanumeric, 3 to 11 characters, with at least one letter, no spaces;
 *  - non-masking ids are numeric: 3 to 15 digits with an optional leading `+`.
 *
 * Passing these rules means the text has a valid shape. It does not mean a carrier or regulator accepts it:
 * registration is recorded by the workspace and never checked here.
 */
final class SenderIdRules
{
    public const int MASKING_MIN = 3;

    public const int MASKING_MAX = 11;

    public const int NUMERIC_MIN = 3;

    public const int NUMERIC_MAX = 15;

    public static function isValid(SenderIdType $type, string $value): bool
    {
        return match ($type) {
            SenderIdType::Masking => preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9]{'.self::MASKING_MIN.','.self::MASKING_MAX.'}$/', $value) === 1,
            SenderIdType::NonMasking => preg_match('/^\+?\d{'.self::NUMERIC_MIN.','.self::NUMERIC_MAX.'}$/', $value) === 1,
        };
    }

    /** The type a value looks like, or null when it fits neither shape. */
    public static function guess(string $value): ?SenderIdType
    {
        foreach (SenderIdType::cases() as $type) {
            if (self::isValid($type, $value)) {
                return $type;
            }
        }

        return null;
    }
}
