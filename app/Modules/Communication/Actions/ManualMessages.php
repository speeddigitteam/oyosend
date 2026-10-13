<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Access\AccessChecker;
use App\Core\Access\RecordAccess;
use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Http\Controllers\ContactController;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\User;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\MessageTemplate;
use Illuminate\Validation\ValidationException;

/**
 * A person writing one message to one contact. Everything an automated send is checked for is checked here
 * first and reported as a form error: channel state, suppression, consent and the plan's limit.
 */
final class ManualMessages
{
    /**
     * Sending free text as a transactional message skips consent, marketing suppression, quiet hours and the
     * unsubscribe footer, so it is a separate, sensitive permission.
     */
    public const string SEND_TRANSACTIONAL = 'communication.messages.send_transactional';

    public function __construct(
        private readonly Outbox $outbox,
        private readonly TemplateManager $templates,
        private readonly ChannelConnections $connections,
        private readonly RecordAccess $records,
        private readonly AccessChecker $access,
        private readonly TemplateRenderer $renderer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The purpose is decided here, never taken on trust: a template carries its own (a transactional template is
     * curated by people who manage templates); free text is marketing unless the sender holds
     * {@see self::SEND_TRANSACTIONAL} and asked for transactional.
     *
     * @param  array<string, mixed>  $input  contact_id, channel, purpose?, template_id|subject+body, request_id
     */
    public function send(User $actor, array $input): Message
    {
        $channel = Channel::from((string) $input['channel']);
        $requested = MessagePurpose::tryFrom((string) ($input['purpose'] ?? ''));
        $purpose = MessagePurpose::Marketing;
        // 404 for a contact outside the actor's scope: it is not disclosed to exist.
        $contact = $this->records->constrain(Contact::query(), $actor, ContactController::VIEW)
            ->with('company:id,name')
            ->findOrFail((string) $input['contact_id']);

        $template = null;

        if (! empty($input['template_id'])) {
            $template = MessageTemplate::query()->where('is_active', true)->findOrFail((string) $input['template_id']);

            if ($template->channel !== $channel) {
                throw ValidationException::withMessages(['template_id' => __('This template is for another channel.')]);
            }

            $purpose = $template->purpose;
        } elseif ($requested === MessagePurpose::Transactional) {
            if (! $actor->can(self::SEND_TRANSACTIONAL)) {
                throw ValidationException::withMessages(['purpose' => __('You may not send free text as a transactional message. Use a transactional template, or send it as marketing.')]);
            }

            $purpose = MessagePurpose::Transactional;
        }

        $address = $channel->normalize((string) ($channel === Channel::Email ? $contact->email : $contact->phone));

        if ($address === null) {
            throw ValidationException::withMessages(['contact_id' => $channel === Channel::Email
                ? __('This contact has no valid email address.')
                : __('This contact has no valid phone number.')]);
        }

        if ($this->connections->state($channel) === ConfigurationState::NotConfigured) {
            throw ValidationException::withMessages(['channel' => __('This channel is not configured for your workspace.')]);
        }

        $reason = $this->outbox->blockedReason($channel, $purpose, $address, $contact->id);

        if ($reason === Outbox::BLOCKED_SUPPRESSED) {
            throw ValidationException::withMessages(['contact_id' => __('This address is on the suppression list.')]);
        }

        if ($reason === Outbox::BLOCKED_NO_CONSENT) {
            throw ValidationException::withMessages(['contact_id' => __('This contact has not opted in to marketing messages.')]);
        }

        $this->outbox->assertWithinLimit('channel');

        $values = $this->templates->values($contact, $contact->company);

        if ($template !== null) {
            $rendered = $this->templates->render($template, $values);
            $subject = $rendered['subject'];
            $body = $rendered['body'];
        } else {
            // Free text is also placeholder-substituted, never evaluated. Unknown tokens are refused.
            $subject = $channel->hasSubject() ? trim((string) ($input['subject'] ?? '')) : null;
            $body = (string) ($input['body'] ?? '');

            foreach (['subject' => (string) $subject, 'body' => $body] as $field => $text) {
                if ($this->renderer->unknownTokens($text) !== []) {
                    throw ValidationException::withMessages([$field => __('This text uses a placeholder that does not exist.')]);
                }
            }

            $subject = $subject === null ? null : $this->renderer->render($subject, $values);
            $body = $this->renderer->render($body, $values);
        }

        $membership = $this->access->membership($actor);
        $requestId = (string) ($input['request_id'] ?? '');
        // The key names the intent, not only the request: a reused request id with another contact, channel or
        // text is a different message and must never answer with the first one.
        $fingerprint = substr(hash('sha256', (string) json_encode([
            $contact->id,
            $channel->value,
            $purpose->value,
            $template?->id,
            $subject,
            $body,
        ])), 0, 24);
        $key = 'composer:'.($membership->id ?? 'none').':'.($requestId !== '' ? $requestId : bin2hex(random_bytes(12))).':'.$fingerprint;

        $message = $this->outbox->queue(new OutboundRequest(
            channel: $channel,
            purpose: $purpose,
            recipient: $address,
            subject: $subject,
            body: $body,
            idempotencyKey: $key,
            contactId: $contact->id,
            companyId: $contact->company_id,
            templateId: $template?->id,
            locale: $template?->locale,
            source: 'composer',
        ));

        $this->audit->record('communication.message.composed', $message, [
            'channel' => $channel->value,
            'purpose' => $purpose->value,
            'contact_id' => $contact->id,
        ]);

        return $message;
    }
}
