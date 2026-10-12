<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\DocumentType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A private employee file, stored only through TenantStorage.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property DocumentType $type
 * @property string $title
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property Carbon|null $expires_on
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 */
class EmployeeDocument extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'hrm_documents';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'type', 'title', 'original_name', 'path', 'mime_type', 'size', 'expires_on', 'uploaded_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => DocumentType::class, 'size' => 'integer', 'expires_on' => 'date'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['path'];
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.document.'.$verb;
    }
}
