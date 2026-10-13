<?php

declare(strict_types=1);

namespace App\Modules\Automation\Http\Controllers;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;
use App\Modules\Automation\Engine\RunStarter;
use App\Modules\Automation\Enums\RunSource;
use App\Modules\Automation\Enums\VersionStatus;
use App\Modules\Automation\Enums\WorkflowStatus;
use App\Modules\Automation\Models\Run;
use App\Modules\Automation\Models\Workflow;
use App\Modules\Automation\Models\WorkflowVersion;
use App\Modules\Automation\Support\Limits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Inbound webhook trigger. Stateless: no session, no cookies, no CSRF (the `tenant.callback` group).
 *
 * The caller proves itself with an HMAC over `{timestamp}.{raw body}` (or `{timestamp}.{delivery}.{raw body}`
 * when it sends an `X-Oyosend-Delivery` id, so the id is covered by the signature) using the workflow's
 * signing secret, and the timestamp must be inside the replay window. The URL token only locates the workflow (it is stored
 * hashed); it is not the credential. Redelivery of the same signed request is recognised and starts nothing.
 * Nothing in the body is trusted beyond being data for the workflow: it cannot choose the tenant, the
 * workflow version or the actor.
 */
final class InboundWebhookController
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly EntitlementChecker $entitlements,
        private readonly RunStarter $starter,
    ) {}

    public function receive(Request $request, string $token): JsonResponse
    {
        $raw = $request->getContent();

        if (strlen($raw) > Limits::int('max_payload_bytes')) {
            return response()->json(['status' => 'too_large'], 413);
        }

        $workflow = Workflow::query()
            ->where('webhook_token_hash', hash('sha256', $token))
            ->where('status', WorkflowStatus::Active->value)
            ->where('trigger_key', 'automation.trigger.webhook')
            ->first();

        $secret = $workflow?->webhook_secret;

        if ($workflow === null || $secret === null || ! $this->entitlements->allows($this->tenants->require(), 'automation')) {
            return response()->json(['status' => 'not_found'], 404);
        }

        $timestamp = (string) $request->header('X-Oyosend-Timestamp', '');
        $signature = (string) $request->header('X-Oyosend-Signature', '');

        if (preg_match('/^\d{10}$/D', $timestamp) !== 1
            || abs(Carbon::now()->timestamp - (int) $timestamp) > Limits::int('webhook_replay_seconds')) {
            return response()->json(['status' => 'stale'], 401);
        }

        // The delivery id picks the replay key, so it is part of what is signed: otherwise a captured request
        // could be replayed with other ids. A body is a JSON object, so `ts.delivery.body` and `ts.body` are
        // never ambiguous (an id cannot contain `{`).
        $delivery = (string) $request->header('X-Oyosend-Delivery', '');

        if ($delivery !== '' && preg_match('/^[A-Za-z0-9_\-]{1,64}$/D', $delivery) !== 1) {
            return response()->json(['status' => 'invalid_delivery'], 422);
        }

        $signed = $delivery === '' ? $timestamp.'.'.$raw : $timestamp.'.'.$delivery.'.'.$raw;
        $expected = 'sha256='.hash_hmac('sha256', $signed, $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $payload = json_decode($raw === '' ? '{}' : $raw, true, 32);

        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return response()->json(['status' => 'invalid_payload'], 422);
        }

        $identity = $delivery !== '' ? $delivery : $signature;
        $key = 'webhook:'.substr(hash('sha256', $identity), 0, 40);

        $version = $workflow->published_version_id === null ? null : WorkflowVersion::query()->find($workflow->published_version_id);

        if ($version === null || $version->status !== VersionStatus::Published) {
            return response()->json(['status' => 'not_found'], 404);
        }

        $run = $this->starter->start($workflow, $version, RunSource::Webhook, $key, $payload);

        if ($run !== null) {
            return response()->json(['status' => 'accepted', 'run_id' => $run->id], 202);
        }

        $existing = Run::query()->where('workflow_id', $workflow->id)->where('idempotency_key', $key)->first();

        return $existing !== null
            ? response()->json(['status' => 'duplicate', 'run_id' => $existing->id])
            : response()->json(['status' => 'rate_limited'], 429);
    }
}
