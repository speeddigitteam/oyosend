<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The record of one CSV import into a list. Rows and the file are never kept, only the counts and errors.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $list_id
 * @property string $file_name
 * @property string $file_sha256
 * @property int $total_rows
 * @property int $subscribed_rows
 * @property int $pending_rows
 * @property int $skipped_rows
 * @property int $failed_rows
 * @property list<array{line: int, message: string}>|null $errors
 * @property Carbon $created_at
 * @property-read MailingList $list
 */
class ImportRun extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'email_marketing_imports';

    /** @var list<string> */
    protected $fillable = [
        'list_id', 'file_name', 'file_sha256', 'total_rows', 'subscribed_rows', 'pending_rows', 'skipped_rows',
        'failed_rows', 'errors', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['errors' => 'array'];
    }

    /** @return BelongsTo<MailingList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(MailingList::class, 'list_id');
    }

    public function auditLabel(): string
    {
        return $this->file_name;
    }

    public function auditEventName(string $verb): string
    {
        return 'email_marketing.import.'.$verb;
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['errors', 'file_sha256'];
    }
}
