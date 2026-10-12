<?php

declare(strict_types=1);

use App\Modules\SmsMarketing\Support\PhoneNumber;

dataset('numbers', [
    'international with plus' => ['+8801712345678', '+8801712345678'],
    'international with spaces and dashes' => ['+880 1712-345 678', '+8801712345678'],
    'bangladesh local with leading zero' => ['01712345678', '+8801712345678'],
    'bangladesh local with country code' => ['8801712345678', '+8801712345678'],
    'bangladesh local without zero' => ['1712345678', '+8801712345678'],
    'double zero prefix' => ['008801712345678', '+8801712345678'],
    'bengali digits' => ['০১৭১২৩৪৫৬৭৮', '+8801712345678'],
    'bengali digits with plus' => ['+৮৮০১৭১২৩৪৫৬৭৮', '+8801712345678'],
    'parentheses and dots' => ['(0171) 234.5678', '+8801712345678'],
    'another country with plus' => ['+14155550123', '+14155550123'],
    'another country with double zero' => ['0014155550123', '+14155550123'],
    'every operator prefix 013' => ['01312345678', '+8801312345678'],
    'every operator prefix 019' => ['01912345678', '+8801912345678'],
]);

it('normalizes to E.164', function (string $raw, string $expected) {
    expect(PhoneNumber::normalize($raw))->toBe($expected);
})->with('numbers');

dataset('refused', [
    'empty' => [''],
    'spaces' => ['   '],
    'letters' => ['abc'],
    'too short' => ['12345'],
    'bangladesh with a wrong operator digit' => ['01012345678'],
    'bangladesh number too short' => ['0171234567'],
    'bangladesh number too long' => ['017123456789'],
    'plus bangladesh too short' => ['+880171234567'],
    'plus with a leading zero after the country code' => ['+88001712345678'],
    'plus then zero' => ['+0123456789'],
    'a local number of another country without a plus' => ['5551234567'],
    'sixteen digits' => ['+1234567890123456'],
    'a landline written locally' => ['0221234567'],
]);

it('refuses what it cannot read without guessing', function (string $raw) {
    expect(PhoneNumber::normalize($raw))->toBeNull();
})->with('refused');

it('lists the spellings a stored number may have, for an exact lookup', function () {
    $variants = PhoneNumber::variants('+8801712345678');

    expect($variants)->toContain('+8801712345678', '8801712345678', '01712345678', '1712345678')
        ->and(PhoneNumber::variants('+14155550123'))->toBe(['+14155550123', '14155550123']);
});

it('tells a Bangladesh number from another', function () {
    expect(PhoneNumber::isBangladesh('+8801712345678'))->toBeTrue()
        ->and(PhoneNumber::isBangladesh('+14155550123'))->toBeFalse();
});
