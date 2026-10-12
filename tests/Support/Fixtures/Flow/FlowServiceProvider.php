<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures\Flow;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\Modules\AppOffer;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\PermissionDefinition;

/** A stand-in app with one event trigger and a handful of controllable actions, for engine tests. */
final class FlowServiceProvider extends ModuleServiceProvider
{
    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'test_flow',
            name: 'Test Flow',
            version: '0.1.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('test_flow.items.view', 'items'),
                new PermissionDefinition('test_flow.items.manage', 'items'),
                new PermissionDefinition('test_flow.items.scoped', 'items', scopable: true),
            ],
            triggers: [ItemCreatedTrigger::class],
            actions: [RecordAction::class, FailAction::class, RejectAction::class, FlakyAction::class, EmitAction::class, ScopedAction::class],
            description: 'Engine test fixture.',
            category: AppCategory::Utilities,
            offers: [new AppOffer('free', 'Free', AppBillingType::Free)],
        );
    }
}
