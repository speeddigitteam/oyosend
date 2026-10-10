<?php

declare(strict_types=1);

namespace App\Core\Organization\Http\Controllers;

use App\Core\Access\RoleManager;
use App\Core\Organization\Models\Department;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DepartmentController
{
    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request): RedirectResponse
    {
        $input = $this->validated($request);

        // The row and its audit entry are written together or not at all.
        $department = DB::transaction(fn (): Department => Department::query()->create($input));

        Toast::success(__('Department ":name" created.', ['name' => $department->name]));

        return back();
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $input = $this->validated($request, $department);

        DB::transaction(fn (): bool => $department->update($input));

        Toast::success(__('Department ":name" updated.', ['name' => $department->name]));

        return back();
    }

    public function destroy(Department $department, RoleManager $roles): RedirectResponse
    {
        DB::transaction(function () use ($department, $roles): void {
            // Its people and records stay; they simply no longer belong to a department.
            $department->delete();

            // Membership placement is part of every cached access decision.
            $roles->flush();
        });

        Toast::success(__('Department deleted.'));

        return back();
    }

    /**
     * @return array{name: string, code?: string|null, description?: string|null}
     */
    private function validated(Request $request, ?Department $department = null): array
    {
        $unique = fn (string $column) => Rule::unique('departments', $column)
            ->where('tenant_id', $this->context->requireId())
            ->ignore($department?->getKey());

        /** @var array{name: string, code?: string|null, description?: string|null} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120', $unique('name')],
            'code' => ['nullable', 'string', 'max:30', $unique('code')],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        return $input;
    }
}
