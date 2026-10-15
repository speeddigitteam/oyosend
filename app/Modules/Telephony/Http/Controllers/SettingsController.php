<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Http\Controllers;

use App\Core\Communication\Support\PhoneNumber;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\Telephony\Actions\ExtensionManager;
use App\Modules\Telephony\Actions\TelephonySettings;
use App\Modules\Telephony\Enums\PbxDriver;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Pbx\PbxUrlGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace's PBX connection and its members' extensions (ADR 0036). Every secret is write-only: the page learns
 * only whether one is stored, and an empty field on save keeps it.
 */
final class SettingsController
{
    public const string MANAGE = 'telephony.settings.manage';

    public function __construct(
        private readonly TelephonySettings $settings,
        private readonly ExtensionManager $extensions,
        private readonly PbxUrlGuard $guard,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $members = TenantMembership::query()->where('status', MembershipStatus::Active)->with('user:id,name,email')->get();
        $extensions = Extension::query()->get()->keyBy('membership_id');

        return Inertia::render('Modules/Telephony/Pages/Settings/Index', [
            'settings' => $this->settings->present($this->settings->current()),
            'drivers' => $this->drivers(),
            'webhookUrl' => route('telephony.events', ['tenant' => $this->context->require()->slug]),
            // Present only on the visit right after it was generated; it is never shown again.
            'revealedWebhookSecret' => $request->session()->get('telephony.webhook_secret'),
            'members' => $members->map(fn (TenantMembership $member): array => [
                'id' => $member->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'extension' => ($record = $extensions->get($member->id)) === null ? null : [
                    'id' => $record->id,
                    'extension' => $record->extension,
                    'hasPassword' => $record->sip_password !== null,
                    'bridgePhone' => $record->bridge_phone,
                ],
            ])->sortBy('name')->values()->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'driver' => ['required', Rule::in($this->drivers())],
            'wss_url' => ['nullable', 'string', 'max:255', 'regex:#^wss://[^\s]+$#i'],
            'sip_domain' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'api_url' => ['nullable', 'string', 'max:255'],
            'api_username' => ['nullable', 'string', 'max:120'],
            'api_secret' => ['nullable', 'string', 'max:255'],
            'origination_context' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'caller_id' => ['nullable', 'string', 'max:40'],
            'stun_urls' => ['nullable', 'array', 'max:4'],
            'stun_urls.*' => ['string', 'max:255', 'regex:#^stuns?:[^\s]+$#i'],
            'turn_url' => ['nullable', 'string', 'max:255', 'regex:#^turns?:[^\s]+$#i'],
            'turn_username' => ['nullable', 'string', 'max:120'],
            'turn_credential' => ['nullable', 'string', 'max:255'],
        ]);

        if (($input['api_url'] ?? null) !== null && ($problem = $this->guard->syntaxProblem($input['api_url'])) !== null) {
            throw ValidationException::withMessages(['api_url' => __($problem)]);
        }

        $input['stun_urls'] = array_values(array_filter($input['stun_urls'] ?? [], static fn (?string $url): bool => $url !== null && $url !== ''));
        $this->settings->update($input);
        Toast::success(__('Telephony settings saved.'));

        return back();
    }

    /** A new signing secret for PBX events, shown once. */
    public function rotateWebhookSecret(Request $request): RedirectResponse
    {
        $request->session()->flash('telephony.webhook_secret', $this->settings->rotateWebhookSecret());
        Toast::success(__('A new webhook secret was created. Copy it now: it will not be shown again.'));

        return back();
    }

    public function storeExtension(Request $request): RedirectResponse
    {
        $input = $this->validatedExtension($request, null);
        $member = TenantMembership::query()->where('status', MembershipStatus::Active)->findOrFail($input['membership_id']);
        $this->extensions->save($member, $input['extension'], $input['sip_password'], $input['bridge_phone']);
        Toast::success(__('Extension saved.'));

        return back();
    }

    public function updateExtension(Request $request, Extension $extension): RedirectResponse
    {
        $input = $this->validatedExtension($request, $extension);
        $this->extensions->save($extension->membership, $input['extension'], $input['sip_password'], $input['bridge_phone']);
        Toast::success(__('Extension saved.'));

        return back();
    }

    public function destroyExtension(Extension $extension): RedirectResponse
    {
        $this->extensions->delete($extension);
        Toast::success(__('Extension removed.'));

        return back();
    }

    /** @return array{membership_id: string, extension: string, sip_password: string|null, bridge_phone: string|null} */
    private function validatedExtension(Request $request, ?Extension $current): array
    {
        $tenant = $this->context->requireId();
        $input = $request->validate([
            'membership_id' => [$current === null ? 'required' : 'prohibited', 'string', 'size:26',
                Rule::unique('telephony_extensions', 'membership_id')->where('tenant_id', $tenant)],
            'extension' => ['required', 'string', 'max:32', 'regex:/^[0-9A-Za-z_\-]+$/',
                Rule::unique('telephony_extensions', 'extension')->where('tenant_id', $tenant)->ignore($current?->id)],
            'sip_password' => ['nullable', 'string', 'max:128'],
            'bridge_phone' => ['nullable', 'string', 'max:40'],
        ]);
        $bridge = $input['bridge_phone'] ?? null;
        $input['bridge_phone'] = $bridge === null || $bridge === '' ? null : PhoneNumber::normalize($bridge);

        if ($bridge !== null && $bridge !== '' && $input['bridge_phone'] === null) {
            throw ValidationException::withMessages(['bridge_phone' => __('Enter a phone number, such as 01712345678.')]);
        }

        $password = $input['sip_password'] ?? null;

        return [
            'membership_id' => (string) ($input['membership_id'] ?? $current?->membership_id),
            'extension' => (string) $input['extension'],
            'sip_password' => is_string($password) ? $password : null,
            'bridge_phone' => $input['bridge_phone'],
        ];
    }

    /** @return list<string> The fake PBX is offered only in local development and tests. */
    private function drivers(): array
    {
        return app()->environment(['local', 'testing'])
            ? [PbxDriver::Asterisk->value, PbxDriver::Fake->value]
            : [PbxDriver::Asterisk->value];
    }
}
