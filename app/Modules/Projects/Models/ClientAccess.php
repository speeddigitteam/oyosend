<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Projects\Enums\ClientAccessStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string $contact_id
 * @property string|null $company_id
 * @property ClientAccessStatus $status
 * @property string|null $token_hash
 * @property bool $can_comment
 * @property bool $can_view_financials
 * @property Carbon|null $invited_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $expires_at
 * @property string|null $invited_by
 * @property-read Project $project
 * @property-read Contact $contact
 * @property-read Company|null $company
 */
class ClientAccess extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'project_client_access';

    protected $fillable = ['project_id', 'contact_id', 'company_id', 'status', 'token_hash', 'can_comment', 'can_view_financials', 'invited_at', 'accepted_at', 'expires_at', 'invited_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['status' => ClientAccessStatus::class, 'can_comment' => 'boolean', 'can_view_financials' => 'boolean', 'invited_at' => 'datetime', 'accepted_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
