<?php

declare(strict_types=1);

namespace App\Core\AppStore\Contracts;

use App\Core\AppStore\Models\AppInstallation;

/**
 * Synchronous, idempotent hooks for trusted first-party modules.
 *
 * Hooks run inside the lifecycle transaction. They must not call external
 * services; queue that work after commit instead.
 */
interface ModuleLifecycle
{
    public function installing(AppInstallation $installation): void;

    public function installed(AppInstallation $installation): void;

    public function activating(AppInstallation $installation): void;

    public function activated(AppInstallation $installation): void;

    public function deactivating(AppInstallation $installation): void;

    public function deactivated(AppInstallation $installation): void;

    public function uninstalling(AppInstallation $installation): void;

    public function uninstalled(AppInstallation $installation): void;

    public function upgraded(AppInstallation $installation, string $fromVersion): void;
}
