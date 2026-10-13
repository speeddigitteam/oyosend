<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Support\Enums\SurveyStatus;
use App\Modules\Support\Enums\TicketEventKind;
use App\Modules\Support\Events\CsatSubmitted;
use App\Modules\Support\Mail\CsatInvitationMail;
use App\Modules\Support\Models\CsatSurvey;
use App\Modules\Support\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Customer satisfaction surveys behind a tokenized, single-use link.
 *
 * The token is 256 random bits shown once (to the agent who resolved the ticket and in the invitation
 * email); only its SHA-256 is stored. Answering clears the hash with a conditional update, so two
 * simultaneous submissions cannot both count. A new request supersedes an older pending one.
 */
final class SurveyManager
{
    /** @var array<string, string> Plain tokens issued during this request, keyed by ticket id. */
    private array $issued = [];

    /** @var array<string, true> Ticket ids whose invitation was queued by email during this request. */
    private array $emailed = [];

    /** An agent may ask for a new survey of one ticket once per this many minutes. */
    public const int MANUAL_COOLDOWN_MINUTES = 10;

    public function __construct(
        private readonly SupportSettings $settings,
        private readonly TicketHistory $history,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * An agent's explicit request (works even with CSAT switched off): at most one new invitation per ticket
     * per cooldown, and always audited as a forced request. Must run inside the caller's transaction, with
     * the ticket locked.
     */
    public function requestManually(Ticket $ticket, ?TenantMembership $actor): ?string
    {
        $recent = CsatSurvey::query()
            ->where('ticket_id', $ticket->id)
            ->where('created_at', '>', CarbonImmutable::now()->subMinutes(self::MANUAL_COOLDOWN_MINUTES))
            ->exists();

        if ($recent) {
            throw ValidationException::withMessages(['ticket' => __('A survey was requested for this ticket a moment ago. Try again in a few minutes.')]);
        }

        $this->audit->record('support.csat.forced', $ticket, ['number' => $ticket->number, 'csat_enabled' => $this->settings->current()->csat_enabled]);

        return $this->request($ticket, $actor, force: true);
    }

    /**
     * Requests a survey for a resolved or closed ticket (when CSAT is enabled, or when `$force`). Returns the
     * plain token, or null when no survey was requested. Must run inside the caller's transaction.
     */
    public function request(Ticket $ticket, ?TenantMembership $actor, bool $force = false): ?string
    {
        $settings = $this->settings->current();

        if (! $force && ! $settings->csat_enabled) {
            return null;
        }

        CsatSurvey::query()
            ->where('ticket_id', $ticket->id)
            ->where('status', SurveyStatus::Pending)
            ->update(['status' => SurveyStatus::Expired, 'token_hash' => null]);

        $token = bin2hex(random_bytes(32));
        $survey = CsatSurvey::query()->create([
            'ticket_id' => $ticket->id,
            'token_hash' => self::hash($token),
            'status' => SurveyStatus::Pending,
            'requested_by' => $actor?->id,
            'expires_at' => CarbonImmutable::now()->addDays(max(1, $settings->csat_expiry_days)),
        ]);

        $this->history->record($ticket, TicketEventKind::SurveyRequested, $actor, ['survey_id' => $survey->id]);
        $this->audit->record('support.csat.requested', $ticket, ['number' => $ticket->number, 'survey_id' => $survey->id]);
        $this->issued[$ticket->id] = $token;
        unset($this->emailed[$ticket->id]);

        $email = $ticket->requester_email
            ?? ($ticket->contact_id === null ? null : Contact::query()->whereKey($ticket->contact_id)->value('email'));

        if ($settings->csat_send_email && $email !== null) {
            Mail::to($email)
                ->locale($this->context->require()->locale)
                ->queue(new CsatInvitationMail($ticket->id, $ticket->number, $token));
            $this->emailed[$ticket->id] = true;
        }

        return $token;
    }

    /** The plain token issued for the ticket during this request, once; the controller shows it to the agent. */
    public function takeIssuedToken(string $ticketId): ?string
    {
        $token = $this->issued[$ticketId] ?? null;
        unset($this->issued[$ticketId]);

        return $token;
    }

    /** Whether the invitation issued for the ticket during this request went out by email. */
    public function wasEmailed(string $ticketId): bool
    {
        return isset($this->emailed[$ticketId]);
    }

    /** The pending, unexpired survey of a token, or null. */
    public function find(string $token): ?CsatSurvey
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return CsatSurvey::query()
            ->with('ticket:id,number,subject')
            ->where('token_hash', self::hash($token))
            ->where('status', SurveyStatus::Pending)
            ->where('expires_at', '>', CarbonImmutable::now())
            ->first();
    }

    /**
     * Records the answer and burns the link. The conditional update is the single-use guarantee.
     */
    public function answer(string $token, int $rating, ?string $comment): CsatSurvey
    {
        if ($rating < 1 || $rating > 5) {
            throw ValidationException::withMessages(['rating' => __('Choose a rating from 1 to 5.')]);
        }

        $comment = $comment === null || trim($comment) === '' ? null : mb_substr(trim($comment), 0, 2000);

        return DB::transaction(function () use ($token, $rating, $comment): CsatSurvey {
            $now = CarbonImmutable::now();
            $survey = CsatSurvey::query()
                ->where('token_hash', self::hash($token))
                ->where('status', SurveyStatus::Pending)
                ->where('expires_at', '>', $now)
                ->lockForUpdate()
                ->first();

            if ($survey === null) {
                abort(404);
            }

            // The conditional update is the single-use guarantee even if the row lock were ever skipped.
            $affected = CsatSurvey::query()
                ->whereKey($survey->id)
                ->where('status', SurveyStatus::Pending)
                ->update([
                    'status' => SurveyStatus::Answered,
                    'rating' => $rating,
                    'comment' => $comment,
                    'answered_at' => $now,
                    'token_hash' => null,
                    'updated_at' => $now,
                ]);

            if ($affected !== 1) {
                abort(404);
            }

            $survey->refresh();
            $ticket = Ticket::query()->findOrFail($survey->ticket_id);

            $this->history->record($ticket, TicketEventKind::SurveyAnswered, null, ['rating' => $rating]);
            $this->audit->record('support.csat.answered', $ticket, ['number' => $ticket->number, 'rating' => $rating]);
            CsatSubmitted::dispatch([
                'ticket_id' => $ticket->id,
                'number' => $ticket->number,
                'survey_id' => $survey->id,
                'rating' => $rating,
                'queue_id' => $ticket->queue_id,
                'owner_id' => $ticket->owner_id,
                'contact_id' => $ticket->contact_id,
                'company_id' => $ticket->company_id,
            ]);

            return $survey;
        });
    }

    /** Marks pending surveys past their expiry as expired and clears their hashes. */
    public function expireStale(): int
    {
        return CsatSurvey::query()
            ->where('status', SurveyStatus::Pending)
            ->where('expires_at', '<=', CarbonImmutable::now())
            ->update(['status' => SurveyStatus::Expired, 'token_hash' => null]);
    }
}
