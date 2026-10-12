<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\WhatsappMarketing\Actions\AgentManager;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Actions\ListManager;
use App\Modules\WhatsappMarketing\Actions\TemplateManager;
use App\Modules\WhatsappMarketing\Models\Agent;
use App\Modules\WhatsappMarketing\Models\ContactList;
use App\Modules\WhatsappMarketing\Models\Conversation;
use App\Modules\WhatsappMarketing\Models\Template;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Building blocks for WhatsApp Business tests: a workspace entitled to the app and to the communication
 * platform, members with exact permissions and scopes, a connected WhatsApp Cloud API channel (HTTP is faked,
 * never a live call), contacts, opt-ins, templates, and signed Meta-shaped webhook requests.
 *
 * @mixin TestCase
 */
trait InteractsWithWhatsapp
{
    use InteractsWithCommunication;

    protected function waTenant(string $slug = 'wa-ws'): Tenant
    {
        $tenant = $this->commTenant($slug);
        $this->entitle($tenant, 'communication', 'whatsapp_marketing');

        return $tenant;
    }

    protected function waUrl(Tenant $tenant, string $path): string
    {
        return $this->tenantUrl($tenant, '/whatsapp'.$path);
    }

    /**
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes  permission => own|department|branch|all
     * @return array{0: User, 1: TenantMembership}
     */
    protected function waMember(Tenant $tenant, array $permissions, array $scopes = []): array
    {
        return $this->commMember($tenant, $permissions, $scopes);
    }

    /** Every WhatsApp permission, for a member who may do everything in the app. */
    protected function waAllPermissions(): array
    {
        return [
            'whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply',
            'whatsapp_marketing.conversations.manage', 'whatsapp_marketing.conversations.assign',
            'whatsapp_marketing.templates.view', 'whatsapp_marketing.templates.manage',
            'whatsapp_marketing.campaigns.view', 'whatsapp_marketing.campaigns.manage', 'whatsapp_marketing.campaigns.send',
            'whatsapp_marketing.lists.view', 'whatsapp_marketing.lists.manage',
            'whatsapp_marketing.quick_replies.view', 'whatsapp_marketing.quick_replies.manage',
            'whatsapp_marketing.flows.manage', 'whatsapp_marketing.analytics.view', 'whatsapp_marketing.settings.manage',
            'core.contacts.view', 'core.contacts.manage',
        ];
    }

    /** @return array{0: User, 1: TenantMembership} */
    protected function waAdmin(Tenant $tenant): array
    {
        return $this->waMember($tenant, $this->waAllPermissions(), array_fill_keys([
            'whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply',
            'whatsapp_marketing.conversations.manage', 'whatsapp_marketing.conversations.assign',
            'whatsapp_marketing.campaigns.view', 'whatsapp_marketing.campaigns.manage', 'core.contacts.view', 'core.contacts.manage',
        ], 'all'));
    }

    /**
     * Connect the WhatsApp Cloud API channel with operator credential references. No call leaves the process:
     * `Http::fake()` answers every Graph API request with an accepted message id.
     */
    protected function waConnectCloud(Tenant $tenant, string $phoneNumberId = '109876543210', string $businessAccountId = '555000111', ?string $wamid = null): ChannelConnection
    {
        config(['communication.credentials' => [
            'wa_token' => ['secret' => 'EAAtest-access-token', 'tenants' => null],
            'wa_app' => ['secret' => 'app-secret-test', 'verify_token' => 'verify-me', 'tenants' => null],
        ]]);

        // Every send is accepted. A fixed id lets a test address the provider's later receipts.
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => $wamid ?? 'wamid.OUT'.Str::upper(Str::random(10))]]], 200)]);

        return $this->inTenant($tenant, fn (): ChannelConnection => ChannelConnection::query()->updateOrCreate(
            ['channel' => Channel::Whatsapp->value],
            [
                'provider' => 'whatsapp_cloud',
                'is_enabled' => true,
                'settings' => ['phone_number_id' => $phoneNumberId, 'business_account_id' => $businessAccountId],
                'credential_ref' => 'wa_token',
                'webhook_secret_ref' => 'wa_app',
            ],
        ));
    }

    /** The in-memory test provider (testing and local only), for tests that are not about the Cloud API. */
    protected function waConnectFake(Tenant $tenant): ChannelConnection
    {
        return $this->connectFakeProvider($tenant, Channel::Whatsapp);
    }

    /** @param array<string, mixed> $attributes */
    protected function waContact(Tenant $tenant, array $attributes = [], ?TenantMembership $owner = null): Contact
    {
        return $this->commContact($tenant, ['phone' => '+8801711000001', ...$attributes], $owner);
    }

    protected function waOptIn(Tenant $tenant, Contact $contact): void
    {
        $this->optIn($tenant, $contact, Channel::Whatsapp);
    }

    /** @param array<string, mixed> $attributes */
    protected function waTemplate(Tenant $tenant, array $attributes = []): Template
    {
        return $this->inTenant($tenant, fn (): Template => app(TemplateManager::class)->save(null, [
            'name' => 'order_update_'.strtolower(Str::random(5)),
            'language' => 'en_US',
            'category' => 'marketing',
            'body' => 'Hi {{1}}, your offer from {{2}} is ready.',
            'status' => 'approved',
            ...$attributes,
        ]));
    }

    /** @param list<Contact> $contacts */
    protected function waList(Tenant $tenant, array $contacts, string $name = 'Customers'): ContactList
    {
        return $this->inTenant($tenant, function () use ($contacts, $name): ContactList {
            $list = app(ListManager::class)->save(null, ['name' => $name.' '.Str::random(4)]);

            foreach ($contacts as $contact) {
                $list->members()->create(['contact_id' => $contact->id]);
            }

            return $list;
        });
    }

    protected function waAgent(Tenant $tenant, TenantMembership $membership, array $attributes = []): Agent
    {
        return $this->inTenant($tenant, fn (): Agent => app(AgentManager::class)->enrol($membership->id, $attributes));
    }

    /** The conversation for a contact's number, created as a sender would have. */
    protected function waConversation(Tenant $tenant, Contact $contact): Conversation
    {
        return $this->inTenant($tenant, fn (): Conversation => app(ConversationManager::class)->forContact($contact) ?? throw new \RuntimeException('Contact has no E.164 phone.'));
    }

    /**
     * A signed Meta-shaped webhook to the communication platform's route for the Cloud API.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function waWebhook(Tenant $tenant, array $payload, ?string $secret = 'app-secret-test', ?string $signature = null): TestResponse
    {
        $body = (string) json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null || $signature !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = $signature ?? 'sha256='.hash_hmac('sha256', $body, (string) $secret);
        }

        return $this->call('POST', $this->tenantUrl($tenant, '/communication/webhooks/whatsapp_cloud'), [], [], [], $server, $body);
    }

    /** A customer's text message, as Meta delivers it. */
    protected function waInbound(Tenant $tenant, string $from, string $text, ?string $id = null, string $phoneNumberId = '109876543210', ?string $name = 'Rahim Uddin'): TestResponse
    {
        return $this->waWebhook($tenant, $this->waMessagePayload($phoneNumberId, [[
            'from' => ltrim($from, '+'),
            'id' => $id ?? 'wamid.IN'.Str::upper(Str::random(12)),
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => $text],
        ]], $from, $name));
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    protected function waMessagePayload(string $phoneNumberId, array $messages, string $from, ?string $name = 'Rahim Uddin'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '555000111',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '8801700000000', 'phone_number_id' => $phoneNumberId],
                        'contacts' => [['profile' => ['name' => $name], 'wa_id' => ltrim($from, '+')]],
                        'messages' => $messages,
                    ],
                ]],
            ]],
        ];
    }

    /**
     * A status receipt for one of our messages.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function waStatus(Tenant $tenant, string $wamid, string $status, array $extra = [], string $phoneNumberId = '109876543210'): TestResponse
    {
        return $this->waWebhook($tenant, [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '555000111',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'statuses' => [[
                            'id' => $wamid,
                            'status' => $status,
                            'timestamp' => (string) now()->timestamp,
                            'recipient_id' => '8801711000001',
                            ...$extra,
                        ]],
                    ],
                ]],
            ]],
        ]);
    }
}
