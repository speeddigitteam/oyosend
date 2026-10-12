<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Modules\WhatsappMarketing\Models\Setting;
use App\Modules\WhatsappMarketing\Support\Phone;
use App\Modules\WhatsappMarketing\Support\Text;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/** The workspace's single settings row, created on first use. */
final class SettingsManager
{
    public function current(): Setting
    {
        $setting = Setting::query()->first();

        if ($setting !== null) {
            return $setting;
        }

        try {
            return Setting::query()->create([
                'auto_assign' => false,
                'auto_create_contacts' => false,
                'bots_enabled' => true,
            ])->refresh();
        } catch (UniqueConstraintViolationException) {
            return Setting::query()->firstOrFail();
        }
    }

    /**
     * @param  array{business_name?: string|null, display_phone?: string|null, auto_assign?: bool, auto_create_contacts?: bool, bots_enabled?: bool}  $input
     */
    public function update(array $input): Setting
    {
        $setting = $this->current();
        $phone = $input['display_phone'] ?? null;

        if ($phone !== null && $phone !== '' && Phone::e164($phone) === null) {
            throw ValidationException::withMessages(['display_phone' => __('Enter the number with its country code, for example +8801711000000.')]);
        }

        $name = $input['business_name'] ?? null;

        $setting->fill([
            'business_name' => $name === null ? null : (Text::clean($name, 120, false) ?: null),
            'display_phone' => $phone === null || $phone === '' ? null : Phone::e164($phone),
            'auto_assign' => (bool) ($input['auto_assign'] ?? $setting->auto_assign),
            'auto_create_contacts' => (bool) ($input['auto_create_contacts'] ?? $setting->auto_create_contacts),
            'bots_enabled' => (bool) ($input['bots_enabled'] ?? $setting->bots_enabled),
        ])->save();

        return $setting;
    }
}
