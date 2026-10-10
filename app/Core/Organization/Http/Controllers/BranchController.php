<?php

declare(strict_types=1);

namespace App\Core\Organization\Http\Controllers;

use App\Core\Access\RoleManager;
use App\Core\Organization\Models\Branch;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class BranchController
{
    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request): RedirectResponse
    {
        $input = $this->validated($request);

        // The row and its audit entry are written together or not at all.
        $branch = DB::transaction(fn (): Branch => Branch::query()->create($input));

        Toast::success(__('Branch ":name" created.', ['name' => $branch->name]));

        return back();
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $input = $this->validated($request, $branch);

        DB::transaction(fn (): bool => $branch->update($input));

        Toast::success(__('Branch ":name" updated.', ['name' => $branch->name]));

        return back();
    }

    public function destroy(Branch $branch, RoleManager $roles): RedirectResponse
    {
        DB::transaction(function () use ($branch, $roles): void {
            // Its people and records stay; they simply no longer belong to a branch.
            $branch->delete();

            // Membership placement is part of every cached access decision.
            $roles->flush();
        });

        Toast::success(__('Branch deleted.'));

        return back();
    }

    /**
     * @return array{name: string, code?: string|null, phone?: string|null, address?: string|null}
     */
    private function validated(Request $request, ?Branch $branch = null): array
    {
        $unique = fn (string $column) => Rule::unique('branches', $column)
            ->where('tenant_id', $this->context->requireId())
            ->ignore($branch?->getKey());

        /** @var array{name: string, code?: string|null, phone?: string|null, address?: string|null} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120', $unique('name')],
            'code' => ['nullable', 'string', 'max:30', $unique('code')],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        return $input;
    }
}
