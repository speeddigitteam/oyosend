<?php

declare(strict_types=1);

namespace App\Core\Communication\Data;

/** The answer behind an {@see AudienceDecision}. Stable strings; safe to store on a recipient row. */
enum AudienceReason: string
{
    /** The contact may be messaged. */
    case Ok = 'ok';
    /** A recorded consent says the contact opted out. */
    case OptedOut = 'opted_out';
    /** There is no consent record. For marketing, no record means no. */
    case NoConsent = 'no_consent';
    /** The address is on the suppression list (bounced, complained, invalid, manual). */
    case Suppressed = 'suppressed';
    /** The address is suppressed because the contact used an unsubscribe link. */
    case Unsubscribed = 'unsubscribed';
    /** The channel cannot send now: app not available, channel disabled or provider not configured. */
    case ChannelUnavailable = 'channel_unavailable';
    /** The contact has no valid address for the channel. */
    case NoAddress = 'no_address';
    /** No such contact in the workspace. */
    case UnknownContact = 'unknown_contact';
}
