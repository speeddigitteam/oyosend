<?php

declare(strict_types=1);

namespace App\Core\Website\Models;

use App\Core\Tenancy\Concerns\UsesCentralConnection;
use App\Core\Website\Enums\EnquiryStatus;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message from the website's contact form. Platform data: written to the company behind the platform, read in
 * the Super Admin panel (docs/WEBSITE.md).
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $company
 * @property string $topic
 * @property string $message
 * @property string $locale
 * @property EnquiryStatus $status
 * @property Carbon|null $handled_at
 * @property string|null $handled_by
 * @property Carbon $created_at
 */
class WebsiteEnquiry extends Model
{
    use HasUlids;
    use UsesCentralConnection;

    protected $fillable = ['name', 'email', 'phone', 'company', 'topic', 'message', 'locale', 'status', 'handled_at', 'handled_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EnquiryStatus::class,
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'handled_by');
    }
}
