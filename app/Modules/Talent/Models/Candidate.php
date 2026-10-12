<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\ConsentBasis;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $headline
 * @property string|null $current_company
 * @property string|null $location
 * @property string|null $source
 * @property int|null $expected_amount
 * @property string|null $currency
 * @property string|null $summary
 * @property ConsentBasis $consent_basis
 * @property Carbon|null $consent_given_at
 * @property Carbon|null $retain_until
 * @property Carbon|null $anonymized_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Candidate extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'talent_candidates';

    /** @var list<string> */
    protected $fillable = ['owner_id', 'department_id', 'branch_id', 'first_name', 'last_name', 'email', 'phone', 'headline', 'current_company', 'location', 'source', 'expected_amount', 'currency', 'summary', 'consent_basis', 'consent_given_at', 'retain_until', 'anonymized_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_amount' => 'integer',
            'consent_basis' => ConsentBasis::class,
            'consent_given_at' => 'datetime',
            'retain_until' => 'date',
            'anonymized_at' => 'datetime',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'candidate_id');
    }

    /** @return HasMany<CandidateDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(CandidateDocument::class, 'candidate_id');
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['email', 'phone', 'summary', 'expected_amount', 'headline', 'current_company', 'location'];
    }

    public function auditLabel(): string
    {
        return (string) ($this->anonymized_at === null ? $this->fullName() : 'Anonymized candidate');
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.candidate.'.$verb;
    }
}
