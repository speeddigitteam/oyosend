<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
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
 * @property string $task_id
 * @property string|null $author_id
 * @property string|null $client_access_id
 * @property string $body
 * @property bool $is_internal
 * @property Carbon $created_at
 * @property-read Task $task
 * @property-read TenantMembership|null $author
 * @property-read ClientAccess|null $clientAccess
 * @property-read Collection<int, CommentMention> $mentions
 */
class TaskComment extends Model
{
    use BelongsToTenant, HasUlids, SoftDeletes;

    protected $table = 'project_task_comments';

    protected $fillable = ['task_id', 'author_id', 'client_access_id', 'body', 'is_internal'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'author_id');
    }

    /** @return BelongsTo<ClientAccess, $this> */
    public function clientAccess(): BelongsTo
    {
        return $this->belongsTo(ClientAccess::class);
    }

    /** @return HasMany<CommentMention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(CommentMention::class, 'comment_id');
    }
}
