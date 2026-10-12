<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Modules\Social\Enums\CampaignStatus;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\Category;
use App\Modules\Social\Models\HashtagSet;
use App\Modules\Social\Support\HashtagNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** Campaigns, categories and hashtag sets: small per-workspace lists a post can be filed under. */
final class LibraryManager
{
    /** @param array<string, mixed> $attributes */
    public function saveCampaign(?Campaign $campaign, array $attributes): Campaign
    {
        $campaign ??= new Campaign;
        $campaign->fill([
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'status' => CampaignStatus::from((string) ($attributes['status'] ?? CampaignStatus::Active->value)),
            'starts_on' => $attributes['starts_on'] ?? null,
            'ends_on' => $attributes['ends_on'] ?? null,
        ]);

        return $this->persist($campaign);
    }

    public function deleteCampaign(Campaign $campaign): void
    {
        $campaign->delete();
    }

    public function saveCategory(?Category $category, string $name): Category
    {
        $category ??= new Category;
        $category->fill(['name' => $name]);

        return $this->persist($category);
    }

    public function deleteCategory(Category $category): void
    {
        $category->delete();
    }

    /** @param string|list<string> $tags */
    public function saveHashtagSet(?HashtagSet $set, string $name, string|array $tags): HashtagSet
    {
        $set ??= new HashtagSet;
        $set->fill(['name' => $name, 'tags' => HashtagNormalizer::normalize($tags)]);

        return $this->persist($set);
    }

    public function deleteHashtagSet(HashtagSet $set): void
    {
        $set->delete();
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T  $model
     * @return T
     */
    private function persist($model)
    {
        try {
            $model->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('That name is already used.')]);
        }

        return $model;
    }
}
