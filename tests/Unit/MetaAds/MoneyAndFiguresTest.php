<?php

declare(strict_types=1);

use App\Modules\MetaAds\Api\ApiUsage;
use App\Modules\MetaAds\Support\Figures;
use App\Modules\MetaAds\Support\MinorUnits;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Response as ClientResponse;

it('parses spend decimals into exact minor units without a float', function () {
    expect(MinorUnits::fromDecimal('12.34', 'BDT'))->toBe(1234)
        ->and(MinorUnits::fromDecimal('12', 'BDT'))->toBe(1200)
        ->and(MinorUnits::fromDecimal('0.07', 'USD'))->toBe(7)
        ->and(MinorUnits::fromDecimal('0.1', 'USD'))->toBe(10)
        ->and(MinorUnits::fromDecimal('1234567890123.45', 'BDT'))->toBe(123456789012345)
        ->and(MinorUnits::fromDecimal('12', 'JPY'))->toBe(12)
        ->and(MinorUnits::fromDecimal('1.234', 'KWD'))->toBe(1234)
        // The classic float trap: 19.99 * 100 is 1998.9999999999998.
        ->and(MinorUnits::fromDecimal('19.99', 'USD'))->toBe(1999)
        ->and(MinorUnits::fromDecimal('0.29', 'USD'))->toBe(29);
});

it('rounds digits beyond the minor unit half up on the digit string', function () {
    expect(MinorUnits::fromDecimal('1.005', 'USD'))->toBe(101)
        ->and(MinorUnits::fromDecimal('1.004', 'USD'))->toBe(100)
        ->and(MinorUnits::fromDecimal('1.5', 'JPY'))->toBe(2);
});

it('treats anything that is not a plain non-negative amount as not reported', function () {
    foreach ([null, '', 'abc', '-1.00', '1e3', '1,000.00', '12.', '.5', ' ', [], 1.5, '99999999999999999999'] as $bad) {
        expect(MinorUnits::fromDecimal($bad, 'USD'))->toBeNull();
    }

    expect(MinorUnits::fromInteger('150000'))->toBe(150000)
        ->and(MinorUnits::fromInteger('0'))->toBe(0)
        ->and(MinorUnits::fromInteger('12.5'))->toBeNull()
        ->and(MinorUnits::fromInteger('-5'))->toBeNull()
        ->and(MinorUnits::fromInteger(null))->toBeNull()
        ->and(MinorUnits::count('1234'))->toBe(1234)
        ->and(MinorUnits::count('n/a'))->toBeNull();
});

it('reads a typed budget with grouping characters and renders it back exactly', function () {
    expect(MinorUnits::fromInput('1,500.50', 'BDT'))->toBe(150050)
        ->and(MinorUnits::fromInput(' 2000 ', 'BDT'))->toBe(200000)
        ->and(MinorUnits::fromInput('', 'BDT'))->toBeNull()
        ->and(MinorUnits::fromInput('1.2.3', 'BDT'))->toBeNull()
        ->and(MinorUnits::toDecimal(150050, 'BDT'))->toBe('1500.50')
        ->and(MinorUnits::toDecimal(5, 'USD'))->toBe('0.05');
});

it('derives ratios from summed amounts with integer arithmetic and returns null instead of zero', function () {
    expect(Figures::ctr(200, 10000))->toBe(200)            // 2.00%
        ->and(Figures::ctr(1, 3))->toBe(3333)
        ->and(Figures::ctr(0, 1000))->toBe(0)
        ->and(Figures::ctr(5, 0))->toBeNull()
        ->and(Figures::ctr(null, 100))->toBeNull()
        ->and(Figures::cpc(50000, 200))->toBe(250)
        ->and(Figures::cpm(50000, 10000))->toBe(5000)
        ->and(Figures::costPerLead(50000, 10))->toBe(5000)
        ->and(Figures::costPerLead(50000, 0))->toBeNull()
        ->and(Figures::costPerLead(null, 10))->toBeNull()
        ->and(Figures::cpc(7, 2))->toBe(4);                // 3.5 rounds half up
});

it('computes period deltas in basis points and has none without a base', function () {
    expect(Figures::delta(150, 100))->toBe(5000)
        ->and(Figures::delta(50, 100))->toBe(-5000)
        ->and(Figures::delta(100, 100))->toBe(0)
        ->and(Figures::delta(10, 0))->toBeNull()
        ->and(Figures::delta(null, 10))->toBeNull()
        ->and(Figures::delta(10, null))->toBeNull()
        ->and(Figures::delta(1, 3))->toBe(-6667);
});

/** A response carrying the given usage headers. */
function metaResponseWith(array $headers): ClientResponse
{
    return new ClientResponse(new Response(200, $headers, '{"data":[]}'));
}

it('reads the highest quota figure from Meta usage headers and when access returns', function () {
    $usage = ApiUsage::fromResponse(metaResponseWith([
        'x-business-use-case-usage' => json_encode(['1001' => [['type' => 'ads_insights', 'call_count' => 28, 'total_cputime' => 81, 'total_time' => 40, 'estimated_time_to_regain_access' => 5]]]),
        'x-ad-account-usage' => json_encode(['acc_id_util_pct' => 9.67]),
        'x-app-usage' => json_encode(['call_count' => 12]),
    ]));

    expect($usage->percent)->toBe(81)->and($usage->regainSeconds)->toBe(300);

    $account = ApiUsage::fromResponse(metaResponseWith(['x-ad-account-usage' => json_encode(['acc_id_util_pct' => 95, 'reset_time_duration' => 120])]));
    expect($account->percent)->toBe(95)->and($account->regainSeconds)->toBe(120);

    $none = ApiUsage::fromResponse(metaResponseWith([]));
    expect($none->percent)->toBe(0)->and($none->regainSeconds)->toBeNull();

    $garbage = ApiUsage::fromResponse(metaResponseWith(['x-business-use-case-usage' => '{not json', 'x-app-usage' => 'null']));
    expect($garbage->percent)->toBe(0);
});
