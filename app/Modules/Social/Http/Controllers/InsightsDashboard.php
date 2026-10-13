<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Modules\Social\Actions\AccountAllowList;
use App\Modules\Social\Actions\PagePerformance;
use App\Modules\Social\Actions\SocialSettings;
use App\Modules\Social\Support\CsvSafe;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Request handling of the Page performance dashboard (period, page picker) and its CSV export.
 * Which accounts a person sees is decided by the account allow-list, never by the request.
 */
final class InsightsDashboard
{
    public function __construct(
        private readonly PagePerformance $performance,
        private readonly SocialSettings $settings,
        private readonly AccountAllowList $allowList,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function props(Request $request, User $actor): array
    {
        [$days, $pages] = $this->input($request);

        return $this->performance->dashboard($actor, $days, $pages);
    }

    /**
     * The accounts `$actor` may see analytics for, or null when no account is restricted.
     *
     * @return list<string>|null
     */
    public function allowedAccountIds(User $actor): ?array
    {
        return $this->allowList->analyticsScope($actor);
    }

    public function download(Request $request): StreamedResponse
    {
        $actor = $request->user('web');
        abort_unless($actor instanceof User, 401);

        return $this->export($request, $actor);
    }

    public function export(Request $request, User $actor): StreamedResponse
    {
        [$days, $pages] = $this->input($request);
        $validated = $request->validate(['dataset' => ['nullable', Rule::in(['daily', 'posts', 'campaigns'])]]);
        $dataset = is_string($validated['dataset'] ?? null) && $validated['dataset'] !== '' ? $validated['dataset'] : 'daily';

        $timezone = $this->settings->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = $today->subDays($days - 1);
        $accounts = $this->performance->accounts($actor);
        $ids = array_values(array_map(static fn ($account): string => $account->id, array_filter(
            $accounts,
            static fn ($account): bool => $pages === null || in_array($account->id, $pages, true),
        )));
        $fromAt = $from->startOfDay()->utc();
        $toAt = $today->endOfDay()->utc();

        if ($dataset === 'campaigns') {
            $header = [__('Campaign'), __('Posts'), __('Posts with figures'), __('Impressions'), __('Reach'), __('Likes'), __('Comments'), __('Shares'), __('Clicks'), __('Engagement')];
            $rows = array_map(static fn (array $row): array => [
                $row['name'] ?? __('No campaign'),
                $row['posts'], $row['reported'],
                $row['totals']['impressions'], $row['totals']['reach'], $row['totals']['likes'],
                $row['totals']['comments'], $row['totals']['shares'], $row['totals']['clicks'], $row['engagement'],
            ], $this->performance->campaigns($fromAt, $toAt, $ids, $timezone));
        } elseif ($dataset === 'posts') {
            $header = [__('Published'), __('Page'), __('Platform'), __('Post'), __('Type'), __('Engagement'), __('Impressions'), __('Reach'), __('Likes'), __('Comments'), __('Shares'), __('Clicks')];
            $rows = array_map(static fn (array $row): array => array_values($row), $this->performance->postRows($fromAt, $toAt, $ids, $timezone));
        } else {
            $header = [
                __('Date'), __('Page'), __('Platform'), __('Followers'), __('Page likes'), __('New followers'), __('Unfollows'),
                __('Impressions'), __('Reach'), __('Engaged users'), __('Post engagements'), __('Page views'), __('Reactions'), __('Video views'),
            ];
            $rows = array_map(static fn (array $row): array => array_values($row), $this->performance->dailyRows($ids, $from->format('Y-m-d'), $today->format('Y-m-d')));
        }

        $csv = CsvSafe::render($header, $rows);
        $name = 'social-'.$dataset.'-'.$today->format('Y-m-d').'.csv';

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * @return array{0: int, 1: list<string>|null}
     */
    private function input(Request $request): array
    {
        $input = $request->validate([
            'days' => ['nullable', 'integer', Rule::in(PagePerformance::PERIODS)],
            'pages' => ['nullable', 'array', 'max:'.PagePerformance::MAX_PAGES],
            'pages.*' => ['string', 'size:26'],
        ]);

        $pages = isset($input['pages']) ? array_values(array_map('strval', $input['pages'])) : null;

        return [(int) ($input['days'] ?? 28), $pages === [] ? null : $pages];
    }
}
