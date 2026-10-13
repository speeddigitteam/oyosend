<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A lead form of a connected Page, cached from the platform, with the person's switch and answer mapping.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property string $external_id
 * @property string $name
 * @property string|null $status
 * @property string|null $locale
 * @property list<array{key: string, label: string, type: string}>|null $questions
 * @property bool $is_enabled
 * @property array<string, array{target: string, answer_key?: string|null}>|null $mapping
 * @property Carbon|null $fetched_at
 * @property Carbon|null $last_lead_at
 */
class LeadForm extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_lead_forms';

    /** @var list<string> */
    protected $fillable = ['account_id', 'external_id', 'name', 'status', 'locale', 'questions', 'is_enabled', 'mapping', 'fetched_at', 'last_lead_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['questions' => 'array', 'is_enabled' => 'boolean', 'mapping' => 'array', 'fetched_at' => 'datetime', 'last_lead_at' => 'datetime'];
    }
}
