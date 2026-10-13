<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Social\Comments\Models\CommentSetting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** The workspace comment settings, created with defaults on first use, and the global kill switch. */
final class CommentSettings
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function current(): CommentSetting
    {
        $existing = CommentSetting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return CommentSetting::query()->create(['page_hourly_cap' => (int) config('social_comments.automation.page_hourly_cap', 60)])->refresh();
        } catch (UniqueConstraintViolationException) {
            return CommentSetting::query()->firstOrFail();
        }
    }

    public function automationPaused(): bool
    {
        return (bool) CommentSetting::query()->value('automation_paused');
    }

    /** Pause or resume every comment rule of the workspace. Comments keep arriving; nothing acts on them. */
    public function setPaused(bool $paused, ?TenantMembership $by): CommentSetting
    {
        return DB::transaction(function () use ($paused, $by): CommentSetting {
            $settings = $this->current();
            $settings->fill([
                'automation_paused' => $paused,
                'paused_by' => $paused ? $by?->id : null,
                'paused_at' => $paused ? now() : null,
            ])->save();
            $this->audit->record($paused ? 'social.comments.automation_paused' : 'social.comments.automation_resumed', null, []);

            return $settings;
        });
    }

    public function updateCap(int $cap): CommentSetting
    {
        return DB::transaction(function () use ($cap): CommentSetting {
            $settings = $this->current();
            $settings->fill(['page_hourly_cap' => max(1, min(500, $cap))])->save();
            $this->audit->record('social.comments.settings_updated', null, ['page_hourly_cap' => $settings->page_hourly_cap]);

            return $settings;
        });
    }
}
