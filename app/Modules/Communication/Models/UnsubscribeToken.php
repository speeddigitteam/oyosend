<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Communication\Enums\Channel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Only the SHA-256 hash of the token is stored.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $contact_id
 * @property string|null $message_id
 * @property Channel $channel
 * @property string $address
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 */
class UnsubscribeToken extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_unsubscribe_tokens';

    /** @var list<string> */
    protected $fillable = ['contact_id', 'message_id', 'channel', 'address', 'token_hash', 'expires_at', 'used_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
