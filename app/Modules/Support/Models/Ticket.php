<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Enums\TicketStatus;
use App\Modules\Support\Models\Concerns\HasAssignee;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * A customer support ticket. The requester is a shared contact/company (never a customer row of this
 * module); `requester_name`/`requester_email` only describe someone who is not in the directory yet.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $subject
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property TicketChannel $channel
 * @property string|null $category_id
 * @property string|null $queue_id
 * @property string|null $sla_policy_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string|null $requester_name
 * @property string|null $requester_email
 * @property string|null $created_by
 * @property string|null $merged_into_id
 * @property string|null $split_from_id
 * @property string|null $source_key
 * @property int $reopened_count
 * @property CarbonInterface|null $first_responded_at
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $closed_at
 * @property CarbonInterface|null $last_customer_message_at
 * @property CarbonInterface|null $last_agent_message_at
 * @property CarbonInterface|null $last_activity_at
 * @property CarbonInterface|null $first_response_due_at
 * @property CarbonInterface|null $resolution_due_at
 * @property int|null $first_response_remaining_minutes
 * @property int|null $resolution_remaining_minutes
 * @property CarbonInterface|null $sla_paused_at
 * @property CarbonInterface|null $first_response_breached_at
 * @property CarbonInterface|null $resolution_breached_at
 * @property CarbonInterface|null $resolution_first_breached_at
 * @property Carbon $created_at
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 * @property-read Category|null $category
 * @property-read Queue|null $queue
 * @property-read SlaPolicy|null $slaPolicy
 * @property-read Collection<int, Message> $messages
 * @property-read Collection<int, Tag> $tags
 */
class Ticket extends Model
{
    use BelongsToTenant;
    use HasAssignee;
    use HasUlids;

    protected $table = 'support_tickets';

    /** @var list<string> */
    protected $fillable = [
        'number', 'subject', 'status', 'priority', 'channel', 'category_id', 'queue_id', 'sla_policy_id',
        'contact_id', 'company_id', 'requester_name', 'requester_email', 'owner_id', 'department_id', 'branch_id',
        'created_by', 'merged_into_id', 'split_from_id', 'source_key', 'reopened_count', 'first_responded_at',
        'resolved_at', 'closed_at', 'last_customer_message_at', 'last_agent_message_at', 'last_activity_at',
        'first_response_due_at', 'resolution_due_at', 'first_response_remaining_minutes',
        'resolution_remaining_minutes', 'sla_paused_at', 'first_response_breached_at', 'resolution_breached_at', 'resolution_first_breached_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'channel' => TicketChannel::class,
            'reopened_count' => 'integer',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_customer_message_at' => 'datetime',
            'last_agent_message_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'first_response_remaining_minutes' => 'integer',
            'resolution_remaining_minutes' => 'integer',
            'sla_paused_at' => 'datetime',
            'first_response_breached_at' => 'datetime',
            'resolution_breached_at' => 'datetime',
            'resolution_first_breached_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return $this->number.' '.$this->subject;
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

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Queue, $this> */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    /** @return BelongsTo<SlaPolicy, $this> */
    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'created_by');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<TicketEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<TicketTag, $this> */
    public function tagLinks(): HasMany
    {
        return $this->hasMany(TicketTag::class);
    }

    /** @return HasManyThrough<Tag, TicketTag, $this> */
    public function tags(): HasManyThrough
    {
        return $this->hasManyThrough(Tag::class, TicketTag::class, 'ticket_id', 'id', 'id', 'tag_id')->orderBy('support_tags.name');
    }

    /** @return HasMany<CsatSurvey, $this> */
    public function surveys(): HasMany
    {
        return $this->hasMany(CsatSurvey::class)->latest('created_at')->latest('id');
    }

    /** The requester as a person would call them. Loads the customer relations when they are not loaded yet. */
    public function displayRequester(): ?string
    {
        $this->loadMissing(['contact:id,first_name,last_name,email', 'company:id,name']);

        return $this->contact?->fullName() ?? $this->company->name ?? $this->requester_name ?? $this->requester_email;
    }
}
