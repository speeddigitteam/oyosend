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
    'bracketed trunk zero after the country code' => ['+880 (0)1712345678', '+8801712345678'],
    'bracketed trunk zero with double zero' => ['00880 (0) 1712-345678', '+8801712345678'],
    'dots between groups' => ['01712.345.678', '+8801712345678'],
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
    'trailing extension text' => ['+1 415 555 2671 ext 12'],
    'extension with x' => ['+14155552671x12'],
    'label before a local number' => ['Tel: 01712345678'],
    'two numbers' => ['01712345678 / 01812345678'],
    'plus in the middle' => ['0171+2345678'],
    'trailing text after a bangladesh number' => ['01712345678 (office)'],
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

it('reads the same way in the core copy used by the communication platform', function (string $raw, string $expected) {
    expect(App\Core\Communication\Support\PhoneNumber::normalize($raw))->toBe($expected);
})->with('numbers');

it('refuses the same input in the core copy', function (string $raw) {
    expect(App\Core\Communication\Support\PhoneNumber::normalize($raw))->toBeNull();
})->with('refused');
