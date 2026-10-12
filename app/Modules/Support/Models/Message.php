<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Enums\MessageKind;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One entry of a ticket's conversation. Public replies and customer replies are visible to the requester;
 * internal notes are not. Entries are never edited: only moved between tickets by merge and split.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property MessageKind $kind
 * @property string|null $author_id
 * @property string|null $author_name
 * @property string|null $author_email
 * @property string $body
 * @property string|null $source_key
 * @property Carbon $created_at
 * @property-read TenantMembership|null $author
 * @property-read Collection<int, Attachment> $attachments
 */
class Message extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_messages';

    /** @var list<string> */
    protected $fillable = ['ticket_id', 'kind', 'author_id', 'author_name', 'author_email', 'body', 'source_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => MessageKind::class];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'author_id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'message_id');
    }
}
