<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Api;

/** What kind of failure Meta (or the network) reported, in the terms the app acts on. */
enum ErrorKind: string
{
    /** The access token is no longer valid: the person must authorise again. */
    case Token = 'token';
    /** The token is valid but lacks a permission or a role on the ad account. */
    case Permission = 'permission';
    /** Meta throttled the request. Nothing was processed; try again later. */
    case RateLimit = 'rate_limit';
    /** A network or server fault; the request may or may not have been processed. */
    case Transient = 'transient';
    /** Meta understood and refused the request (validation, state, policy). */
    case Rejected = 'rejected';
    /** The platform app is not configured on this installation. */
    case NotConfigured = 'not_configured';
}
