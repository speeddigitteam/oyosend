<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property string $tag_id
 */
class TicketTag extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected $table = 'support_ticket_tag';

    /** @var list<string> */
    protected $fillable = ['ticket_id', 'tag_id'];

    /** @return BelongsTo<Tag, $this> */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
