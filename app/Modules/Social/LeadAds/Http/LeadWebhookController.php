<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Http;

use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\LeadAds\LeadIngest;
use App\Modules\Social\LeadAds\LeadStatus;
use App\Modules\Social\LeadAds\Models\LeadAdLead;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;
use App\Modules\Social\LeadAds\Models\LeadPageClaim;
use App\Modules\Social\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Facebook `leadgen` webhook. Stateless, on the workspace host.
 *
 * The order is the whole security model: the workspace comes from the hostname; the body is size-capped; the
 * `X-Hub-Signature-256` HMAC (the Meta app secret, over the raw body) is verified BEFORE anything is parsed;
 * only then each change is matched to a Page THIS workspace claimed (the platform-wide claim registry), and a
 * lead is registered once per `leadgen_id`. Nothing in the payload except ids is used; the answers are fetched
 * by the queued job with the Page token. A delivery that fails any step changes nothing.
 */
final class LeadWebhookController
{
    private const string ID = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(private readonly LeadIngest $ingest) {}

    /** Meta's subscription handshake: answered only with the operator's verify token, in constant time. */
    public function verify(Request $request): Response
    {
        $expected = config('social.lead_ads.webhook_verify_token');

        if (config('social.lead_ads.enabled') !== true || ! is_string($expected) || trim($expected) === '') {
            return $this->text('Not found', 404);
        }

        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        if ($mode !== 'subscribe' || ! is_string($token) || ! hash_equals($expected, $token)
            || ! is_string($challenge) || preg_match('/^[A-Za-z0-9_-]{1,128}$/', $challenge) !== 1) {
            return $this->text('Forbidden', 403);
        }

        return $this->text($challenge, 200);
    }

    public function receive(Request $request): Response
    {
        $secret = config('social.platforms.facebook.client_secret');

        if (config('social.lead_ads.enabled') !== true || ! is_string($secret) || trim($secret) === '') {
            return $this->text('Not found', 404);
        }

        $body = $request->getContent();

        if (strlen($body) > (int) config('social.lead_ads.webhook_max_bytes', 262144)) {
            return $this->text('Payload too large', 413);
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $body, $secret);

        if ($header === '' || ! hash_equals($expected, $header)) {
            return $this->text('Invalid signature', 401);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? null) !== 'page' || ! is_array($payload['entry'] ?? null)) {
            // Signed but not ours (another object type): acknowledged so the platform does not retry it.
            return $this->text('OK', 200);
        }

        if ($this->openLeads() >= (int) config('social.lead_ads.max_open_leads', 2000)) {
            return $this->text('Try again', 503)->header('Retry-After', '300');
        }

        $budget = max(1, (int) config('social.lead_ads.max_changes_per_delivery', 100));

        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (is_array($entry['changes'] ?? null) ? $entry['changes'] : [] as $change) {
                if ($budget-- <= 0) {
                    break 2;
                }

                if (! is_array($change) || ($change['field'] ?? null) !== 'leadgen' || ! is_array($change['value'] ?? null)) {
                    continue;
                }

                $this->change($change['value'], is_string($entry['id'] ?? null) ? $entry['id'] : null);
            }
        }

        return $this->text('OK', 200);
    }

    /** @param array<string, mixed> $value */
    private function change(array $value, ?string $entryPage): void
    {
        $leadgenId = $this->id($value['leadgen_id'] ?? null);
        $pageId = $this->id($value['page_id'] ?? null) ?? $this->id($entryPage);

        if ($leadgenId === null || $pageId === null) {
            return;
        }

        // The claim names a lead-capture row; reading that row through the tenant scope drops every Page that
        // another workspace holds (and every Page nobody holds).
        $claim = LeadPageClaim::query()->where('page_id', $pageId)->first();
        $page = $claim === null ? null : LeadAdsPage::query()->find($claim->lead_page_id);

        if ($page === null || ! $page->isSubscribed()) {
            return;
        }

        $account = SocialAccount::query()->find($page->account_id);

        if ($account === null || $account->status === AccountStatus::Disconnected) {
            return;
        }

        $created = null;

        if (is_int($value['created_time'] ?? null) || (is_string($value['created_time'] ?? null) && ctype_digit($value['created_time']))) {
            $created = CarbonImmutable::createFromTimestampUTC((int) $value['created_time']);
        }

        $this->ingest->register(
            $page,
            $leadgenId,
            $this->id($value['form_id'] ?? null),
            $this->id($value['ad_id'] ?? null),
            $created,
            'webhook',
        );
    }

    private function openLeads(): int
    {
        return LeadAdLead::query()->whereIn('status', LeadStatus::open())->count();
    }

    private function id(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && preg_match(self::ID, $value) === 1 ? $value : null;
    }

    private function text(string $body, int $status): Response
    {
        return response($body, $status)->header('Content-Type', 'text/plain');
    }
}
