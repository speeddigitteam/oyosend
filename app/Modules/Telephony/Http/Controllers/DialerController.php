<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Http\Controllers;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Communication\Support\PhoneNumber;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\Telephony\Actions\BridgeCalls;
use App\Modules\Telephony\Actions\CallLog;
use App\Modules\Telephony\Actions\SoftphoneConfig;
use App\Modules\Telephony\Actions\TelephonySettings;
use App\Modules\Telephony\Enums\CallDirection;
use App\Modules\Telephony\Enums\CallMode;
use App\Modules\Telephony\Enums\CallStatus;
use App\Modules\Telephony\Models\Call;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Pbx\PbxUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dialer page and what the softphone on it talks to. The softphone carries the call itself (SIP over WSS from the
 * browser); these endpoints only hand the member their own credentials and keep the log in step.
 */
final class DialerController
{
    public const string MAKE = 'telephony.calls.make';

    public function __construct(
        private readonly AccessChecker $access,
        private readonly RecordAccess $records,
        private readonly TelephonySettings $settings,
        private readonly SoftphoneConfig $softphone,
        private readonly CallLog $log,
        private readonly BridgeCalls $bridge,
    ) {}

    public function show(Request $request): Response
    {
        $actor = $this->actor($request);
        $member = $this->membership($actor);
        $input = $request->validate([
            'number' => ['nullable', 'string', 'max:40'],
            'contact' => ['nullable', 'string', 'size:26'],
        ]);
        $settings = $this->settings->current();
        $extension = Extension::query()->where('membership_id', $member->id)->first();
        $contact = $this->contact($input['contact'] ?? null, $actor);
        $number = PhoneNumber::normalize($input['number'] ?? null) ?? PhoneNumber::normalize($contact?->phone);

        return Inertia::render('Modules/Telephony/Pages/Dialer', [
            'setup' => [
                'workspaceSoftphone' => $settings->softphoneReady(),
                'workspaceBridge' => $settings->bridgeReady(),
                'extension' => $extension?->extension,
                'hasPassword' => $extension?->sip_password !== null,
                'bridgePhone' => $extension?->bridge_phone,
            ],
            'prefill' => [
                'number' => $number,
                'contact' => $contact === null ? null : ['id' => $contact->id, 'name' => $contact->fullName()],
            ],
            'recent' => $this->recent($member),
            'canViewLog' => $actor->can(CallController::VIEW),
            'canManageSettings' => $actor->can(SettingsController::MANAGE),
        ]);
    }

    /** The member's own softphone credentials. JSON, never cached, never in page props. */
    public function credentials(Request $request): JsonResponse
    {
        $config = $this->softphone->for($this->membership($this->actor($request)));

        return response()
            ->json($config === null ? ['status' => 'not_configured'] : ['status' => 'ok', 'config' => $config], $config === null ? 409 : 200)
            ->header('Cache-Control', 'no-store, private');
    }

    /** The softphone started or received a call: a row for the log, unconfirmed until the PBX reports it. */
    public function start(Request $request): JsonResponse
    {
        $member = $this->membership($this->actor($request));
        $input = $request->validate([
            'number' => ['required', 'string', 'max:40'],
            'direction' => ['required', Rule::enum(CallDirection::class)],
            'client_call_id' => ['required', 'string', 'max:120', 'regex:/^[\x21-\x7e]+$/'],
        ]);
        $number = PhoneNumber::normalize($input['number']);

        if ($number === null) {
            throw ValidationException::withMessages(['number' => __('Enter a phone number, such as 01712345678.')]);
        }

        $existing = Call::query()->where('client_call_id', $input['client_call_id'])->first();

        if ($existing !== null) {
            abort_unless($existing->owner_id === $member->id, 404);

            return response()->json(['id' => $existing->id]);
        }

        $extension = Extension::query()->where('membership_id', $member->id)->value('extension');
        $call = $this->log->start($member, $number, CallDirection::from($input['direction']), CallMode::Softphone, is_string($extension) ? $extension : null, $input['client_call_id']);

        return response()->json(['id' => $call->id], 201);
    }

    /** The softphone reports a change on the member's own call. */
    public function report(Request $request, Call $call): JsonResponse
    {
        $member = $this->membership($this->actor($request));
        // Only the member's own call; anyone else's is simply not found.
        abort_unless($call->owner_id === $member->id, 404);
        $input = $request->validate([
            'status' => ['required', Rule::in(['ringing', 'answered', 'completed', 'busy', 'no_answer', 'failed'])],
        ]);
        $call = $this->log->reportFromClient($call, CallStatus::from($input['status']));

        return response()->json(['id' => $call->id, 'status' => $call->status->value]);
    }

    /** Ring the member's own phone, then the customer. */
    public function bridge(Request $request): JsonResponse
    {
        $member = $this->membership($this->actor($request));
        $input = $request->validate(['number' => ['required', 'string', 'max:40']]);
        $number = PhoneNumber::normalize($input['number']);

        if ($number === null) {
            throw ValidationException::withMessages(['number' => __('Enter a phone number, such as 01712345678.')]);
        }

        try {
            $call = $this->bridge->start($member, $number);
        } catch (PbxUnavailable $exception) {
            return response()->json(['status' => 'unavailable', 'message' => __($exception->getMessage())], 503);
        }

        return response()->json(['status' => 'ringing', 'id' => $call->id], 201);
    }

    /** @return list<array<string, mixed>> */
    private function recent(TenantMembership $member): array
    {
        return Call::query()->where('owner_id', $member->id)->with('contact:id,first_name,last_name')
            ->latest('started_at')->limit(15)->get()
            ->map(fn (Call $call): array => CallController::present($call))->all();
    }

    private function contact(?string $id, User $actor): ?Contact
    {
        if ($id === null || ! $actor->can(ContactController::VIEW)) {
            return null;
        }

        return $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)->find($id);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function membership(User $actor): TenantMembership
    {
        return $this->access->membership($actor) ?? abort(403);
    }
}
