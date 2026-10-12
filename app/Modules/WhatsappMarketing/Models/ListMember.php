<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A contact on a list.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $list_id
 * @property string $contact_id
 * @property string|null $added_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Contact|null $contact
 * @property-read ContactList|null $list
 */
class ListMember extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_list_members';

    /** @var list<string> */
    protected $fillable = ['list_id', 'contact_id', 'added_by'];

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return BelongsTo<ContactList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'list_id');
    }
}
