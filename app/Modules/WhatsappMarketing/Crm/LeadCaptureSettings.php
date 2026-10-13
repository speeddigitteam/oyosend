<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Crm;

use App\Modules\WhatsappMarketing\Models\LeadCaptureSetting;
use App\Modules\WhatsappMarketing\Support\Text;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The workspace's lead-capture configuration: one row per workspace, created on first use. Off by default, so
 * nothing reaches the CRM until an owner switches it on.
 */
final class LeadCaptureSettings
{
    public const int MAX_KEYWORDS = 20;

    public const int MAX_KEYWORD = 40;

    public function current(): LeadCaptureSetting
    {
        $setting = LeadCaptureSetting::query()->first();

        if ($setting !== null) {
            return $setting;
        }

        try {
            return LeadCaptureSetting::query()->create(['enabled' => false, 'keywords' => null])->refresh();
        } catch (UniqueConstraintViolationException) {
            return LeadCaptureSetting::query()->firstOrFail();
        }
    }

    /**
     * @param  array{enabled?: bool, keywords?: array<array-key, mixed>|null}  $input
     */
    public function update(array $input): LeadCaptureSetting
    {
        $setting = $this->current();
        $setting->fill([
            'enabled' => (bool) ($input['enabled'] ?? $setting->enabled),
            'keywords' => array_key_exists('keywords', $input) ? $this->keywords($input['keywords']) : $setting->keywords,
        ])->save();

        return $setting;
    }

    /**
     * Keywords, lower-cased, trimmed, de-duplicated and bounded. Empty input clears them.
     *
     * @param  array<array-key, mixed>|null  $raw
     * @return list<string>|null
     */
    private function keywords(?array $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $clean = [];

        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }

            $keyword = mb_strtolower(Text::clean($value, self::MAX_KEYWORD, false));

            if ($keyword !== '' && ! in_array($keyword, $clean, true)) {
                $clean[] = $keyword;
            }

            if (count($clean) >= self::MAX_KEYWORDS) {
                break;
            }
        }

        return $clean === [] ? null : $clean;
    }
}
