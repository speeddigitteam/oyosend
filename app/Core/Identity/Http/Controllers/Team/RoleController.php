<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Controllers\Team;

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Access\Models\Role;
use App\Core\Access\RoleManager;
use App\Core\Identity\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class RoleController
{
    public function __construct(
        private readonly RoleManager $roles,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): Response
    {
        $tenantId = $this->context->requireId();

        $memberCounts = DB::table('model_has_roles')
            ->where('tenant_id', $tenantId)
            ->groupBy('role_id')
            ->selectRaw('role_id, count(*) as members')
            ->pluck('members', 'role_id');

        $grantCounts = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->where('roles.tenant_id', $tenantId)
            ->groupBy('role_has_permissions.role_id')
            ->selectRaw('role_has_permissions.role_id as role_id, count(*) as grants')
            ->pluck('grants', 'role_id');

        return Inertia::render('Core/Pages/Team/Roles/Index', [
            'roles' => Role::query()
                ->forCurrentTenant()
                ->orderBy('name')
                ->get()
                // System roles first, from the most powerful down; then the workspace's own, by name.
                ->sortBy(fn (Role $role): array => [
                    $role->is_system ? 0 : 1,
                    $role->is_system ? array_search($role->name, Role::SYSTEM_ORDER, true) : 0,
                ])
                ->values()
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'isSystem' => $role->is_system,
                    'isOwner' => $role->isOwnerRole(),
                    'members' => (int) ($memberCounts[$role->id] ?? 0),
                    'permissions' => (int) ($grantCounts[$role->id] ?? 0),
                ])
                ->all(),
            'totalPermissions' => count($this->roles->assignablePermissionNames()),
        ]);
    }

    public function create(Request $request, ModuleRegistry $modules, AccessChecker $access): Response
    {
        return Inertia::render('Core/Pages/Team/Roles/Edit', [
            'role' => null,
            'catalogue' => $this->catalogue($request, $modules, $access),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $this->validated($request);

        /** @var User $actor */
        $actor = $request->user('web');

        $role = $this->roles->create(
            $actor,
            $input['name'],
            $input['description'] ?? null,
            $input['permissions'] ?? [],
            $input['scopes'] ?? [],
        );

        Toast::success(__('Role ":name" created.', ['name' => $role->name]));

        return redirect()->route('core.roles.index');
    }

    public function edit(Request $request, Role $role, ModuleRegistry $modules, AccessChecker $access): Response
    {
        return Inertia::render('Core/Pages/Team/Roles/Edit', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'isSystem' => $role->is_system,
                'isOwner' => $role->isOwnerRole(),
                'permissions' => $this->roles->grantedNames($role),
                // How far each grant reaches: own, department, branch or all.
                'scopes' => array_map(
                    static fn (AccessScope $scope): string => $scope->value,
                    $this->roles->grants($role),
                ),
            ],
            'catalogue' => $this->catalogue($request, $modules, $access),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $input = $this->validated($request, $role);

        /** @var User $actor */
        $actor = $request->user('web');

        $this->roles->update(
            $actor,
            $role,
            $input['name'],
            $input['description'] ?? null,
            $input['permissions'] ?? [],
            $input['scopes'] ?? [],
        );

        Toast::success(__('Role ":name" updated.', ['name' => $role->name]));

        return redirect()->route('core.roles.index');
    }

    public function destroy(Role $role): RedirectResponse
    {
        $this->roles->delete($role);

        Toast::success(__('Role deleted.'));

        return redirect()->route('core.roles.index');
    }

    /**
     * @return array{name: string, description?: string|null, permissions?: list<string>, scopes?: array<string, string>}
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        /** @var array{name: string, description?: string|null, permissions?: list<string>, scopes?: array<string, mixed>} $input */
        $input = $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                // Unique within this tenant only.
                Rule::unique('roles', 'name')
                    ->where('tenant_id', $this->context->requireId())
                    ->where('guard_name', 'web')
                    ->ignore($role?->getKey()),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'max:150'],
            // Permission name => scope. Names contain dots, so the map is validated as a whole.
            'scopes' => ['array'],
        ]);

        foreach ($input['scopes'] ?? [] as $permission => $scope) {
            if (! is_string($scope) || AccessScope::tryFrom($scope) === null) {
                throw ValidationException::withMessages(['scopes' => __('One of the selected scopes is not valid.')]);
            }
        }

        return $input;
    }

    /**
     * The permissions this tenant's roles may carry, grouped for the editor,
     * with whether the person editing is able to grant each one.
     *
     * @return list<array{module: string, name: string, groups: list<array<string, mixed>>}>
     */
    private function catalogue(Request $request, ModuleRegistry $modules, AccessChecker $access): array
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $assignable = array_flip($this->roles->assignablePermissionNames());
        $held = $access->effectivePermissions($actor);
        $catalogue = [];

        foreach ($modules->all() as $manifest) {
            $groups = [];

            foreach ($manifest->permissions as $permission) {
                if (! isset($assignable[$permission->name])) {
                    continue;
                }

                $groups[$permission->group][] = [
                    'name' => $permission->name,
                    'sensitive' => $permission->sensitive,
                    'grantable' => isset($held[$permission->name]),
                    'scopable' => $permission->scopable,
                    // The widest scope the editor may hand out: the one they hold themselves.
                    'maxScope' => ($held[$permission->name] ?? null)?->value,
                ];
            }

            if ($groups === []) {
                continue;
            }

            $catalogue[] = [
                'module' => $manifest->key,
                'name' => $manifest->name,
                'groups' => collect($groups)
                    ->map(fn (array $permissions, string $group): array => ['key' => $group, 'permissions' => $permissions])
                    ->values()
                    ->all(),
            ];
        }

        return $catalogue;
    }
}
