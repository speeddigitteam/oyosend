<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A public sign-up waiting for its confirmation link (double opt-in). Holds the address and the optional name
 * until the person confirms; only the hash of the token is stored.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $list_id
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 */
class SignupRequest extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'email_marketing_signup_requests';

    /** @var list<string> */
    protected $fillable = ['list_id', 'email', 'first_name', 'last_name', 'token_hash', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
