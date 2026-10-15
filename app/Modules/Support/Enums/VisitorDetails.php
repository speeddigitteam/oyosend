<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

/** Whether the chat widget asks a visitor for a name and an email address before the first message. */
enum VisitorDetails: string
{
    /** Not asked; whatever is sent is ignored. */
    case None = 'none';

    /** Asked, may be left empty. */
    case Optional = 'optional';

    /** Both are needed to start a chat. */
    case Required = 'required';
}
