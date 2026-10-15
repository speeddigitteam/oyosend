<?php

declare(strict_types=1);

namespace App\Modules\Links\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Links\Events\LinkCreated;
use App\Modules\Links\Models\Link;
use Illuminate\Support\Facades\DB;

final class LinkManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?TenantMembership $owner = null): Link
    {
        return DB::transaction(function () use ($attributes, $owner): Link {
            $link = new Link(['is_pinned' => false, ...$attributes]);
            $link->assignOwner($owner)->save();
            $this->audit->record('links.link.created', $link, ['kind' => $link->kind->value, 'is_pinned' => $link->is_pinned]);
            LinkCreated::dispatch($link);

            return $link;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Link $link, array $attributes): Link
    {
        return DB::transaction(function () use ($link, $attributes): Link {
            $link->fill($attributes);

            if ($link->isDirty()) {
                $changed = array_keys($link->getDirty());
                $link->save();
                $this->audit->record('links.link.updated', $link, ['changed' => $changed]);
            }

            return $link;
        });
    }

    public function delete(Link $link): void
    {
        DB::transaction(function () use ($link): void {
            $this->audit->record('links.link.deleted', $link);
            $link->delete();
        });
    }
}
