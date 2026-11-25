<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Modules\Ai\Enums\Feature;
use App\Modules\Ai\Models\Setting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The workspace AI settings: master switch, per-feature switches, readable data scopes, retention and
 * PII redaction. Written only here; every change is audited by the model.
 */
final class AiSettings
{
    /** Data scopes enabled when a workspace first uses the app (privacy first: later scopes are opt-in). */
    public const array DEFAULT_SCOPES = ['contacts', 'companies'];

    public function current(): Setting
    {
        $existing = Setting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return Setting::query()->create([
                'enabled' => true,
                'chat_enabled' => true,
                'drafting_enabled' => true,
                'automation_enabled' => false,
                'voice_enabled' => false,
                'data_scopes' => self::DEFAULT_SCOPES,
                'retention_days' => (int) config('ai.retention.default_days', 90),
                'redact_pii' => false,
            ])->refresh();
        } catch (UniqueConstraintViolationException) {
            return Setting::query()->firstOrFail();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes): Setting
    {
        return DB::transaction(function () use ($attributes): Setting {
            $setting = $this->current();

            if (array_key_exists('data_scopes', $attributes)) {
                $known = app(ToolRegistry::class)->scopes();
                $attributes['data_scopes'] = array_values(array_unique(array_filter(
                    (array) $attributes['data_scopes'],
                    static fn ($scope): bool => is_string($scope) && in_array($scope, $known, true),
                )));
            }

            $setting->fill($attributes)->save();

            return $setting;
        });
    }

    public function allows(Feature $feature): bool
    {
        $setting = $this->current();

        if (! $setting->enabled) {
            return false;
        }

        // Strictly true: a switch that is missing or unreadable is off, never an error.
        return match ($feature) {
            Feature::Chat => $setting->chat_enabled === true,
            Feature::Drafting => $setting->drafting_enabled === true,
            Feature::Automation => $setting->automation_enabled === true,
            Feature::Voice => $setting->voice_enabled === true,
        };
    }

    public function redacts(): bool
    {
        return $this->current()->redact_pii || (bool) config('ai.redaction.enabled', false);
    }

    /**
     * @return list<string>
     */
    public function enabledScopes(): array
    {
        return array_map('strval', $this->current()->data_scopes);
    }
}
