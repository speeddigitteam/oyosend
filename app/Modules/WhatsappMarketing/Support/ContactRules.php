<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Support;

use App\Core\Contacts\Models\Contact;
use App\Core\Support\Like;
use App\Modules\WhatsappMarketing\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Declarative rules that pick contacts for a WhatsApp list: shared contact fields and what this app knows about
 * the contact's conversation (its tags, when the customer last wrote). A rule is `{field, operator, value}` from
 * fixed allow-lists and becomes a query with bound parameters. Nothing is evaluated or interpreted.
 *
 * Consent is not a rule on purpose: whether someone may receive marketing is decided by the platform when a
 * campaign is snapshotted and again when each message is sent.
 */
final class ContactRules
{
    public const int MAX_RULES = 10;

    private const array TEXT = ['equals', 'not_equals', 'contains', 'exists', 'not_exists'];

    /** @var array<string, list<string>> */
    public const array FIELDS = [
        'city' => self::TEXT,
        'country' => self::TEXT,
        'job_title' => self::TEXT,
        'has_company' => ['is_true', 'is_false'],
        'created_after' => ['on_or_after'],
        'created_before' => ['on_or_before'],
        'conversation_tag' => ['equals', 'not_equals'],
        'wrote_within_days' => ['within'],
        'has_written' => ['is_true', 'is_false'],
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
            $value = is_array($rule) && is_scalar($rule['value'] ?? null) ? Text::clean((string) $rule['value'], 200, false) : '';
            $operators = self::FIELDS[$name] ?? null;

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

            if ($operator === 'within' && (preg_match('/^\d{1,3}$/', $value) !== 1 || (int) $value < 1 || (int) $value > 365)) {
                throw ValidationException::withMessages([$path => __('Enter a number of days from 1 to 365.')]);
            }

            if ($name === 'conversation_tag') {
                $value = strtolower($value);

                if (preg_match('/^[a-z0-9][a-z0-9_-]{0,29}$/', $value) !== 1) {
                    throw ValidationException::withMessages([$path => __('A tag uses lower-case letters, digits, dashes and underscores, up to 30 characters.')]);
                }
            }

            $clean[] = ['field' => $name, 'operator' => $operator, 'value' => $needsValue ? $value : null];
        }

        return $clean;
    }

    /**
     * Narrow a contacts query to the rules: every rule (`all`) or at least one (`any`).
     *
     * @param  Builder<Contact>  $query
     * @param  list<array{field: string, operator: string, value: string|null}>  $rules
     */
    public static function apply(Builder $query, array $rules, string $match): void
    {
        $query->where(function (Builder $group) use ($rules, $match): void {
            foreach ($rules as $rule) {
                $method = $match === 'any' ? 'orWhere' : 'where';
                $group->{$method}(fn (Builder $one) => self::condition($one, $rule));
            }
        });
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array{field: string, operator: string, value: string|null}  $rule
     */
    private static function condition(Builder $query, array $rule): void
    {
        $value = (string) $rule['value'];

        switch ($rule['field']) {
            case 'has_company':
                $rule['operator'] === 'is_true' ? $query->whereNotNull('company_id') : $query->whereNull('company_id');

                return;
            case 'created_after':
                $query->whereDate('created_at', '>=', $value);

                return;
            case 'created_before':
                $query->whereDate('created_at', '<=', $value);

                return;
            case 'conversation_tag':
                $tagged = Conversation::query()->whereNotNull('contact_id')->whereJsonContains('tags', $value)->select('contact_id');
                $rule['operator'] === 'equals' ? $query->whereIn('id', $tagged) : $query->whereNotIn('id', $tagged);

                return;
            case 'wrote_within_days':
                $query->whereIn('id', Conversation::query()
                    ->whereNotNull('contact_id')
                    ->where('last_inbound_at', '>=', CarbonImmutable::now()->subDays((int) $value))
                    ->select('contact_id'));

                return;
            case 'has_written':
                $written = Conversation::query()->whereNotNull('contact_id')->whereNotNull('last_inbound_at')->select('contact_id');
                $rule['operator'] === 'is_true' ? $query->whereIn('id', $written) : $query->whereNotIn('id', $written);

                return;
        }

        // city, country, job_title: a plain column of the shared contact.
        $column = $rule['field'];

        match ($rule['operator']) {
            'equals' => $query->where($column, $value),
            'not_equals' => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '!=', $value)),
            'contains' => $query->where($column, 'like', Like::contains($value)),
            'exists' => $query->whereNotNull($column)->where($column, '!=', ''),
            default => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '')),
        };
    }
}
