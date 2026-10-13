<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Crm;

use App\Core\Access\RecordAccess;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Contact;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use App\Modules\WhatsappMarketing\Actions\ConversationManager;
use App\Modules\WhatsappMarketing\Actions\Messenger;
use App\Modules\WhatsappMarketing\Actions\VariableResolver;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Http\Presenter;
use App\Modules\WhatsappMarketing\Models\CapturedLead;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Support\Permissions;
use App\Modules\WhatsappMarketing\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The WhatsApp CRM bridge page: the lead-capture gate and keywords, and a manual sender for the transactional
 * reminder/notification templates (order confirmation, invoice/payment notification, appointment reminder). The
 * templates are the workspace's own approved templates; values map from the allow-listed fields, exactly like a
 * campaign. Every send goes through the gateway, which enforces consent, suppression and the 24-hour window.
 */
final class WhatsappCrmController
{
    /** The transactional reminder packs, mirrored by the automation actions of the same names. */
    public const array REMINDER_TYPES = ['order_confirmation', 'invoice_notification', 'appointment_reminder', 'payment_reminder', 'generic'];

    public function __construct(
        private readonly Presenter $presenter,
        private readonly LeadCaptureSettings $leadCapture,
        private readonly RecordAccess $records,
        private readonly VariableResolver $variables,
        private readonly ConversationManager $conversations,
        private readonly Messenger $messenger,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->presenter->actor($request);
        $setting = $this->leadCapture->current();

        return Inertia::render('Modules/WhatsappMarketing/Pages/Crm/Index', [
            'leadCapture' => [
                'enabled' => $setting->enabled,
                'keywords' => $setting->keywords ?? [],
            ],
            'leadTag' => CaptureLeadFromConversation::TAG,
            'templates' => Template::query()->where('status', TemplateStatus::Approved->value)->orderBy('name')->limit(200)->get()
                ->map(fn (Template $template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'language' => $template->language,
                    'body' => $template->body_text,
                    'variableCount' => $template->variable_count,
                ])->all(),
            'fields' => VariableResolver::FIELDS,
            'reminderTypes' => self::REMINDER_TYPES,
            'recentCaptures' => CapturedLead::query()->with('conversation:id,phone,profile_name')
                ->orderByDesc('created_at')->limit(10)->get()
                ->map(fn (CapturedLead $lead): array => [
                    'id' => $lead->id,
                    'conversationId' => $lead->conversation_id,
                    'phone' => $lead->conversation?->phone,
                    'leadId' => $lead->lead_id,
                    'contactId' => $lead->contact_id,
                    'capturedAt' => $lead->created_at->toIso8601String(),
                ])->all(),
            'can' => [
                'manageSettings' => $actor->can(Permissions::SETTINGS_MANAGE),
                'send' => $actor->can(Permissions::CONVERSATIONS_REPLY),
            ],
        ]);
    }

    public function updateLeadCapture(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'enabled' => ['required', 'boolean'],
            'keywords' => ['nullable', 'array', 'max:'.LeadCaptureSettings::MAX_KEYWORDS],
            'keywords.*' => ['string', 'max:'.LeadCaptureSettings::MAX_KEYWORD],
        ]);

        $this->leadCapture->update([
            'enabled' => (bool) $input['enabled'],
            'keywords' => $input['keywords'] ?? [],
        ]);
        Toast::success(__('Lead capture settings saved.'));

        return back();
    }

    /** Send one transactional reminder/notification template to a contact, with mapped values. */
    public function sendReminder(Request $request): RedirectResponse
    {
        $actor = $this->presenter->actor($request);
        $membership = $this->presenter->membership($actor);

        $input = $request->validate([
            'reminder_type' => ['required', Rule::in(self::REMINDER_TYPES)],
            'template_id' => ['required', 'string', 'size:26'],
            'contact_id' => ['required', 'string', 'size:26'],
            'variables' => ['nullable', 'array', 'max:20'],
            'variables.*.type' => ['required_with:variables', Rule::in(['field', 'text'])],
            'variables.*.field' => ['nullable', 'string', Rule::in(VariableResolver::FIELDS)],
            'variables.*.value' => ['nullable', 'string', 'max:200'],
        ]);

        $template = Template::query()->find($input['template_id']);

        if ($template === null || $template->status !== TemplateStatus::Approved) {
            throw ValidationException::withMessages(['template_id' => __('Only approved templates can be sent.')]);
        }

        // Only a contact the member may see.
        $contact = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
            ->with('company:id,name')
            ->whereKey($input['contact_id'])
            ->first();

        if ($contact === null) {
            throw ValidationException::withMessages(['contact_id' => __('Choose a contact you can see.')]);
        }

        $conversation = $this->conversations->forContact($contact);

        if ($conversation === null) {
            throw ValidationException::withMessages(['contact_id' => __('This contact has no valid WhatsApp number.')]);
        }

        $map = $this->variables->normalizeMap((array) ($input['variables'] ?? []), $template->variable_count);
        $workspace = $this->context->require()->name;
        $parameters = $this->variables->parameters(
            $map,
            $this->variables->valuesFor($contact, $contact->company, $workspace, $actor->name),
        );

        $outcome = $this->messenger->sendTemplate($conversation, $template, $parameters, $membership, SenderType::Agent);
        $this->toastOutcome($outcome->receipt->state->value, $outcome->receipt->reason);

        return back();
    }

    /** Contact search for the reminder composer, within the member's contact scope. */
    public function contacts(Request $request): JsonResponse
    {
        $actor = $this->presenter->actor($request);
        $search = trim((string) $request->query('q', ''));
        $results = [];

        if ($search !== '') {
            $like = Like::contains($search);
            $results = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
                ->whereNotNull('phone')
                ->where(fn ($match) => $match->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('phone', 'like', $like))
                ->orderBy('first_name')->limit(20)->get(['id', 'first_name', 'last_name', 'phone'])
                ->map(fn (Contact $contact): array => [...$this->presenter->contact($contact), 'validPhone' => Phone::e164($contact->phone) !== null])
                ->all();
        }

        return response()->json(['contacts' => $results]);
    }

    private function toastOutcome(string $state, ?string $reason): void
    {
        match ($state) {
            'sent', 'delivered', 'queued' => Toast::success(__('The message was queued.')),
            'blocked' => Toast::error(__('Not sent: the contact has not opted in or is suppressed.')),
            default => Toast::error(__('The message could not be sent (:reason).', ['reason' => $reason ?? $state])),
        };
    }
}
