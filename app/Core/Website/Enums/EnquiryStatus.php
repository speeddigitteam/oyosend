<?php

declare(strict_types=1);

namespace App\Core\Website\Enums;

/**
 * Where a website enquiry stands: waiting for an answer, or answered.
 */
enum EnquiryStatus: string
{
    case New = 'new';
    case Handled = 'handled';
}
