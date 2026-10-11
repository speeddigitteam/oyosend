<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Projects\Enums\Priority;
use App\Modules\Projects\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $template_id
 * @property string|null $category_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property ProjectStatus $status
 * @property Priority $priority
 * @property Carbon|null $starts_on
 * @property Carbon|null $due_on
 * @property Carbon|null $completed_at
 * @property string $currency
 * @property int $budget_amount
 * @property int $progress
 * @property string|null $owner_id
 * @property Carbon $created_at
 * @property-read ProjectTemplate|null $template
 * @property-read ProjectCategory|null $category
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, ProjectMember> $members
 */
class Project extends Model
{
    use BelongsToTenant, HasRecordOwner, HasUlids, SoftDeletes;

    protected $table = 'projects';

    protected $fillable = ['template_id', 'category_id', 'contact_id', 'company_id', 'code', 'name', 'description', 'status', 'priority', 'starts_on', 'due_on', 'completed_at', 'currency', 'budget_amount', 'progress'];

    protected function casts(): array
    {
        return ['status' => ProjectStatus::class, 'priority' => Priority::class, 'starts_on' => 'date', 'due_on' => 'date', 'completed_at' => 'datetime', 'budget_amount' => 'integer', 'progress' => 'integer'];
    }

    /** @return BelongsTo<ProjectTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProjectTemplate::class);
    }

    /** @return BelongsTo<ProjectCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class);
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

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<ProjectMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
