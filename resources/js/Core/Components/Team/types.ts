/**
 * Prop shapes of the Team pages (members, invitations, roles), as the
 * controllers in `app/Core/Identity/Http/Controllers/Team` return them.
 */

/** A role as offered in a picker. */
export interface RoleOption {
    id: string;
    name: string;
}

/** A branch or a department, as offered in a picker and shown on a member's row. */
export interface UnitOption {
    id: string;
    name: string;
}

export type MemberStatus = 'active' | 'suspended';

export interface Member {
    /** Id of the membership, not of the user: the tenant-scoped handle. */
    id: string;
    name: string;
    email: string;
    initials: string;
    role: RoleOption | null;
    /** Where the person sits. It decides what a grant limited to a branch or a department reaches. */
    branch: UnitOption | null;
    department: UnitOption | null;
    status: MemberStatus;
    isOwner: boolean;
    isSelf: boolean;
    /** The viewer may change, suspend or remove this member (decided by the server). */
    manageable: boolean;
    /** The viewer may set this member's branch and department: anyone they may manage, and the owner themselves. */
    placeable: boolean;
    twoFactorEnabled: boolean;
    joinedAt: string | null;
    lastSeenAt: string | null;
}

/** An invitation that has been neither accepted nor withdrawn; it may have expired. */
export interface OpenInvitation {
    id: string;
    email: string;
    /** Name of the role the person will join with. */
    role: string;
    inviter: string | null;
    expired: boolean;
    expiresAt: string;
    createdAt: string;
}

export interface RoleSummary {
    id: string;
    name: string;
    description: string | null;
    isSystem: boolean;
    isOwner: boolean;
    /** People who hold the role. */
    members: number;
    /** Stored grants. Always 0 for the Owner role, whose access is implicit. */
    permissions: number;
}

/** How far a grant reaches, from the narrowest to the widest. */
export type AccessScope = 'own' | 'department' | 'branch' | 'all';

/** Permission name => scope. */
export type ScopeMap = Record<string, AccessScope>;

export interface EditableRole {
    id: string;
    name: string;
    description: string | null;
    isSystem: boolean;
    isOwner: boolean;
    permissions: string[];
    /** The scope of every grant. A role that grants nothing sends an empty list: PHP has one empty array. */
    scopes: ScopeMap | [];
}

export interface CataloguePermission {
    name: string;
    sensitive: boolean;
    /** Whether the person editing holds it, and so may add or remove it. */
    grantable: boolean;
    /** Whether the grant can be narrowed to a person's own, their department's or their branch's records. */
    scopable: boolean;
    /** The widest scope the person editing may hand out: the one they hold. `null` when they do not hold it. */
    maxScope: AccessScope | null;
}

export interface CatalogueGroup {
    key: string;
    permissions: CataloguePermission[];
}

export interface CatalogueModule {
    module: string;
    /** The module's own name, used when the catalogue has no translation for it. */
    name: string;
    groups: CatalogueGroup[];
}
