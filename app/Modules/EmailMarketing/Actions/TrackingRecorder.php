<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Modules\EmailMarketing\Models\Delivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Counts a click on a tracked link and a view of the web version.
 *
 * What is measured, honestly: a click is a request to our redirect (automated link scanners at the recipient's
 * mail provider can produce some); a view is a request for the web version of the email. Opens of the email
 * itself are not measured, because the platform mailer sends plain text and there is nowhere to put a pixel.
 */
final class TrackingRecorder
{
    public function click(Delivery $delivery): void
    {
        Delivery::query()->whereKey($delivery->id)->update([
            'click_count' => DB::raw('click_count + 1'),
            'clicked_at' => $delivery->clicked_at ?? CarbonImmutable::now(),
        ]);
    }

    public function view(Delivery $delivery): void
    {
        Delivery::query()->whereKey($delivery->id)->update([
            'view_count' => DB::raw('view_count + 1'),
            'viewed_at' => $delivery->viewed_at ?? CarbonImmutable::now(),
        ]);
    }
}
