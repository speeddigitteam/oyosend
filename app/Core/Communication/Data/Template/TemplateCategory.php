<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Template;

/** A message template category (billing and rules differ). Authentication templates are not built (ADR 0022). */
enum TemplateCategory: string
{
    case Marketing = 'marketing';
    case Utility = 'utility';
    case Authentication = 'authentication';
}
