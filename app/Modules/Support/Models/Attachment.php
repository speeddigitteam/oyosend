<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A private file stored through `TenantStorage`. The path is never sent to the browser; downloads go through
 * an authorized controller action.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property string|null $message_id
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property string|null $uploaded_by
 */
class Attachment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_attachments';

    /** @var list<string> */
    protected $fillable = ['ticket_id', 'message_id', 'original_name', 'path', 'mime_type', 'size', 'uploaded_by'];

    /** @var list<string> */
    protected $hidden = ['path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
