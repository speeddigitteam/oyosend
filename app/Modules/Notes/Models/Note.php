<?php

declare(strict_types=1);

namespace App\Modules\Notes\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A workspace note, optionally linked to a shared contact or company. The note
 * belongs to the member who wrote it ({@see HasRecordOwner}), so reading it
 * honours the `own` / `department` / `branch` / `all` scope of its permission.
 *
 * @property string $id
 * @property string|null $title
 * @property string|null $body
 * @property bool $is_pinned
 * @property string|null $color
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string|null $owner_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 * @property-read TenantMembership|null $owner
 */
class Note extends Model
{
    use BelongsToTenant, HasRecordOwner, HasUlids;

    protected $table = 'notes';

    protected $fillable = ['contact_id', 'company_id', 'title', 'body', 'is_pinned', 'color'];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean'];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function auditLabel(): string
    {
        return str($this->title ?: $this->body ?: __('Note'))->limit(80)->toString();
    }
}
