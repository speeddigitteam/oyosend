<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Http\Controllers;

use App\Modules\Telephony\Actions\CallLog;
use App\Modules\Telephony\Models\TelephonySetting;
use App\Modules\Telephony\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Call events from the workspace's PBX. Stateless (`tenant.callback`): no session, no cookies, no CSRF.
 *
 * The PBX proves itself with `X-Oyosend-Signature: sha256=HMAC(webhook_secret, "{timestamp}.{raw body}")` and an
 * `X-Oyosend-Timestamp` inside the replay window. The event id makes a redelivery a no-op. The body is trusted only
 * for what it says about its own call: it cannot pick the tenant (the host does) or a member (an extension maps to
 * one, inside this workspace only). See docs/TELEPHONY.md for the body.
 */
final class WebhookController
{
    public function __construct(private readonly CallLog $log) {}

    public function receive(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (strlen($raw) > (int) config('telephony.webhook_max_bytes', 16384)) {
            return response()->json(['status' => 'too_large'], 413);
        }

        $secret = TelephonySetting::query()->first()?->webhook_secret;

        if ($secret === null || $secret === '') {
            return response()->json(['status' => 'not_found'], 404);
        }

        $timestamp = (string) $request->header('X-Oyosend-Timestamp', '');

        if (preg_match('/^\d{10}$/D', $timestamp) !== 1
            || abs(Carbon::now()->timestamp - (int) $timestamp) > (int) config('telephony.webhook_replay_seconds', 300)) {
            return response()->json(['status' => 'stale'], 401);
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret);

        if (! hash_equals($expected, (string) $request->header('X-Oyosend-Signature', ''))) {
            return response()->json(['status' => 'invalid_signature'], 401);
        }

        $body = json_decode($raw, true, 8);
        $event = is_array($body) ? $this->event($body) : null;

        if ($event === null) {
            return response()->json(['status' => 'invalid_payload'], 422);
        }

        try {
            $call = DB::transaction(function () use ($event): ?string {
                WebhookEvent::query()->create(['event_key' => hash('sha256', $event['event_id']), 'received_at' => Carbon::now()]);

                return $this->log->applyPbxEvent($event)?->id;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => $call === null ? 'ignored' : 'accepted', 'call_id' => $call]);
    }

    /**
     * @param  array<mixed>  $body
     * @return array{event_id: string, call_id: string, sip_call_id: string|null, type: string, direction: string|null, from: string|null, to: string|null, extension: string|null, status: string|null, at: Carbon}|null
     */
    private function event(array $body): ?array
    {
        $string = static fn (string $key, int $max): ?string => isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '' && strlen($body[$key]) <= $max
            ? $body[$key]
            : null;

        $eventId = $string('event_id', 120);
        $callId = $string('call_id', 120);
        $type = $string('type', 20);

        if ($eventId === null || $callId === null || ! in_array($type, ['ringing', 'answered', 'ended'], true)) {
            return null;
        }

        try {
            $at = isset($body['at']) && is_string($body['at']) ? Carbon::parse($body['at']) : Carbon::now();
        } catch (Throwable) {
            return null;
        }

        return [
            'event_id' => $eventId,
            'call_id' => $callId,
            'sip_call_id' => $string('sip_call_id', 120),
            'type' => $type,
            'direction' => $string('direction', 10),
            'from' => $string('from', 40),
            'to' => $string('to', 40),
            'extension' => $string('extension', 32),
            'status' => $string('status', 16),
            'at' => $at,
        ];
    }
}
