<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\TenantContext;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Models\MessageTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Message templates (per channel and locale) and the safe rendering of them for a contact.
 */
final class TemplateManager
{
    /** Locales a template can be written in. */
    public const array LOCALES = ['en', 'bn'];

    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly TemplateVariables $variables,
        private readonly CommunicationSettings $settings,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $input  slug, name, channel, locale, purpose, subject, body, is_active
     */
    public function save(?MessageTemplate $template, array $input): MessageTemplate
    {
        $channel = Channel::from((string) $input['channel']);
        $purpose = MessagePurpose::from((string) $input['purpose']);
        $subject = $channel->hasSubject() ? trim((string) ($input['subject'] ?? '')) : null;
        $body = (string) $input['body'];
        $errors = [];

        if ($channel->hasSubject() && $subject === '') {
            $errors['subject'] = __('A subject is required.');
        }

        $limit = $channel->hasSubject() ? 5000 : 1000;

        if (mb_strlen($body) > $limit) {
            $errors['body'] = __('The message is too long.');
        }

        foreach (['subject' => (string) $subject, 'body' => $body] as $field => $text) {
            $unknown = $this->renderer->unknownTokens($text);

            if ($unknown !== []) {
                $errors[$field] = __('Unknown placeholder: :names.', ['names' => implode(', ', $unknown)]);
            }
        }

        $duplicate = MessageTemplate::query()
            ->where('slug', $input['slug'])->where('channel', $channel->value)->where('locale', $input['locale'])
            ->when($template !== null, fn ($query) => $query->whereKeyNot($template?->id))
            ->exists();

        if ($duplicate) {
            $errors['slug'] = __('A template with this name, channel and language already exists.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($template, $input, $channel, $purpose, $subject, $body): MessageTemplate {
            $template ??= new MessageTemplate;
            $template->fill([
                'slug' => $input['slug'],
                'name' => $input['name'],
                'channel' => $channel,
                'locale' => $input['locale'],
                'purpose' => $purpose,
                'subject' => $subject,
                'body' => $body,
                'is_active' => (bool) ($input['is_active'] ?? true),
            ])->save();

            return $template;
        });
    }

    /**
     * The template to use: the requested locale, then the workspace's, then English. Active only.
     */
    public function resolve(string $slug, Channel $channel, ?string $locale = null): ?MessageTemplate
    {
        $candidates = array_values(array_unique(array_filter([
            $locale,
            $this->context->require()->locale,
            'en',
        ])));

        foreach ($candidates as $candidate) {
            $found = MessageTemplate::query()
                ->where('slug', $slug)->where('channel', $channel->value)->where('locale', $candidate)
                ->where('is_active', true)
                ->first();

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The values a template may use, for a contact and/or company.
     *
     * @param  array<string, scalar|null>  $extra  Values for variables other modules registered.
     * @return array<string, scalar|null>
     */
    public function values(?Contact $contact, ?Company $company, array $extra = []): array
    {
        $company ??= $contact?->company;
        $settings = $this->settings->current();

        $values = [
            'contact.first_name' => $contact?->first_name,
            'contact.last_name' => $contact?->last_name,
            'contact.full_name' => $contact?->fullName(),
            'contact.email' => $contact?->email,
            'contact.phone' => $contact?->phone,
            'company.name' => $company?->name,
            'workspace.name' => $this->context->require()->name,
            'sender.name' => $settings->from_name ?? $this->context->require()->name,
        ];

        foreach ($extra as $key => $value) {
            if ($this->variables->has($key)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, scalar|null>  $values
     * @return array{subject: string|null, body: string}
     */
    public function render(MessageTemplate $template, array $values): array
    {
        return [
            'subject' => $template->subject === null ? null : $this->renderer->render($template->subject, $values),
            'body' => $this->renderer->render($template->body, $values),
        ];
    }
}
