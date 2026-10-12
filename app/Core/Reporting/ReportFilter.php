<?php

declare(strict_types=1);

namespace App\Core\Reporting;

use InvalidArgumentException;

/**
 * One filter a provider accepts. The declaration is the only thing a viewer's input is validated
 * against: a filter that is not declared is rejected, and a value is only ever a bound parameter.
 */
final readonly class ReportFilter
{
    /**
     * @param  array<string, string>  $options  For a select: value => English label.
     * @param  int|null  $min  For an integer.
     * @param  int|null  $max  For an integer.
     */
    public function __construct(
        public string $key,
        public string $label,
        public FilterType $type = FilterType::Text,
        public array $options = [],
        public ?int $min = null,
        public ?int $max = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) !== 1) {
            throw new InvalidArgumentException("Invalid report filter key [{$key}].");
        }

        if ($type === FilterType::Select && $options === []) {
            throw new InvalidArgumentException("Select filter [{$key}] needs options.");
        }
    }

    /**
     * Laravel validation rules for one value of this filter.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this->type) {
            FilterType::Select => ['nullable', 'string', 'in:'.implode(',', array_keys($this->options))],
            FilterType::Text => ['nullable', 'string', 'max:100'],
            FilterType::Currency => ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'],
            FilterType::Id => ['nullable', 'string', 'size:26', 'regex:/^[0-9A-Za-z]{26}$/'],
            FilterType::Integer => ['nullable', 'integer', 'min:'.($this->min ?? 0), 'max:'.($this->max ?? 1000000)],
            FilterType::Boolean => ['nullable', 'boolean'],
        };
    }
}
