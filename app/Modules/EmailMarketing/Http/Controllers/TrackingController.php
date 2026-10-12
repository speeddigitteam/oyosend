<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Http\Controllers;

use App\Core\Contacts\Models\Contact;
use App\Modules\EmailMarketing\Actions\ContentComposer;
use App\Modules\EmailMarketing\Actions\EmailMarketingSettings;
use App\Modules\EmailMarketing\Actions\TrackingRecorder;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\SequenceStep;
use App\Modules\EmailMarketing\Support\SafeUrl;
use App\Modules\EmailMarketing\Support\TrackingTokens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * The two public links of an email, both stateless (no session, cookie or CSRF; see `callbacks.php`).
 *
 * The signed token is the only credential. A click is answered with a redirect to the address inside the signed
 * token (re-checked against {@see SafeUrl}); the web version renders the stored content with a locked-down
 * Content-Security-Policy. Every invalid, forged, foreign-tenant or unknown token is the same bare 404.
 */
final class TrackingController
{
    public function __construct(
        private readonly TrackingTokens $tokens,
        private readonly TrackingRecorder $recorder,
        private readonly ContentComposer $composer,
        private readonly EmailMarketingSettings $settings,
    ) {}

    public function click(string $token): RedirectResponse|Response
    {
        $data = $this->tokens->verify($token, TrackingTokens::CLICK);
        $delivery = $data === null ? null : Delivery::query()->find($data['delivery']);

        if ($data === null || $delivery === null || ! SafeUrl::isAllowed($data['url'], false)) {
            return $this->notFound();
        }

        $this->recorder->click($delivery);

        return redirect()->away($data['url'], 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function view(string $token): Response
    {
        $data = $this->tokens->verify($token, TrackingTokens::VIEW);
        $delivery = $data === null ? null : Delivery::query()->find($data['delivery']);
        $contact = $delivery === null ? null : Contact::query()->with('company')->find($delivery->contact_id);

        if ($delivery === null || $contact === null) {
            return $this->notFound();
        }

        $source = $delivery->campaign_id !== null
            ? Campaign::query()->find($delivery->campaign_id)
            : SequenceStep::query()->find($delivery->step_id);

        if ($source === null) {
            return $this->notFound();
        }

        if ($source instanceof Campaign) {
            $subject = $delivery->variant === 'b' && $source->ab_enabled && $source->ab_subject_b !== null ? $source->ab_subject_b : $source->subject;
            $track = $source->track_clicks;
        } else {
            $subject = $source->subject;
            $track = $this->settings->current()->track_clicks;
        }

        $email = $this->composer->webVersion($source->blocks, $subject, $source->preheader, $contact, $delivery, $track);
        $this->recorder->view($delivery);

        return response($email->html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'none'; img-src https:; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ]);
    }

    private function notFound(): Response
    {
        return response('Not found', 404, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
