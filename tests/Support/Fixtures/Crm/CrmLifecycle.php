<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Crm;

use App\Core\AppStore\Contracts\ModuleLifecycle;
use App\Core\AppStore\Models\AppInstallation;

final class CrmLifecycle implements ModuleLifecycle
{
    /** @var list<string> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    public function installing(AppInstallation $installation): void
    {
        self::$calls[] = 'installing';
    }

    public function installed(AppInstallation $installation): void
    {
        self::$calls[] = 'installed';
    }

    public function activating(AppInstallation $installation): void
    {
        self::$calls[] = 'activating';
    }

    public function activated(AppInstallation $installation): void
    {
        self::$calls[] = 'activated';
    }

    public function deactivating(AppInstallation $installation): void
    {
        self::$calls[] = 'deactivating';
    }

    public function deactivated(AppInstallation $installation): void
    {
        self::$calls[] = 'deactivated';
    }

    public function uninstalling(AppInstallation $installation): void
    {
        self::$calls[] = 'uninstalling';
    }

    public function uninstalled(AppInstallation $installation): void
    {
        self::$calls[] = 'uninstalled';
    }

    public function upgraded(AppInstallation $installation, string $fromVersion): void
    {
        self::$calls[] = 'upgraded:'.$fromVersion;
    }
}
