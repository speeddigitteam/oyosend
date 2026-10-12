<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Models;

use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One contact's membership of a group.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $group_id
 * @property string $contact_id
 * @property string|null $added_by
 */
class GroupMember extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'sms_marketing_group_members';

    /** @var list<string> */
    protected $fillable = ['group_id', 'contact_id', 'added_by'];

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
