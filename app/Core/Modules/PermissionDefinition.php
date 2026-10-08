<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * One permission a module contributes to the platform.
 */
final readonly class PermissionDefinition
{
    /**
     * @param  string  $name  Dotted and prefixed with the module key: `crm.leads.edit`.
     * @param  string  $group  Groups permissions in the role editor: `leads`.
     * @param  bool  $scopable  Whether the grant can be narrowed to own/department/branch.
     * @param  bool  $sensitive  Shown with a warning in the role editor (billing, security, ...).
     */
    public function __construct(
        public string $name,
        public string $group,
        public bool $scopable = false,
        public bool $sensitive = false,
    ) {}

    /**
     * Module key, derived from the name's first segment.
     */
    public function module(): string
    {
        return strstr($this->name, '.', true) ?: $this->name;
    }

    /**
     * Translation key for the permission's label, resolved on the client.
     */
    public function labelKey(): string
    {
        return 'permissions.'.$this->name;
    }
}
