<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Enums\StatusSource;
use App\Modules\WhatsappMarketing\Enums\TemplateCategory;
use App\Modules\WhatsappMarketing\Enums\TemplateStatus;
use App\Modules\WhatsappMarketing\Models\Campaign;
use App\Modules\WhatsappMarketing\Models\Template;
use App\Modules\WhatsappMarketing\Support\Text;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Templates are created and approved on Meta's side. This app only mirrors them: the name, language, category,
 * text and the approval status as the provider reported it (a verified webhook event) or as an administrator
 * recorded it by hand. Only `approved` templates are ever sent.
 */
final class TemplateManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $input  name, language, category, header?, body, footer?, buttons?, status?, status_reason?
     */
    public function save(?Template $template, array $input, ?string $actorMembershipId = null): Template
    {
        $components = $this->components($input);
        $body = $components['body'];
        $positions = TemplateParameters::positions($body);

        if (! TemplateParameters::isSequential($positions) || count($positions) > TemplateParameters::MAX) {
            throw ValidationException::withMessages(['body' => __('Variables must be numbered {{1}}, {{2}} and so on, without gaps.')]);
        }

        $category = TemplateCategory::from((string) $input['category']);

        return DB::transaction(function () use ($template, $input, $components, $body, $positions, $category, $actorMembershipId): Template {
            $creating = $template === null;

            if ($creating) {
                $name = (string) $input['name'];
                $language = (string) $input['language'];

                if (Template::query()->where('name', $name)->where('language', $language)->exists()) {
                    throw ValidationException::withMessages(['name' => __('This template name already exists in this language.')]);
                }

                $template = new Template(['name' => $name, 'language' => $language, 'created_by' => $actorMembershipId]);
            }

            $template->fill([
                'category' => $category,
                'components' => $components['components'],
                'body_text' => $body,
                'variable_count' => count($positions),
            ]);

            if ($creating || isset($input['status'])) {
                $status = TemplateStatus::tryFrom((string) ($input['status'] ?? TemplateStatus::Pending->value)) ?? TemplateStatus::Pending;
                $this->applyStatus($template, $status, StatusSource::Admin, $input['status_reason'] ?? null);
            }

            $template->save();

            return $template;
        });
    }

    /** An administrator records the status Meta shows for the template. */
    public function recordStatus(Template $template, TemplateStatus $status, ?string $reason = null): Template
    {
        $this->applyStatus($template, $status, StatusSource::Admin, $reason);
        $template->save();

        return $template;
    }

    /**
     * A verified provider event named a template and its new status. Unknown templates are ignored (the event
     * carries no body to create one from).
     */
    public function applyProviderStatus(string $name, string $language, string $providerStatus, ?string $reason, ?string $providerTemplateId): bool
    {
        $status = match (strtolower($providerStatus)) {
            'approved', 'reinstated' => TemplateStatus::Approved,
            'rejected' => TemplateStatus::Rejected,
            'pending', 'in_appeal' => TemplateStatus::Pending,
            'paused', 'limit_exceeded' => TemplateStatus::Paused,
            'disabled', 'pending_deletion' => TemplateStatus::Disabled,
            default => null,
        };

        if ($status === null) {
            return false;
        }

        $template = Template::query()->where('name', $name)->where('language', $language)->first();

        if ($template === null) {
            return false;
        }

        DB::transaction(function () use ($template, $status, $reason, $providerTemplateId): void {
            $this->applyStatus($template, $status, StatusSource::Provider, $reason);

            if ($providerTemplateId !== null && $providerTemplateId !== '') {
                $template->provider_template_id = mb_substr($providerTemplateId, 0, 64);
            }

            $template->save();
        });

        return true;
    }

    /** @throws ValidationException When a campaign that is not finished still uses the template. */
    public function delete(Template $template): void
    {
        $inUse = Campaign::query()
            ->where('template_id', $template->id)
            ->whereIn('status', [CampaignStatus::Draft->value, CampaignStatus::Scheduled->value, CampaignStatus::Sending->value])
            ->exists();

        if ($inUse) {
            throw ValidationException::withMessages(['template' => __('This template is used by a campaign that is not finished.')]);
        }

        DB::transaction(function () use ($template): void {
            $this->audit->record('whatsapp_marketing.template.deleted', $template);
            $template->delete();
        });
    }

    private function applyStatus(Template $template, TemplateStatus $status, StatusSource $source, mixed $reason): void
    {
        $text = is_string($reason) ? Text::clean($reason, 300, false) : '';

        $template->fill([
            'status' => $status,
            'status_source' => $source,
            'status_reason' => $text === '' ? null : $text,
            'status_changed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{components: list<array<string, mixed>>, body: string}
     */
    private function components(array $input): array
    {
        $body = Text::clean((string) ($input['body'] ?? ''), 1024);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('The template body is required.')]);
        }

        $components = [];
        $header = Text::clean((string) ($input['header'] ?? ''), 60, false);

        if ($header !== '') {
            if (TemplateParameters::positions($header) !== []) {
                throw ValidationException::withMessages(['header' => __('Variables in the header are not supported.')]);
            }

            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $header];
        }

        $components[] = ['type' => 'BODY', 'text' => $body];
        $footer = Text::clean((string) ($input['footer'] ?? ''), 60, false);

        if ($footer !== '') {
            $components[] = ['type' => 'FOOTER', 'text' => $footer];
        }

        $buttons = [];

        foreach (array_slice((array) ($input['buttons'] ?? []), 0, 3) as $index => $button) {
            if (! is_array($button)) {
                continue;
            }

            $type = (string) ($button['type'] ?? '');
            $text = Text::clean((string) ($button['text'] ?? ''), 25, false);
            $value = Text::clean((string) ($button['value'] ?? ''), 2000, false);

            if ($text === '' || ! in_array($type, ['quick_reply', 'url', 'phone_number'], true)) {
                throw ValidationException::withMessages(["buttons.{$index}.text" => __('Each button needs a type and a label of up to 25 characters.')]);
            }

            if ($type === 'url' && (preg_match('#^https://[^\s{}]+$#i', $value) !== 1)) {
                throw ValidationException::withMessages(["buttons.{$index}.value" => __('A link button needs a complete https address without variables.')]);
            }

            if ($type === 'phone_number' && preg_match('/^\+[1-9]\d{6,14}$/', $value) !== 1) {
                throw ValidationException::withMessages(["buttons.{$index}.value" => __('A call button needs a number with its country code.')]);
            }

            $buttons[] = match ($type) {
                'url' => ['type' => 'URL', 'text' => $text, 'url' => $value],
                'phone_number' => ['type' => 'PHONE_NUMBER', 'text' => $text, 'phone_number' => $value],
                default => ['type' => 'QUICK_REPLY', 'text' => $text],
            };
        }

        if ($buttons !== []) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        return ['components' => $components, 'body' => $body];
    }
}
