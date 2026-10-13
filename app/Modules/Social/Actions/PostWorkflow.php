<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Models\UsageRecord;
use App\Core\Billing\UsageLimits;
use App\Core\Billing\UsageMeter;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Events\PostApproved;
use App\Modules\Social\Events\PostScheduled;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The review and scheduling states of a post:
 *
 *   draft -> pending_approval -> approved -> scheduled -> (publishing) -> published | partially_failed | failed
 *
 * Approval is four-eyes: the approver must be neither the person who submitted the post nor its
 * author. A workspace that does not require approval goes draft -> scheduled directly.
 */
final class PostWorkflow
{
    public const string LIMIT = 'social.scheduled_posts_per_month';

    public function __construct(
        private readonly SocialSettings $settings,
        private readonly PostContentRules $rules,
        private readonly UsageLimits $limits,
        private readonly UsageMeter $meter,
        private readonly AuditLogger $audit,
    ) {}

    public function submit(Post $post, TenantMembership $actor): Post
    {
        return DB::transaction(function () use ($post, $actor): Post {
            $locked = $this->lock($post);
            $this->assertStatus($locked, [PostStatus::Draft], __('Only a draft can be submitted for approval.'));
            $this->rules->assertPublishable($locked);

            $locked->fill([
                'status' => PostStatus::PendingApproval,
                'submitted_by' => $actor->id,
                'submitted_at' => now(),
                'approved_by' => null, 'approved_at' => null,
                'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
            ])->save();
            $this->audit->record('social.post.submitted', $locked);

            return $locked;
        });
    }

    /**
     * Approve a submitted post. When it has a planned time still ahead it is queued at once.
     */
    public function approve(Post $post, TenantMembership $approver): Post
    {
        return DB::transaction(function () use ($post, $approver): Post {
            $locked = $this->lock($post);
            $this->assertStatus($locked, [PostStatus::PendingApproval], __('Only a post awaiting approval can be approved.'));

            if ($locked->submitted_by === $approver->id || $locked->owner_id === $approver->id) {
                throw ValidationException::withMessages([
                    'approval' => __('A post must be approved by someone other than its author and the person who submitted it.'),
                ]);
            }

            $this->rules->assertPublishable($locked);

            $locked->fill([
                'status' => PostStatus::Approved,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null,
            ])->save();
            $this->audit->record('social.post.approved', $locked, ['submitted_by' => $locked->submitted_by]);
            event(PostApproved::from($locked));

            if ($locked->scheduled_for !== null && $locked->scheduled_for->isFuture()) {
                $this->enqueue($locked, CarbonImmutable::instance($locked->scheduled_for));
            }

            return $locked;
        });
    }

    public function reject(Post $post, TenantMembership $reviewer, string $reason): Post
    {
        return DB::transaction(function () use ($post, $reviewer, $reason): Post {
            $locked = $this->lock($post);
            $this->assertStatus($locked, [PostStatus::PendingApproval], __('Only a post awaiting approval can be rejected.'));

            $locked->fill([
                'status' => PostStatus::Draft,
                'rejected_by' => $reviewer->id,
                'rejected_at' => now(),
                'rejection_reason' => mb_substr($reason, 0, 500),
                'submitted_by' => null, 'submitted_at' => null,
            ])->save();
            $this->audit->record('social.post.rejected', $locked, ['reason' => $locked->rejection_reason]);

            return $locked;
        });
    }

    /**
     * Queue a post. `$at === null` means as soon as the next minute's run.
     */
    public function schedule(Post $post, ?CarbonImmutable $at): Post
    {
        return DB::transaction(function () use ($post, $at): Post {
            $locked = $this->lock($post);
            $requiresApproval = $this->settings->current()->require_approval;

            if ($requiresApproval) {
                $this->assertStatus($locked, [PostStatus::Approved], __('This workspace requires approval before a post can be scheduled.'));
            } else {
                $this->assertStatus($locked, [PostStatus::Draft, PostStatus::Approved], __('Only a draft or approved post can be scheduled.'));
            }

            $when = $this->assertTime($at ?? CarbonImmutable::now());

            $this->rules->assertPublishable($locked);
            $this->enqueue($locked, $when);

            return $locked;
        });
    }

    /** Move a queued post to another time. The content is unchanged, so its approval stands. */
    public function reschedule(Post $post, CarbonImmutable $at): Post
    {
        return DB::transaction(function () use ($post, $at): Post {
            $locked = $this->lock($post);
            $this->assertStatus($locked, [PostStatus::Scheduled], __('Only a scheduled post can be moved.'));
            $when = $this->assertTime($at);

            if ($locked->targets()->whereIn('status', [TargetStatus::Publishing, TargetStatus::Processing])->exists()) {
                throw ValidationException::withMessages(['post' => __('This post is being published and cannot be moved.')]);
            }

            $locked->targets()->where('status', TargetStatus::Pending)->update(['next_attempt_at' => $when]);
            $locked->fill(['scheduled_for' => $when])->save();
            $this->audit->record('social.post.rescheduled', $locked, ['scheduled_for' => $when->toIso8601String()]);

            return $locked;
        });
    }

    private function assertTime(CarbonImmutable $when): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        if ($when->lt($now->subMinute())) {
            throw ValidationException::withMessages(['scheduled_for' => __('Choose a time in the future.')]);
        }

        if ($when->gt($now->addDays(366))) {
            throw ValidationException::withMessages(['scheduled_for' => __('Posts can be scheduled at most one year ahead.')]);
        }

        return $when;
    }

    /** Take a post out of the queue (or review) and back to draft. Its planned time is kept. */
    public function unschedule(Post $post): Post
    {
        return DB::transaction(function () use ($post): Post {
            $locked = $this->lock($post);

            if (! $locked->status->canReturnToDraft()) {
                throw ValidationException::withMessages(['post' => __('This post cannot be returned to draft.')]);
            }

            // A target already claimed by the publisher is mid-flight; stopping now would race it.
            if ($locked->targets()->whereIn('status', [TargetStatus::Publishing, TargetStatus::Processing])->exists()) {
                throw ValidationException::withMessages(['post' => __('This post is being published and cannot be stopped.')]);
            }

            $locked->targets()->where('status', TargetStatus::Pending)->update(['next_attempt_at' => null]);
            $locked->fill([
                'status' => PostStatus::Draft,
                'submitted_by' => null, 'submitted_at' => null,
                'approved_by' => null, 'approved_at' => null,
            ])->save();
            $this->audit->record('social.post.unscheduled', $locked);

            return $locked;
        });
    }

    /**
     * Try a failed target again. The platform may already have the post when `needs_manual_check`
     * is set; the caller confirms that before using this.
     */
    public function retryTarget(Post $post, PostTarget $target): PostTarget
    {
        return DB::transaction(function () use ($post, $target): PostTarget {
            $lockedPost = $this->lock($post);
            $locked = PostTarget::query()->lockForUpdate()->where('post_id', $lockedPost->id)->findOrFail($target->id);

            if ($locked->status !== TargetStatus::Failed) {
                throw ValidationException::withMessages(['target' => __('Only a failed account can be retried.')]);
            }

            $account = $locked->account;

            if ($account->status !== AccountStatus::Connected) {
                throw ValidationException::withMessages(['target' => __('Authorise the account again before retrying.')]);
            }

            $locked->fill([
                'status' => TargetStatus::Pending,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'claimed_at' => null,
                'last_error' => null,
                'last_error_code' => null,
                'needs_manual_check' => false,
            ])->save();

            $lockedPost->fill(['status' => PostStatus::Scheduled, 'published_at' => null])->save();
            $this->audit->record('social.post.target_retried', $lockedPost, ['account_id' => $locked->account_id]);

            return $locked;
        });
    }

    /** Count the post against the plan once, then put its unpublished targets in the queue. */
    private function enqueue(Post $post, CarbonImmutable $at): void
    {
        $key = 'post:'.$post->id;
        $counted = UsageRecord::query()->where('meter', self::LIMIT)->where('idempotency_key', $key)->exists();

        if (! $counted) {
            $this->limits->ensure(self::LIMIT, 1, 'scheduled_for');
            $this->meter->record(self::LIMIT, 1, $key, ['post_id' => $post->id]);
        }

        $post->targets()->where('status', '!=', TargetStatus::Published->value)->update([
            'status' => TargetStatus::Pending->value,
            'attempts' => 0,
            'next_attempt_at' => $at,
            'claimed_at' => null,
            'last_error' => null,
            'last_error_code' => null,
            'needs_manual_check' => false,
        ]);

        $post->fill(['status' => PostStatus::Scheduled, 'scheduled_for' => $at, 'published_at' => null])->save();
        $this->audit->record('social.post.scheduled', $post, ['scheduled_for' => $at->toIso8601String()]);
        event(PostScheduled::from($post->load('targets')));
    }

    private function lock(Post $post): Post
    {
        return Post::query()->lockForUpdate()->findOrFail($post->id);
    }

    /** @param list<PostStatus> $allowed */
    private function assertStatus(Post $post, array $allowed, string $message): void
    {
        if (! in_array($post->status, $allowed, true)) {
            throw ValidationException::withMessages(['post' => $message]);
        }
    }
}
