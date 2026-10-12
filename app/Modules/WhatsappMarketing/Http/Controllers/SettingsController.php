<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Http\Controllers;

use App\Core\Billing\UsageLimits;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Support\Toast;
use App\Modules\WhatsappMarketing\Actions\AgentManager;
use App\Modules\WhatsappMarketing\Actions\ChannelStatus;
use App\Modules\WhatsappMarketing\Actions\SettingsManager;
use App\Modules\WhatsappMarketing\Actions\WindowTracker;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace's WhatsApp options, the agent roster and the connection status. The connection itself is a
 * channel of the communication app: this page only reports it, and never shows a credential.
 */
final class SettingsController
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly AgentManager $agents,
        private readonly ChannelStatus $channel,
        private readonly UsageLimits $limits,
        private readonly Presenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $this->presenter->actor($request);
        $setting = $this->settings->current();

        $enrolled = Agent::query()->with('membership.user:id,name')->get()
            ->filter(static fn (Agent $agent): bool => $agent->membership->status === MembershipStatus::Active)
            ->map(static fn (Agent $agent): array => [
                'id' => $agent->id,
                'membershipId' => $agent->membership_id,
                'name' => $agent->membership->user->name,
                'isActive' => $agent->is_active,
                'isAvailable' => $agent->is_available,
                'maxOpenConversations' => $agent->max_open_conversations,
            ])->sortBy('name')->values()->all();

        $enrolledIds = array_column($enrolled, 'membershipId');

        return Inertia::render('Modules/WhatsappMarketing/Pages/Settings/Index', [
            'settings' => [
                'businessName' => $setting->business_name,
                'displayPhone' => $setting->display_phone,
                'autoAssign' => $setting->auto_assign,
                'autoCreateContacts' => $setting->auto_create_contacts,
                'botsEnabled' => $setting->bots_enabled,
            ],
            'channel' => $this->channel->describe(),
            'agents' => $enrolled,
            'members' => TenantMembership::query()->where('status', MembershipStatus::Active)->with('user:id,name')->get()
                ->reject(static fn (TenantMembership $membership): bool => in_array($membership->id, $enrolledIds, true))
                ->map(static fn (TenantMembership $membership): array => ['id' => $membership->id, 'name' => $membership->user->name])
                ->sortBy('name')->values()->all(),
            'limits' => [
                'agents' => ['used' => $this->limits->used(AgentManager::LIMIT), 'limit' => $this->limits->limit(AgentManager::LIMIT)],
                'conversations' => ['used' => $this->limits->used(WindowTracker::LIMIT), 'limit' => $this->limits->limit(WindowTracker::LIMIT)],
            ],
            'guidance' => ['communicationSettingsRoute' => 'communication.settings.index', 'webhookPath' => '/communication/webhooks/whatsapp_cloud'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'business_name' => ['nullable', 'string', 'max:120'],
            'display_phone' => ['nullable', 'string', 'max:32'],
            'auto_assign' => ['required', 'boolean'],
            'auto_create_contacts' => ['required', 'boolean'],
            'bots_enabled' => ['required', 'boolean'],
        ]);

        $this->settings->update($input);
        Toast::success(__('WhatsApp settings saved.'));

        return back();
    }

    public function enrol(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'membership_id' => ['required', 'string', 'size:26'],
            'is_available' => ['nullable', 'boolean'],
            'max_open_conversations' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $this->agents->enrol($input['membership_id'], [
            'is_available' => (bool) ($input['is_available'] ?? true),
            'max_open_conversations' => $input['max_open_conversations'] ?? null,
        ]);
        Toast::success(__('Agent enrolled.'));

        return back();
    }

    public function updateAgent(Request $request, Agent $agent): RedirectResponse
    {
        $input = $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'is_available' => ['nullable', 'boolean'],
            'max_open_conversations' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $this->agents->update($agent, array_intersect_key($input, ['is_active' => 1, 'is_available' => 1, 'max_open_conversations' => 1]));
        Toast::success(__('Agent saved.'));

        return back();
    }
}
