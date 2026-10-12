<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $sequence_id
 * @property int $position
 * @property int $delay_minutes
 * @property string $subject
 * @property string|null $preheader
 * @property list<array<string, mixed>> $blocks
 */
class SequenceStep extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'email_marketing_sequence_steps';

    /** @var list<string> */
    protected $fillable = ['sequence_id', 'position', 'delay_minutes', 'subject', 'preheader', 'blocks'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer', 'delay_minutes' => 'integer', 'blocks' => 'array'];
    }

    /** @return BelongsTo<Sequence, $this> */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class, 'sequence_id');
    }
}
