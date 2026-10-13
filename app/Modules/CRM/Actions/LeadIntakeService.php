<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Communication\Support\PhoneNumber;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Crm\Contracts\LeadIntake;
use App\Core\Crm\Data\LeadCapture;
use App\Core\Crm\Data\LeadCaptureResult;
use App\Core\Crm\Data\LeadCaptureStatus;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;
use App\Modules\CRM\Automation\ActionReceiptStore;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use App\Modules\CRM\Models\Note;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * The CRM side of {@see LeadIntake} (ADR 0023): a system integration hands over one outside enquiry.
 *
 * It runs as the workspace, not as a person. It finds or creates the shared contact through
 * {@see ContactDirectory} (never a second contact for the same email or phone), reuses an open lead of that
 * contact and source or creates one, records the timeline, a plain-text note of the answers and an audit entry,
 * and remembers the outcome per (workspace, source, idempotency key) in the CRM action receipts, so a retry
 * returns the first result and fires no second event.
 */
final class LeadIntakeService implements LeadIntake
{
    private const string MODULE = 'crm';

    /** @var array<string, string> */
    private const array SOURCE_NAMES = [
        'whatsapp' => 'WhatsApp',
        'facebook_lead_ads' => 'Facebook Lead Ads',
        'web_form' => 'Web form',
    ];

    private const array OPEN = [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Qualified];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
        private readonly ActionReceiptStore $receipts,
        private readonly ContactDirectory $directory,
        private readonly LeadManager $leads,
        private readonly TimelineRecorder $timeline,
        private readonly AuditLogger $audit,
    ) {}

    public function capture(LeadCapture $capture): LeadCaptureResult
    {
        if (! $this->entitlements->allows($this->context->require(), self::MODULE)) {
            return LeadCaptureResult::unavailable();
        }

        $ran = false;

        $output = $this->receipts->run('crm.lead_intake.'.$capture->source, $capture->idempotencyKey, function () use ($capture, &$ran): array {
            $ran = true;

            return $this->process($capture);
        });

        return new LeadCaptureResult(
            status: $ran ? LeadCaptureStatus::Captured : LeadCaptureStatus::Replayed,
            contactId: is_string($output['contact_id'] ?? null) ? $output['contact_id'] : null,
            leadId: is_string($output['lead_id'] ?? null) ? $output['lead_id'] : null,
            contactCreated: ($output['contact_created'] ?? false) === true,
            leadCreated: ($output['lead_created'] ?? false) === true,
        );
    }

    /** @return array{contact_id: string, lead_id: string, contact_created: bool, lead_created: bool} */
    private function process(LeadCapture $capture): array
    {
        [$contact, $contactCreated] = $this->contactFor($capture);
        $source = $this->sourceFor($capture->source);

        $lead = Lead::query()
            ->where('contact_id', $contact->id)
            ->where('source_id', $source->id)
            ->whereIn('status', array_map(static fn (LeadStatus $status): string => $status->value, self::OPEN))
            ->oldest()
            ->first();
        $leadCreated = $lead === null;

        if ($lead === null) {
            $lead = $this->leads->createFromIntake([
                'contact_id' => $contact->id,
                'company_id' => $contact->company_id,
                'source_id' => $source->id,
                'title' => Str::limit($source->name.': '.$contact->fullName(), 200, ''),
            ], $capture->source);
        }

        $this->timeline->record(
            'crm.lead.captured',
            __('Enquiry captured from :source.', ['source' => $source->name]),
            ['contact_id' => $lead->contact_id, 'company_id' => $lead->company_id, 'lead_id' => $lead->id],
            null,
            array_filter([
                'source' => $capture->source,
                'campaign' => $capture->campaignRef,
                'ad' => $capture->adRef,
                'consent_evidence' => $capture->consentEvidence,
                'occurred_at' => $capture->occurredAt->toIso8601String(),
            ], static fn (bool|string|null $value): bool => $value !== null),
        );

        $body = $this->noteBody($capture);

        if ($body !== null) {
            Note::query()->create(['lead_id' => $lead->id, 'contact_id' => $lead->contact_id, 'body' => $body]);
        }

        $this->audit->record('crm.lead.captured', $lead, [
            'source' => $capture->source,
            'lead_created' => $leadCreated,
            'contact_created' => $contactCreated,
        ]);

        return [
            'contact_id' => $contact->id,
            'lead_id' => $lead->id,
            'contact_created' => $contactCreated,
            'lead_created' => $leadCreated,
        ];
    }

    /** @return array{0: Contact, 1: bool} */
    private function contactFor(LeadCapture $capture): array
    {
        $existing = $this->findContact($capture);

        if ($existing !== null) {
            $fill = [];

            if ($existing->email === null && $capture->email !== null) {
                $fill['email'] = $capture->email;
            }

            if ($existing->phone === null && $capture->phone !== null) {
                $fill['phone'] = $capture->phone;
            }

            if ($fill !== []) {
                $this->directory->updateContact($existing, $fill);
            }

            return [$existing, false];
        }

        [$first, $last] = $this->splitName($capture);

        $contact = $this->directory->createContact([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $capture->email,
            'phone' => $capture->phone,
            'company_id' => $capture->companyName === null ? null : $this->companyFor($capture->companyName)->id,
        ], null);

        return [$contact, true];
    }

    private function findContact(LeadCapture $capture): ?Contact
    {
        if ($capture->phone !== null) {
            $byPhone = Contact::query()->where('phone_e164', $capture->phone)->orderBy('created_at')->orderBy('id')->first();

            if ($byPhone !== null) {
                return $byPhone;
            }

            // Rows saved before the canonical column existed, or with a phone it could not normalize.
            $digits = PhoneNumber::digitVariants($capture->phone);
            $legacy = Contact::query()
                ->whereNull('phone_e164')
                ->whereNotNull('phone')
                ->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') IN (".implode(',', array_fill(0, count($digits), '?')).')', $digits)
                ->orderBy('created_at')->orderBy('id')
                ->first();

            if ($legacy !== null) {
                return $legacy;
            }
        }

        if ($capture->email !== null) {
            return Contact::query()->whereRaw('LOWER(email) = ?', [$capture->email])->orderBy('created_at')->orderBy('id')->first();
        }

        return null;
    }

    private function companyFor(string $name): Company
    {
        return Company::query()->where('name', $name)->orderBy('created_at')->orderBy('id')->first()
            ?? $this->directory->createCompany(['name' => $name], null);
    }

    private function sourceFor(string $key): LeadSource
    {
        $name = self::SOURCE_NAMES[$key] ?? Str::headline($key);
        $existing = LeadSource::query()->where('name', $name)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $source = LeadSource::query()->create([
                'name' => $name,
                'description' => __('Registered automatically by lead capture.'),
                'is_active' => true,
                'position' => (int) LeadSource::query()->max('position') + 1,
            ]);
            $this->audit->record('crm.lead_source.created', $source);

            return $source;
        } catch (UniqueConstraintViolationException) {
            return LeadSource::query()->where('name', $name)->firstOrFail();
        }
    }

    /** @return array{0: string, 1: string|null} */
    private function splitName(LeadCapture $capture): array
    {
        $name = $capture->name ?? Str::before((string) $capture->email, '@');
        $name = $name === '' ? (string) $capture->phone : $name;
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [$name];
        $first = mb_substr($parts[0] !== '' ? $parts[0] : (string) $capture->phone, 0, 100);
        $last = isset($parts[1]) && $parts[1] !== '' ? mb_substr($parts[1], 0, 100) : null;

        return [$first, $last];
    }

    private function noteBody(LeadCapture $capture): ?string
    {
        if ($capture->answers === [] && $capture->campaignRef === null && $capture->adRef === null) {
            return null;
        }

        $lines = [];

        if ($capture->campaignRef !== null) {
            $lines[] = __('Campaign').': '.$capture->campaignRef;
        }

        if ($capture->adRef !== null) {
            $lines[] = __('Ad').': '.$capture->adRef;
        }

        foreach ($capture->answers as $question => $answer) {
            $lines[] = $question.': '.$answer;
        }

        return implode("\n", $lines);
    }
}
