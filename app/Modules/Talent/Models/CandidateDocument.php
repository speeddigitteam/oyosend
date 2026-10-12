<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\DocumentType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $candidate_id
 * @property string|null $application_id
 * @property DocumentType $type
 * @property string $title
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CandidateDocument extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_candidate_documents';

    /** @var list<string> */
    protected $fillable = ['candidate_id', 'application_id', 'type', 'title', 'original_name', 'path', 'mime_type', 'size', 'uploaded_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }
}
