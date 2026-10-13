<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Automation;

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Comments\Actions\CommentActions;
use App\Modules\Social\Comments\Actions\CommentSettings;
use App\Modules\Social\Comments\CommentGateway;
use App\Modules\Social\Comments\Data\CommentActionFailed;
use App\Modules\Social\Comments\Enums\RemoteState;
use App\Modules\Social\Comments\Enums\RuleActionType;
use App\Modules\Social\Comments\Models\AudienceComment;
use App\Modules\Social\Comments\Models\CommentRule;
use App\Modules\Social\Comments\Models\CommentRuleRun;
use App\Modules\Social\Comments\Support\KeywordMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Runs the workspace's keyword rules on one new comment.
 *
 * Safety is layered and every layer is in the database, not in memory: a global kill switch; the comment must be
 * visible, not the Page's own and recent; per rule and per Page hourly caps; a cooldown per commenter; quiet hours;
 * at most one run of each action of each rule per comment (a claim row written before the platform call); and the
 * rule's member must still hold the permission for each action. Held-back work is returned as a {@see Deferral};
 * every executed, skipped or failed action leaves a {@see CommentRuleRun} row and an audit entry.
 */
final class RuleEngine
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CommentSettings $settings,
        private readonly CommentActions $actions,
        private readonly CommentGateway $gateway,
        private readonly RuleActorGuard $guard,
    ) {}

    public function process(AudienceComment $comment): ?Deferral
    {
        $comment->loadMissing(['account', 'post']);

        if ($this->settings->automationPaused()
            || $comment->is_from_page
            || $comment->remote_state !== RemoteState::Visible
            || ($comment->account->status->value !== 'connected')
            || $comment->commented_at === null
            || $comment->commented_at->lt(now()->subHours((int) config('social_comments.automation.max_comment_age_hours', 48)))) {
            return null;
        }

        $rules = CommentRule::query()->with('actor.user')->where('is_enabled', true)->orderBy('priority')->orderBy('created_at')->get();
        $deferral = null;

        foreach ($rules as $rule) {
            if (! $this->applies($rule, $comment)) {
                continue;
            }

            $result = KeywordMatcher::evaluate((string) $comment->message, $this->keywords($rule), $rule->match_mode, $rule->match_type);

            if (! $result['matched']) {
                continue;
            }

            $this->noteMatch($rule, $comment);

            foreach ($this->actionList($rule) as $action) {
                $held = $this->run($rule, $comment, $action);

                if ($held !== null && ($deferral === null || $held->until->lt($deferral->until))) {
                    $deferral = $held;
                }
            }
        }

        return $deferral;
    }

    /**
     * What a draft rule would do with a sample comment. Nothing is saved, nothing is sent, the platform is not called.
     *
     * @param  array<string, mixed>  $rule  match_mode, match_type, keywords, actions, reply_templates, quiet_start, quiet_end
     * @return array{matched: bool, hits: list<string>, actions: list<array{type: string, detail: string|null, note: string|null}>, quiet: bool}
     */
    public function preview(array $rule, string $text, ?string $authorName, string $pageName): array
    {
        $result = KeywordMatcher::evaluate(
            $text,
            KeywordMatcher::sanitize((array) ($rule['keywords'] ?? [])),
            ($rule['match_mode'] ?? 'any') === 'all' ? 'all' : 'any',
            ($rule['match_type'] ?? 'word') === 'contains' ? 'contains' : 'word',
        );

        $templates = array_values(array_filter((array) ($rule['reply_templates'] ?? []), 'is_string'));
        $quiet = $this->quietUntil(is_string($rule['quiet_start'] ?? null) ? $rule['quiet_start'] : null, is_string($rule['quiet_end'] ?? null) ? $rule['quiet_end'] : null) !== null;
        $planned = [];

        if ($result['matched']) {
            foreach ((array) ($rule['actions'] ?? []) as $action) {
                $type = is_array($action) ? RuleActionType::tryFrom((string) ($action['type'] ?? '')) : null;

                if ($type === null) {
                    continue;
                }

                $detail = null;
                $note = null;

                if ($type === RuleActionType::Reply) {
                    $detail = $templates === [] ? null : ReplyTemplate::render($templates[0], $authorName, $pageName);
                    $note = $templates === [] ? 'no_template' : (count($templates) > 1 ? 'one_of_templates' : null);
                } elseif ($type === RuleActionType::PrivateReply) {
                    $detail = ReplyTemplate::render((string) ($action['message'] ?? ''), $authorName, $pageName);
                } elseif ($type === RuleActionType::Tag) {
                    $detail = (string) ($action['tag'] ?? '');
                }

                $planned[] = ['type' => $type->value, 'detail' => $detail, 'note' => $note];
            }
        }

        return ['matched' => $result['matched'], 'hits' => $result['hits'], 'actions' => $planned, 'quiet' => $quiet];
    }

    /** The first time a rule matches a comment: counted once, whatever the number of retries. */
    private function noteMatch(CommentRule $rule, AudienceComment $comment): void
    {
        try {
            CommentRuleRun::query()->create([
                'rule_id' => $rule->id, 'comment_id' => $comment->id, 'account_id' => $comment->account_id,
                'author_external_id' => $comment->author_external_id, 'action' => 'match', 'outcome' => CommentRuleRun::DONE,
            ]);
            $rule->forceFill(['matched_count' => $rule->matched_count + 1, 'last_matched_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            // Seen on an earlier pass.
        }
    }

    /** @param array<string, mixed> $action */
    private function run(CommentRule $rule, AudienceComment $comment, array $action): ?Deferral
    {
        $type = RuleActionType::tryFrom((string) ($action['type'] ?? ''));

        if ($type === null) {
            return null;
        }

        $prior = CommentRuleRun::query()->where('rule_id', $rule->id)->where('comment_id', $comment->id)->where('action', $type->value)->exists();

        if ($prior) {
            return null;
        }

        $denial = $this->guard->denial($rule->actor, $type->permission());

        if ($denial !== null) {
            $this->record($rule, $comment, $type->value, CommentRuleRun::SKIPPED, $denial);

            return null;
        }

        if ($type->isOutward()) {
            $held = $this->hold($rule, $comment, $type);

            if ($held instanceof Deferral) {
                return $held;
            }

            if (is_string($held)) {
                $this->record($rule, $comment, $type->value, CommentRuleRun::SKIPPED, $held);

                return null;
            }
        }

        // Claim before acting: a second worker (or a replayed delivery) that loses this insert does nothing.
        if (! $this->record($rule, $comment, $type->value, CommentRuleRun::CLAIMED, null)) {
            return null;
        }

        try {
            $reason = $this->perform($rule, $comment, $type, $action);
        } catch (CommentActionFailed $failure) {
            if ($failure->isTemporary()) {
                $this->release($rule, $comment, $type->value);

                return new Deferral(
                    now()->toImmutable()->addSeconds($failure->retryAfter ?? ((int) config('social_comments.automation.defer_minutes', 10) * 60)),
                    $failure->errorCode,
                );
            }

            $this->finish($rule, $comment, $type->value, CommentRuleRun::FAILED, $failure->errorCode);

            return null;
        }

        $this->finish($rule, $comment, $type->value, $reason === null ? CommentRuleRun::DONE : CommentRuleRun::SKIPPED, $reason);

        return null;
    }

    /**
     * @param  array<string, mixed>  $action
     * @return string|null A reason when nothing was done (skipped), null when the action ran.
     */
    private function perform(CommentRule $rule, AudienceComment $comment, RuleActionType $type, array $action): ?string
    {
        $actor = $rule->actor;
        $pageName = $comment->account->name;

        switch ($type) {
            case RuleActionType::Reply:
                $templates = array_values(array_filter($rule->reply_templates ?? [], static fn (string $item): bool => trim($item) !== ''));

                if ($templates === []) {
                    return 'no_template';
                }

                // Deterministic per comment: a retry picks the same wording.
                $template = $templates[crc32($comment->external_id) % count($templates)];
                $text = ReplyTemplate::render($template, $comment->author_name, $pageName);

                if ($text === '') {
                    return 'no_template';
                }

                $this->actions->reply($comment, $text, $actor, $rule);

                return null;

            case RuleActionType::PrivateReply:
                $text = ReplyTemplate::render((string) ($action['message'] ?? ''), $comment->author_name, $pageName);

                if ($text === '') {
                    return 'no_template';
                }

                if (! $this->gateway->provider($comment->account)->supportsPrivateReplies($comment->account->scopes)) {
                    return 'private_replies_unavailable';
                }

                $this->actions->privateReply($comment, $text, $actor, $rule);

                return null;

            case RuleActionType::Hide:
                $this->actions->setHidden($comment, true, $actor, $rule);

                return null;

            case RuleActionType::Assign:
                $assignee = TenantMembership::query()->where('status', MembershipStatus::Active->value)->find((string) ($action['membership_id'] ?? ''));

                if ($assignee === null) {
                    return 'assignee_unavailable';
                }

                $this->actions->assign($comment, $assignee, $actor, $rule);

                return null;

            case RuleActionType::Tag:
                $this->actions->addTag($comment, (string) ($action['tag'] ?? ''), $actor, $rule);

                return null;

            case RuleActionType::Review:
                $this->actions->flagAttention($comment, 'rule:'.$rule->id, $actor, $rule);

                return null;
        }
    }

    /**
     * Caps, cooldown and quiet hours for an action the audience can see.
     *
     * @return Deferral|string|null A deferral to try later, a terminal skip reason, or null to go ahead.
     */
    private function hold(CommentRule $rule, AudienceComment $comment, RuleActionType $type): Deferral|string|null
    {
        $quiet = $this->quietUntil($rule->quiet_start, $rule->quiet_end);

        if ($quiet !== null) {
            return new Deferral($quiet, 'quiet_hours', counts: false);
        }

        $window = now()->subHour();
        $outward = [RuleActionType::Reply->value, RuleActionType::Hide->value, RuleActionType::PrivateReply->value];
        $live = [CommentRuleRun::CLAIMED, CommentRuleRun::DONE];
        $later = now()->toImmutable()->addMinutes(max(1, (int) config('social_comments.automation.defer_minutes', 10)));

        $byRule = CommentRuleRun::query()->where('rule_id', $rule->id)->whereIn('action', $outward)->whereIn('outcome', $live)->where('created_at', '>=', $window)->count();

        if ($byRule >= max(1, $rule->hourly_cap)) {
            return new Deferral($later, 'rule_cap');
        }

        $byPage = CommentRuleRun::query()->where('account_id', $comment->account_id)->whereIn('action', $outward)->whereIn('outcome', $live)->where('created_at', '>=', $window)->count();

        if ($byPage >= max(1, $this->settings->current()->page_hourly_cap)) {
            return new Deferral($later, 'page_cap');
        }

        if (in_array($type, [RuleActionType::Reply, RuleActionType::PrivateReply], true)
            && $rule->cooldown_minutes > 0
            && $comment->author_external_id !== null
            && CommentRuleRun::query()
                ->where('rule_id', $rule->id)->where('author_external_id', $comment->author_external_id)
                ->whereIn('action', [RuleActionType::Reply->value, RuleActionType::PrivateReply->value])->whereIn('outcome', $live)
                ->where('created_at', '>=', now()->subMinutes($rule->cooldown_minutes))->exists()) {
            return 'author_cooldown';
        }

        return null;
    }

    /** The end of the quiet window when it is quiet now, in the workspace's timezone. */
    public function quietUntil(?string $start, ?string $end): ?CarbonImmutable
    {
        $from = $this->minutes($start);
        $to = $this->minutes($end);

        if ($from === null || $to === null || $from === $to) {
            return null;
        }

        $local = CarbonImmutable::now($this->context->require()->timezone);
        $minute = $local->hour * 60 + $local->minute;
        $inside = $from < $to ? ($minute >= $from && $minute < $to) : ($minute >= $from || $minute < $to);

        if (! $inside) {
            return null;
        }

        $until = $local->startOfDay()->addMinutes($to);

        if ($until->lte($local)) {
            $until = $until->addDay();
        }

        return $until->utc();
    }

    private function minutes(?string $time): ?int
    {
        if ($time === null || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    private function applies(CommentRule $rule, AudienceComment $comment): bool
    {
        return ($rule->account_id === null || $rule->account_id === $comment->account_id)
            && ($rule->post_id === null || $rule->post_id === $comment->post_id)
            && ($rule->campaign_id === null || $rule->campaign_id === $comment->post?->campaign_id);
    }

    /** @return list<string> */
    private function keywords(CommentRule $rule): array
    {
        return KeywordMatcher::sanitize((array) $rule->keywords);
    }

    /** @return list<array<string, mixed>> */
    private function actionList(CommentRule $rule): array
    {
        return array_values(array_filter((array) $rule->actions, 'is_array'));
    }

    /** Insert a run row; false when one already exists (someone else got there first). */
    private function record(CommentRule $rule, AudienceComment $comment, string $action, string $outcome, ?string $reason): bool
    {
        try {
            CommentRuleRun::query()->create([
                'rule_id' => $rule->id, 'comment_id' => $comment->id, 'account_id' => $comment->account_id,
                'author_external_id' => $comment->author_external_id, 'action' => $action, 'outcome' => $outcome,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 64),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function finish(CommentRule $rule, AudienceComment $comment, string $action, string $outcome, ?string $reason): void
    {
        CommentRuleRun::query()->where('rule_id', $rule->id)->where('comment_id', $comment->id)->where('action', $action)
            ->update(['outcome' => $outcome, 'reason' => $reason === null ? null : mb_substr($reason, 0, 64)]);

        if ($outcome === CommentRuleRun::FAILED) {
            Log::notice('A comment rule action failed.', ['rule_id' => $rule->id, 'comment_id' => $comment->id, 'action' => $action, 'reason' => $reason]);
        }
    }

    private function release(CommentRule $rule, AudienceComment $comment, string $action): void
    {
        CommentRuleRun::query()->where('rule_id', $rule->id)->where('comment_id', $comment->id)->where('action', $action)
            ->where('outcome', CommentRuleRun::CLAIMED)->delete();
    }
}
