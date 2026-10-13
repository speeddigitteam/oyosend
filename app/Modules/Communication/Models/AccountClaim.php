<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A provider account (a WhatsApp phone number id) that one workspace's connection has claimed. Platform-wide on
 * purpose (no tenant column, no tenant scope): the unique index is what stops a second workspace from connecting
 * the same account and sending from it. Always filtered by `connection_id` (a tenant-scoped model's id) when
 * read or removed.
 *
 * @property string $id
 * @property string $connection_id
 * @property string $provider
 * @property string $account_key
 * @property string $account_value
 */
class AccountClaim extends Model
{
    use HasUlids;

    protected $table = 'communication_account_claims';

    /** @var list<string> */
    protected $fillable = ['connection_id', 'provider', 'account_key', 'account_value'];
}
