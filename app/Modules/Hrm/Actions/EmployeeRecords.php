<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\EmergencyContact;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAsset;
use App\Modules\Hrm\Models\EmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Emergency contacts, private documents and assets assigned to an employee. */
final class EmployeeRecords
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantStorage $storage,
        private readonly HrmSettings $settings,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function addContact(Employee $employee, array $attributes): EmergencyContact
    {
        return DB::transaction(function () use ($employee, $attributes): EmergencyContact {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if (EmergencyContact::query()->where('employee_id', $employee->id)->count() >= 10) {
                throw ValidationException::withMessages(['name' => __('An employee can have at most 10 emergency contacts.')]);
            }

            $primary = (bool) ($attributes['is_primary'] ?? false);
            $hasAny = EmergencyContact::query()->where('employee_id', $employee->id)->exists();
            $attributes['is_primary'] = $primary || ! $hasAny;

            if ($attributes['is_primary']) {
                EmergencyContact::query()->where('employee_id', $employee->id)->update(['is_primary' => false]);
            }

            return EmergencyContact::query()->create([...$attributes, 'employee_id' => $employee->id]);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function updateContact(EmergencyContact $contact, array $attributes): EmergencyContact
    {
        return DB::transaction(function () use ($contact, $attributes): EmergencyContact {
            if ((bool) ($attributes['is_primary'] ?? false)) {
                EmergencyContact::query()->where('employee_id', $contact->employee_id)->whereKeyNot($contact->id)->update(['is_primary' => false]);
            }

            $contact->fill($attributes)->save();

            return $contact;
        });
    }

    public function deleteContact(EmergencyContact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            $employeeId = $contact->employee_id;
            $wasPrimary = $contact->is_primary;
            $contact->delete();

            if ($wasPrimary) {
                EmergencyContact::query()->where('employee_id', $employeeId)->orderBy('created_at')->limit(1)->update(['is_primary' => true]);
            }
        });
    }

    /** @param array{type: string, title: string, expires_on?: string|null} $attributes */
    public function addDocument(Employee $employee, UploadedFile $file, array $attributes, ?TenantMembership $actor = null): EmployeeDocument
    {
        $path = $this->storage->putFile("hrm/{$employee->id}/documents", $file);

        try {
            return DB::transaction(function () use ($employee, $file, $attributes, $actor, $path): EmployeeDocument {
                $document = EmployeeDocument::query()->create([
                    'employee_id' => $employee->id,
                    'type' => $attributes['type'],
                    'title' => $attributes['title'],
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size' => (int) $file->getSize(),
                    'expires_on' => $attributes['expires_on'] ?? null,
                    'uploaded_by' => $actor?->id,
                ]);
                $this->audit->record('hrm.document.uploaded', $employee, ['title' => $document->title, 'type' => $document->type->value, 'size' => $document->size]);

                return $document;
            });
        } catch (Throwable $exception) {
            $this->storage->delete($path);

            throw $exception;
        }
    }

    public function deleteDocument(EmployeeDocument $document): void
    {
        $path = $document->path;

        DB::transaction(function () use ($document): void {
            $this->audit->record('hrm.document.removed', $document->employee, ['title' => $document->title]);
            $document->delete();
        });

        $this->storage->delete($path);
    }

    public function documentContents(EmployeeDocument $document): ?string
    {
        return $this->storage->get($document->path);
    }

    /** @param array<string, mixed> $attributes */
    public function assignAsset(Employee $employee, array $attributes, ?TenantMembership $actor = null): EmployeeAsset
    {
        if ($employee->status === EmployeeStatus::Terminated) {
            throw ValidationException::withMessages(['name' => __('Assets cannot be assigned to a terminated employee.')]);
        }

        return DB::transaction(function () use ($employee, $attributes, $actor): EmployeeAsset {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $tag = isset($attributes['asset_tag']) && $attributes['asset_tag'] !== '' ? (string) $attributes['asset_tag'] : null;

            if ($tag !== null && EmployeeAsset::query()->where('asset_tag', $tag)->whereNull('returned_on')->exists()) {
                throw ValidationException::withMessages(['asset_tag' => __('This asset is already assigned.')]);
            }

            $asset = EmployeeAsset::query()->create([
                ...$attributes,
                'asset_tag' => $tag,
                'employee_id' => $employee->id,
                'assigned_on' => $attributes['assigned_on'] ?? $this->settings->today()->toDateString(),
                'assigned_by' => $actor?->id,
            ]);
            $this->audit->record('hrm.asset.assigned', $asset, ['employee_id' => $employee->id, 'asset_tag' => $tag]);

            return $asset;
        });
    }

    /** @param array{returned_on?: string|null, return_condition?: string|null, notes?: string|null} $attributes */
    public function returnAsset(EmployeeAsset $asset, array $attributes, ?TenantMembership $actor = null): EmployeeAsset
    {
        return DB::transaction(function () use ($asset, $attributes, $actor): EmployeeAsset {
            /** @var EmployeeAsset $locked */
            $locked = EmployeeAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isHeld()) {
                throw ValidationException::withMessages(['returned_on' => __('This asset has already been returned.')]);
            }

            $returned = (string) ($attributes['returned_on'] ?? $this->settings->today()->toDateString());

            if ($returned < $locked->assigned_on->toDateString()) {
                throw ValidationException::withMessages(['returned_on' => __('The return date cannot be before the assignment date.')]);
            }

            $locked->update([
                'returned_on' => $returned,
                'return_condition' => $attributes['return_condition'] ?? null,
                'notes' => $attributes['notes'] ?? $locked->notes,
                'returned_by' => $actor?->id,
            ]);
            $this->audit->record('hrm.asset.returned', $locked, ['employee_id' => $locked->employee_id, 'returned_on' => $returned]);

            return $locked;
        });
    }
}
