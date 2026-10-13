<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Template;

/** The outcome class of a template catalogue call. Stable strings. */
enum TemplateCatalogueStatus: string
{
    case Ok = 'ok';
    /** No communication app, or the workspace is not entitled. */
    case ModuleUnavailable = 'module_unavailable';
    /** No WhatsApp connection, or its credential does not resolve. */
    case NotConfigured = 'not_configured';
    /** The connection has no WhatsApp Business Account id. */
    case MissingBusinessAccount = 'missing_business_account';
    /** The provider refused the content (name taken, category, example missing, ...). `reason` says why. */
    case Rejected = 'rejected';
    /** A temporary failure: unreachable, rate limited, provider error. Retry later. */
    case Failed = 'failed';
    case NotFound = 'not_found';
}
