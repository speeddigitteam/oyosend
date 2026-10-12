<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A private conversation between one member and the assistant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $membership_id
 * @property string $title
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 */
class Conversation extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'ai_conversations';

    /** @var list<string> */
    protected $fillable = ['title', 'last_message_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    /** @return HasMany<Proposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'conversation_id');
    }
}
