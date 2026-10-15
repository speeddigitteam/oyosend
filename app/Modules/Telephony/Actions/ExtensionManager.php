<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Telephony\Models\Extension;
use Illuminate\Support\Facades\DB;

/** Gives a member a SIP extension (and optionally their own phone for bridge calls). The password is write-only. */
final class ExtensionManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function save(TenantMembership $member, string $extension, ?string $password, ?string $bridgePhone): Extension
    {
        return DB::transaction(function () use ($member, $extension, $password, $bridgePhone): Extension {
            $record = Extension::query()->firstOrNew(['membership_id' => $member->id]);
            $record->extension = $extension;
            $record->bridge_phone = $bridgePhone;

            if ($password !== null && $password !== '') {
                $record->sip_password = $password;
            }

            $created = ! $record->exists;
            $record->save();
            $this->audit->record($created ? 'telephony.extension.created' : 'telephony.extension.updated', $record, ['extension' => $extension]);

            return $record;
        });
    }

    public function delete(Extension $extension): void
    {
        DB::transaction(function () use ($extension): void {
            $this->audit->record('telephony.extension.deleted', $extension, ['extension' => $extension->extension]);
            $extension->delete();
        });
    }
}
