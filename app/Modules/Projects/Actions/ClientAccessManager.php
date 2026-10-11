<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\TenantContext;
use App\Modules\Projects\Enums\ClientAccessStatus;
use App\Modules\Projects\Models\ClientAccess;
use App\Modules\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClientAccessManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly ActivityRecorder $activity, private readonly TenantContext $context) {}

    /** @return array{access: ClientAccess, token: string} */
    public function grant(Project $project, Contact $contact, ?Company $company, bool $canComment, bool $canViewFinancials, ?\DateTimeInterface $expiresAt, ?TenantMembership $actor = null): array
    {
        $tenantId = $this->context->requireId();

        if ($project->tenant_id !== $tenantId || $contact->tenant_id !== $tenantId || ($company !== null && $company->tenant_id !== $tenantId) || ($actor !== null && ($actor->tenant_id !== $tenantId || $actor->status !== MembershipStatus::Active))) {
            throw ValidationException::withMessages(['contact_id' => __('The selected client is unavailable.')]);
        }

        if ($company !== null && $contact->company_id !== null && $contact->company_id !== $company->id) {
            throw ValidationException::withMessages(['company_id' => __('The contact does not belong to the selected company.')]);
        }

        $token = bin2hex(random_bytes(32));

        return DB::transaction(function () use ($project, $contact, $company, $canComment, $canViewFinancials, $expiresAt, $actor, $token): array {
            /** @var ClientAccess $access */
            $access = ClientAccess::query()->updateOrCreate(
                ['project_id' => $project->id, 'contact_id' => $contact->id],
                ['company_id' => $company?->id, 'status' => ClientAccessStatus::Invited, 'token_hash' => hash('sha256', $token), 'can_comment' => $canComment, 'can_view_financials' => $canViewFinancials, 'invited_at' => now(), 'accepted_at' => null, 'expires_at' => $expiresAt, 'invited_by' => $actor?->id],
            );
            $this->activity->record($project->id, 'projects.client_access.granted', __('Client project access granted.'), $actor, metadata: ['client_access_id' => $access->id, 'contact_id' => $contact->id]);
            $this->audit->record('projects.client_access.granted', $access, ['project_id' => $project->id, 'contact_id' => $contact->id, 'can_comment' => $canComment, 'can_view_financials' => $canViewFinancials, 'expires_at' => $access->expires_at?->toIso8601String()]);

            return ['access' => $access, 'token' => $token];
        });
    }

    public function revoke(ClientAccess $access, ?TenantMembership $actor = null): void
    {
        if ($access->tenant_id !== $this->context->requireId() || ($actor !== null && ($actor->tenant_id !== $this->context->requireId() || $actor->status !== MembershipStatus::Active))) {
            throw ValidationException::withMessages(['client_access' => __('The selected client access is unavailable.')]);
        }

        DB::transaction(function () use ($access, $actor): void {
            $access->update(['status' => ClientAccessStatus::Revoked, 'token_hash' => null]);
            $this->activity->record($access->project_id, 'projects.client_access.revoked', __('Client project access revoked.'), $actor, metadata: ['client_access_id' => $access->id]);
            $this->audit->record('projects.client_access.revoked', $access, ['project_id' => $access->project_id]);
        });
    }

    public function resolve(string $token): ClientAccess
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            abort(404);
        }

        $access = DB::transaction(function () use ($token): ClientAccess {
            $access = ClientAccess::query()->where('token_hash', hash('sha256', $token))->whereIn('status', [ClientAccessStatus::Invited, ClientAccessStatus::Active])->lockForUpdate()->first();

            abort_if($access === null, 404);

            if ($access->expires_at !== null && $access->expires_at->isPast()) {
                $access->update(['status' => ClientAccessStatus::Expired, 'token_hash' => null]);

                return $access;
            }

            if ($access->status === ClientAccessStatus::Invited) {
                $access->update(['status' => ClientAccessStatus::Active, 'accepted_at' => now()]);
            }

            return $access;
        });

        abort_if($access->status === ClientAccessStatus::Expired, 404);

        Project::query()->findOrFail($access->project_id);
        Contact::query()->findOrFail($access->contact_id);
        $access->load(['project', 'contact', 'company']);

        return $access;
    }
}
