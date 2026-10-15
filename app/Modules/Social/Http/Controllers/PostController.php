<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Actions\PostWorkflow;
use App\Modules\Social\Actions\SocialSettings;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Http\Requests\PostRequest;
use App\Modules\Social\Http\Requests\RejectPostRequest;
use App\Modules\Social\Http\Requests\RescheduleRequest;
use App\Modules\Social\Http\Requests\ScheduleRequest;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Support\CsvSafe;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PostController
{
    public const string VIEW = 'social.posts.view';

    public const string CREATE = 'social.posts.create';

    public const string EDIT = 'social.posts.edit';

    public const string DELETE = 'social.posts.delete';

    public const string SCHEDULE = 'social.posts.schedule';

    public const string APPROVE = 'social.posts.approve';

    public function __construct(
        private readonly RecordAccess $records,
        private readonly PostManager $posts,
        private readonly PostWorkflow $workflow,
        private readonly SocialSupport $support,
        private readonly SocialPresenter $presenter,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PostStatus::class)],
            'campaign_id' => ['nullable', 'string', 'size:26'],
            'category_id' => ['nullable', 'string', 'size:26'],
            'account_id' => ['nullable', 'string', 'size:26'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $posts = $this->records->constrain(Post::query(), $actor, self::VIEW)
            ->with(['targets.account', 'campaign', 'category'])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['campaign_id'] ?? null, fn ($query, string $id) => $query->where('campaign_id', $id))
            ->when($filters['category_id'] ?? null, fn ($query, string $id) => $query->where('category_id', $id))
            ->when($filters['account_id'] ?? null, fn ($query, string $id) => $query->whereHas('targets', fn ($targets) => $targets->where('account_id', $id)))
            ->when($filters['q'] ?? null, fn ($query, string $term) => $query->where(function ($match) use ($term): void {
                $match->where('title', 'like', Like::contains($term))->orWhere('body', 'like', Like::contains($term));
            }))
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Post $post): array => $this->presenter->summary($post));

        $options = $this->support->formOptions();

        return Inertia::render('Modules/Social/Pages/Posts/Index', [
            'posts' => $posts,
            'filters' => [
                'status' => $filters['status'] ?? null,
                'campaign_id' => $filters['campaign_id'] ?? null,
                'category_id' => $filters['category_id'] ?? null,
                'account_id' => $filters['account_id'] ?? null,
                'q' => $filters['q'] ?? null,
            ],
            'statuses' => array_map(static fn (PostStatus $status): string => $status->value, PostStatus::cases()),
            'campaigns' => $options['campaigns'],
            'categories' => $options['categories'],
            'accounts' => $options['accounts'],
            'canCreate' => $actor->can(self::CREATE),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->support->actor($request);

        return Inertia::render('Modules/Social/Pages/Posts/Form', [
            'post' => null,
            'form' => $this->support->formOptions(),
        ]);
    }

    public function store(PostRequest $request): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->support->assertAccounts($actor, 'publish', array_column($this->targets($request), 'account_id'));
        $post = $this->posts->create(
            $this->attributes($request),
            $this->targets($request),
            $this->mediaIds($request),
            $this->support->membership($actor),
        );
        Toast::success(__('Post saved as a draft.'));

        return redirect()->route('social.posts.show', ['post' => $post->id]);
    }

    public function show(Request $request, string $post): Response
    {
        $actor = $this->support->actor($request);
        $record = $this->support->post($actor, $post, self::VIEW);

        return Inertia::render('Modules/Social/Pages/Posts/Show', [
            'post' => $this->presenter->detail($record, $actor),
        ]);
    }

    public function edit(Request $request, string $post): Response|RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->post($actor, $post, self::EDIT);

        if (! $record->status->isEditable()) {
            Toast::warning(__('This post can no longer be edited.'));

            return redirect()->route('social.posts.show', ['post' => $record->id]);
        }

        return Inertia::render('Modules/Social/Pages/Posts/Form', [
            'post' => $this->presenter->detail($record, $actor),
            'form' => $this->support->formOptions(),
        ]);
    }

    public function update(PostRequest $request, string $post): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->post($actor, $post, self::EDIT);

        if ($request->has('targets')) {
            $this->support->assertAccounts($actor, 'publish', array_column($this->targets($request), 'account_id'));
        }

        $updated = $this->posts->update(
            $record,
            $this->attributes($request),
            $request->has('targets') ? $this->targets($request) : null,
            $request->has('media_ids') ? $this->mediaIds($request) : null,
        );
        Toast::success($updated->status === PostStatus::Draft && $record->status !== PostStatus::Draft
            ? __('Post saved. It is a draft again and needs to be reviewed or scheduled.')
            : __('Post saved.'));

        return redirect()->route('social.posts.show', ['post' => $record->id]);
    }

    public function destroy(Request $request, string $post): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->posts->delete($this->support->post($actor, $post, self::DELETE));
        Toast::success(__('Post deleted.'));

        return redirect()->route('social.posts.index');
    }

    public function submit(Request $request, string $post): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->workflow->submit($this->support->post($actor, $post, self::SCHEDULE), $this->support->membership($actor));
        Toast::success(__('Post submitted for approval.'));

        return back();
    }

    public function approve(Request $request, string $post): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->post($actor, $post, self::APPROVE);
        $approved = $this->workflow->approve($record, $this->support->membership($actor));
        Toast::success($approved->status === PostStatus::Scheduled ? __('Post approved and scheduled.') : __('Post approved.'));

        return back();
    }

    public function reject(RejectPostRequest $request, string $post): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->post($actor, $post, self::APPROVE);
        $this->workflow->reject($record, $this->support->membership($actor), (string) $request->validated('reason'));
        Toast::success(__('Post sent back to draft.'));

        return back();
    }

    public function schedule(ScheduleRequest $request, string $post): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->post($actor, $post, self::SCHEDULE);
        $now = (bool) $request->boolean('publish_now');
        $this->workflow->schedule($record, $now ? null : $this->support->utc((string) $request->validated('scheduled_for')));
        Toast::success($now ? __('Post queued for publishing.') : __('Post scheduled.'));

        return back();
    }

    public function reschedule(RescheduleRequest $request, string $post): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->post($actor, $post, self::SCHEDULE);
        $at = $this->support->utc((string) $request->validated('scheduled_for')) ?? CarbonImmutable::now();
        $this->workflow->reschedule($record, $at);
        Toast::success(__('Post rescheduled.'));

        return back();
    }

    public function unschedule(Request $request, string $post): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $this->workflow->unschedule($this->support->post($actor, $post, self::SCHEDULE));
        Toast::success(__('Post returned to draft.'));

        return back();
    }

    public function retry(Request $request, string $post, string $target): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $record = $this->support->post($actor, $post, self::SCHEDULE);
        $row = PostTarget::query()->where('post_id', $record->id)->findOrFail($target);
        $this->workflow->retryTarget($record, $row);
        Toast::success(__('The post will be tried again shortly.'));

        return back();
    }

    public function export(Request $request): StreamedResponse
    {
        $actor = $this->support->actor($request);
        $rows = [];

        $this->records->constrain(Post::query(), $actor, self::VIEW)
            ->with(['targets.account', 'campaign', 'category'])
            ->orderBy('scheduled_for')
            ->limit(5000)
            ->get()
            ->each(function (Post $post) use (&$rows): void {
                $rows[] = [
                    $post->title,
                    $post->status->value,
                    $post->scheduled_for?->setTimezone(app(SocialSettings::class)->timezone())->format('Y-m-d H:i'),
                    $post->targets->map(fn (PostTarget $target): string => $target->account->name)->implode('; '),
                    $post->campaign?->name,
                    $post->category?->name,
                    $post->composedBody(),
                ];
            });

        $csv = CsvSafe::render(['title', 'status', 'scheduled_at', 'accounts', 'campaign', 'category', 'body'], $rows);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'social-posts.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    private function attributes(PostRequest $request): array
    {
        $input = $request->validated();

        return [
            ...(isset($input['post_type']) ? ['post_type' => (string) $input['post_type']] : []),
            'title' => $this->blank($input['title'] ?? null),
            'body' => $this->blank($input['body'] ?? null),
            'link_url' => $this->blank($input['link_url'] ?? null),
            'scheduled_for' => $this->support->utc($input['scheduled_for'] ?? null),
            'campaign_id' => $this->blank($input['campaign_id'] ?? null),
            'category_id' => $this->blank($input['category_id'] ?? null),
            'hashtag_set_id' => $this->blank($input['hashtag_set_id'] ?? null),
            ...((array) ($input['extra_hashtags'] ?? []) !== [] ? ['extra_hashtags' => array_values((array) $input['extra_hashtags'])] : []),
            ...(! empty($input['ai_assisted']) && $request->user('web')?->can('social.posts.ai_assist') ? ['ai_assisted' => true] : []),
        ];
    }

    /**
     * The chosen accounts. `options` is passed on only when the composer sent the key (null clears them), so a
     * request without it leaves a target's stored options as they are.
     *
     * @return list<array{account_id: string, body_override: string|null, options?: array<string, mixed>|null}>
     */
    private function targets(PostRequest $request): array
    {
        return array_values(array_map(static fn (array $target): array => [
            'account_id' => (string) $target['account_id'],
            'body_override' => isset($target['body_override']) ? (string) $target['body_override'] : null,
            ...(array_key_exists('options', $target) ? ['options' => is_array($target['options']) ? $target['options'] : null] : []),
        ], (array) ($request->validated('targets') ?? [])));
    }

    /** @return list<string> */
    private function mediaIds(PostRequest $request): array
    {
        return array_values(array_map('strval', (array) ($request->validated('media_ids') ?? [])));
    }

    private function blank(mixed $value): ?string
    {
        return $value === null || trim((string) $value) === '' ? null : (string) $value;
    }
}
