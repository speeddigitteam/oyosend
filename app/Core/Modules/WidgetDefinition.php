<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * A dashboard widget contributed by a module.
 *
 * `component` is the client component that renders it; `provider` is an
 * invokable class that returns the widget's data for the current user. The
 * dashboard only resolves providers for widgets the user may see.
 */
final readonly class WidgetDefinition
{
    /**
     * @param  string  $key  Unique: `core.team`.
     * @param  string  $component  Client component name.
     * @param  class-string  $provider  Invokable returning array<string, mixed>.
     * @param  int  $span  Columns occupied on the 12-column dashboard grid.
     */
    public function __construct(
        public string $key,
        public string $component,
        public string $provider,
        public ?string $permission = null,
        public int $span = 4,
        public int $order = 100,
    ) {}
}
