<?php

declare(strict_types=1);

use App\Core\Billing\Support\Money;

/*
 * Money is an integer number of minor units plus a currency code. These are
 * the only conversions the server makes: to the decimal string a gateway
 * expects, back from the one a gateway answers with, and to text for mail.
 */

describe('decimals', function () {
    it('knows the currencies whose minor unit is not a hundredth', function (string $currency, int $decimals) {
        expect(Money::decimals($currency))->toBe($decimals);
    })->with([
        'taka' => ['BDT', 2],
        'dollar' => ['USD', 2],
        'lower case' => ['bdt', 2],
        'yen has none' => ['JPY', 0],
        'yen, lower case' => ['jpy', 0],
        'won has none' => ['KRW', 0],
        'dong has none' => ['VND', 0],
        'Kuwaiti dinar has three' => ['KWD', 3],
        'Bahraini dinar has three' => ['BHD', 3],
        'Omani rial has three' => ['OMR', 3],
        'an unknown code is treated like most currencies' => ['XXX', 2],
    ]);
});

describe('decimal', function () {
    it('writes minor units as the decimal string a gateway expects', function (int $minorUnits, string $currency, string $expected) {
        expect(Money::decimal($minorUnits, $currency))->toBe($expected);
    })->with([
        'a round amount' => [150000, 'BDT', '1500.00'],
        'paisa' => [150050, 'BDT', '1500.50'],
        'a single paisa' => [1, 'BDT', '0.01'],
        'five paisa keeps its leading zero' => [5, 'BDT', '0.05'],
        'less than one taka' => [99, 'BDT', '0.99'],
        'nothing' => [0, 'BDT', '0.00'],
        'no thousands separator' => [123456789, 'USD', '1234567.89'],
        'a negative amount (a credit line)' => [-150050, 'BDT', '-1500.50'],
        'a negative amount below one' => [-5, 'BDT', '-0.05'],
        'a currency code in lower case' => [150000, 'bdt', '1500.00'],
        'yen: no decimals, no point' => [1500, 'JPY', '1500'],
        'yen: nothing' => [0, 'JPY', '0'],
        'yen: negative' => [-1500, 'JPY', '-1500'],
        'Kuwaiti dinar: three decimals' => [1234, 'KWD', '1.234'],
        'Kuwaiti dinar: padded' => [1005, 'KWD', '1.005'],
        'Kuwaiti dinar: below one' => [5, 'KWD', '0.005'],
        // 9,007,199,254,740,993 is the first integer a float cannot hold.
        'more than a float can hold exactly' => [9007199254740993, 'BDT', '90071992547409.93'],
    ]);
});

describe('minorUnits', function () {
    it('reads a plain decimal amount', function (string $decimal, string $currency, int $expected) {
        expect(Money::minorUnits($decimal, $currency))->toBe($expected);
    })->with([
        'two decimals' => ['1500.00', 'BDT', 150000],
        'no decimals' => ['1500', 'BDT', 150000],
        'one decimal' => ['1500.5', 'BDT', 150050],
        'paisa' => ['0.05', 'BDT', 5],
        'nothing' => ['0', 'BDT', 0],
        'nothing, with decimals' => ['0.00', 'BDT', 0],
        'leading zeros' => ['007.10', 'BDT', 710],
        'surrounding white space' => ["  1500.00\n", 'BDT', 150000],
        'a currency code in lower case' => ['1500.00', 'bdt', 150000],
        'yen' => ['1500', 'JPY', 1500],
        'Kuwaiti dinar' => ['1.234', 'KWD', 1234],
        'Kuwaiti dinar, short' => ['1.2', 'KWD', 1200],
        'Kuwaiti dinar, whole' => ['12', 'KWD', 12000],
        'fifteen digits before the point' => ['999999999999999.99', 'BDT', 99999999999999999],
    ]);

    it('cuts extra decimals, it never rounds them', function (string $decimal, string $currency, int $expected) {
        expect(Money::minorUnits($decimal, $currency))->toBe($expected);
    })->with([
        'a third decimal' => ['1500.999', 'BDT', 150099],
        'just below the next paisa' => ['0.019999', 'BDT', 1],
        'half a paisa' => ['10.005', 'BDT', 1000],
        'yen with decimals' => ['1500.75', 'JPY', 1500],
        'yen just below the next' => ['1500.99', 'JPY', 1500],
        'a fourth decimal of a dinar' => ['1.2349', 'KWD', 1234],
    ]);

    it('answers null for anything that is not a plain, non-negative amount', function (string $garbage) {
        expect(Money::minorUnits($garbage, 'BDT'))->toBeNull();
    })->with([
        'nothing' => [''],
        'white space' => ['   '],
        'words' => ['abc'],
        'a negative amount' => ['-5.00'],
        'an explicit plus' => ['+5.00'],
        'a thousands separator' => ['1,500.00'],
        'a decimal comma' => ['1500,00'],
        'an exponent' => ['1e3'],
        'hexadecimal' => ['0x1A'],
        'no digit before the point' => ['.50'],
        'no digit after the point' => ['5.'],
        'two points' => ['1.2.3'],
        'a currency sign' => ['$5.00'],
        'a currency code' => ['5.00 BDT'],
        'white space inside' => ['1 500.00'],
        'Bengali digits' => ['১৫০০.০০'],
        'a second line' => ["1500.00\n1"],
        'more digits than any amount has' => ['1234567890123456.00'],
        'a null byte inside' => ["15\0".'00.00'],
    ]);

    it('gives back exactly what decimal() wrote', function (int $minorUnits, string $currency) {
        expect(Money::minorUnits(Money::decimal($minorUnits, $currency), $currency))->toBe($minorUnits);
    })->with([
        [0, 'BDT'], [1, 'BDT'], [5, 'BDT'], [99, 'BDT'], [100, 'BDT'], [101, 'BDT'], [150000, 'BDT'], [150050, 'BDT'],
        [999999999, 'USD'], [9007199254740993, 'BDT'],
        [0, 'JPY'], [1, 'JPY'], [1500, 'JPY'],
        [0, 'KWD'], [1, 'KWD'], [999, 'KWD'], [1000, 'KWD'], [1234, 'KWD'], [1005, 'KWD'],
    ]);

    it('never goes through a float', function () {
        // 0.29 * 100 is 28.999999999999996 as a float; 1.15 * 100 is 114.99999999999999.
        expect(Money::minorUnits('0.29', 'BDT'))->toBe(29)
            ->and(Money::minorUnits('1.15', 'BDT'))->toBe(115)
            ->and(Money::minorUnits('8.70', 'BDT'))->toBe(870)
            ->and(Money::minorUnits('90071992547409.93', 'BDT'))->toBe(9007199254740993);
    });
});

describe('format', function () {
    /** ICU puts a no-break space between a currency code and the amount. */
    $plainSpaces = static fn (string $text): string => str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);

    it('writes money the way English does', function () use ($plainSpaces) {
        expect($plainSpaces(Money::format(125000, 'BDT', 'en')))->toBe('BDT 1,250.00')
            ->and($plainSpaces(Money::format(5, 'BDT', 'en')))->toBe('BDT 0.05')
            ->and($plainSpaces(Money::format(0, 'BDT', 'en')))->toBe('BDT 0.00')
            ->and(Money::format(125000, 'USD', 'en'))->toBe('$1,250.00')
            ->and($plainSpaces(Money::format(125000, 'bdt', 'en')))->toBe('BDT 1,250.00');
    });

    it('writes money the way Bengali does: its own digits and the taka sign', function () {
        $formatted = Money::format(125000, 'BDT', 'bn');

        // Where the sign goes depends on the ICU version; the digits and the sign do not.
        expect($formatted)->toContain('১,২৫০.০০', '৳')
            ->and($formatted)->not->toMatch('/[0-9]/')
            ->and($formatted)->not->toContain('BDT');
    });

    it('uses the right number of decimals for the currency', function () use ($plainSpaces) {
        expect(Money::format(1500, 'JPY', 'en'))->toBe('¥1,500')
            ->and($plainSpaces(Money::format(1234, 'KWD', 'en')))->toBe('KWD 1.234');
    });

    it('uses the language of the application when none is named', function () use ($plainSpaces) {
        app()->setLocale('en');

        expect($plainSpaces(Money::format(125000, 'BDT')))->toBe('BDT 1,250.00');

        app()->setLocale('bn');

        expect(Money::format(125000, 'BDT'))->toContain('১,২৫০.০০');
    });

    it('keeps the sign of a negative amount', function () {
        expect(Money::format(-125000, 'BDT', 'en'))->toContain('-', '1,250.00');
    });
});

it('formats a very large amount from the exact decimal string, never through a float', function () {
    expect(Money::format(9_007_199_254_740_993, 'BDT', 'en'))->toContain('90071992547409.93')
        ->and(Money::format(-9_007_199_254_740_993, 'BDT', 'en'))->toContain('-90071992547409.93');
});
