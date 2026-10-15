<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Actions;

use App\Core\Communication\Support\PhoneNumber;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Telephony\Enums\CallDirection;
use App\Modules\Telephony\Enums\CallMode;
use App\Modules\Telephony\Enums\CallStatus;
use App\Modules\Telephony\Events\CallEnded;
use App\Modules\Telephony\Models\Call;
use App\Modules\Telephony\Models\Extension;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes the call log. Two sources, ranked: the PBX's own events are the record and confirm a call; what a member's
 * browser reports fills an unconfirmed row so the log is live, and is ignored once the PBX has spoken. A call that
 * reaches a final state announces {@see CallEnded} exactly once.
 */
final class CallLog
{
    public function __construct(private readonly ContactMatch $contacts) {}

    /** A call the member started (or, from their softphone, took) before the PBX reported anything. */
    public function start(TenantMembership $member, string $number, CallDirection $direction, CallMode $mode, ?string $extension, ?string $clientCallId = null): Call
    {
        return DB::transaction(function () use ($member, $number, $direction, $mode, $extension, $clientCallId): Call {
            $call = new Call([
                'direction' => $direction,
                'mode' => $mode,
                'status' => $direction === CallDirection::Inbound ? CallStatus::Ringing : CallStatus::Dialing,
                'remote_number' => $number,
                'extension' => $extension,
                'contact_id' => $this->contacts->idFor($number),
                'client_call_id' => $clientCallId,
                'started_at' => Carbon::now(),
            ]);
            $call->assignOwner($member)->save();

            return $call;
        });
    }

    /** What the member's own browser says about their call; ignored once the PBX confirmed it. */
    public function reportFromClient(Call $call, CallStatus $status): Call
    {
        if ($call->confirmed_at !== null || $call->status->isFinal()) {
            return $call;
        }

        return DB::transaction(fn (): Call => $this->transition($call, $status, Carbon::now()));
    }

    public function fail(Call $call): Call
    {
        return DB::transaction(fn (): Call => $this->transition($call, CallStatus::Failed, Carbon::now()));
    }

    /**
     * Applies one PBX event (already authenticated and de-duplicated). Finds the call by our id, the PBX's id or the
     * SIP Call-ID; an unknown call becomes a new row owned by the member whose extension took or placed it.
     *
     * @param  array{call_id: string, sip_call_id?: string|null, type: string, direction?: string|null, from?: string|null, to?: string|null, extension?: string|null, status?: string|null, at: Carbon}  $event
     */
    public function applyPbxEvent(array $event): ?Call
    {
        return DB::transaction(function () use ($event): ?Call {
            $call = $this->locate($event['call_id'], $event['sip_call_id'] ?? null);

            if ($call === null) {
                $call = $this->createFromPbx($event);

                if ($call === null) {
                    return null;
                }
            }

            if ($call->pbx_call_id === null && $call->id !== $event['call_id']) {
                $call->pbx_call_id = $event['call_id'];
            }

            $call->confirmed_at ??= $event['at'];

            $status = match ($event['type']) {
                'ringing' => CallStatus::Ringing,
                'answered' => CallStatus::Answered,
                'ended' => CallStatus::tryFrom((string) ($event['status'] ?? '')) ?? CallStatus::Completed,
                default => null,
            };

            if ($status === null) {
                $call->save();

                return $call;
            }

            return $this->transition($call, $status, $event['at']);
        });
    }

    private function locate(string $callId, ?string $sipCallId): ?Call
    {
        $call = Call::query()->where(fn ($query) => $query->whereKey($callId)->orWhere('pbx_call_id', $callId))->lockForUpdate()->first();

        if ($call === null && $sipCallId !== null && $sipCallId !== '') {
            $call = Call::query()->where('client_call_id', $sipCallId)->lockForUpdate()->first();
        }

        return $call;
    }

    /** @param array{call_id: string, direction?: string|null, from?: string|null, to?: string|null, extension?: string|null, at: Carbon} $event */
    private function createFromPbx(array $event): ?Call
    {
        $direction = CallDirection::tryFrom((string) ($event['direction'] ?? '')) ?? CallDirection::Inbound;
        $remote = PhoneNumber::normalize($direction === CallDirection::Inbound ? ($event['from'] ?? null) : ($event['to'] ?? null));
        $extension = isset($event['extension']) && $event['extension'] !== '' ? (string) $event['extension'] : null;

        if ($remote === null) {
            return null;
        }

        $owner = $extension === null ? null : Extension::query()->where('extension', $extension)->with('membership')->first()?->membership;

        $call = new Call([
            'direction' => $direction,
            'mode' => CallMode::Softphone,
            'status' => CallStatus::Ringing,
            'remote_number' => $remote,
            'extension' => $extension,
            'contact_id' => $this->contacts->idFor($remote),
            'pbx_call_id' => $event['call_id'],
            'started_at' => $event['at'],
        ]);
        $call->assignOwner($owner)->save();

        return $call;
    }

    private function transition(Call $call, CallStatus $status, Carbon $at): Call
    {
        $wasFinal = $call->status->isFinal();

        if ($wasFinal) {
            $call->save();

            return $call;
        }

        if ($status === CallStatus::Answered) {
            $call->answered_at ??= $at;
        }

        // A call nobody answered that "completed" was missed, whoever reported it.
        if ($status === CallStatus::Completed && $call->answered_at === null) {
            $status = $call->direction === CallDirection::Inbound ? CallStatus::Missed : CallStatus::NoAnswer;
        }

        $call->status = $status;

        if ($status->isFinal()) {
            $call->ended_at = $at;
            $call->duration_seconds = $call->answered_at === null ? 0 : max(0, (int) $call->answered_at->diffInSeconds($at));
        }

        $call->save();

        if ($status->isFinal()) {
            CallEnded::dispatch($call);
        }

        return $call;
    }
}
