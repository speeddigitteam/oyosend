import type { Component } from 'vue';

/**
 * Prop shapes of the organization page, as
 * `App\Core\Organization\Http\Controllers\OrganizationController` returns them.
 */

/** What a branch and a department have in common. */
export interface OrganizationUnit {
    id: string;
    name: string;
    /** The company's own short reference, unique in the workspace when set. */
    code: string | null;
    /** People placed in it. */
    members: number;
}

export interface BranchRow extends OrganizationUnit {
    phone: string | null;
    address: string | null;
}

export interface DepartmentRow extends OrganizationUnit {
    description: string | null;
}

export type UnitKind = 'branches' | 'departments';

/** One line under a unit's name. The label is read out in place of the icon. */
export interface UnitDetail {
    text: string;
    icon?: Component;
    label?: string;
}
