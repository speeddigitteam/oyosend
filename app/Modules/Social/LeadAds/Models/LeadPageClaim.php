<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A Facebook Page that one workspace has switched lead capture on for. Platform-wide on purpose (no tenant
 * column, no tenant scope): the unique index on `page_id` is what stops a second workspace from receiving
 * the same Page's leads. Owned through `lead_page_id`, a tenant-scoped model's id; read through it or by the
 * Page id of an incoming delivery, never listed.
 *
 * @property string $id
 * @property string $page_id
 * @property string $lead_page_id
 */
class LeadPageClaim extends Model
{
    use HasUlids;

    protected $table = 'social_lead_ad_page_claims';

    /** @var list<string> */
    protected $fillable = ['page_id', 'lead_page_id'];
}
