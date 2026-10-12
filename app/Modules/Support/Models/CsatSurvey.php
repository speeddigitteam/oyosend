<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Enums\SurveyStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer satisfaction request. Only the SHA-256 of its token is stored, and it is cleared as soon as the
 * survey is answered, superseded or expired, so a link works once.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property string|null $token_hash
 * @property SurveyStatus $status
 * @property int|null $rating
 * @property string|null $comment
 * @property string|null $requested_by
 * @property Carbon $expires_at
 * @property Carbon|null $answered_at
 * @property Carbon $created_at
 * @property-read Ticket $ticket
 */
class CsatSurvey extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_csat_surveys';

    /** @var list<string> */
    protected $fillable = ['ticket_id', 'token_hash', 'status', 'rating', 'comment', 'requested_by', 'expires_at', 'answered_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SurveyStatus::class,
            'rating' => 'integer',
            'expires_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
