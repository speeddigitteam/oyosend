<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Http\Controllers;

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Support\Toast;
use App\Modules\Social\Comments\Actions\CommentSettings;
use App\Modules\Social\Comments\Actions\CommentSources;
use App\Modules\Social\Comments\Actions\RuleManager;
use App\Modules\Social\Comments\Automation\ReplyTemplate;
use App\Modules\Social\Comments\Automation\RuleEngine;
use App\Modules\Social\Comments\Enums\RuleActionType;
use App\Modules\Social\Comments\Http\CommentPresenter;
use App\Modules\Social\Comments\Http\Requests\CommentRuleRequest;
use App\Modules\Social\Comments\Models\CommentRule;
use App\Modules\Social\Comments\Models\CommentSource;
use App\Modules\Social\Comments\Support\KeywordMatcher;
use App\Modules\Social\Http\Controllers\SocialSupport;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Keyword rules, the workspace kill switch and the per-Page comment connections. Needs `social.comments.manage_rules`. */
final class CommentRuleController
{
    public function __construct(
        private readonly RuleManager $rules,
        private readonly RuleEngine $engine,
        private readonly CommentSettings $settings,
        private readonly CommentSources $sources,
        private readonly CommentPresenter $presenter,
        private readonly SocialSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $this->support->actor($request);
        $settings = $this->settings->current();
        $sources = CommentSource::query()->get()->keyBy('account_id');

        return Inertia::render('Modules/Social/Pages/Comments/Rules', [
            'rules' => CommentRule::query()->with('actor.user')->orderBy('priority')->orderBy('name')->get()
                ->map(fn (CommentRule $rule): array => $this->presenter->rule($rule))->all(),
            'pages' => SocialAccount::query()->where('status', '!=', 'disconnected')->orderBy('name')->get()
                ->map(fn (SocialAccount $account): array => $this->presenter->source($account, $sources->get($account->id)))->all(),
            'paused' => $settings->automation_paused,
            'pausedAt' => $settings->paused_at?->toIso8601String(),
            'pageHourlyCap' => $settings->page_hourly_cap,
            'options' => [
                'accounts' => SocialAccount::query()->where('status', '!=', 'disconnected')->orderBy('name')->get(['id', 'name', 'platform'])
                    ->map(fn (SocialAccount $account): array => ['id' => $account->id, 'name' => $account->name, 'platform' => $account->platform])->all(),
                'posts' => Post::query()->latest('updated_at')->limit(100)->get(['id', 'title', 'body'])
                    ->map(fn (Post $post): array => ['id' => $post->id, 'name' => (string) ($post->title ?? Str::limit((string) $post->body, 60))])->all(),
                'campaigns' => Campaign::query()->orderBy('name')->get(['id', 'name'])->map(fn (Campaign $campaign): array => ['id' => $campaign->id, 'name' => $campaign->name])->all(),
                'members' => TenantMembership::query()->with('user:id,name')->where('status', MembershipStatus::Active->value)->get()
                    ->map(fn (TenantMembership $membership): array => $this->presenter->member($membership))->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
                'actions' => array_map(static fn (RuleActionType $type): string => $type->value, (array_filter(
                    RuleActionType::cases(),
                    static fn (RuleActionType $type): bool => $type !== RuleActionType::PrivateReply || (bool) config('social_comments.facebook.private_replies', false),
                ))),
                'variables' => ReplyTemplate::VARIABLES,
            ],
            'limits' => [
                'keywords' => KeywordMatcher::MAX_KEYWORDS,
                'keyword' => KeywordMatcher::MAX_KEYWORD_LENGTH,
                'template' => (int) config('social_comments.limits.template_max', 500),
                'templates' => (int) config('social_comments.limits.templates_max', 5),
                'tag' => (int) config('social_comments.limits.tag_max', 40),
                'rules' => (int) config('social_comments.limits.rules_max', 50),
                'previewText' => (int) config('social_comments.limits.preview_text_max', 2000),
            ],
        ]);
    }

    public function store(CommentRuleRequest $request): RedirectResponse
    {
        $this->rules->save(null, $request->validated(), $this->support->membership($this->support->actor($request)));
        Toast::success(__('Rule saved. Switch it on when you are ready.'));

        return redirect()->route('social.comments.rules.index');
    }

    public function update(CommentRuleRequest $request, string $rule): RedirectResponse
    {
        $this->rules->save(CommentRule::query()->findOrFail($rule), $request->validated(), $this->support->membership($this->support->actor($request)));
        Toast::success(__('Rule saved.'));

        return redirect()->route('social.comments.rules.index');
    }

    public function toggle(Request $request, string $rule): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->rules->setEnabled(CommentRule::query()->findOrFail($rule), (bool) $data['enabled'], $this->support->membership($this->support->actor($request)));
        Toast::success($data['enabled'] ? __('Rule switched on.') : __('Rule switched off.'));

        return back();
    }

    public function destroy(Request $request, string $rule): RedirectResponse
    {
        $this->support->actor($request);
        $this->rules->delete(CommentRule::query()->findOrFail($rule));
        Toast::success(__('Rule deleted.'));

        return redirect()->route('social.comments.rules.index');
    }

    /** The test box: what a draft rule would do with a sample comment. Saves nothing, sends nothing. */
    public function preview(Request $request): JsonResponse
    {
        $this->support->actor($request);
        $max = (int) config('social_comments.limits.preview_text_max', 2000);
        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.$max],
            'author_name' => ['nullable', 'string', 'max:80'],
            'page_name' => ['nullable', 'string', 'max:80'],
            'keywords' => ['nullable', 'array', 'max:'.KeywordMatcher::MAX_KEYWORDS],
            'keywords.*' => ['nullable', 'string', 'max:'.KeywordMatcher::MAX_KEYWORD_LENGTH],
            'match_mode' => ['nullable', 'in:any,all'],
            'match_type' => ['nullable', 'in:word,contains'],
            'actions' => ['nullable', 'array', 'max:6'],
            'actions.*.type' => ['required', 'string', 'max:24'],
            'actions.*.message' => ['nullable', 'string', 'max:2000'],
            'actions.*.tag' => ['nullable', 'string', 'max:200'],
            'reply_templates' => ['nullable', 'array', 'max:10'],
            'reply_templates.*' => ['nullable', 'string', 'max:2000'],
            'quiet_start' => ['nullable', 'string', 'max:5'],
            'quiet_end' => ['nullable', 'string', 'max:5'],
        ]);

        return response()->json($this->engine->preview(
            $data,
            (string) $data['text'],
            isset($data['author_name']) ? (string) $data['author_name'] : null,
            isset($data['page_name']) && $data['page_name'] !== '' ? (string) $data['page_name'] : 'Your Page',
        ));
    }

    public function automation(Request $request): RedirectResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $this->settings->setPaused((bool) $data['paused'], $this->support->membership($this->support->actor($request)));
        Toast::success($data['paused'] ? __('All comment rules are paused.') : __('Comment rules are running again.'));

        return back();
    }

    public function limits(Request $request): RedirectResponse
    {
        $this->support->actor($request);
        $data = $request->validate(['page_hourly_cap' => ['required', 'integer', 'min:1', 'max:500']]);
        $this->settings->updateCap((int) $data['page_hourly_cap']);
        Toast::success(__('Limit saved.'));

        return back();
    }

    public function enableSource(Request $request, string $account): RedirectResponse
    {
        $this->support->actor($request);
        $source = $this->sources->enable(SocialAccount::query()->findOrFail($account));
        $source->webhook_state === CommentSource::SUBSCRIBED
            ? Toast::success(__('Comments are on for this Page.'))
            : Toast::warning(__('Comments are on, using regular checks. The live connection to Facebook could not be set up yet.'));

        return back();
    }

    public function disableSource(Request $request, string $account): RedirectResponse
    {
        $this->support->actor($request);
        $this->sources->disable(SocialAccount::query()->findOrFail($account));
        Toast::success(__('Comments are off for this Page.'));

        return back();
    }
}
