<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Communication\Enums\InteractionType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One entry of a contact's or company's interaction timeline.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string|null $message_id
 * @property InteractionType $type
 * @property string|null $direction
 * @property string $summary
 * @property string|null $body
 * @property Carbon $occurred_at
 * @property string|null $source_module
 * @property string|null $source_type
 * @property string|null $source_id
 * @property string|null $dedupe_key
 * @property array<string, mixed>|null $meta
 */
class Interaction extends Model
{
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'communication_interactions';

    /** @var list<string> */
    protected $fillable = [
        'contact_id', 'company_id', 'message_id', 'type', 'direction', 'summary', 'body', 'occurred_at',
        'source_module', 'source_type', 'source_id', 'dedupe_key', 'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => InteractionType::class,
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
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
}
