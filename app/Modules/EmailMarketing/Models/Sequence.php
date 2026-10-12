<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\EmailMarketing\Enums\SequenceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A drip or welcome series: ordered steps with delays, started for each contact that becomes a subscriber of
 * its list while the sequence is active.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string $list_id
 * @property SequenceStatus $status
 * @property Carbon|null $activated_at
 * @property-read MailingList $list
 */
class Sequence extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'email_marketing_sequences';

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'list_id', 'status', 'activated_at'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => SequenceStatus::class, 'activated_at' => 'datetime'];
    }

    /** @return BelongsTo<MailingList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(MailingList::class, 'list_id');
    }

    /** @return HasMany<SequenceStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class, 'sequence_id')->orderBy('position');
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'sequence_id');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'email_marketing.sequence.'.$verb;
    }
}
