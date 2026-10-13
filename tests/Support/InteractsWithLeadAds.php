<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Tenancy\Models\Tenant;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;
use App\Modules\Social\LeadAds\Models\LeadForm;
use App\Modules\Social\LeadAds\Models\LeadPageClaim;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Building blocks for Facebook Lead Ads tests: operator configuration, a Page that already has lead capture on
 * (account, subscribed row, claim, one switched-on form), signed webhook deliveries and a faked Graph API.
 * Combine with `InteractsWithSocial` and `InteractsWithCrmContracts`.
 */
trait InteractsWithLeadAds
{
    protected const string APP_SECRET = 'app-secret-test';

    protected const string PAGE_TOKEN = 'page-token-secret-1';

    protected function leadAdsConfig(bool $enabled = true): void
    {
        config([
            'social.lead_ads.enabled' => $enabled,
            'social.lead_ads.webhook_verify_token' => 'verify-me',
            'social.platforms.facebook.client_id' => 'app-id',
            'social.platforms.facebook.client_secret' => self::APP_SECRET,
        ]);
    }

    /**
     * @param  list<array{key: string, label: string, type: string}>|null  $questions
     * @return array{account: SocialAccount, page: LeadAdsPage, form: LeadForm}
     */
    protected function fbLeadPage(Tenant $tenant, string $pageId = '1000', bool $formEnabled = true, ?array $questions = null, ?array $mapping = null): array
    {
        $account = $this->socialAccount($tenant, [
            'platform' => 'facebook',
            'external_id' => $pageId,
            'name' => 'Brand Page '.$pageId,
            'access_token' => self::PAGE_TOKEN,
            'refresh_token' => null,
            'token_expires_at' => null,
        ]);

        return $this->inTenant($tenant, function () use ($account, $pageId, $formEnabled, $questions, $mapping): array {
            $page = LeadAdsPage::query()->create(['account_id' => $account->id, 'page_id' => $pageId, 'state' => LeadAdsPage::SUBSCRIBED, 'subscribed_at' => now()]);
            LeadPageClaim::query()->create(['page_id' => $pageId, 'lead_page_id' => $page->id]);
            $form = LeadForm::query()->create([
                'account_id' => $account->id,
                'external_id' => 'F1',
                'name' => 'Spring offer',
                'status' => 'ACTIVE',
                'questions' => $questions ?? [
                    ['key' => 'full_name', 'label' => 'Full name', 'type' => 'FULL_NAME'],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'EMAIL'],
                    ['key' => 'phone_number', 'label' => 'Phone', 'type' => 'PHONE'],
                    ['key' => 'city', 'label' => 'City', 'type' => 'CUSTOM'],
                ],
                'is_enabled' => $formEnabled,
                'mapping' => $mapping,
                'fetched_at' => now(),
            ]);

            return ['account' => $account, 'page' => $page, 'form' => $form];
        });
    }

    /**
     * @param  list<array{field?: string, value?: array<string, mixed>}>|null  $changes
     * @return array<string, mixed>
     */
    protected function leadgenPayload(string $leadgenId = 'L1', string $pageId = '1000', string $formId = 'F1', ?array $changes = null): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => $pageId,
                'time' => time(),
                'changes' => $changes ?? [[
                    'field' => 'leadgen',
                    'value' => ['leadgen_id' => $leadgenId, 'page_id' => $pageId, 'form_id' => $formId, 'ad_id' => 'AD1', 'adgroup_id' => 'AG1', 'created_time' => 1760000000],
                ]],
            ]],
        ];
    }

    /** @param array<string, mixed> $payload */
    protected function leadWebhook(Tenant $tenant, array $payload, ?string $secret = self::APP_SECRET, ?string $signature = null, ?string $rawBody = null): TestResponse
    {
        $body = $rawBody ?? (string) json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null || $signature !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature ?? 'sha256='.hash_hmac('sha256', $body, (string) $secret);
        }

        return $this->call('POST', $this->tenantUrl($tenant, '/social/webhooks/facebook/leads'), [], [], [], $server, $body);
    }

    /**
     * The lead node as the Graph API returns it.
     *
     * @param  list<array{name: string, values: list<string>}>|null  $fieldData
     * @return array<string, mixed>
     */
    protected function leadNode(string $id = 'L1', ?array $fieldData = null): array
    {
        return [
            'id' => $id,
            'created_time' => '2026-10-13T10:00:00+0000',
            'ad_id' => 'AD1',
            'campaign_id' => 'CMP1',
            'form_id' => 'F1',
            'field_data' => $fieldData ?? [
                ['name' => 'full_name', 'values' => ['Rahim Uddin']],
                ['name' => 'email', 'values' => ['Rahim@Example.com']],
                ['name' => 'phone_number', 'values' => ['p:+8801712345678']],
                ['name' => 'city', 'values' => ['Dhaka']],
            ],
        ];
    }

    /** @param array<string, mixed> $node */
    protected function fakeGraphLead(string $id = 'L1', ?array $node = null): void
    {
        Http::fake(['graph.facebook.com/*/'.$id.'*' => Http::response($node ?? $this->leadNode($id))]);
    }

    /** @param array<string, mixed> $body */
    protected function fakeGraphError(string $id, int $status, array $body): void
    {
        Http::fake(['graph.facebook.com/*/'.$id.'*' => Http::response($body, $status)]);
    }

    /** A connected Facebook account with lead capture still off. */
    protected function plainFbAccount(Tenant $tenant, string $pageId = '2000'): SocialAccount
    {
        return $this->socialAccount($tenant, [
            'platform' => 'facebook', 'external_id' => $pageId, 'name' => 'Plain Page', 'access_token' => 'tok-'.$pageId, 'refresh_token' => null,
        ]);
    }

    /**
     * Fakes the Graph calls that switching lead capture on makes: permission check, subscribe, forms list.
     *
     * @param  list<string>  $scopes
     */
    protected function fakeGraphSetup(array $scopes, bool $subscribe = true): void
    {
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => $scopes]]),
            'graph.facebook.com/*/subscribed_apps*' => Http::response($subscribe ? ['success' => true] : ['error' => ['code' => 190, 'message' => 'expired']], $subscribe ? 200 : 400),
            'graph.facebook.com/*/leadgen_forms*' => Http::response(['data' => [
                ['id' => '777', 'name' => 'Summer', 'status' => 'ACTIVE', 'locale' => 'en_US', 'questions' => [
                    ['key' => 'full_name', 'label' => 'Full name', 'type' => 'FULL_NAME'],
                    ['key' => 'email', 'label' => 'Email', 'type' => 'EMAIL'],
                    ['key' => 'budget', 'label' => 'Budget', 'type' => 'CUSTOM'],
                ]],
            ]]),
        ]);
    }
}
