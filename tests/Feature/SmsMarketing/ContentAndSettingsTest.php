<?php

declare(strict_types=1);

use App\Modules\SmsMarketing\Actions\MessageComposer;
use App\Modules\SmsMarketing\Actions\SmsSettings;
use App\Modules\SmsMarketing\Models\SenderId;
use App\Modules\SmsMarketing\Models\Setting;
use App\Modules\SmsMarketing\Models\Template;
use Tests\Support\InteractsWithSmsMarketing;

uses(InteractsWithSmsMarketing::class);

beforeEach(function () {
    $this->tenant = $this->smsTenant('sms-content');
    $this->actingAsOwner($this->tenant);
    $this->template = fn (array $overrides = []) => [
        'name' => 'Sale '.bin2hex(random_bytes(3)),
        'purpose' => 'marketing',
        'body' => 'Hello {{first_name|friend}}, sale today.',
        'is_active' => true,
        ...$overrides,
    ];
});

it('saves a template and counts its segments with the opt-out line', function () {
    $this->post($this->smsUrl($this->tenant, '/templates'), ($this->template)())->assertRedirect();

    $this->get($this->smsUrl($this->tenant, '/templates'))->assertInertia(fn ($page) => $page
        ->has('templates', 1)
        ->where('templates.0.segments.encoding', 'gsm7')
        ->where('templates.0.segments.segments', 1));
});

it('refuses variables outside the allow-list, broken braces and expressions', function (string $body, string $needle) {
    $response = $this->post($this->smsUrl($this->tenant, '/templates'), ($this->template)(['body' => $body]));

    $response->assertSessionHasErrors('body');
    expect(session('errors')->first('body'))->toContain($needle);
    $this->inTenant($this->tenant, fn () => expect(Template::query()->count())->toBe(0));
})->with([
    'unknown variable' => ['Hi {{salary}}', 'Unknown variable'],
    'phone is not a variable' => ['Hi {{phone}}', 'Unknown variable'],
    'unclosed' => ['Hi {{first_name', 'not closed'],
    'stray closing braces' => ['Hi first_name}}', 'not closed'],
    'expression is not evaluated' => ['{{ 1+1 }}', 'not closed'],
    'one-time code in marketing' => ['Your code is {{code}}', 'transactional'],
]);

it('allows a one-time code only in a transactional template, and keeps it out of campaigns', function () {
    $this->post($this->smsUrl($this->tenant, '/templates'), ($this->template)(['name' => 'OTP', 'purpose' => 'transactional', 'body' => 'Your code is {{code}}']))->assertRedirect();

    $otp = $this->inTenant($this->tenant, fn () => Template::query()->where('name', 'OTP')->sole());
    $group = $this->smsGroup($this->tenant, [$this->smsContact($this->tenant)->id]);

    $this->post($this->smsUrl($this->tenant, '/campaigns'), ['name' => 'Uses otp', 'template_id' => $otp->id, 'group_ids' => [$group->id]])
        ->assertSessionHasErrors('template_id');
});

it('refuses a message that would take more than the maximum number of segments', function () {
    config(['sms_marketing.max_segments' => 2]);

    $this->post($this->smsUrl($this->tenant, '/templates'), ($this->template)(['body' => str_repeat('অ', 140)]))->assertSessionHasErrors('body');
    $this->post($this->smsUrl($this->tenant, '/templates'), ($this->template)(['body' => str_repeat('অ', 15)]))->assertSessionDoesntHaveErrors();
});

it('keeps template names unique per workspace', function () {
    $this->post($this->smsUrl($this->tenant, '/templates'), ($this->template)(['name' => 'Same']))->assertRedirect();
    $this->post($this->smsUrl($this->tenant, '/templates'), ($this->template)(['name' => 'Same']))->assertSessionHasErrors('name');
});

it('previews a template with sample values and counts Bengali as unicode', function () {
    $response = $this->postJson($this->smsUrl($this->tenant, '/templates/preview'), ['purpose' => 'marketing', 'body' => 'হ্যালো {{first_name}}'])->assertOk();

    expect($response->json('text'))->toBe('হ্যালো Rahim')
        ->and($response->json('segments.encoding'))->toBe('ucs2')
        ->and($response->json('withOptOut'))->toBeTrue();

    $this->postJson($this->smsUrl($this->tenant, '/templates/preview'), ['purpose' => 'marketing', 'body' => '{{nope}}'])->assertStatus(422);
});

it('counts the opt-out line in the estimate of a text', function () {
    $count = $this->inTenant($this->tenant, fn () => app(MessageComposer::class)->preview(str_repeat('a', 100)));
    $without = $this->inTenant($this->tenant, fn () => app(MessageComposer::class)->preview(str_repeat('a', 100), false));

    expect($without->segments)->toBe(1)->and($count->units)->toBeGreaterThan($without->units + 50);
});

it('validates sender ids by type and never calls a registration verified', function () {
    $url = $this->smsUrl($this->tenant, '/sender-ids');
    $valid = ['label' => 'Brand', 'value' => 'ACME', 'type' => 'masking', 'registration_status' => 'registered', 'registration_reference' => 'BTRC-1'];

    $this->post($url, [...$valid, 'value' => 'WAYTOOLONGSENDER'])->assertSessionHasErrors('value');
    $this->post($url, [...$valid, 'value' => '12345'])->assertSessionHasErrors('value');
    $this->post($url, [...$valid, 'value' => 'AB'])->assertSessionHasErrors('value');
    $this->post($url, [...$valid, 'value' => 'ACME 1'])->assertSessionHasErrors('value');
    $this->post($url, [...$valid, 'type' => 'non_masking', 'value' => 'ACME'])->assertSessionHasErrors('value');
    $this->post($url, [...$valid, 'registration_status' => 'verified'])->assertSessionHasErrors('registration_status');

    $this->post($url, $valid)->assertRedirect();
    $this->post($url, [...$valid, 'label' => 'Again'])->assertSessionHasErrors('value');
    $this->post($url, ['label' => 'Number', 'value' => '+8801711000001', 'type' => 'non_masking', 'registration_status' => 'pending'])->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        $brand = SenderId::query()->where('value', 'ACME')->sole();
        expect($brand->registration_status->value)->toBe('registered')->and(SenderId::query()->count())->toBe(2);
    });

    $this->get($url)->assertInertia(fn ($page) => $page->where('statuses', ['not_registered', 'pending', 'registered', 'rejected']));
});

it('stores settings within bounds and the price per segment as integer minor units', function () {
    $url = $this->smsUrl($this->tenant, '/settings');

    $this->put($url, ['daily_cap' => 0, 'throttle_per_minute' => 60])->assertSessionHasErrors('daily_cap');
    $this->put($url, ['daily_cap' => 100001, 'throttle_per_minute' => 60])->assertSessionHasErrors('daily_cap');
    $this->put($url, ['daily_cap' => 100, 'throttle_per_minute' => 601])->assertSessionHasErrors('throttle_per_minute');
    $this->put($url, ['daily_cap' => 100, 'throttle_per_minute' => 60, 'segment_cost_amount' => 0.5])->assertSessionHasErrors('segment_cost_amount');

    $this->put($url, ['daily_cap' => 250, 'throttle_per_minute' => 30, 'segment_cost_amount' => 45, 'opt_out_wording' => ' STOP: '])->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        $settings = Setting::query()->sole();
        expect($settings->daily_cap)->toBe(250)
            ->and($settings->segment_cost_amount)->toBe(45)
            ->and($settings->currency)->toBe('BDT')
            ->and($settings->opt_out_wording)->toBe('STOP:')
            ->and(app(SmsSettings::class)->optOutWording())->toBe('STOP:');
    });
});

it('refuses a default sender id of another workspace', function () {
    $other = $this->smsTenant('sms-content-other');
    $foreign = $this->inTenant($other, fn () => SenderId::query()->create([
        'label' => 'Theirs', 'value' => 'THEIRS', 'type' => 'masking', 'registration_status' => 'not_registered',
    ]));

    $this->put($this->smsUrl($this->tenant, '/settings'), ['daily_cap' => 100, 'throttle_per_minute' => 60, 'default_sender_id' => $foreign->id])
        ->assertSessionHasErrors('default_sender_id');
});

it('uses the workspace opt-out wording in the text that is sent', function () {
    $this->put($this->smsUrl($this->tenant, '/settings'), ['daily_cap' => 100, 'throttle_per_minute' => 60, 'opt_out_wording' => 'Reply STOP or tap'])->assertRedirect();

    $text = $this->inTenant($this->tenant, fn () => app(MessageComposer::class)->compose('Hello {{first_name}}', ['first_name' => 'Rahim'], 'https://x.test/u/abc'));

    expect($text)->toBe("Hello Rahim\nReply STOP or tap https://x.test/u/abc");
});
