<?php

declare(strict_types=1);

namespace App\Modules\Social\Http\Controllers;

use App\Core\Support\Toast;
use App\Modules\Social\Actions\LibraryManager;
use App\Modules\Social\Enums\CampaignStatus;
use App\Modules\Social\Http\Requests\CampaignRequest;
use App\Modules\Social\Http\Requests\CategoryRequest;
use App\Modules\Social\Http\Requests\HashtagSetRequest;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\Category;
use App\Modules\Social\Models\HashtagSet;
use App\Modules\Social\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Campaigns, categories and hashtag sets. */
final class LibraryController
{
    public function __construct(
        private readonly LibraryManager $library,
        private readonly SocialSupport $support,
    ) {}

    public function index(Request $request): Response
    {
        $actor = $this->support->actor($request);
        $counts = Post::query()->selectRaw('campaign_id, count(*) as total')->whereNotNull('campaign_id')->groupBy('campaign_id')->pluck('total', 'campaign_id');
        $categoryCounts = Post::query()->selectRaw('category_id, count(*) as total')->whereNotNull('category_id')->groupBy('category_id')->pluck('total', 'category_id');

        return Inertia::render('Modules/Social/Pages/Library/Index', [
            'campaigns' => Campaign::query()->orderBy('status')->orderBy('name')->get()->map(fn (Campaign $campaign): array => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'description' => $campaign->description,
                'status' => $campaign->status->value,
                'startsOn' => $campaign->starts_on?->toDateString(),
                'endsOn' => $campaign->ends_on?->toDateString(),
                'posts' => (int) ($counts[$campaign->id] ?? 0),
            ])->all(),
            'categories' => Category::query()->orderBy('name')->get()->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'posts' => (int) ($categoryCounts[$category->id] ?? 0),
            ])->all(),
            'hashtagSets' => HashtagSet::query()->orderBy('name')->get()->map(fn (HashtagSet $set): array => [
                'id' => $set->id,
                'name' => $set->name,
                'tags' => $set->tags,
            ])->all(),
            'campaignStatuses' => array_map(static fn (CampaignStatus $status): string => $status->value, CampaignStatus::cases()),
            'canManage' => $actor->can('social.library.manage'),
        ]);
    }

    public function storeCampaign(CampaignRequest $request): RedirectResponse
    {
        $this->support->actor($request);
        $this->library->saveCampaign(null, $request->validated());
        Toast::success(__('Campaign created.'));

        return back();
    }

    public function updateCampaign(CampaignRequest $request, string $campaign): RedirectResponse
    {
        $this->support->actor($request);
        $this->library->saveCampaign(Campaign::query()->findOrFail($campaign), $request->validated());
        Toast::success(__('Campaign saved.'));

        return back();
    }

    public function destroyCampaign(Request $request, string $campaign): RedirectResponse
    {
        $this->support->actor($request);
        $this->library->deleteCampaign(Campaign::query()->findOrFail($campaign));
        Toast::success(__('Campaign deleted.'));

        return back();
    }

    public function storeCategory(CategoryRequest $request): RedirectResponse
    {
        $this->support->actor($request);
        $this->library->saveCategory(null, (string) $request->validated('name'));
        Toast::success(__('Category created.'));

        return back();
    }

    public function updateCategory(CategoryRequest $request, string $category): RedirectResponse
    {
        $this->support->actor($request);
        $this->library->saveCategory(Category::query()->findOrFail($category), (string) $request->validated('name'));
        Toast::success(__('Category saved.'));

        return back();
    }

    public function destroyCategory(Request $request, string $category): RedirectResponse
    {
        $this->support->actor($request);
        $this->library->deleteCategory(Category::query()->findOrFail($category));
        Toast::success(__('Category deleted.'));

        return back();
    }

    public function storeHashtagSet(HashtagSetRequest $request): RedirectResponse
    {
        $this->support->actor($request);
        $this->library->saveHashtagSet(null, (string) $request->validated('name'), $request->validated('tags'));
        Toast::success(__('Hashtag set created.'));

        return back();
    }

    public function updateHashtagSet(HashtagSetRequest $request, string $set): RedirectResponse
    {
        $this->support->actor($request);
        $this->library->saveHashtagSet(HashtagSet::query()->findOrFail($set), (string) $request->validated('name'), $request->validated('tags'));
        Toast::success(__('Hashtag set saved.'));

        return back();
    }

    public function destroyHashtagSet(Request $request, string $set): RedirectResponse
    {
        $this->support->actor($request);
        $this->library->deleteHashtagSet(HashtagSet::query()->findOrFail($set));
        Toast::success(__('Hashtag set deleted.'));

        return back();
    }
}
