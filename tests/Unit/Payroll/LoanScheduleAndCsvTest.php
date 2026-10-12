<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\LoanManager;
use App\Modules\Payroll\Support\SafeCsv;

it('splits a loan into installments that add up to exactly the principal', function () {
    $schedule = LoanManager::schedule(100_000, 3, '2026-03-05');

    // 100000 / 3 = 33333 remainder 1: the remainder goes to the first installment.
    expect(array_column($schedule, 'amount'))->toBe([33_334, 33_333, 33_333])
        ->and(array_sum(array_column($schedule, 'amount')))->toBe(100_000)
        ->and(array_column($schedule, 'due_on'))->toBe(['2026-03-05', '2026-04-05', '2026-05-05'])
        ->and(array_column($schedule, 'sequence'))->toBe([1, 2, 3]);

    $seven = LoanManager::schedule(10, 4, '2026-01-31');
    // 10 / 4 = 2 remainder 2; month-end dates are clamped, not rolled over.
    expect(array_column($seven, 'amount'))->toBe([3, 3, 2, 2])
        ->and(array_column($seven, 'due_on'))->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);

    expect(array_sum(array_column(LoanManager::schedule(999_999_937, 60, '2026-01-01'), 'amount')))->toBe(999_999_937);
});

it('writes csv with a byte order mark and neutralizes formula injection', function () {
    $csv = SafeCsv::render(
        ['Name', 'Account', 'Amount'],
        [
            ['=HYPERLINK("http://evil.test")', '+8801700000000', SafeCsv::number(125_000, 'BDT')],
            ['-2+3', '@SUM(A1)', SafeCsv::number(5, 'BDT')],
            ["\t=cmd|' /C calc'!A0", ' =1+1', SafeCsv::number(0, 'BDT')],
            ['Rafi, "the" Ahmed', 'rahman@example.test', SafeCsv::number(99_999, 'JPY')],
            ['রাফি আহমেদ', null, 12],
        ],
    );

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue();

    $rows = array_map('str_getcsv', array_filter(explode("\r\n", substr($csv, 3))));

    expect($rows[0])->toBe(['Name', 'Account', 'Amount'])
        // Text cells that start with a formula character are prefixed with an apostrophe.
        ->and($rows[1])->toBe(["'=HYPERLINK(\"http://evil.test\")", "'+8801700000000", '1250.00'])
        ->and($rows[2])->toBe(["'-2+3", "'@SUM(A1)", '0.05'])
        ->and($rows[3][0])->toStartWith("'")
        ->and($rows[3][1])->toBe("' =1+1")
        // Quotes and commas survive; amounts are exact decimals; Bengali text is untouched.
        ->and($rows[4])->toBe(['Rafi, "the" Ahmed', 'rahman@example.test', '99999'])
        ->and($rows[5])->toBe(['রাফি আহমেদ', '', '12']);
});
