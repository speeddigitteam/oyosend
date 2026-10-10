<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Support\Like;
use App\Modules\CRM\Enums\CrmEntityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class SegmentQuery
{
    /** @var array<string, list<string>> */
    private const array ALLOWED = [
        'lead' => ['search', 'status', 'source_id', 'owner_id', 'min_score', 'max_score'],
        'deal' => ['search', 'status', 'pipeline_id', 'stage_id', 'owner_id', 'min_amount', 'max_amount'],
        'activity' => ['search', 'status', 'type', 'owner_id'],
        'contact' => ['search', 'company_id', 'city', 'country'],
        'company' => ['search', 'industry', 'city', 'country'],
    ];

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @param  array<string, scalar|null>  $filters
     * @return Builder<T>
     */
    public function apply(Builder $query, CrmEntityType $type, array $filters): Builder
    {
        self::validateFilters($type, $filters);

        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            match ($key) {
                'search' => $query->where(function (Builder $match) use ($type, $value): void {
                    $pattern = Like::contains((string) $value);
                    match ($type) {
                        CrmEntityType::Lead, CrmEntityType::Deal => $match->where('title', 'like', $pattern),
                        CrmEntityType::Activity => $match->where('subject', 'like', $pattern),
                        CrmEntityType::Contact => $match->where('first_name', 'like', $pattern)
                            ->orWhere('last_name', 'like', $pattern)
                            ->orWhere('email', 'like', $pattern),
                        CrmEntityType::Company => $match->where('name', 'like', $pattern)
                            ->orWhere('email', 'like', $pattern),
                    };
                }),
                'min_score' => $query->where('score', '>=', (int) $value),
                'max_score' => $query->where('score', '<=', (int) $value),
                'min_amount' => $query->where('amount', '>=', (int) $value),
                'max_amount' => $query->where('amount', '<=', (int) $value),
                default => $query->where($key, $value),
            };
        }

        return $query;
    }

    /** @param array<string, scalar|null> $filters */
    public static function validateFilters(CrmEntityType $type, array $filters): void
    {
        $unknown = array_diff(array_keys($filters), self::ALLOWED[$type->value]);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['filters' => __('The segment contains unsupported filters.')]);
        }
    }
}
