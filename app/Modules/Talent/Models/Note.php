<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $candidate_id
 * @property string|null $application_id
 * @property string|null $author_id
 * @property string $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Note extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_notes';

    /** @var list<string> */
    protected $fillable = ['candidate_id', 'application_id', 'author_id', 'body'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
        ];
    }

    /** @return BelongsTo<Candidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Candidate::class, 'candidate_id');
    }
}
