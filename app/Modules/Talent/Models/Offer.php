<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\OfferStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $application_id
 * @property OfferStatus $status
 * @property int|null $amount
 * @property string|null $currency
 * @property Carbon|null $proposed_start_on
 * @property Carbon|null $expires_on
 * @property Carbon|null $sent_at
 * @property Carbon|null $decided_at
 * @property string|null $notes
 * @property int|null $live_marker
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Offer extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_offers';

    /** @var list<string> */
    protected $fillable = ['application_id', 'status', 'amount', 'currency', 'proposed_start_on', 'expires_on', 'sent_at', 'decided_at', 'notes', 'live_marker', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'amount' => 'integer',
            'proposed_start_on' => 'date',
            'expires_on' => 'date',
            'sent_at' => 'datetime',
            'decided_at' => 'datetime',
            'live_marker' => 'integer',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id');
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['amount', 'notes'];
    }

    public function auditLabel(): string
    {
        return (string) ('Offer '.$this->id);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.offer.'.$verb;
    }
}
