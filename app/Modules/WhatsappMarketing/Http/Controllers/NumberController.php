<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Communication\Contracts\ChannelNumberRegistry;
use App\Core\Communication\Data\ChannelNumberOutcome;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Support\Toast;
use App\Modules\WhatsappMarketing\Actions\ChannelStatus;
use App\Modules\WhatsappMarketing\Actions\WhatsappNumbers;
use App\Modules\WhatsappMarketing\Http\Presenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace's WhatsApp business numbers (ADR 0022): list, add, label, enable/disable and set the default.
 * The numbers live on the communication app's one WhatsApp connection; each is claimed platform-wide, so this
 * page shows each number's claim/availability status honestly and never shows a credential. Permission is reused
 * from the app's own settings (`whatsapp_marketing.settings.manage`).
 */
final class NumberController
{
    public function __construct(
        private readonly ChannelNumberRegistry $registry,
        private readonly WhatsappNumbers $numbers,
        private readonly ChannelStatus $channel,
        private readonly Presenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $this->presenter->actor($request);

        return Inertia::render('Modules/WhatsappMarketing/Pages/Settings/Numbers', [
            'numbers' => $this->numbers->all(),
            'channel' => $this->channel->describe(),
            'guidance' => ['communicationSettingsRoute' => 'communication.settings.index'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->presenter->actor($request);
        $input = $request->validate([
            'account_id' => ['required', 'string', 'regex:/^\d{5,32}$/'],
            'label' => ['required', 'string', 'max:60'],
            'display_number' => ['nullable', 'string', 'max:32'],
            'make_default' => ['nullable', 'boolean'],
        ]);

        $this->handle($this->registry->register(
            MarketingChannel::Whatsapp,
            $input['account_id'],
            $input['label'],
            $input['display_number'] ?? null,
            (bool) ($input['make_default'] ?? false),
        ), __('Number added.'));

        return back();
    }

    public function update(Request $request, string $number): RedirectResponse
    {
        $this->presenter->actor($request);
        $input = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'display_number' => ['nullable', 'string', 'max:32'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->handle($this->registry->update(
            $number,
            $input['label'] ?? null,
            $input['display_number'] ?? null,
            array_key_exists('is_active', $input) ? (bool) $input['is_active'] : null,
        ), __('Number saved.'));

        return back();
    }

    public function makeDefault(Request $request, string $number): RedirectResponse
    {
        $this->presenter->actor($request);
        $this->handle($this->registry->makeDefault($number), __('Default number updated.'));

        return back();
    }

    public function destroy(Request $request, string $number): RedirectResponse
    {
        $this->presenter->actor($request);
        $this->handle($this->registry->remove($number), __('Number removed.'));

        return back();
    }

    /** Turn the registry's outcome into a toast or a validation error with an honest reason. */
    private function handle(ChannelNumberOutcome $outcome, string $success): void
    {
        if ($outcome->ok) {
            Toast::success($success);

            return;
        }

        throw ValidationException::withMessages(['number' => $this->message((string) $outcome->reason)]);
    }

    private function message(string $reason): string
    {
        return match ($reason) {
            'module_unavailable' => __('WhatsApp Business is not available for this workspace.'),
            'not_connected', 'provider_not_configured', 'unsupported_channel' => __('Connect the WhatsApp channel in Communication settings first.'),
            'invalid_account_id' => __('A WhatsApp phone number id is 5 to 32 digits.'),
            'invalid_label' => __('Give the number a label of up to 60 characters.'),
            'label_in_use' => __('Another number already uses this label.'),
            'account_in_use' => __('This WhatsApp number is already registered, here or in another workspace.'),
            'not_found' => __('That number is not part of this workspace.'),
            'in_use' => __('Messages have already been sent from this number, so it can only be disabled, not removed.'),
            'is_default' => __('Make another number the default before you remove or disable this one.'),
            'inactive' => __('Enable the number before you make it the default.'),
            default => __('The number could not be saved.'),
        };
    }
}
