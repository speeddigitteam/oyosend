<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

final class HandleCentralInertia extends HandleInertiaRequests
{
    protected function area(): string
    {
        return 'central';
    }
}
