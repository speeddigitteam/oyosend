<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Support;

use App\Core\Support\Like;
use App\Modules\EmailMarketing\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Declarative segment filters over the members of one list.
 *
 * A rule is `{field, operator, value}` taken from fixed allow-lists; the query is built with bound parameters.
 * Contact fields are read from the shared contact, `property:<key>` from the member's per-app properties
 * (the key is a strict identifier, so it can be placed in a JSON path). Nothing is evaluated or interpreted.
 */
final class SegmentRules
{
    public const int MAX_RULES = 10;

    public const string PROPERTY_KEY = '/^[a-z][a-z0-9_]{0,31}$/';

    private const array TEXT = ['equals', 'not_equals', 'contains', 'exists', 'not_exists'];

    /** @var array<string, list<string>> */
    public const array FIELDS = [
        'email_domain' => ['equals', 'not_equals'],
        'city' => self::TEXT,
        'country' => self::TEXT,
        'job_title' => self::TEXT,
        'has_company' => ['is_true', 'is_false'],
        'source' => ['equals', 'not_equals'],
        'subscribed_after' => ['on_or_after'],
        'subscribed_before' => ['on_or_before'],
    ];

    /**
     * @return list<array{field: string, operator: string, value: string|null}>
     *
     * @throws ValidationException
     */
    public static function normalize(mixed $rules, string $field = 'rules'): array
    {
        if (! is_array($rules) || ! array_is_list($rules) || count($rules) < 1 || count($rules) > self::MAX_RULES) {
            throw ValidationException::withMessages([$field => __('Add between 1 and :count rules.', ['count' => self::MAX_RULES])]);
        }

        $clean = [];

        foreach ($rules as $index => $rule) {
            $path = $field.'.'.$index;
            $name = is_array($rule) && is_string($rule['field'] ?? null) ? $rule['field'] : '';
            $operator = is_array($rule) && is_string($rule['operator'] ?? null) ? $rule['operator'] : '';
            $value = is_array($rule) && is_scalar($rule['value'] ?? null) ? ContentBlocks::line($rule['value']) : '';

            $operators = self::FIELDS[$name] ?? null;

            if ($operators === null && str_starts_with($name, 'property:') && preg_match(self::PROPERTY_KEY, substr($name, 9)) === 1) {
                $operators = self::TEXT;
            }

            if ($operators === null || ! in_array($operator, $operators, true)) {
                throw ValidationException::withMessages([$path => __('This rule is not supported.')]);
            }

            $needsValue = ! in_array($operator, ['exists', 'not_exists', 'is_true', 'is_false'], true);

            if ($needsValue && ($value === '' || mb_strlen($value) > 100)) {
                throw ValidationException::withMessages([$path => __('Enter a value of up to 100 characters.')]);
            }

            if (in_array($operator, ['on_or_after', 'on_or_before'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                throw ValidationException::withMessages([$path => __('Enter a date as YYYY-MM-DD.')]);
            }

            if ($name === 'source' && ! in_array($value, ['manual', 'import', 'automation'], true)) {
                throw ValidationException::withMessages([$path => __('This rule is not supported.')]);
            }

            $clean[] = ['field' => $name, 'operator' => $operator, 'value' => $needsValue ? $value : null];
        }

        return $clean;
    }

    /**
     * Narrow a subscriptions query to the segment.
     *
     * @param  Builder<Subscription>  $query
     * @param  list<array{field: string, operator: string, value: string|null}>  $rules
     */
    public static function apply(Builder $query, array $rules, string $match): void
    {
        if ($rules === []) {
            return;
        }

        $query->where(function (Builder $group) use ($rules, $match): void {
            foreach ($rules as $rule) {
                $method = $match === 'any' ? 'orWhere' : 'where';
                $group->{$method}(fn (Builder $one) => self::condition($one, $rule));
            }
        });
    }

    /**
     * @param  Builder<Subscription>  $query
     * @param  array{field: string, operator: string, value: string|null}  $rule
     */
    private static function condition(Builder $query, array $rule): void
    {
        $field = $rule['field'];
        $operator = $rule['operator'];
        $value = (string) $rule['value'];

        if (str_starts_with($field, 'property:')) {
            $path = 'properties->'.substr($field, 9);

            match ($operator) {
                'equals' => $query->where($path, $value),
                'not_equals' => $query->where(fn (Builder $q) => $q->whereNull($path)->orWhere($path, '!=', $value)),
                'contains' => $query->where($path, 'like', Like::contains($value)),
                'exists' => $query->whereNotNull($path)->where($path, '!=', ''),
                default => $query->where(fn (Builder $q) => $q->whereNull($path)->orWhere($path, '')),
            };

            return;
        }

        switch ($field) {
            case 'source':
                $operator === 'equals' ? $query->where('source', $value) : $query->where('source', '!=', $value);

                return;
            case 'subscribed_after':
                $query->whereDate('subscribed_at', '>=', $value);

                return;
            case 'subscribed_before':
                $query->whereDate('subscribed_at', '<=', $value);

                return;
        }

        $query->whereHas('contact', function (Builder $contact) use ($field, $operator, $value): void {
            if ($field === 'email_domain') {
                $pattern = '%@'.Like::escape(ltrim(strtolower($value), '@'));
                $operator === 'equals' ? $contact->where('email', 'like', $pattern) : $contact->where(fn (Builder $q) => $q->whereNull('email')->orWhere('email', 'not like', $pattern));

                return;
            }

            if ($field === 'has_company') {
                $operator === 'is_true' ? $contact->whereNotNull('company_id') : $contact->whereNull('company_id');

                return;
            }

            // city, country, job_title: the field name is one of the three keys above, never user text.
            match ($operator) {
                'equals' => $contact->where($field, $value),
                'not_equals' => $contact->where(fn (Builder $q) => $q->whereNull($field)->orWhere($field, '!=', $value)),
                'contains' => $contact->where($field, 'like', Like::contains($value)),
                'exists' => $contact->whereNotNull($field)->where($field, '!=', ''),
                default => $contact->where(fn (Builder $q) => $q->whereNull($field)->orWhere($field, '')),
            };
        });
    }
}
