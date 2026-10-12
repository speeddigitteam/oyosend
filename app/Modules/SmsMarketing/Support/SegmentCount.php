<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Support;

/**
 * The result of {@see SegmentCounter::count()}.
 *
 * `units` are GSM-7 septets (an extension character is two) or UTF-16 code units (a character outside the
 * Basic Multilingual Plane is two). `perSegment` is the capacity that applied: 160 or 153 for GSM-7, 70 or 67
 * for UCS-2 (153 and 67 once the text is concatenated over several segments).
 */
final readonly class SegmentCount
{
    public const string GSM7 = 'gsm7';

    public const string UCS2 = 'ucs2';

    /**
     * @param  list<string>  $unicodeCharacters  Up to five distinct characters that forced UCS-2.
     */
    public function __construct(
        public string $encoding,
        public int $characters,
        public int $units,
        public int $segments,
        public int $perSegment,
        public int $remaining,
        public array $unicodeCharacters = [],
    ) {}

    public function isUnicode(): bool
    {
        return $this->encoding === self::UCS2;
    }

    /** @return array{encoding: string, characters: int, units: int, segments: int, perSegment: int, remaining: int, unicodeCharacters: list<string>} */
    public function toArray(): array
    {
        return [
            'encoding' => $this->encoding,
            'characters' => $this->characters,
            'units' => $this->units,
            'segments' => $this->segments,
            'perSegment' => $this->perSegment,
            'remaining' => $this->remaining,
            'unicodeCharacters' => $this->unicodeCharacters,
        ];
    }
}
