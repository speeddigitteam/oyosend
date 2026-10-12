<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\EmailMarketing\Enums\ListStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named audience. Its members are {@see Subscription} rows that point at shared contacts.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property ListStatus $status
 * @property Carbon|null $created_at
 */
class MailingList extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'email_marketing_lists';

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'status'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => ListStatus::class];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'list_id');
    }

    /** @return HasMany<Segment, $this> */
    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class, 'list_id')->orderBy('name');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'email_marketing.list.'.$verb;
    }
}
