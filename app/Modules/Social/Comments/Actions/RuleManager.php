<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Social\Comments\Automation\ReplyTemplate;
use App\Modules\Social\Comments\Automation\RuleActorGuard;
use App\Modules\Social\Comments\Enums\RuleActionType;
use App\Modules\Social\Comments\Models\CommentRule;
use App\Modules\Social\Comments\Support\KeywordMatcher;
use App\Modules\Social\Comments\Support\UntrustedText;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes, switches and deletes keyword rules. A rule is data: keywords (never patterns), a closed list of
 * actions and plain-text templates with an allow-list of variables. The member who saves it becomes the member it runs
 * as, and must hold the permission of every action for every record - nobody can build a rule more powerful than themselves.
 */
final class RuleManager
{
    public function __construct(
        private readonly RuleActorGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function save(?CommentRule $rule, array $input, TenantMembership $by): CommentRule
    {
        $data = $this->normalise($input, $rule);
        $this->assertMayRun($data['actions'], $by);

        if ($rule === null && CommentRule::query()->count() >= (int) config('social_comments.limits.rules_max', 50)) {
            throw ValidationException::withMessages(['name' => __('A workspace can have at most :max comment rules.', ['max' => (int) config('social_comments.limits.rules_max', 50)])]);
        }

        return DB::transaction(function () use ($rule, $data, $by): CommentRule {
            $created = $rule === null;
            $rule ??= new CommentRule(['created_by' => $by->id, 'is_enabled' => false]);
            $rule->fill([...$data, 'actor_id' => $by->id])->save();
            $this->audit->record($created ? 'social.comment_rule.created' : 'social.comment_rule.updated', $rule, [
                'keywords' => count($data['keywords']),
                'actions' => array_map(static fn (array $action): string => (string) $action['type'], $data['actions']),
                'enabled' => $rule->is_enabled,
            ]);

            return $rule;
        });
    }

    /** Switch a rule on or off. Switching it on makes the switcher the member it runs as. */
    public function setEnabled(CommentRule $rule, bool $enabled, TenantMembership $by): CommentRule
    {
        if ($enabled) {
            $this->assertMayRun(array_values(array_filter((array) $rule->actions, 'is_array')), $by);

            if (KeywordMatcher::sanitize((array) $rule->keywords) === []) {
                throw ValidationException::withMessages(['keywords' => __('Add at least one keyword first.')]);
            }
        }

        return DB::transaction(function () use ($rule, $enabled, $by): CommentRule {
            $rule->forceFill(['is_enabled' => $enabled, 'actor_id' => $enabled ? $by->id : $rule->actor_id])->save();
            $this->audit->record($enabled ? 'social.comment_rule.enabled' : 'social.comment_rule.disabled', $rule, []);

            return $rule;
        });
    }

    public function delete(CommentRule $rule): void
    {
        DB::transaction(function () use ($rule): void {
            $this->audit->record('social.comment_rule.deleted', $rule, []);
            $rule->delete();
        });
    }

    /**
     * Whether the rule's member can still do everything it does (for the rules list to warn about).
     *
     * @return string|null A denial code or null.
     */
    public function standing(CommentRule $rule): ?string
    {
        $rule->loadMissing('actor.user');

        foreach ((array) $rule->actions as $action) {
            $type = RuleActionType::tryFrom((string) ($action['type'] ?? ''));
            $denial = $type === null ? null : $this->guard->denial($rule->actor, $type->permission());

            if ($denial !== null) {
                return $denial;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, match_mode: string, match_type: string, keywords: list<string>, account_id: string|null, post_id: string|null, campaign_id: string|null, actions: list<array<string, mixed>>, reply_templates: list<string>|null, hourly_cap: int, cooldown_minutes: int, quiet_start: string|null, quiet_end: string|null, priority: int, is_enabled?: bool}
     */
    public function normalise(array $input, ?CommentRule $current): array
    {
        $errors = [];
        $name = UntrustedText::line($input['name'] ?? '', 120);

        if ($name === '') {
            $errors['name'] = __('Give the rule a name.');
        } elseif (CommentRule::query()->where('name', $name)->when($current !== null, fn ($query) => $query->whereKeyNot($current?->getKey()))->exists()) {
            $errors['name'] = __('A rule with this name already exists.');
        }

        $keywords = KeywordMatcher::sanitize((array) ($input['keywords'] ?? []));

        if ($keywords === []) {
            $errors['keywords'] = __('Add at least one keyword.');
        }

        $scope = [];

        foreach ([['account_id', SocialAccount::class], ['post_id', Post::class], ['campaign_id', Campaign::class]] as [$field, $model]) {
            $id = $input[$field] ?? null;
            $scope[$field] = is_string($id) && $id !== '' ? $id : null;

            if ($scope[$field] !== null && ! $model::query()->whereKey($scope[$field])->exists()) {
                $errors[$field] = __('That choice is not available.');
            }
        }

        $templates = [];

        foreach ((array) ($input['reply_templates'] ?? []) as $template) {
            $text = is_string($template) ? UntrustedText::clean($template, (int) config('social_comments.limits.template_max', 500) + 1) : '';

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > (int) config('social_comments.limits.template_max', 500)) {
                $errors['reply_templates'] = __('Each reply can have at most :max characters.', ['max' => (int) config('social_comments.limits.template_max', 500)]);
            } elseif (ReplyTemplate::unknownVariables($text) !== []) {
                $errors['reply_templates'] = __('Only :variables can be used in a reply.', ['variables' => '{first_name}, {page_name}']);
            }

            $templates[] = $text;
        }

        $templates = array_slice($templates, 0, (int) config('social_comments.limits.templates_max', 5));
        $actions = [];
        $seen = [];

        foreach ((array) ($input['actions'] ?? []) as $action) {
            $type = is_array($action) ? RuleActionType::tryFrom((string) ($action['type'] ?? '')) : null;

            if ($type === null || isset($seen[$type->value])) {
                continue;
            }

            $seen[$type->value] = true;

            switch ($type) {
                case RuleActionType::Reply:
                    if ($templates === []) {
                        $errors['reply_templates'] = __('Add at least one reply to send.');
                    }

                    $actions[] = ['type' => $type->value];
                    break;
                case RuleActionType::PrivateReply:
                    $message = UntrustedText::clean($action['message'] ?? '', (int) config('social_comments.limits.template_max', 500) + 1);

                    if (! (bool) config('social_comments.facebook.private_replies', false)) {
                        $errors['actions'] = __('Private replies are not available on this installation.');
                    } elseif ($message === '' || mb_strlen($message) > (int) config('social_comments.limits.template_max', 500) || ReplyTemplate::unknownVariables($message) !== []) {
                        $errors['actions'] = __('Write the private reply (up to :max characters, using only :variables).', ['max' => (int) config('social_comments.limits.template_max', 500), 'variables' => '{first_name}, {page_name}']);
                    }

                    $actions[] = ['type' => $type->value, 'message' => $message];
                    break;
                case RuleActionType::Assign:
                    $id = (string) ($action['membership_id'] ?? '');

                    if ($id === '' || ! TenantMembership::query()->where('status', MembershipStatus::Active->value)->whereKey($id)->exists()) {
                        $errors['actions'] = __('Choose an active team member to assign to.');
                    }

                    $actions[] = ['type' => $type->value, 'membership_id' => $id];
                    break;
                case RuleActionType::Tag:
                    $tag = UntrustedText::line($action['tag'] ?? '', (int) config('social_comments.limits.tag_max', 40));

                    if ($tag === '') {
                        $errors['actions'] = __('Write the tag to add.');
                    }

                    $actions[] = ['type' => $type->value, 'tag' => $tag];
                    break;
                default:
                    $actions[] = ['type' => $type->value];
            }
        }

        if ($actions === []) {
            $errors['actions'] = $errors['actions'] ?? __('Choose at least one action.');
        }

        $quiet = [$this->time($input['quiet_start'] ?? null), $this->time($input['quiet_end'] ?? null)];

        if (($quiet[0] === null) !== ($quiet[1] === null)) {
            $errors['quiet_start'] = __('Set both the start and the end of the quiet hours, or neither.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $name,
            'match_mode' => ($input['match_mode'] ?? 'any') === 'all' ? 'all' : 'any',
            'match_type' => ($input['match_type'] ?? 'word') === 'contains' ? 'contains' : 'word',
            'keywords' => $keywords,
            ...$scope,
            'actions' => $actions,
            'reply_templates' => isset($seen[RuleActionType::Reply->value]) ? $templates : null,
            'hourly_cap' => max(1, min(200, (int) ($input['hourly_cap'] ?? 20))),
            'cooldown_minutes' => max(0, min(10080, (int) ($input['cooldown_minutes'] ?? 60))),
            'quiet_start' => $quiet[0],
            'quiet_end' => $quiet[1],
            'priority' => max(1, min(1000, (int) ($input['priority'] ?? 100))),
        ];
    }

    /** @param list<array<string, mixed>> $actions */
    private function assertMayRun(array $actions, TenantMembership $by): void
    {
        $by->loadMissing('user');

        foreach ($actions as $action) {
            $type = RuleActionType::tryFrom((string) ($action['type'] ?? ''));

            if ($type !== null && $this->guard->denial($by, $type->permission()) !== null) {
                throw ValidationException::withMessages(['actions' => __('A rule can only do what you are allowed to do yourself, for every comment. You are not allowed to use the ":action" action.', [
                    'action' => $type->value,
                ])]);
            }
        }
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
    }
}
