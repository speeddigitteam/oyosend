<?php

declare(strict_types=1);

namespace App\Core\AppStore;

use App\Core\AppStore\Enums\AppSettingType;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** A compiled, typed field that an installed app lets a workspace configure. */
final readonly class AppSettingDefinition
{
    /**
     * @param  array<string, string>  $options  Stored value => human label, for select fields only.
     */
    public function __construct(
        public string $key,
        public string $label,
        public AppSettingType $type = AppSettingType::Text,
        public bool $required = false,
        public ?string $description = null,
        public string|int|bool|null $default = null,
        public array $options = [],
        public ?int $min = null,
        public ?int $max = null,
        public int $maxLength = 255,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1) {
            throw new InvalidArgumentException("Invalid app setting key [{$key}].");
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException("App setting [{$key}] needs a label.");
        }

        if ($maxLength < 1 || $maxLength > 10_000) {
            throw new InvalidArgumentException("App setting [{$key}] has an invalid maximum length.");
        }

        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException("App setting [{$key}] has an invalid numeric range.");
        }

        if ($type === AppSettingType::Select) {
            if ($options === []) {
                throw new InvalidArgumentException("Select app setting [{$key}] needs options.");
            }

            foreach ($options as $value => $optionLabel) {
                if (preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1 || trim($optionLabel) === '') {
                    throw new InvalidArgumentException("Select app setting [{$key}] has an invalid option.");
                }
            }
        } elseif ($options !== []) {
            throw new InvalidArgumentException("Only select app settings may declare options [{$key}].");
        }

        if ($type === AppSettingType::Secret && $default !== null) {
            throw new InvalidArgumentException("Secret app setting [{$key}] cannot have a compiled default.");
        }

        if ($default !== null && ! $this->accepts($default)) {
            throw new InvalidArgumentException("App setting [{$key}] has a default of the wrong type.");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'required' => $this->required,
            'description' => $this->description,
            'default' => $this->default,
            'options' => $this->options,
            'min' => $this->min,
            'max' => $this->max,
            'max_length' => $this->maxLength,
        ];
    }

    /** @return list<mixed> */
    public function validationRules(bool $hasValue): array
    {
        $mayKeepExistingSecret = $this->type === AppSettingType::Secret && $hasValue;
        $rules = [$this->required && ! $mayKeepExistingSecret ? 'required' : 'sometimes'];

        if (! $this->required || $mayKeepExistingSecret) {
            $rules[] = 'nullable';
        }

        return match ($this->type) {
            AppSettingType::Text, AppSettingType::Secret => [...$rules, 'string', 'max:'.$this->maxLength],
            AppSettingType::Integer => [
                ...$rules,
                'integer',
                ...($this->min === null ? [] : ['min:'.$this->min]),
                ...($this->max === null ? [] : ['max:'.$this->max]),
            ],
            AppSettingType::Boolean => [...$rules, 'boolean'],
            AppSettingType::Select => [...$rules, 'string', Rule::in(array_keys($this->options))],
        };
    }

    private function accepts(string|int|bool $value): bool
    {
        return match ($this->type) {
            AppSettingType::Text, AppSettingType::Secret => is_string($value) && strlen($value) <= $this->maxLength,
            AppSettingType::Integer => is_int($value)
                && ($this->min === null || $value >= $this->min)
                && ($this->max === null || $value <= $this->max),
            AppSettingType::Boolean => is_bool($value),
            AppSettingType::Select => is_string($value) && array_key_exists($value, $this->options),
        };
    }
}
