<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Core\Tenancy\TenantContext;
use App\Modules\Talent\Enums\ApplicationEvent;
use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Enums\ConsentBasis;
use App\Modules\Talent\Events\CandidateAnonymized;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\ApplicationHistory;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateDocument;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\Note;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Scorecard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Candidate records: duplicate detection by email, consent and retention fields, private
 * documents and anonymization. Candidates are their own table and never a `User` or an employee.
 */
final class CandidateManager
{
    public const string LIMIT = 'talent.candidates';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UsageLimits $limits,
        private readonly TenantContext $context,
        private readonly TalentSettings $settings,
        private readonly TenantStorage $storage,
    ) {}

    public static function normalizeEmail(?string $email): ?string
    {
        $email = $email === null ? null : strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    /**
     * Candidates with the same email (case-insensitive), anonymized ones excluded.
     *
     * @return Collection<int, Candidate>
     */
    public function findDuplicates(?string $email, ?string $exceptId = null): Collection
    {
        $email = self::normalizeEmail($email);

        if ($email === null) {
            return new Collection;
        }

        return Candidate::query()
            ->where('email', $email)
            ->whereNull('anonymized_at')
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->orderBy('created_at')
            ->get();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, TenantMembership $actor, bool $allowDuplicate = false): Candidate
    {
        $values = $this->values($attributes);
        $tenantId = $this->context->requireId();

        return DB::transaction(function () use ($values, $actor, $allowDuplicate, $tenantId): Candidate {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $this->limits->ensure(self::LIMIT, field: 'first_name');

            if (! $allowDuplicate && $this->findDuplicates($values['email'] ?? null)->isNotEmpty()) {
                throw ValidationException::withMessages(['email' => __('A candidate with this email already exists.')]);
            }

            $candidate = new Candidate($values);
            $candidate->owner_id = $actor->id;
            $candidate->department_id = $actor->department_id;
            $candidate->branch_id = $actor->branch_id;
            $candidate->consent_given_at ??= now();
            $candidate->retain_until ??= $this->settings->today()
                ->addMonthsNoOverflow($this->settings->current()->candidate_retention_months);
            $candidate->save();

            return $candidate;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Candidate $candidate, array $attributes, bool $allowDuplicate = false): Candidate
    {
        if ($candidate->isAnonymized()) {
            throw ValidationException::withMessages(['first_name' => __('An anonymized candidate cannot be edited.')]);
        }

        $values = $this->values($attributes);

        return DB::transaction(function () use ($candidate, $values, $allowDuplicate): Candidate {
            /** @var Candidate $locked */
            $locked = Candidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if (! $allowDuplicate && ($values['email'] ?? null) !== $locked->email
                && $this->findDuplicates($values['email'] ?? null, $locked->id)->isNotEmpty()) {
                throw ValidationException::withMessages(['email' => __('A candidate with this email already exists.')]);
            }

            $locked->fill($values)->save();

            return $locked;
        });
    }

    /**
     * Erase the personal data of a candidate. Applications stay (as statistics) and point at an
     * anonymized record; documents, notes and the free-text profile are deleted.
     */
    public function anonymize(Candidate $candidate, string $reason = 'request', ?TenantMembership $actor = null): Candidate
    {
        if ($candidate->isAnonymized()) {
            return $candidate;
        }

        $paths = [];

        $result = DB::transaction(function () use ($candidate, $reason, $actor, &$paths): Candidate {
            /** @var Candidate $locked */
            $locked = Candidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if ($locked->isAnonymized()) {
                return $locked;
            }

            $open = Application::query()->where('candidate_id', $locked->id)->where('status', ApplicationStatus::Open)->get();

            foreach ($open as $application) {
                $application->status = ApplicationStatus::Withdrawn;
                $application->status_changed_at = now();
                $application->save();
                ApplicationHistory::query()->create([
                    'application_id' => $application->id, 'event' => ApplicationEvent::Withdrawn,
                    'from_stage_id' => $application->stage_id, 'note' => 'Candidate data erased', 'actor_id' => $actor?->id,
                ]);
            }

            $paths = CandidateDocument::query()->where('candidate_id', $locked->id)->pluck('path')->all();
            CandidateDocument::query()->where('candidate_id', $locked->id)->delete();
            Note::query()->where('candidate_id', $locked->id)->delete();

            // Free text written about the candidate elsewhere in the pipeline goes too (amounts and dates stay as statistics).
            $applicationIds = Application::query()->where('candidate_id', $locked->id)->pluck('id')->all();

            if ($applicationIds !== []) {
                Application::query()->whereIn('id', $applicationIds)->update(['rejection_note' => null]);
                Offer::query()->whereIn('application_id', $applicationIds)->update(['notes' => null]);
                Scorecard::query()->whereIn('application_id', $applicationIds)->update(['strengths' => null, 'concerns' => null]);
                Interview::query()->whereIn('application_id', $applicationIds)->update(['notes' => null]);
            }

            $locked->forceFill([
                'first_name' => 'Anonymized', 'last_name' => null, 'email' => null, 'phone' => null, 'headline' => null,
                'current_company' => null, 'location' => null, 'source' => null, 'expected_amount' => null, 'currency' => null,
                'summary' => null, 'anonymized_at' => now(), 'retain_until' => null,
            ])->save();
            $this->audit->record('talent.candidate.anonymized', $locked, ['reason' => $reason], $actor?->user);
            CandidateAnonymized::dispatch(['candidate_id' => $locked->id, 'reason' => $reason]);

            return $locked;
        });

        foreach ($paths as $path) {
            $this->storage->delete((string) $path);
        }

        return $result;
    }

    public function addNote(Candidate $candidate, ?Application $application, string $body, ?TenantMembership $actor): Note
    {
        if ($application !== null && $application->candidate_id !== $candidate->id) {
            abort(404);
        }

        return Note::query()->create([
            'candidate_id' => $candidate->id,
            'application_id' => $application?->id,
            'author_id' => $actor?->id,
            'body' => $body,
        ]);
    }

    /** @param array{type: string, title: string, application_id?: string|null} $attributes */
    public function addDocument(Candidate $candidate, UploadedFile $file, array $attributes, ?TenantMembership $actor): CandidateDocument
    {
        if ($candidate->isAnonymized()) {
            throw ValidationException::withMessages(['file' => __('An anonymized candidate cannot receive documents.')]);
        }

        $applicationId = $attributes['application_id'] ?? null;

        if ($applicationId !== null && $applicationId !== ''
            && Application::query()->whereKey($applicationId)->where('candidate_id', $candidate->id)->doesntExist()) {
            throw ValidationException::withMessages(['application_id' => __('Choose an application of this candidate.')]);
        }

        $path = $this->storage->putFile("talent/candidates/{$candidate->id}/documents", $file);

        try {
            return DB::transaction(function () use ($candidate, $file, $attributes, $actor, $path, $applicationId): CandidateDocument {
                $document = CandidateDocument::query()->create([
                    'candidate_id' => $candidate->id,
                    'application_id' => $applicationId === '' ? null : $applicationId,
                    'type' => $attributes['type'],
                    'title' => $attributes['title'],
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size' => (int) $file->getSize(),
                    'uploaded_by' => $actor?->id,
                ]);
                $this->audit->record('talent.candidate_document.uploaded', $candidate, ['title' => $document->title, 'type' => $document->type->value]);

                return $document;
            });
        } catch (Throwable $exception) {
            $this->storage->delete($path);

            throw $exception;
        }
    }

    public function deleteDocument(CandidateDocument $document): void
    {
        $path = $document->path;
        $candidate = Candidate::query()->findOrFail($document->candidate_id);

        DB::transaction(function () use ($document, $candidate): void {
            $this->audit->record('talent.candidate_document.removed', $candidate, ['title' => $document->title]);
            $document->delete();
        });

        $this->storage->delete($path);
    }

    public function documentContents(CandidateDocument $document): ?string
    {
        return $this->storage->get($document->path);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function values(array $attributes): array
    {
        $email = self::normalizeEmail(isset($attributes['email']) ? (string) $attributes['email'] : null);
        $amount = $attributes['expected_amount'] ?? null;
        $currency = $amount === null || $amount === '' ? null : strtoupper((string) ($attributes['currency'] ?? $this->settings->currency()));

        $values = [
            'first_name' => (string) $attributes['first_name'],
            'last_name' => $attributes['last_name'] ?? null,
            'email' => $email,
            'phone' => $attributes['phone'] ?? null,
            'headline' => $attributes['headline'] ?? null,
            'current_company' => $attributes['current_company'] ?? null,
            'location' => $attributes['location'] ?? null,
            'source' => $attributes['source'] ?? null,
            'expected_amount' => $amount === null || $amount === '' ? null : (int) $amount,
            'currency' => $currency,
            'summary' => $attributes['summary'] ?? null,
            'consent_basis' => ConsentBasis::tryFrom((string) ($attributes['consent_basis'] ?? ''))
                ?? ConsentBasis::CandidateProvided,
        ];

        if (array_key_exists('retain_until', $attributes) && $attributes['retain_until'] !== null && $attributes['retain_until'] !== '') {
            $values['retain_until'] = (string) $attributes['retain_until'];
        }

        return $values;
    }
}
