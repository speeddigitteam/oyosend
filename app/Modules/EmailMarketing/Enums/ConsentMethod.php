<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Enums;

/** How the person asked to be on a list. Stored with the free-text evidence. */
enum ConsentMethod: string
{
    case WebsiteForm = 'website_form';
    case InPerson = 'in_person';
    case Phone = 'phone';
    case EmailReply = 'email_reply';
    case ExistingCustomer = 'existing_customer';
    case Other = 'other';
}
