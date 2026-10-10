<?php

declare(strict_types=1);

use App\Modules\CRM\Actions\SalesAnalytics;
use App\Modules\CRM\Enums\ContractStatus;
use App\Modules\CRM\Enums\QuoteKind;
use App\Modules\CRM\Enums\QuoteStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Contract;
use App\Modules\CRM\Models\Quote;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesTarget;
use Carbon\CarbonImmutable;

it('returns exact forecast, target, conversion and contract report data', function () {
    $tenant = $this->createTenant('sales-report');
    $owner = $this->ownerOf($tenant);
    $membership = $this->membershipOf($tenant, $owner);
    $this->entitle($tenant, 'crm');
    $this->travelTo('2026-10-10 09:00:00');

    $report = $this->inTenant($tenant, function () use ($owner, $membership): array {
        $open = new Quote([
            'number' => 'QT-OPEN',
            'kind' => QuoteKind::Quotation,
            'title' => 'Open',
            'status' => QuoteStatus::Sent,
            'currency' => 'BDT',
            'probability_percent' => 25,
            'subtotal_amount' => 10_000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 10_000,
        ]);
        $open->assignOwner($membership)->save();

        $accepted = new Quote([
            'number' => 'QT-WON',
            'kind' => QuoteKind::Quotation,
            'title' => 'Won',
            'status' => QuoteStatus::Accepted,
            'currency' => 'BDT',
            'probability_percent' => 100,
            'subtotal_amount' => 20_000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 20_000,
            'accepted_at' => now(),
        ]);
        $accepted->assignOwner($membership)->save();

        $order = new SalesOrder([
            'quote_id' => $accepted->id,
            'number' => 'SO-WON',
            'status' => SalesOrderStatus::Confirmed,
            'currency' => 'BDT',
            'subtotal_amount' => 20_000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 20_000,
            'ordered_at' => now(),
        ]);
        $order->assignOwner($membership)->save();

        $target = new SalesTarget([
            'name' => 'October',
            'currency' => 'BDT',
            'target_amount' => 50_000,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ]);
        $target->assignOwner($membership)->save();

        $contract = new Contract([
            'number' => 'CT-ACTIVE',
            'title' => 'Retainer',
            'status' => ContractStatus::Active,
            'currency' => 'BDT',
            'value_amount' => 30_000,
            'starts_on' => '2026-10-01',
        ]);
        $contract->assignOwner($membership)->save();

        $analytics = app(SalesAnalytics::class);

        return [
            $analytics->overview(
                $owner,
                CarbonImmutable::parse('2026-10-01'),
                CarbonImmutable::parse('2026-10-31'),
                'BDT',
            ),
            $analytics->targetActual($target),
        ];
    });

    expect($report[0])->toMatchArray([
        'quoted_amount' => 30_000,
        'open_weighted_amount' => 2500,
        'actual_sales_amount' => 20_000,
        'forecast_amount' => 22_500,
        'target_amount' => 50_000,
        'target_gap_amount' => 30_000,
        'target_attainment_bps' => 4000,
        'active_contract_value_amount' => 30_000,
        'quote_count' => 2,
        'accepted_quote_count' => 1,
        'order_count' => 1,
        'conversion_bps' => 10_000,
    ])->and($report[1])->toBe(20_000);
});
