<?php

declare(strict_types=1);

use App\Modules\Automation\Actions\WorkflowManager;
use App\Modules\Automation\Engine\Outbound\HostResolver;
use App\Modules\Automation\Enums\RunStatus;
use App\Modules\Automation\Http\Presenters\Presenter;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\WebhookEndpoint;
use App\Modules\Automation\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\Flow\FlowRecorder;
use Tests\Support\InteractsWithAutomation;

uses(InteractsWithAutomation::class);

beforeEach(function () {
    $this->tenant = $this->flowTenant();
    $graph = $this->graphOf(
        [
            $this->node('trigger', 'trigger', ['trigger_key' => 'automation.trigger.webhook', 'config' => []]),
            $this->recordNode('act', ['label' => 'from {{ trigger.source }}']),
        ],
        [$this->edge('trigger', 'act')],
    );

    $this->hook = $this->inTenant($this->tenant, function () use ($graph): array {
        $manager = app(WorkflowManager::class);
        $owner = $this->ownerMembership($this->tenant);
        $workflow = $manager->create('Inbound', null, $owner, $graph);
        $result = $manager->publish($workflow, $owner);

        return ['workflow' => $workflow->refresh(), ...$result['credentials']];
    });

    /** Send a signed delivery. Overrides let a test break one property at a time. */
    $this->deliver = function (array|string $body, array $overrides = []) {
        $raw = is_array($body) ? json_encode($body) : $body;
        $timestamp = (string) ($overrides['timestamp'] ?? now()->timestamp);
        $secret = $overrides['secret'] ?? $this->hook['secret'];
        $signature = $overrides['signature'] ?? 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if (! ($overrides['no_headers'] ?? false)) {
            $server['HTTP_X_OYOSEND_TIMESTAMP'] = $timestamp;
            $server['HTTP_X_OYOSEND_SIGNATURE'] = $signature;
        }

        if (isset($overrides['delivery'])) {
            $server['HTTP_X_OYOSEND_DELIVERY'] = $overrides['delivery'];
        }

        $tenant = $overrides['tenant'] ?? $this->tenant;
        $token = $overrides['token'] ?? $this->hook['token'];

        return $this->call('POST', $this->tenantUrl($tenant, '/automation/hooks/'.$token), [], [], [], $server, $raw);
    };
});

it('accepts a correctly signed delivery and runs the workflow with its body', function () {
    $response = ($this->deliver)(['source' => 'facebook', 'password' => 'hunter2']);

    $response->assertStatus(202)->assertJsonPath('status', 'accepted');

    $run = $this->onlyRun($this->tenant, $this->hook['workflow']);
    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->source->value)->toBe('webhook')
        ->and(FlowRecorder::$calls[0]['input']['label'])->toBe('from facebook');

    // The payload is shown masked in the run history.
    expect(Presenter::payload($run)['password'])->toBe('[redacted]');
});

it('is stateless: no session or cookie is created', function () {
    $response = ($this->deliver)(['source' => 'x']);

    expect($response->headers->getCookies())->toBeEmpty();
});

it('rejects a missing, wrong or tampered signature', function () {
    ($this->deliver)(['source' => 'x'], ['no_headers' => true])->assertStatus(401);
    ($this->deliver)(['source' => 'x'], ['secret' => 'whsec_not_the_secret'])->assertStatus(401);
    ($this->deliver)(['source' => 'x'], ['signature' => 'sha256=deadbeef'])->assertStatus(401);

    // Signed for one body, sent with another.
    $good = 'sha256='.hash_hmac('sha256', now()->timestamp.'.'.json_encode(['source' => 'x']), $this->hook['secret']);
    ($this->deliver)(['source' => 'tampered'], ['signature' => $good, 'timestamp' => now()->timestamp])->assertStatus(401);

    expect($this->runsOf($this->tenant, $this->hook['workflow']))->toBeEmpty();
});

it('rejects a delivery outside the replay window, even if the signature is valid', function () {
    ($this->deliver)(['source' => 'old'], ['timestamp' => now()->subMinutes(10)->timestamp])->assertStatus(401);
    ($this->deliver)(['source' => 'future'], ['timestamp' => now()->addMinutes(10)->timestamp])->assertStatus(401);

    expect($this->runsOf($this->tenant, $this->hook['workflow']))->toBeEmpty();
});

it('recognises a replayed delivery and starts nothing twice', function () {
    $timestamp = now()->timestamp;

    ($this->deliver)(['source' => 'once'], ['timestamp' => $timestamp])->assertStatus(202);
    ($this->deliver)(['source' => 'once'], ['timestamp' => $timestamp])->assertOk()->assertJsonPath('status', 'duplicate');

    expect($this->runsOf($this->tenant, $this->hook['workflow']))->toHaveCount(1);

    // The same signed bytes later than the window are refused outright.
    $this->travel(10)->minutes();
    ($this->deliver)(['source' => 'once'], ['timestamp' => $timestamp])->assertStatus(401);
});

it('de-duplicates on a sender-chosen delivery id', function () {
    ($this->deliver)(['source' => 'a'], ['delivery' => 'evt_1'])->assertStatus(202);
    ($this->deliver)(['source' => 'b'], ['delivery' => 'evt_1'])->assertOk()->assertJsonPath('status', 'duplicate');
    ($this->deliver)(['source' => 'c'], ['delivery' => 'evt_2'])->assertStatus(202);

    expect($this->runsOf($this->tenant, $this->hook['workflow']))->toHaveCount(2);
});

it('answers 404 for an unknown token and for a token used on another workspace', function () {
    $other = $this->flowTenant('flow-other');

    ($this->deliver)(['source' => 'x'], ['token' => 'whk_'.str_repeat('a', 40)])->assertStatus(404);
    ($this->deliver)(['source' => 'x'], ['tenant' => $other])->assertStatus(404);

    expect(Run::withoutTenancy()->count())->toBe(0);
});

it('answers 404 when the workflow is paused or the app is not entitled', function () {
    $this->inTenant($this->tenant, fn () => app(WorkflowManager::class)->pause(Workflow::query()->findOrFail($this->hook['workflow']->id)));
    ($this->deliver)(['source' => 'x'])->assertStatus(404);

    $this->inTenant($this->tenant, fn () => app(WorkflowManager::class)->resume(
        Workflow::query()->findOrFail($this->hook['workflow']->id),
        $this->ownerMembership($this->tenant),
    ));
    ($this->deliver)(['source' => 'x'])->assertStatus(202);

    $this->revokeModules($this->tenant, 'automation');
    ($this->deliver)(['source' => 'y'])->assertStatus(404);
});

it('rejects a body that is not a JSON object, or is too large', function () {
    ($this->deliver)('not json at all')->assertStatus(422);
    ($this->deliver)(json_encode([1, 2, 3]))->assertStatus(422);

    config(['automation.limits.max_payload_bytes' => 64]);
    ($this->deliver)(['source' => str_repeat('x', 200)])->assertStatus(413);
});

it('stores the token hashed and the signing secret encrypted', function () {
    $row = DB::table('automation_workflows')->where('id', $this->hook['workflow']->id)->first();

    expect($row->webhook_token_hash)->toBe(hash('sha256', $this->hook['token']))
        ->and($row->webhook_token_hash)->not->toContain($this->hook['token'])
        ->and($row->webhook_secret)->not->toContain($this->hook['secret'])
        ->and($row->webhook_secret)->not->toBe('');
});

it('stops accepting the old credentials once they are rotated', function () {
    $new = $this->inTenant($this->tenant, fn () => app(WorkflowManager::class)->rotateWebhook(
        Workflow::query()->findOrFail($this->hook['workflow']->id),
    ));

    ($this->deliver)(['source' => 'x'])->assertStatus(404); // old token
    ($this->deliver)(['source' => 'x'], ['token' => $new['token'], 'secret' => $this->hook['secret']])->assertStatus(401); // old secret
    ($this->deliver)(['source' => 'x'], ['token' => $new['token'], 'secret' => $new['secret']])->assertStatus(202);
});

describe('outbound webhook node', function () {
    beforeEach(function () {
        $this->resolvesTo = function (array $addresses): void {
            $this->app->bind(HostResolver::class, fn () => new class($addresses) extends HostResolver
            {
                public function __construct(private readonly array $addresses) {}

                public function resolve(string $host): array
                {
                    return $this->addresses;
                }
            });
        };

        $this->endpoint = fn (string $url = 'https://hooks.example.test/in', ?string $secret = 'topsecret-topsecret') => $this->inTenant(
            $this->tenant,
            fn () => WebhookEndpoint::query()->create(['name' => 'CRM '.bin2hex(random_bytes(3)), 'url' => $url, 'secret' => $secret, 'is_active' => true]),
        );

        $this->hookGraph = fn (WebhookEndpoint $endpoint, array $extra = []) => $this->graphOf(
            [
                $this->eventTrigger(),
                $this->node('call', 'webhook', ['endpoint_id' => $endpoint->id, 'payload' => ['item' => '{{ trigger.item_id }}', 'token' => 'abc'], ...$extra]),
            ],
            [$this->edge('trigger', 'call')],
        );
    });

    it('posts a signed JSON payload to the registered endpoint', function () {
        ($this->resolvesTo)(['93.184.216.34']);
        Http::fake(['hooks.example.test/*' => Http::response(['ok' => true], 200)]);
        $endpoint = ($this->endpoint)();
        $workflow = $this->makeWorkflow($this->tenant, ($this->hookGraph)($endpoint));

        $this->raise($this->tenant, 'item-7');

        Http::assertSent(function ($request) {
            $timestamp = $request->header('X-Oyosend-Timestamp')[0] ?? '';
            $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), 'topsecret-topsecret');

            return $request->url() === 'https://hooks.example.test/in'
                && $request['item'] === 'item-7'
                && ($request->header('X-Oyosend-Signature')[0] ?? null) === $expected
                && str_starts_with($request->header('X-Oyosend-Idempotency-Key')[0] ?? '', 'wf:');
        });
        expect($this->onlyRun($this->tenant, $workflow)->status)->toBe(RunStatus::Succeeded);

        // The secret is not in the run's logged input, and secret-looking payload keys are masked.
        $step = $this->inTenant($this->tenant, fn () => $this->onlyRun($this->tenant, $workflow)->steps()->where('node_id', 'call')->sole());
        expect(json_encode($step->input))->not->toContain('topsecret')->and($step->input['token'])->toBe('[redacted]');
    });

    it('refuses an endpoint that resolves to a private address', function () {
        ($this->resolvesTo)(['10.0.0.5']);
        Http::fake();
        $workflow = $this->makeWorkflow($this->tenant, ($this->hookGraph)(($this->endpoint)()));

        $this->raise($this->tenant);

        Http::assertNothingSent();
        $run = $this->onlyRun($this->tenant, $workflow);
        expect($run->status)->toBe(RunStatus::Failed)->and($run->error_code)->toBe('webhook_failed');
    });

    it('retries a server error with backoff, and does not retry a client error', function () {
        ($this->resolvesTo)(['93.184.216.34']);
        Http::fake(['hooks.example.test/*' => Http::sequence()->push('', 503)->push('', 200)->push('', 400)]);
        $workflow = $this->makeWorkflow($this->tenant, ($this->hookGraph)(($this->endpoint)(), ['retry' => ['max_attempts' => 3, 'backoff_seconds' => 30]]));

        $this->raise($this->tenant);
        $run = $this->onlyRun($this->tenant, $workflow);
        expect($run->status)->toBe(RunStatus::Waiting);

        $this->travel(2)->minutes();
        expect($this->drain($this->tenant, $run)->status)->toBe(RunStatus::Succeeded);

        $this->raise($this->tenant, 'again'); // answered 400
        $runs = $this->runsOf($this->tenant, $workflow);
        expect(end($runs)->status)->toBe(RunStatus::Failed);
    });

    it('does not follow redirects', function () {
        ($this->resolvesTo)(['93.184.216.34']);
        Http::fake(['hooks.example.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
        $workflow = $this->makeWorkflow($this->tenant, ($this->hookGraph)(($this->endpoint)()));

        $this->raise($this->tenant);

        Http::assertSentCount(1);
        expect($this->onlyRun($this->tenant, $workflow)->status)->toBe(RunStatus::Failed);
    });
});
