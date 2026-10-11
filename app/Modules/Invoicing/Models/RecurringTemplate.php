<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Invoicing\Enums\RecurringFrequency;
use App\Modules\Invoicing\Enums\RecurringStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string $name
 * @property RecurringStatus $status
 * @property RecurringFrequency $frequency
 * @property int $every
 * @property string $currency
 * @property Carbon $starts_on
 * @property Carbon|null $next_run_on
 * @property Carbon|null $ends_on
 * @property int $due_days
 * @property bool $auto_issue
 * @property bool $auto_send
 * @property string|null $title
 * @property string|null $reference
 * @property string|null $terms
 * @property string|null $notes
 * @property list<array<string, mixed>> $lines
 * @property Carbon|null $last_run_on
 * @property int $runs_count
 * @property string|null $last_error
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 */
class RecurringTemplate extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'invoicing_recurring_templates';

    /** @var list<string> */
    protected $fillable = [
        'contact_id', 'company_id', 'name', 'status', 'frequency', 'every', 'currency', 'starts_on',
        'next_run_on', 'ends_on', 'due_days', 'auto_issue', 'auto_send', 'title', 'reference', 'terms',
        'notes', 'lines', 'last_run_on', 'runs_count', 'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RecurringStatus::class,
            'frequency' => RecurringFrequency::class,
            'every' => 'integer',
            'starts_on' => 'date',
            'next_run_on' => 'date',
            'ends_on' => 'date',
            'due_days' => 'integer',
            'auto_issue' => 'boolean',
            'auto_send' => 'boolean',
            'lines' => 'array',
            'last_run_on' => 'date',
            'runs_count' => 'integer',
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

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'invoicing.recurring.'.$verb;
    }
}
