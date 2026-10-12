<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named list of shared contacts a campaign is sent to. It holds references only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, ListMember> $members
 */
class ContactList extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_lists';

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'created_by'];

    /** @return HasMany<ListMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ListMember::class, 'list_id');
    }
}
