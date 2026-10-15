<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Support\Enums\VisitorDetails;
use App\Modules\Support\Models\ChatSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The website chat widget settings of a workspace. The public side only reads them ({@see find()}); a row is created
 * the first time a member with `support.settings.manage` saves them, and every save is audited.
 */
final class LiveChatSettings
{
    private const array AUDITED = ['enabled', 'allowed_origins', 'widget_title', 'greeting', 'visitor_details', 'offline_note'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** The saved settings, or null when the chat was never set up (it is then off). */
    public function find(): ?ChatSetting
    {
        return ChatSetting::query()->first();
    }

    /** The saved settings, or the defaults (off, optional visitor details) without writing anything. */
    public function forDisplay(): ChatSetting
    {
        return $this->find() ?? new ChatSetting([
            'enabled' => false,
            'allowed_origins' => [],
            'visitor_details' => VisitorDetails::Optional,
        ]);
    }

    /**
     * @param  array{enabled: bool, allowed_origins: list<string>, widget_title: string|null, greeting: string|null, visitor_details: VisitorDetails, offline_note: string|null}  $attributes
     */
    public function update(array $attributes): ChatSetting
    {
        try {
            return $this->save($attributes);
        } catch (UniqueConstraintViolationException) {
            // Two first saves at once: the other one created the row; apply this one on top of it.
            return $this->save($attributes);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function save(array $attributes): ChatSetting
    {
        return DB::transaction(function () use ($attributes): ChatSetting {
            $settings = ChatSetting::query()->lockForUpdate()->first() ?? new ChatSetting;
            $before = $settings->exists ? $this->snapshot($settings) : [];
            $settings->fill($attributes)->save();
            $after = $this->snapshot($settings);

            $changes = [];

            foreach ($after as $key => $value) {
                if (! array_key_exists($key, $before) || $before[$key] !== $value) {
                    $changes[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
                }
            }

            $this->audit->record('support.live_chat.settings_saved', $settings, ['changes' => $changes]);

            return $settings;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(ChatSetting $settings): array
    {
        $values = [];

        foreach (self::AUDITED as $key) {
            $value = $settings->getAttribute($key);
            $values[$key] = $value instanceof VisitorDetails ? $value->value : $value;
        }

        return $values;
    }
}
