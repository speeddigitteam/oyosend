<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named list of shared contacts. Members are references to contacts, never copies.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property-read int|null $members_count
 */
class Group extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'sms_marketing_groups';

    /** @var list<string> */
    protected $fillable = ['name', 'description'];

    /** @return HasMany<GroupMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'group_id');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'sms_marketing.group.'.$verb;
    }
}
