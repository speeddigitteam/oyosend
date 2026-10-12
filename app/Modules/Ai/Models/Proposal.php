<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Ai\Enums\ProposalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A change the assistant suggested and a person has not yet confirmed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $membership_id
 * @property string $tool
 * @property string $summary
 * @property array<string, mixed> $payload
 * @property ProposalStatus $status
 * @property array<string, mixed>|null $result
 * @property Carbon|null $decided_at
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 */
class Proposal extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'ai_proposals';

    /** @var list<string> */
    protected $fillable = ['conversation_id', 'membership_id', 'tool', 'summary', 'payload', 'status', 'result', 'decided_at', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'status' => ProposalStatus::class,
            'result' => 'array',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
