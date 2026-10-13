<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Communication\Contracts\ConsentRecorder;
use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Data\AudienceReason;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Support\TenantHost;
use App\Core\Tenancy\TenantContext;
use App\Modules\EmailMarketing\Enums\ListStatus;
use App\Modules\EmailMarketing\Enums\SignupOutcome;
use App\Modules\EmailMarketing\Enums\SubscriptionSource;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Mail\SignupConfirmationMail;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\SignupRequest;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Support\BlockedSignup;
use App\Modules\EmailMarketing\Support\ContentBlocks;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The public sign-up form of a list, with double opt-in.
 *
 * A submission creates nothing but a {@see SignupRequest} (address, optional name, the hash of a 256-bit token)
 * and one confirmation email. No contact and no membership exist until the person follows the link and confirms
 * with a POST (a GET only shows the button, so mail scanners cannot subscribe anyone). Confirming finds or creates
 * the shared contact through ContactDirectory, subscribes the member with the double opt-in evidence, records the
 * platform's marketing opt-in through the core ConsentRecorder contract and burns the link, all in one
 * transaction. Submitting changes no existing membership, so the form can never resubscribe, or
 * even touch, a person who left: only a fresh confirmation by the owner of the mailbox does.
 *
 * Every public answer to a submission is the same ("check your inbox"), whether the address was new, known,
 * subscribed, suppressed or recently asked: the form is not an oracle for who is on a list.
 */
final class PublicSignup
{
    public const int TTL_DAYS = 3;

    /** At most one confirmation email per address per list in this window. */
    public const int RESEND_WINDOW_MINUTES = 60;

    /** A human needs a moment; a script posting at once is turned away. */
    public const int MIN_FILL_SECONDS = 3;

    public const int MAX_FORM_AGE_HOURS = 24;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantHost $hosts,
        private readonly SubscriberManager $subscribers,
        private readonly ContactDirectory $contacts,
        private readonly MarketingAudience $audience,
        private readonly ConsentRecorder $consent,
        private readonly AuditLogger $audit,
    ) {}

    /** The list behind a public form token, only while the form is on and the list is active. */
    public function formFor(string $token): ?MailingList
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }

        return MailingList::query()
            ->where('signup_token', $token)
            ->where('signup_enabled', true)
            ->where('status', ListStatus::Active->value)
            ->first();
    }

    public function formUrl(MailingList $list): ?string
    {
        if (! $list->signup_enabled || $list->signup_token === null) {
            return null;
        }

        return $this->hosts->url($this->context->require(), 'email-marketing/join/'.$list->signup_token);
    }

    /** The time trap of a rendered form: a signed issue time. */
    public function trap(MailingList $list): string
    {
        $issued = CarbonImmutable::now()->getTimestamp();

        return $issued.'.'.$this->mac($list, $issued);
    }

    /** Whether the trap value is ours, was issued at least a few seconds ago and is not stale. */
    public function trapPasses(MailingList $list, mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $value, $match) !== 1) {
            return false;
        }

        $issued = (int) $match[1];

        if (! hash_equals($this->mac($list, $issued), $match[2])) {
            return false;
        }

        $age = CarbonImmutable::now()->getTimestamp() - $issued;

        return $age >= self::MIN_FILL_SECONDS && $age <= self::MAX_FORM_AGE_HOURS * 3600;
    }

    /** A lower-cased address the importer would also accept, or null. */
    public function normalizeEmail(string $email): ?string
    {
        $email = Str::lower(trim($email));

        if ($email === '' || strlen($email) > 190 || preg_match('/^[a-z0-9_]/', $email) !== 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    /**
     * Take a submission. Returns whether a confirmation email was queued; callers must not tell the visitor.
     */
    public function request(MailingList $list, string $email, ?string $firstName, ?string $lastName): bool
    {
        $lock = Cache::lock('em-signup:'.$list->id.':'.sha1($email), 10);

        try {
            return (bool) $lock->block(3, fn (): bool => $this->issue($list, $email, $firstName, $lastName));
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /** The pending, unexpired request behind a link, while its form is still on. */
    public function find(string $token): ?SignupRequest
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $request = SignupRequest::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', CarbonImmutable::now())
            ->first();

        if ($request === null) {
            return null;
        }

        $list = MailingList::query()->find($request->list_id);

        return $list !== null && $list->signup_enabled && $list->status === ListStatus::Active ? $request : null;
    }

    public function listOf(SignupRequest $request): ?MailingList
    {
        return MailingList::query()->find($request->list_id);
    }

    /**
     * Confirm the link: single use, tenant-bound, recorded as consent evidence on the membership.
     */
    public function confirm(string $token, string $userAgent): SignupOutcome
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return SignupOutcome::Invalid;
        }

        $hash = hash('sha256', $token);

        try {
            return DB::transaction(fn (): SignupOutcome => $this->consume($hash, $userAgent));
        } catch (BlockedSignup $blocked) {
            // Rolled back: no contact, no membership. The link is spent, the address cannot be added here.
            SignupRequest::query()->where('token_hash', $hash)->delete();
            $this->audit->record('email_marketing.signup.blocked', null, ['list_id' => $blocked->listId, 'reason' => $blocked->getMessage()], subjectLabel: 'Sign-up form');

            return SignupOutcome::Blocked;
        } catch (ValidationException) {
            // The plan's subscriber limit: nothing changed and the link stays valid.
            return SignupOutcome::Unavailable;
        }
    }

    /** Expired requests hold an address for no reason once their link is dead. */
    public function prune(): int
    {
        return SignupRequest::query()->where('expires_at', '<', CarbonImmutable::now()->subDay())->delete();
    }

    /**
     * What the list page shows the manager.
     *
     * @return array{enabled: bool, embed: bool, url: string|null, iframe: string|null, confirmed: int, unconfirmed: int}
     */
    public function summary(MailingList $list): array
    {
        $url = $this->formUrl($list);

        return [
            'enabled' => $list->signup_enabled,
            'embed' => $list->signup_embed,
            'url' => $url,
            'iframe' => $url !== null && $list->signup_embed
                ? '<iframe src="'.$url.'" title="'.e($list->name).'" width="100%" height="460" style="border:0;max-width:480px" loading="lazy"></iframe>'
                : null,
            'confirmed' => Subscription::query()->where('list_id', $list->id)->where('source', SubscriptionSource::Form->value)->whereNotNull('subscribed_at')->count(),
            'unconfirmed' => SignupRequest::query()->where('list_id', $list->id)->where('expires_at', '>', CarbonImmutable::now())->count(),
        ];
    }

    private function issue(MailingList $list, string $email, ?string $firstName, ?string $lastName): bool
    {
        $now = CarbonImmutable::now();

        $recent = SignupRequest::query()
            ->where('list_id', $list->id)
            ->where('email', $email)
            ->where('created_at', '>', $now->subMinutes(self::RESEND_WINDOW_MINUTES))
            ->where('expires_at', '>', $now)
            ->exists();

        if ($recent) {
            return false;
        }

        $contact = Contact::query()->whereRaw('lower(email) = ?', [$email])->orderBy('id')->first();

        if ($contact !== null) {
            $member = Subscription::query()->where('list_id', $list->id)->where('contact_id', $contact->id)->first();

            // Already in, or closed by a bounce or complaint: a mail would add nothing.
            if ($member !== null && in_array($member->status, [SubscriptionStatus::Subscribed, SubscriptionStatus::Bounced, SubscriptionStatus::Complained], true)) {
                return false;
            }

            // A hard-bounced or complained address is never mailed, not even to ask.
            if ($this->audience->canReceive($contact->id, MarketingChannel::Email)->reason === AudienceReason::Suppressed) {
                return false;
            }
        }

        $token = bin2hex(random_bytes(32));

        DB::transaction(function () use ($list, $email, $firstName, $lastName, $token, $now): void {
            SignupRequest::query()->create([
                'list_id' => $list->id,
                'email' => $email,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $now->addDays(self::TTL_DAYS),
            ]);

            Mail::to($email)
                ->locale($this->context->require()->locale)
                ->queue(new SignupConfirmationMail($list->name, $token));
        });

        return true;
    }

    private function consume(string $hash, string $userAgent): SignupOutcome
    {
        $now = CarbonImmutable::now();

        $request = SignupRequest::query()
            ->where('token_hash', $hash)
            ->where('expires_at', '>', $now)
            ->lockForUpdate()
            ->first();

        $list = $request === null ? null : MailingList::query()->lockForUpdate()->find($request->list_id);

        if ($request === null || $list === null || ! $list->signup_enabled || $list->status !== ListStatus::Active) {
            return SignupOutcome::Invalid;
        }

        $contact = Contact::query()->whereRaw('lower(email) = ?', [$request->email])->orderBy('id')->first();

        if ($contact === null) {
            $first = ContentBlocks::line($request->first_name ?? '');
            $last = ContentBlocks::line($request->last_name ?? '');

            $contact = $this->contacts->createContact([
                'first_name' => mb_substr($first !== '' ? $first : Str::before($request->email, '@'), 0, 100),
                'last_name' => $last === '' ? null : mb_substr($last, 0, 100),
                'email' => $request->email,
            ], null);
        }

        $member = Subscription::query()->where('list_id', $list->id)->where('contact_id', $contact->id)->first();

        if ($member !== null && in_array($member->status, [SubscriptionStatus::Bounced, SubscriptionStatus::Complained], true)) {
            throw new BlockedSignup($list->id, 'membership_'.$member->status->value);
        }

        $decision = $this->audience->canReceive($contact->id, MarketingChannel::Email);

        // A suppression or an opt-out held by the platform cannot be lifted from here: say so, change nothing.
        if (in_array($decision->reason, [AudienceReason::Suppressed, AudienceReason::Unsubscribed, AudienceReason::OptedOut], true)) {
            throw new BlockedSignup($list->id, $decision->reason->value);
        }

        $evidence = sprintf(
            'Double opt-in on the public sign-up form: requested %s UTC, confirmed %s UTC, form %s, user agent hash %s.',
            $request->created_at?->utc()->format('Y-m-d H:i') ?? 'unknown',
            $now->utc()->format('Y-m-d H:i'),
            $list->id,
            substr(hash('sha256', $userAgent), 0, 16),
        );

        $subscription = $this->subscribers->add($list, $contact->id, [
            'consent_method' => 'website_form',
            'consent_evidence' => $evidence,
        ], SubscriptionSource::Form);

        // The platform consent, in the same transaction: the confirmed address is now sendable. A refusal (a
        // suppression appeared, the communication app is unavailable) rolls the membership back too.
        $platformEvidence = sprintf(
            'Double opt-in via form %s confirmed %s UTC; user agent hash %s',
            $list->id,
            $now->utc()->format('Y-m-d H:i:s'),
            substr(hash('sha256', $userAgent), 0, 16),
        );

        if (! $this->consent->recordOptIn($contact->id, MarketingChannel::Email, 'double_opt_in', $platformEvidence)) {
            throw new BlockedSignup($list->id, 'consent_refused');
        }

        $request->delete();

        $this->audit->record('email_marketing.signup.confirmed', $subscription, [
            'list_id' => $list->id, 'contact_id' => $contact->id, 'status' => $subscription->status->value,
        ], subjectLabel: $list->name);

        return SignupOutcome::Subscribed;
    }

    private function mac(MailingList $list, int $issued): string
    {
        return hash_hmac('sha256', $this->context->requireId().'|'.$list->id.'|'.$issued, 'email_marketing_signup|'.(string) config('app.key'));
    }
}
