<?php

declare(strict_types=1);

use App\Core\Audit\Models\AuditLog;
use App\Core\Communication\Contracts\TemplateCatalogue;
use App\Core\Communication\Data\Template\ProviderTemplateStatus;
use App\Core\Communication\Data\Template\TemplateCatalogueStatus;
use App\Core\Communication\Data\Template\TemplateCategory;
use App\Core\Communication\Data\Template\TemplateComponent;
use App\Core\Communication\Data\Template\TemplateDraft;
use App\Core\Communication\NullTemplateCatalogue;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

function tplDraft(): TemplateDraft
{
    return new TemplateDraft('order_update', 'en_US', TemplateCategory::Utility, [TemplateComponent::body('Hi {{1}}, it shipped.', ['Rahim'])]);
}

beforeEach(function () {
    $this->tenant = $this->waTenant('catalogue-ws');
    $this->waConnectCloud($this->tenant, businessAccountId: '555000111');
    $this->catalogue = fn () => app(TemplateCatalogue::class);
});

it('lists the provider catalogue for the workspace\'s business account with the platform credential', function () {
    Http::swap(new Factory);
    Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
        ['id' => '1', 'name' => 'order_update', 'language' => 'en_US', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}.', 'example' => ['body_text' => [['Rahim']]]]]],
    ]], 200)]);

    $result = $this->inTenant($this->tenant, fn () => ($this->catalogue)()->list(ProviderTemplateStatus::Approved, limit: 500));

    expect($result->isOk())->toBeTrue()
        ->and($result->templates)->toHaveCount(1)
        ->and($result->templates[0]->status)->toBe(ProviderTemplateStatus::Approved)
        ->and($result->templates[0]->components[0]->examples)->toBe(['Rahim'])
        ->and(json_encode($result))->not->toContain('EAAtest-access-token');

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://graph.facebook.com/v21.0/555000111/message_templates?')
            && $request->hasHeader('Authorization', 'Bearer EAAtest-access-token')
            && $query['limit'] === '100' && $query['status'] === 'APPROVED';
    });
});

it('submits a template and audits it without the token', function () {
    Http::swap(new Factory);
    Http::fake(['graph.facebook.com/*' => Http::response(['id' => '77', 'status' => 'PENDING', 'category' => 'UTILITY'], 200)]);

    $result = $this->inTenant($this->tenant, fn () => ($this->catalogue)()->create(tplDraft()));

    expect($result->isOk())->toBeTrue()
        ->and($result->template->providerTemplateId)->toBe('77')
        ->and($result->template->status)->toBe(ProviderTemplateStatus::Pending);

    $audit = $this->inTenant($this->tenant, fn () => AuditLog::query()->where('event', 'communication.template.provider_submitted')->firstOrFail());
    expect($audit->properties)->toMatchArray(['name' => 'order_update', 'language' => 'en_US', 'category' => 'utility'])
        ->and(json_encode($audit->properties))->not->toContain('EAAtest');
});

it('deletes by name, refusing a malformed name before any request', function () {
    Http::swap(new Factory);
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true], 200)]);

    expect($this->inTenant($this->tenant, fn () => ($this->catalogue)()->delete('Bad Name!'))->status)->toBe(TemplateCatalogueStatus::Rejected);
    Http::assertNothingSent();

    $ok = $this->inTenant($this->tenant, fn () => ($this->catalogue)()->delete('order_update'));
    expect($ok->isOk())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'name=order_update'));
});

it('reports a Meta refusal with its reason and does not audit a failed submission', function () {
    Http::swap(new Factory);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 100, 'error_user_msg' => 'Template name already exists']], 400)]);

    $result = $this->inTenant($this->tenant, fn () => ($this->catalogue)()->create(tplDraft()));

    expect($result->status)->toBe(TemplateCatalogueStatus::Rejected)->and($result->detail)->toBe('Template name already exists');
    $this->inTenant($this->tenant, fn () => expect(AuditLog::query()->where('event', 'communication.template.provider_submitted')->exists())->toBeFalse());
});

it('says missing_business_account when the connection has no business account id and sends nothing', function () {
    $this->inTenant($this->tenant, function (): void {
        $connection = ChannelConnection::query()->where('channel', 'whatsapp')->firstOrFail();
        $connection->forceFill(['settings' => ['phone_number_id' => '109876543210']])->save();
    });
    Http::swap(new Factory);
    Http::fake();

    foreach ([
        fn () => ($this->catalogue)()->list(),
        fn () => ($this->catalogue)()->create(tplDraft()),
        fn () => ($this->catalogue)()->delete('order_update'),
    ] as $call) {
        $result = $this->inTenant($this->tenant, $call);

        expect($result->status)->toBe(TemplateCatalogueStatus::MissingBusinessAccount)->and($result->reason)->toBe('missing_business_account_id');
    }

    Http::assertNothingSent();
});

it('says not_configured without a connection, with a provider that cannot manage templates or without a credential', function () {
    $bare = $this->waTenant('catalogue-bare');
    expect($this->inTenant($bare, fn () => ($this->catalogue)()->list())->status)->toBe(TemplateCatalogueStatus::NotConfigured);

    $fake = $this->waTenant('catalogue-fake');
    $this->waConnectFake($fake);
    expect($this->inTenant($fake, fn () => ($this->catalogue)()->create(tplDraft()))->status)->toBe(TemplateCatalogueStatus::NotConfigured);

    $unresolved = $this->waTenant('catalogue-unresolved');
    $this->waConnectCloud($unresolved, '444000111222', '555000333');
    config(['communication.credentials' => []]);
    expect($this->inTenant($unresolved, fn () => ($this->catalogue)()->list())->status)->toBe(TemplateCatalogueStatus::NotConfigured);

    $this->inTenant($unresolved, fn () => ChannelConnection::query()->update(['is_enabled' => false]));
    expect($this->inTenant($unresolved, fn () => ($this->catalogue)()->list())->status)->toBe(TemplateCatalogueStatus::NotConfigured);
});

it('says module_unavailable when the workspace is not entitled to the communication app', function () {
    $plain = $this->createTenant('catalogue-plain');

    foreach ([
        fn () => ($this->catalogue)()->list(),
        fn () => ($this->catalogue)()->create(tplDraft()),
        fn () => ($this->catalogue)()->delete('order_update'),
    ] as $call) {
        expect($this->inTenant($plain, $call)->status)->toBe(TemplateCatalogueStatus::ModuleUnavailable);
    }
});

it('has an honest null implementation', function () {
    $null = new NullTemplateCatalogue;

    expect($null->list()->status)->toBe(TemplateCatalogueStatus::ModuleUnavailable)
        ->and($null->create(tplDraft())->isOk())->toBeFalse()
        ->and($null->delete('x')->reason)->toBe('module_unavailable');
});
