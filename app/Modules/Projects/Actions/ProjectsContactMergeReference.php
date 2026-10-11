<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Core\Contacts\Contracts\ContactMergeReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Moves project-owned direct contact references during a core contact merge. */
final class ProjectsContactMergeReference implements ContactMergeReference
{
    public function merge(string $tenantId, string $canonicalId, string $duplicateId): array
    {
        $counts = [];

        if (Schema::hasTable('projects')) {
            $counts['projects'] = DB::table('projects')->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->update(['contact_id' => $canonicalId]);
        }

        if (Schema::hasTable('project_client_access')) {
            $duplicateRows = DB::table('project_client_access')
                ->where('tenant_id', $tenantId)
                ->where('contact_id', $duplicateId)
                ->get(['id', 'project_id']);
            $moved = 0;
            $movedComments = 0;

            foreach ($duplicateRows as $row) {
                $duplicateAccess = DB::table('project_client_access')
                    ->where('tenant_id', $tenantId)
                    ->where('id', $row->id);
                $canonicalAccessId = DB::table('project_client_access')
                    ->where('tenant_id', $tenantId)
                    ->where('project_id', $row->project_id)
                    ->where('contact_id', $canonicalId)
                    ->value('id');

                if (is_string($canonicalAccessId)) {
                    if (Schema::hasTable('project_task_comments')) {
                        $movedComments += DB::table('project_task_comments')
                            ->where('tenant_id', $tenantId)
                            ->where('client_access_id', $row->id)
                            ->update(['client_access_id' => $canonicalAccessId]);
                    }

                    $duplicateAccess->delete();
                } else {
                    $duplicateAccess->update(['contact_id' => $canonicalId]);
                }
                $moved++;
            }

            $counts['project_client_access'] = $moved;
            $counts['project_task_comments'] = $movedComments;
        }

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }
}
