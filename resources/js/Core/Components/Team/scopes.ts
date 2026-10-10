import type { AccessScope, CataloguePermission, EditableRole, ScopeMap } from './types';

/**
 * Permission scopes in the role editor. The order is the server's
 * (`App\Core\Access\Enums\AccessScope::rank()`): each scope reaches everything
 * the one before it does, and more.
 */
export const SCOPES: readonly AccessScope[] = ['own', 'department', 'branch', 'all'];

export function scopeRank(scope: AccessScope): number {
    return SCOPES.indexOf(scope);
}

/** The scopes someone who holds a permission with `max` may hand out: `max` and everything narrower. */
export function scopesUpTo(max: AccessScope | null): AccessScope[] {
    return max === null ? [] : SCOPES.slice(0, scopeRank(max) + 1);
}

/** The scopes a role was saved with. */
export function savedScopes(role: EditableRole | null): ScopeMap {
    const scopes = role?.scopes;

    return scopes && !Array.isArray(scopes) ? scopes : {};
}

/**
 * Whether the person editing may add, remove or re-scope a permission on a
 * role that currently grants it with `saved` (`undefined`: not granted).
 *
 * The server's rule: touching a grant takes the same permission, held at
 * least as widely as the grant. So a permission the editor does not hold is
 * locked, and so is a grant that reaches further than their own.
 */
export function isChangeable(permission: CataloguePermission, saved: AccessScope | undefined): boolean {
    if (!permission.grantable || permission.maxScope === null) {
        return false;
    }

    return saved === undefined || scopeRank(saved) <= scopeRank(permission.maxScope);
}
