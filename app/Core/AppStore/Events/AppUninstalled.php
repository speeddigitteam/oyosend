<?php

declare(strict_types=1);

namespace App\Core\AppStore\Events;

use App\Core\AppStore\Models\AppInstallation;
use App\Core\Events\TenantDomainEvent;

final class AppUninstalled extends TenantDomainEvent
{
    public readonly string $installationId;

    public readonly string $module;

    public function __construct(AppInstallation $installation)
    {
        parent::__construct();
        $this->installationId = $installation->id;
        $this->module = $installation->app->module_key;
    }

    public static function name(): string
    {
        return 'core.app.uninstalled';
    }

    public function payload(): array
    {
        return ['installation_id' => $this->installationId, 'module' => $this->module];
    }
}
