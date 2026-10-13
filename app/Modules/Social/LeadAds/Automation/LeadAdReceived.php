<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Automation;

use App\Modules\Social\Events\SocialEvent;
use App\Modules\Social\LeadAds\Models\LeadAdLead;

/**
 * A Facebook lead ad enquiry was handed to the CRM for the first time. Ids only: no name, email, phone or
 * answer ever travels in the event. The CRM ids are opaque references.
 */
final class LeadAdReceived extends SocialEvent
{
    public static function from(LeadAdLead $lead): self
    {
        return new self([
            'lead_ad_id' => $lead->id,
            'account_id' => $lead->account_id,
            'form_id' => $lead->form_id,
            'crm_contact_id' => $lead->crm_contact_id,
            'crm_lead_id' => $lead->crm_lead_id,
        ]);
    }

    public static function name(): string
    {
        return 'social.lead_ad.received';
    }
}
