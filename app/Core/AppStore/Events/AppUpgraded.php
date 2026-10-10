<?php

declare(strict_types=1);

namespace App\Core\AppStore\Events;

use App\Core\AppStore\Models\AppInstallation;
use App\Core\Events\TenantDomainEvent;

final class AppUpgraded extends TenantDomainEvent
{
    public readonly string $installationId;

    public readonly string $module;

    public readonly string $fromVersion;

    public readonly string $toVersion;

    public function __construct(AppInstallation $installation, string $fromVersion, string $toVersion)
    {
        parent::__construct();
        $this->installationId = $installation->id;
        $this->module = $installation->app->module_key;
        $this->fromVersion = $fromVersion;
        $this->toVersion = $toVersion;
    }

    public static function name(): string
    {
        return 'core.app.upgraded';
    }

    public function payload(): array
    {
        return [
            'installation_id' => $this->installationId,
            'module' => $this->module,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
        ];
    }
}
