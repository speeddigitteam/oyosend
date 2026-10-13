<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Social\LeadAds\LeadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One lead the platform announced (or listed) and what became of it. The fetched answers are held encrypted in
 * `payload` only while they are still needed (waiting for the CRM, or a failed capture a person may fix by
 * changing the mapping) and are hidden from every serialization.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property string|null $form_id
 * @property string $leadgen_id
 * @property string $page_id
 * @property string|null $external_form_id
 * @property string|null $ad_id
 * @property string|null $campaign_ref
 * @property string $origin
 * @property LeadStatus $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $claimed_at
 * @property string|null $reason_code
 * @property string|null $reason
 * @property array<string, mixed>|null $payload
 * @property string|null $crm_contact_id
 * @property string|null $crm_lead_id
 * @property Carbon|null $created_time_at
 * @property Carbon $received_at
 * @property Carbon|null $fetched_at
 * @property Carbon|null $processed_at
 */
class LeadAdLead extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_lead_ad_leads';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'form_id', 'leadgen_id', 'page_id', 'external_form_id', 'ad_id', 'campaign_ref', 'origin', 'status',
        'attempts', 'next_attempt_at', 'claimed_at', 'reason_code', 'reason', 'payload', 'crm_contact_id', 'crm_lead_id',
        'created_time_at', 'received_at', 'fetched_at', 'processed_at',
    ];

    /** @var list<string> */
    protected $hidden = ['payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'attempts' => 'integer',
            'payload' => 'encrypted:array',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'created_time_at' => 'datetime',
            'received_at' => 'datetime',
            'fetched_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
