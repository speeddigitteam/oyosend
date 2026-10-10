<?php

declare(strict_types=1);

namespace App\Core\AppStore\Contracts;

use App\Core\AppStore\Models\AppInstallation;

final class NullModuleLifecycle implements ModuleLifecycle
{
    public function installing(AppInstallation $installation): void {}

    public function installed(AppInstallation $installation): void {}

    public function activating(AppInstallation $installation): void {}

    public function activated(AppInstallation $installation): void {}

    public function deactivating(AppInstallation $installation): void {}

    public function deactivated(AppInstallation $installation): void {}

    public function uninstalling(AppInstallation $installation): void {}

    public function uninstalled(AppInstallation $installation): void {}

    public function upgraded(AppInstallation $installation, string $fromVersion): void {}
}
