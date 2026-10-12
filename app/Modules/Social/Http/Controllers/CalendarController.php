<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Access\RecordAccess;
use App\Modules\Social\Actions\SocialSettings;
use App\Modules\Social\Models\Post;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The content calendar: posts by their scheduled (or published) time in the workspace timezone. */
final class CalendarController
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly SocialSupport $support,
        private readonly SocialPresenter $presenter,
        private readonly SocialSettings $settings,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $input = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'campaign_id' => ['nullable', 'string', 'size:26'],
            'account_id' => ['nullable', 'string', 'size:26'],
        ]);

        $timezone = $this->settings->timezone();
        $month = isset($input['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', $input['month'], $timezone)
            : CarbonImmutable::now($timezone)->startOfMonth();
        $month = $month instanceof CarbonImmutable ? $month : CarbonImmutable::now($timezone)->startOfMonth();
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        $posts = $this->records->constrain(Post::query(), $actor, PostController::VIEW)
            ->with(['targets.account', 'campaign', 'category'])
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('scheduled_for', [$from->utc(), $to->utc()])
                    ->orWhere(fn ($published) => $published->whereNull('scheduled_for')->whereBetween('published_at', [$from->utc(), $to->utc()]));
            })
            ->when($input['campaign_id'] ?? null, fn ($query, string $id) => $query->where('campaign_id', $id))
            ->when($input['account_id'] ?? null, fn ($query, string $id) => $query->whereHas('targets', fn ($targets) => $targets->where('account_id', $id)))
            ->orderBy('scheduled_for')
            ->limit(500)
            ->get();

        $options = $this->support->formOptions();

        return Inertia::render('Modules/Social/Pages/Calendar/Index', [
            'month' => $from->format('Y-m'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'timezone' => $timezone,
            'posts' => $posts->map(fn (Post $post): array => $this->presenter->summary($post))->values()->all(),
            'filters' => ['campaign_id' => $input['campaign_id'] ?? null, 'account_id' => $input['account_id'] ?? null],
            'campaigns' => $options['campaigns'],
            'accounts' => $options['accounts'],
            'canCreate' => $actor->can(PostController::CREATE),
            'canSchedule' => $actor->can(PostController::SCHEDULE),
        ]);
    }
}
