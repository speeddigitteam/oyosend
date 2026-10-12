<?php

declare(strict_types=1);

namespace App\Core\Reporting;

/** The kinds of filter a provider can declare. There is no free-form expression type, by design. */
enum FilterType: string
{
    /** One of the declared options. */
    case Select = 'select';
    /** Short free text (at most 100 characters), used only as a bound parameter. */
    case Text = 'text';
    /** A three-letter currency code. */
    case Currency = 'currency';
    /** The id of a record of the workspace (a 26 character ULID). The provider still scopes it. */
    case Id = 'id';
    case Integer = 'integer';
    case Boolean = 'boolean';
}
