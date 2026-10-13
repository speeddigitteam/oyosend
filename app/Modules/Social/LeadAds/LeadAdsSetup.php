<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds;

use App\Core\Audit\AuditLogger;
use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;
use App\Modules\Social\LeadAds\Models\LeadForm;
use App\Modules\Social\LeadAds\Models\LeadPageClaim;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Facebook\LeadAdsGraph;
use App\Modules\Social\Platforms\Facebook\LeadAdsGraphException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Per-Page lead capture: check the permissions the token holds, claim the Page for this workspace, subscribe
 * the app to its `leadgen` webhook field, list and cache its forms, switch forms on and save their mapping.
 *
 * Page tokens are read only here and in the processor, always from the encrypted model attribute, and are
 * never put in a message, an audit entry or a log line.
 */
final class LeadAdsSetup
{
    public function __construct(
        private readonly LeadAdsGraph $graph,
        private readonly LeadAdsAvailability $availability,
        private readonly AccountManager $accounts,
        private readonly FieldMapper $mapper,
        private readonly AuditLogger $audit,
    ) {}

    /** The workspace's lead-capture row for an account, created off when absent. */
    public function pageFor(SocialAccount $account): LeadAdsPage
    {
        return LeadAdsPage::query()->firstOrCreate(
            ['account_id' => $account->id],
            ['page_id' => $account->external_id, 'state' => LeadAdsPage::OFF],
        );
    }

    /**
     * Check what the connection may do, claim the Page and subscribe to its lead webhook.
     *
     * @throws ValidationException
     */
    public function enable(SocialAccount $account): LeadAdsPage
    {
        $this->assertUsable($account);

        $problem = $this->availability->operatorProblem();

        if ($problem !== null) {
            throw ValidationException::withMessages(['page' => $this->operatorMessage($problem)]);
        }

        $page = $this->pageFor($account);
        $token = (string) $account->access_token;

        $this->checkPermissions($account, $page, $token);

        try {
            DB::transaction(function () use ($page): void {
                $claim = LeadPageClaim::query()->where('lead_page_id', $page->id)->first();

                if ($claim === null) {
                    LeadPageClaim::query()->create(['page_id' => $page->page_id, 'lead_page_id' => $page->id]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['page' => __('This Facebook Page is already connected to lead capture in another workspace.')]);
        }

        try {
            $this->graph->subscribe($account->external_id, $token);
        } catch (LeadAdsGraphException $exception) {
            LeadPageClaim::query()->where('lead_page_id', $page->id)->delete();

            $this->fail($account, $page, $exception);

            throw ValidationException::withMessages(['page' => $exception->getMessage()]);
        }

        $page->fill([
            'state' => LeadAdsPage::SUBSCRIBED,
            'subscribed_at' => now(),
            'last_error' => null,
            'last_error_code' => null,
        ])->save();

        $this->audit->record('social.leadads.enabled', $page, ['account_id' => $account->id]);

        // The form list is a convenience: failing to read it does not undo the subscription.
        $this->syncForms($account, $page);

        return $page->refresh();
    }

    /** Stop receiving leads for a Page. Forms and received leads are kept. */
    public function disable(SocialAccount $account): LeadAdsPage
    {
        $page = $this->pageFor($account);

        if ($account->access_token !== null && $account->status === AccountStatus::Connected) {
            try {
                $this->graph->unsubscribe($account->external_id, (string) $account->access_token);
            } catch (LeadAdsGraphException) {
                // Best effort: without a claim the receiver drops this Page's deliveries anyway.
            }
        }

        DB::transaction(function () use ($page): void {
            LeadPageClaim::query()->where('lead_page_id', $page->id)->delete();
            $page->fill(['state' => LeadAdsPage::OFF, 'subscribed_at' => null])->save();
        });

        $this->audit->record('social.leadads.disabled', $page, ['account_id' => $account->id]);

        return $page;
    }

    /**
     * Re-list the Page's forms (cached in `social_lead_forms`). Keeps each form's switch and mapping.
     *
     * @return int the number of forms the platform listed, or -1 when they could not be read
     */
    public function syncForms(SocialAccount $account, ?LeadAdsPage $page = null): int
    {
        $page ??= $this->pageFor($account);

        if ($account->status !== AccountStatus::Connected || $account->access_token === null) {
            $page->forceFill(['forms_sync_error' => __('The Page must be connected again before its forms can be listed.')])->save();

            return -1;
        }

        try {
            $forms = $this->graph->forms($account->external_id, (string) $account->access_token);
        } catch (LeadAdsGraphException $exception) {
            $this->fail($account, $page, $exception, forms: true);

            return -1;
        }

        DB::transaction(function () use ($account, $forms): void {
            foreach ($forms as $form) {
                $row = LeadForm::query()->where('account_id', $account->id)->where('external_id', $form['id'])->first()
                    ?? new LeadForm(['account_id' => $account->id, 'external_id' => $form['id'], 'is_enabled' => false]);

                $row->fill([
                    'name' => $form['name'],
                    'status' => $form['status'],
                    'locale' => $form['locale'],
                    'questions' => $form['questions'],
                    'fetched_at' => now(),
                ])->save();
            }
        });

        $page->forceFill(['forms_synced_at' => now(), 'forms_sync_error' => null])->save();

        return count($forms);
    }

    public function switchForm(LeadForm $form, bool $enabled): LeadForm
    {
        if ($form->is_enabled !== $enabled) {
            $form->forceFill(['is_enabled' => $enabled])->save();
            $this->audit->record($enabled ? 'social.leadads.form_enabled' : 'social.leadads.form_disabled', $form, ['account_id' => $form->account_id]);
        }

        return $form;
    }

    /**
     * Save the person's mapping, validated against the form's questions.
     *
     * @param  array<string, array{target?: mixed, answer_key?: mixed}>  $choices
     *
     * @throws ValidationException
     */
    public function saveMapping(LeadForm $form, array $choices): LeadForm
    {
        $questions = $form->questions ?? [];
        $known = array_column($questions, 'key');
        $clean = [];

        foreach ($choices as $key => $choice) {
            $key = (string) $key;

            if (! in_array($key, $known, true)) {
                throw ValidationException::withMessages(['mapping' => __('One of the questions is not on this form. Refresh the forms and try again.')]);
            }

            $target = is_string($choice['target'] ?? null) ? $choice['target'] : '';

            if (! in_array($target, FieldMapper::TARGETS, true)) {
                throw ValidationException::withMessages(['mapping' => __('Choose where each answer goes.')]);
            }

            $answerKey = is_string($choice['answer_key'] ?? null) ? trim($choice['answer_key']) : '';

            if ($answerKey !== '' && preg_match('/^[\p{L}\p{N}_ .\-]{1,64}$/u', $answerKey) !== 1) {
                throw ValidationException::withMessages(['mapping' => __('An answer key may hold letters, numbers, spaces, dots, dashes and underscores, up to 64 characters.')]);
            }

            $clean[$key] = ['target' => $target, 'answer_key' => $target === FieldMapper::ANSWER && $answerKey !== '' ? $answerKey : null];
        }

        $consent = count(array_filter($clean, static fn (array $choice): bool => $choice['target'] === FieldMapper::CONSENT));

        if ($consent > 1) {
            throw ValidationException::withMessages(['mapping' => __('Only one question can be the consent question.')]);
        }

        $form->forceFill(['mapping' => $clean])->save();
        $this->audit->record('social.leadads.mapping_saved', $form, ['account_id' => $form->account_id]);

        return $form;
    }

    /**
     * The mapping in force for a form and what it would do, for the page and the preview.
     *
     * @param  array<string, array{target?: string, answer_key?: string|null}>|null  $draft  unsaved choices to try
     * @return array{mapping: array<string, array{target: string, answer_key: string|null}>, preview: array<string, mixed>, warnings: list<string>}
     */
    public function describe(LeadForm $form, ?array $draft = null): array
    {
        $questions = $form->questions ?? [];
        $mapping = $this->mapper->resolve($questions, $draft ?? $form->mapping);
        $sample = $this->mapper->sample($questions, $mapping);

        return [
            'mapping' => $mapping,
            'preview' => $this->mapper->map($sample, $mapping)->toPreview(),
            'warnings' => $this->mapper->warnings($mapping),
        ];
    }

    private function assertUsable(SocialAccount $account): void
    {
        if ($account->platform !== 'facebook') {
            throw ValidationException::withMessages(['page' => __('Lead capture is available for Facebook Pages only.')]);
        }

        if ($account->status !== AccountStatus::Connected || $account->access_token === null) {
            throw ValidationException::withMessages(['page' => __('Connect the Page again before switching on lead capture.')]);
        }
    }

    /** @throws ValidationException */
    private function checkPermissions(SocialAccount $account, LeadAdsPage $page, string $token): void
    {
        try {
            $granted = $this->graph->grantedScopes($token);
        } catch (LeadAdsGraphException $exception) {
            $this->fail($account, $page, $exception);

            throw ValidationException::withMessages(['page' => $exception->getMessage()]);
        }

        // Not reported: let the subscription call be the judge rather than refusing on a guess.
        if ($granted === null) {
            return;
        }

        $missing = array_values(array_diff($this->availability->requiredScopes(), $granted));
        $page->forceFill([
            'granted_scopes' => $granted,
            'missing_scopes' => $missing,
            'permissions_checked_at' => now(),
        ])->save();

        if ($missing !== []) {
            $page->forceFill(['state' => LeadAdsPage::FAILED, 'last_error_code' => 'permission_missing', 'last_error' => Str::limit(__('The Page connection is missing permissions needed for lead capture.'), 480, '')])->save();

            throw ValidationException::withMessages(['page' => __('This Page connection is missing permissions for lead capture: :scopes. Connect the Page again and approve all of them.', ['scopes' => implode(', ', $missing)])]);
        }
    }

    private function fail(SocialAccount $account, LeadAdsPage $page, LeadAdsGraphException $exception, bool $forms = false): void
    {
        if ($exception->kind === LeadAdsGraphException::TOKEN) {
            $this->accounts->markNeedsReauthorization($account, $exception->getMessage());
        }

        if ($forms) {
            $page->forceFill(['forms_sync_error' => Str::limit($exception->getMessage(), 480, '')])->save();

            return;
        }

        $page->forceFill([
            'state' => $page->state === LeadAdsPage::SUBSCRIBED ? LeadAdsPage::SUBSCRIBED : LeadAdsPage::FAILED,
            'last_error_code' => $exception->kind,
            'last_error' => Str::limit($exception->getMessage(), 480, ''),
        ])->save();
    }

    private function operatorMessage(string $code): string
    {
        return match ($code) {
            LeadAdsAvailability::NOT_ENABLED => __('Lead ads are not switched on for this installation. The operator must enable them once the Meta app has the lead permissions approved.'),
            LeadAdsAvailability::NOT_CONFIGURED => __('Facebook app credentials are not configured on this installation.'),
            default => __('The Facebook webhook verification token is not configured on this installation.'),
        };
    }
}
