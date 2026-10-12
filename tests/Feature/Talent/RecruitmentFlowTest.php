<?php

declare(strict_types=1);

use App\Core\Billing\UsageLimits;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Talent\Actions\ApplicationManager;
use App\Modules\Talent\Actions\CandidateManager;
use App\Modules\Talent\Actions\InterviewManager;
use App\Modules\Talent\Actions\OfferManager;
use App\Modules\Talent\Actions\OpeningManager;
use App\Modules\Talent\Actions\RecruitmentCatalogue;
use App\Modules\Talent\Enums\ApplicationStatus;
use App\Modules\Talent\Enums\InterviewOutcome;
use App\Modules\Talent\Enums\OfferStatus;
use App\Modules\Talent\Enums\OpeningStatus;
use App\Modules\Talent\Events\ApplicationStageChanged;
use App\Modules\Talent\Events\CandidateHired;
use App\Modules\Talent\Events\InterviewScheduled;
use App\Modules\Talent\Models\Application;
use App\Modules\Talent\Models\ApplicationHistory;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\Note;
use App\Modules\Talent\Models\PipelineStage;
use App\Modules\Talent\Models\RejectionReason;
use App\Modules\Talent\Models\Scorecard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithHrm;
use Tests\Support\InteractsWithTalent;

uses(InteractsWithHrm::class, InteractsWithTalent::class);

beforeEach(function () {
    $this->tenant = $this->talentTenant('talent-rec');
    $this->hrmAt('2026-03-02 10:00:00');
});

it('starts an application at the first stage and keeps an immutable move history', function () {
    $application = $this->talentApplication($this->tenant);
    $screening = $this->pipelineStage($this->tenant, 'Screening');
    $interview = $this->pipelineStage($this->tenant, 'Interview');

    $this->inTenant($this->tenant, function () use ($application, $screening, $interview): void {
        expect($application->stage->name)->toBe('Applied')
            ->and($application->status)->toBe(ApplicationStatus::Open)
            ->and($application->applied_on->toDateString())->toBe('2026-03-02');

        $manager = app(ApplicationManager::class);
        $manager->move($application, PipelineStage::query()->findOrFail($screening), 'Good CV', null);
        $manager->move($application->refresh(), PipelineStage::query()->findOrFail($interview), null, null);

        $history = ApplicationHistory::query()->where('application_id', $application->id)->orderBy('created_at')->orderBy('id')->get();
        expect($history->pluck('event.value')->all())->toBe(['applied', 'moved', 'moved'])
            ->and($history[1]->note)->toBe('Good CV')
            ->and($history[2]->from_stage_id)->toBe($screening)
            ->and($history[2]->to_stage_id)->toBe($interview)
            ->and(fn () => $history[0]->update(['note' => 'edited']))->toThrow(LogicException::class)
            ->and(fn () => $history[0]->delete())->toThrow(LogicException::class);

        // Same stage twice, or a stage that finishes the application, are refused.
        expect(fn () => $manager->move($application->refresh(), PipelineStage::query()->findOrFail($interview), null, null))->toThrow(ValidationException::class);
        $hired = PipelineStage::query()->where('name', 'Hired')->firstOrFail();
        expect(fn () => $manager->move($application->refresh(), $hired, null, null))->toThrow(ValidationException::class);
    });
});

it('announces stage changes with scalars only', function () {
    Event::fake([ApplicationStageChanged::class]);
    $application = $this->talentApplication($this->tenant);
    $stage = $this->pipelineStage($this->tenant, 'Screening');

    $this->inTenant($this->tenant, fn () => app(ApplicationManager::class)->move($application, PipelineStage::query()->findOrFail($stage), null, null));

    Event::assertDispatched(ApplicationStageChanged::class, function (ApplicationStageChanged $event) use ($application, $stage): bool {
        $payload = $event->payload();

        return $payload['application_id'] === $application->id && $payload['to_stage_id'] === $stage
            && $payload['stage_name'] === 'Screening' && array_filter($payload, 'is_array') === [];
    });
});

it('rejects with a reason, withdraws the live offer, and reopens', function () {
    $application = $this->talentApplication($this->tenant);

    $this->inTenant($this->tenant, function () use ($application): void {
        $reason = app(RecruitmentCatalogue::class)->saveReason(null, ['name' => 'Not enough experience']);
        $offer = app(OfferManager::class)->create($application, ['amount' => 5000000, 'currency' => 'bdt'], null);
        $manager = app(ApplicationManager::class);

        $manager->reject($application, $reason, 'Junior', null);
        $application->refresh();

        expect($application->status)->toBe(ApplicationStatus::Rejected)
            ->and($application->stage->name)->toBe('Rejected')
            ->and($application->rejection_reason_id)->toBe($reason->id)
            ->and($offer->refresh()->status)->toBe(OfferStatus::Withdrawn)
            ->and($offer->live_marker)->toBeNull()
            ->and($offer->currency)->toBe('BDT');

        // A finished application cannot be moved or rejected again; reopening puts it back at the start.
        expect(fn () => $manager->reject($application, $reason, null, null))->toThrow(ValidationException::class);
        $manager->reopen($application, null, 'Second look', null);
        expect($application->refresh()->status)->toBe(ApplicationStatus::Open)
            ->and($application->stage->name)->toBe('Applied')
            ->and($application->rejection_reason_id)->toBeNull();

        $inactive = RejectionReason::query()->create(['name' => 'Old reason', 'is_active' => false]);
        expect(fn () => $manager->reject($application, $inactive, null, null))->toThrow(ValidationException::class);
    });
});

it('detects duplicate candidates by email regardless of case, and honours an explicit override', function () {
    $first = $this->talentCandidate($this->tenant, ['email' => 'Tania@Example.test']);

    expect($first->email)->toBe('tania@example.test');

    $this->inTenant($this->tenant, function () use ($first): void {
        $manager = app(CandidateManager::class);
        $owner = TenantMembership::query()->where('is_owner', true)->firstOrFail();

        expect($manager->findDuplicates(' TANIA@example.TEST ')->pluck('id')->all())->toBe([$first->id])
            ->and(fn () => $manager->create(['first_name' => 'Tania', 'email' => 'tania@example.test'], $owner))->toThrow(ValidationException::class)
            ->and($manager->create(['first_name' => 'Tania B', 'email' => 'tania@example.test'], $owner, allowDuplicate: true)->exists)->toBeTrue()
            ->and($manager->findDuplicates('tania@example.test', $first->id))->toHaveCount(1);
    });
});

it('sets consent and retention defaults on a candidate', function () {
    $candidate = $this->talentCandidate($this->tenant);

    expect($candidate->consent_given_at)->not->toBeNull()
        ->and($candidate->consent_basis->value)->toBe('candidate_provided')
        ->and($candidate->retain_until->toDateString())->toBe('2028-03-02')
        ->and($candidate->owner_id)->not->toBeNull();
});

it('hires through an event, fills the headcount and closes the opening', function () {
    Event::fake([CandidateHired::class]);
    $opening = $this->talentOpening($this->tenant, ['headcount' => 1, 'employment_type' => 'full_time']);
    $application = $this->talentApplication($this->tenant, $opening);

    $this->inTenant($this->tenant, function () use ($application, $opening): void {
        $manager = app(ApplicationManager::class);
        $offers = app(OfferManager::class);
        $offer = $offers->create($application, ['amount' => 8000000, 'proposed_start_on' => '2026-04-01'], null);

        // A live offer that is not accepted blocks the hire.
        expect(fn () => $manager->hire($application, [], null))->toThrow(ValidationException::class);
        $offers->transition($offer, OfferStatus::Sent, null);
        $offers->transition($offer->refresh(), OfferStatus::Accepted, null);

        $manager->hire($application, [], null);
        $application->refresh();
        $opening->refresh();

        expect($application->status)->toBe(ApplicationStatus::Hired)
            ->and($application->stage->name)->toBe('Hired')
            ->and($application->start_on->toDateString())->toBe('2026-04-01')
            ->and($opening->status)->toBe(OpeningStatus::Closed)
            ->and($opening->closed_at)->not->toBeNull();

        // Nobody else can be hired into a filled opening.
        expect(fn () => $manager->hire($application, [], null))->toThrow(ValidationException::class);
    });

    Event::assertDispatched(CandidateHired::class, function (CandidateHired $event) use ($application, $opening): bool {
        $payload = $event->payload();

        return $payload['application_id'] === $application->id
            && $payload['opening_id'] === $opening->id
            && $payload['first_name'] === 'Tania'
            && $payload['email'] === 'tania@example.test'
            && $payload['job_title'] === 'Backend Engineer'
            && $payload['offer_amount'] === 8000000
            && $payload['offer_currency'] === 'BDT'
            && array_filter($payload, 'is_array') === [];
    });
});

it('keeps an opening open until every position is filled', function () {
    $opening = $this->talentOpening($this->tenant, ['headcount' => 2]);
    $one = $this->talentApplication($this->tenant, $opening);
    $two = $this->talentApplication($this->tenant, $opening, $this->talentCandidate($this->tenant, ['email' => 'second@example.test', 'first_name' => 'Second']));

    $this->inTenant($this->tenant, function () use ($opening, $one, $two): void {
        $manager = app(ApplicationManager::class);
        $manager->hire($one, [], null);
        expect($opening->refresh()->status)->toBe(OpeningStatus::Open)
            ->and(app(OpeningManager::class)->hiredCount($opening))->toBe(1);

        $manager->hire($two, [], null);
        expect($opening->refresh()->status)->toBe(OpeningStatus::Closed);
    });
});

it('only accepts applications on an open opening and one application per candidate', function () {
    $draft = $this->talentOpening($this->tenant, [], 'draft');
    $candidate = $this->talentCandidate($this->tenant);

    $this->inTenant($this->tenant, function () use ($draft, $candidate): void {
        $manager = app(ApplicationManager::class);
        expect(fn () => $manager->apply($draft, $candidate, [], null))->toThrow(ValidationException::class);

        app(OpeningManager::class)->changeStatus($draft, OpeningStatus::Open);
        $manager->apply($draft->refresh(), $candidate, [], null);
        expect(fn () => $manager->apply($draft->refresh(), $candidate, [], null))->toThrow(ValidationException::class);
    });
});

it('walks the opening lifecycle and refuses invalid transitions', function () {
    $opening = $this->talentOpening($this->tenant, [], 'draft');

    $this->inTenant($this->tenant, function () use ($opening): void {
        $manager = app(OpeningManager::class);
        expect(fn () => $manager->changeStatus($opening, OpeningStatus::OnHold))->toThrow(ValidationException::class);

        $manager->changeStatus($opening, OpeningStatus::Open);
        expect($opening->refresh()->opened_at)->not->toBeNull();
        $manager->changeStatus($opening, OpeningStatus::OnHold);
        $manager->changeStatus($opening, OpeningStatus::Open);
        $manager->changeStatus($opening, OpeningStatus::Closed);
        $manager->changeStatus($opening, OpeningStatus::Open);
        expect($opening->refresh()->status)->toBe(OpeningStatus::Open)
            ->and($opening->closed_at)->toBeNull();
    });
});

it('enforces the plan limit on open job openings and on active candidates', function () {
    $plan = $this->createFreePlan(['key' => 'tiny-talent', 'modules' => ['hrm', 'talent'], 'limits' => ['talent.job_openings' => 1, 'talent.candidates' => 2]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);

    $first = $this->talentOpening($this->tenant);
    expect(fn () => $this->talentOpening($this->tenant, ['title' => 'Designer']))->toThrow(ValidationException::class);

    // A draft does not count; closing the open one frees the place.
    $draft = $this->talentOpening($this->tenant, ['title' => 'Designer'], 'draft');
    $this->inTenant($this->tenant, function () use ($first, $draft): void {
        expect(app(UsageLimits::class)->used('talent.job_openings'))->toBe(1);
        expect(fn () => app(OpeningManager::class)->changeStatus($draft, OpeningStatus::Open))->toThrow(ValidationException::class);
        app(OpeningManager::class)->changeStatus($first, OpeningStatus::Closed);
        app(OpeningManager::class)->changeStatus($draft, OpeningStatus::Open);
    });

    $this->talentCandidate($this->tenant, ['email' => 'a@example.test']);
    $this->talentCandidate($this->tenant, ['email' => 'b@example.test']);
    expect(fn () => $this->talentCandidate($this->tenant, ['email' => 'c@example.test']))->toThrow(ValidationException::class);
});

it('stores an interview in UTC from the workspace-local time and announces it', function () {
    Event::fake([InterviewScheduled::class]);
    $application = $this->talentApplication($this->tenant);
    $a = $this->hire($this->tenant, ['first_name' => 'Panel', 'last_name' => 'One']);
    $b = $this->hire($this->tenant, ['first_name' => 'Panel', 'last_name' => 'Two']);

    $interview = $this->inTenant($this->tenant, fn (): Interview => app(InterviewManager::class)->schedule($application, [
        'round_name' => 'Technical', 'mode' => 'video', 'date' => '2026-03-10', 'time' => '15:00', 'duration_minutes' => 60,
        'location' => 'https://meet.example.test/x', 'panel' => [$a->id, $b->id],
    ], null));

    // Asia/Dhaka is UTC+6 with no daylight saving.
    expect($interview->starts_at->utc()->toDateTimeString())->toBe('2026-03-10 09:00:00')
        ->and($interview->ends_at->utc()->toDateTimeString())->toBe('2026-03-10 10:00:00')
        ->and($interview->timezone)->toBe('Asia/Dhaka')
        ->and($this->inTenant($this->tenant, fn (): int => $interview->panel()->count()))->toBe(2);

    Event::assertDispatched(InterviewScheduled::class, fn (InterviewScheduled $event): bool => $event->payload()['starts_at'] === '2026-03-10T09:00:00+00:00'
        && $event->payload()['timezone'] === 'Asia/Dhaka'
        && $event->payload()['panel_employee_ids'] === implode(',', [$a->id, $b->id]));
});

it('refuses past interviews, empty panels, foreign interviewers and double-booked panelists', function () {
    $application = $this->talentApplication($this->tenant);
    $other = $this->talentApplication($this->tenant, $this->talentOpening($this->tenant, ['title' => 'Second']), $this->talentCandidate($this->tenant, ['email' => 'x@example.test']));
    $panelist = $this->hire($this->tenant, ['first_name' => 'Busy']);
    $outsider = $this->talentTenant('talent-rec-other');
    $foreign = $this->hire($outsider, ['first_name' => 'Foreign']);
    $base = ['round_name' => 'Screen', 'mode' => 'phone', 'date' => '2026-03-10', 'time' => '10:00', 'duration_minutes' => 60];

    $this->inTenant($this->tenant, function () use ($application, $other, $panelist, $foreign, $base): void {
        $manager = app(InterviewManager::class);
        $manager->schedule($application, [...$base, 'panel' => [$panelist->id]], null);

        expect(fn () => $manager->schedule($other, [...$base, 'date' => '2026-03-01', 'panel' => [$panelist->id]], null))->toThrow(ValidationException::class)
            ->and(fn () => $manager->schedule($other, [...$base, 'panel' => []], null))->toThrow(ValidationException::class)
            ->and(fn () => $manager->schedule($other, [...$base, 'panel' => [$foreign->id]], null))->toThrow(ValidationException::class)
            // Overlaps 10:00-11:00 for the same interviewer.
            ->and(fn () => $manager->schedule($other, [...$base, 'time' => '10:30', 'panel' => [$panelist->id]], null))->toThrow(ValidationException::class);

        // Back to back is fine.
        $manager->schedule($other, [...$base, 'time' => '11:00', 'panel' => [$panelist->id]], null);
        expect(Interview::query()->count())->toBe(2);
    });
});

it('takes scorecards from panelists only, after the interview started, and completes with an outcome', function () {
    $application = $this->talentApplication($this->tenant);
    $panelist = $this->hire($this->tenant, ['first_name' => 'Judge']);
    $stranger = $this->hire($this->tenant, ['first_name' => 'Stranger']);

    $this->inTenant($this->tenant, function () use ($application, $panelist, $stranger): void {
        $manager = app(InterviewManager::class);
        $interview = $manager->schedule($application, [
            'round_name' => 'Final', 'mode' => 'in_person', 'date' => '2026-03-02', 'time' => '16:00', 'duration_minutes' => 45, 'panel' => [$panelist->id],
        ], null);
        $card = ['rating' => 4, 'recommendation' => 'yes', 'strengths' => 'Solid', 'concerns' => null];

        // 16:00 Dhaka is 10:00 UTC: the clock is at 10:00 UTC, so it has started; move it earlier to test the guard.
        $this->travelTo(CarbonImmutable::parse('2026-03-02 09:00:00', 'UTC'));
        expect(fn () => $manager->submitScorecard($interview, $panelist->id, $card))->toThrow(ValidationException::class)
            ->and(fn () => $manager->complete($interview, InterviewOutcome::Advance, null))->toThrow(ValidationException::class);

        $this->travelTo(CarbonImmutable::parse('2026-03-02 10:30:00', 'UTC'));
        expect(fn () => $manager->submitScorecard($interview, $stranger->id, $card))->toThrow(ValidationException::class);

        $manager->submitScorecard($interview, $panelist->id, $card);
        $manager->submitScorecard($interview, $panelist->id, [...$card, 'rating' => 5]);
        expect($interview->scorecards()->count())->toBe(1)
            ->and($interview->scorecards()->first()->rating)->toBe(5);

        $manager->complete($interview, InterviewOutcome::Advance, 'Strong');
        expect($interview->refresh()->outcome)->toBe(InterviewOutcome::Advance)
            ->and(fn () => $manager->submitScorecard($interview, $panelist->id, $card))->toThrow(ValidationException::class)
            ->and(fn () => $manager->cancel($interview, null))->toThrow(ValidationException::class);
    });
});

it('prevents a pipeline from having two hired stages or a type change on a used stage', function () {
    $this->talentApplication($this->tenant);

    $this->inTenant($this->tenant, function (): void {
        $catalogue = app(RecruitmentCatalogue::class);
        $pipeline = $catalogue->defaultPipeline();
        $applied = $pipeline->stages()->where('name', 'Applied')->firstOrFail();

        expect(fn () => $catalogue->saveStage($pipeline, null, ['name' => 'Second hired', 'type' => 'hired']))->toThrow(ValidationException::class)
            ->and(fn () => $catalogue->saveStage($pipeline, $applied, ['name' => 'Applied', 'type' => 'rejected']))->toThrow(ValidationException::class);

        $stage = $catalogue->saveStage($pipeline, null, ['name' => 'Assessment', 'type' => 'active', 'position' => 25]);
        expect($stage->position)->toBe(25);
    });
});

it('counts anonymized candidates out of the active total and erases their data', function () {
    $candidate = $this->talentCandidate($this->tenant);
    $application = $this->talentApplication($this->tenant, null, $candidate);

    $this->inTenant($this->tenant, function () use ($candidate, $application): void {
        app(CandidateManager::class)->addNote($candidate, $application, 'Call back on Monday', null);
        app(CandidateManager::class)->anonymize($candidate, 'request');
        $candidate->refresh();

        expect($candidate->isAnonymized())->toBeTrue()
            ->and($candidate->email)->toBeNull()
            ->and($candidate->first_name)->toBe('Anonymized')
            ->and($application->refresh()->status)->toBe(ApplicationStatus::Withdrawn)
            ->and(Candidate::query()->whereNull('anonymized_at')->count())->toBe(0)
            ->and(Note::query()->count())->toBe(0);
    });
});

it('refuses to hire after the latest offer was declined until a new offer is accepted', function () {
    $opening = $this->talentOpening($this->tenant, ['headcount' => 1, 'employment_type' => 'full_time']);
    $application = $this->talentApplication($this->tenant, $opening);

    $this->inTenant($this->tenant, function () use ($application): void {
        $manager = app(ApplicationManager::class);
        $offers = app(OfferManager::class);
        $first = $offers->create($application, ['amount' => 8000000], null);
        $offers->transition($first, OfferStatus::Sent, null);
        $offers->transition($first->refresh(), OfferStatus::Declined, null);

        expect($first->refresh()->live_marker)->toBeNull()
            ->and(fn () => $manager->hire($application, [], null))->toThrow(ValidationException::class)
            ->and($application->refresh()->status)->toBe(ApplicationStatus::Open);

        $second = $offers->create($application, ['amount' => 9000000], null);
        $offers->transition($second, OfferStatus::Sent, null);
        $offers->transition($second->refresh(), OfferStatus::Accepted, null);

        $manager->hire($application, [], null);

        expect($application->refresh()->status)->toBe(ApplicationStatus::Hired);
    });
});

it('erases free text written about a candidate on scorecards, rejections and offers', function () {
    $candidate = $this->talentCandidate($this->tenant);
    $application = $this->talentApplication($this->tenant, null, $candidate);
    $panelist = $this->hire($this->tenant, ['first_name' => 'Judge']);

    $this->inTenant($this->tenant, function () use ($candidate, $application, $panelist): void {
        $interviews = app(InterviewManager::class);
        $interview = $interviews->schedule($application, [
            'round_name' => 'Final', 'mode' => 'in_person', 'date' => '2026-03-02', 'time' => '16:00', 'duration_minutes' => 45, 'panel' => [$panelist->id],
        ], null);
        $this->travelTo(CarbonImmutable::parse('2026-03-02 10:30:00', 'UTC'));
        $interviews->submitScorecard($interview, $panelist->id, ['rating' => 4, 'recommendation' => 'yes', 'strengths' => 'Warm and clear', 'concerns' => 'Health concern mentioned']);
        $interviews->complete($interview, InterviewOutcome::Advance, 'Spoke about family');

        $offer = app(OfferManager::class)->create($application, ['amount' => 5000000, 'notes' => 'Needs visa help'], null);
        Application::query()->whereKey($application->id)->update(['rejection_note' => 'Personal reason']);

        app(CandidateManager::class)->anonymize($candidate, 'request');

        $card = Scorecard::query()->where('interview_id', $interview->id)->sole();

        expect($card->strengths)->toBeNull()->and($card->concerns)->toBeNull()->and($card->rating)->toBe(4)
            ->and($offer->refresh()->notes)->toBeNull()->and($offer->amount)->toBe(5000000)
            ->and($application->refresh()->rejection_note)->toBeNull()
            ->and($interview->refresh()->notes)->toBeNull();
    });
});
