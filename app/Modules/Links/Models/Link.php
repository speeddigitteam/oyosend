<?php

declare(strict_types=1);

namespace App\Modules\Links\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Links\Enums\LinkKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A saved link: a website, a tool, an email address, a document or anything
 * else a team keeps coming back to. It never holds a password or other secret.
 * The link belongs to the member who saved it ({@see HasRecordOwner}), so
 * reading it honours the `own` / `department` / `branch` / `all` scope of its
 * permission.
 *
 * @property string $id
 * @property LinkKind $kind
 * @property string $title
 * @property string $address
 * @property string|null $description
 * @property string|null $folder
 * @property bool $is_pinned
 * @property string|null $owner_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TenantMembership|null $owner
 */
class Link extends Model
{
    use BelongsToTenant, HasRecordOwner, HasUlids;

    protected $table = 'links';

    protected $fillable = ['kind', 'title', 'address', 'description', 'folder', 'is_pinned'];

    protected function casts(): array
    {
        return ['kind' => LinkKind::class, 'is_pinned' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return str($this->title)->limit(80)->toString();
    }
}
