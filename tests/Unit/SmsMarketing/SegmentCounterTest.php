<?php

declare(strict_types=1);

use App\Modules\SmsMarketing\Support\SegmentCount;
use App\Modules\SmsMarketing\Support\SegmentCounter;

/**
 * The counter must agree with how handsets and carriers segment text (GSM 03.38 and UCS-2).
 * Each row: text, encoding, units, segments, per-segment capacity.
 */
dataset('segments', [
    'empty text is no segment' => ['', SegmentCount::GSM7, 0, 0, 160],
    'one short ascii message' => ['Hello', SegmentCount::GSM7, 5, 1, 160],
    'exactly 160 gsm characters' => [str_repeat('a', 160), SegmentCount::GSM7, 160, 1, 160],
    '161 gsm characters need two segments' => [str_repeat('a', 161), SegmentCount::GSM7, 161, 2, 153],
    '306 gsm characters are two segments of 153' => [str_repeat('a', 306), SegmentCount::GSM7, 306, 2, 153],
    '307 gsm characters are three segments' => [str_repeat('a', 307), SegmentCount::GSM7, 307, 3, 153],
    'extension character counts twice' => [str_repeat('a', 159).'€', SegmentCount::GSM7, 161, 2, 153],
    'extension characters at the single limit' => [str_repeat('a', 156).'[]', SegmentCount::GSM7, 160, 1, 160],
    'curly braces and pipe are extension' => ['{a}|', SegmentCount::GSM7, 7, 1, 160],
    'gsm accented letters are single' => ['èéùìòÇØøÅå', SegmentCount::GSM7, 10, 1, 160],
    'newline and carriage return are gsm' => ["a\nb\r", SegmentCount::GSM7, 4, 1, 160],
    'bengali is ucs2' => ['আমি', SegmentCount::UCS2, 3, 1, 70],
    'exactly 70 ucs2 characters' => [str_repeat('অ', 70), SegmentCount::UCS2, 70, 1, 70],
    '71 ucs2 characters need two segments' => [str_repeat('অ', 71), SegmentCount::UCS2, 71, 2, 67],
    '134 ucs2 characters are two segments of 67' => [str_repeat('অ', 134), SegmentCount::UCS2, 134, 2, 67],
    '135 ucs2 characters are three segments' => [str_repeat('অ', 135), SegmentCount::UCS2, 135, 3, 67],
    'one unicode character makes the whole text ucs2' => [str_repeat('a', 69).'আ', SegmentCount::UCS2, 70, 1, 70],
    'smart quote forces ucs2' => ['It’s on', SegmentCount::UCS2, 7, 1, 70],
    'emoji is a surrogate pair of two units' => ['😀', SegmentCount::UCS2, 2, 1, 70],
    'thirty five emoji fill one segment' => [str_repeat('😀', 35), SegmentCount::UCS2, 70, 1, 70],
    'thirty six emoji need two segments' => [str_repeat('😀', 36), SegmentCount::UCS2, 72, 2, 67],
]);

it('counts segments, encoding and units', function (string $text, string $encoding, int $units, int $segments, int $perSegment) {
    $count = SegmentCounter::count($text);

    expect($count->encoding)->toBe($encoding)
        ->and($count->units)->toBe($units)
        ->and($count->segments)->toBe($segments)
        ->and($count->perSegment)->toBe($perSegment);
})->with('segments');

it('never splits a surrogate pair across segments', function () {
    // 66 BMP characters then an emoji: the pair would straddle the 67-unit boundary, so it moves on whole.
    $count = SegmentCounter::count(str_repeat('অ', 66).'😀'.str_repeat('অ', 10));

    expect($count->encoding)->toBe(SegmentCount::UCS2)
        ->and($count->units)->toBe(78)
        ->and($count->segments)->toBe(2)
        // Segment one holds 66 units (the pair did not fit); segment two holds 2 + 10 = 12 units.
        ->and($count->remaining)->toBe(67 - 12);
});

it('never splits an extension character across segments', function () {
    // 152 septets then a 2-septet extension character: it starts a new segment.
    $count = SegmentCounter::count(str_repeat('a', 152).'€'.str_repeat('b', 8));

    expect($count->encoding)->toBe(SegmentCount::GSM7)
        ->and($count->units)->toBe(162)
        ->and($count->segments)->toBe(2)
        ->and($count->remaining)->toBe(153 - 10);
});

it('reports the characters that forced unicode', function () {
    $count = SegmentCounter::count('Eid mubarak ঈদ মুবারক');

    expect($count->isUnicode())->toBeTrue()
        ->and($count->unicodeCharacters)->toContain('ঈ')
        ->and(count($count->unicodeCharacters))->toBeLessThanOrEqual(5);
});

it('bounds the work for a very long text', function () {
    $count = SegmentCounter::count(str_repeat('a', 100000));

    expect($count->segments)->toBe((int) ceil(100000 / 153));
});

it('survives invalid utf-8 without throwing', function () {
    $count = SegmentCounter::count("abc\xFF\xFEdef");

    expect($count->segments)->toBeGreaterThanOrEqual(1);
});
