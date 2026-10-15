<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

/** A website chat is open until a member closes it; a visitor writing again opens it again. */
enum ChatStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
