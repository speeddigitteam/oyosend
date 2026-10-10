<?php

declare(strict_types=1);

namespace App\Core\Organization\Http\Controllers;

use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use Inertia\Inertia;
use Inertia\Response;

final class OrganizationController
{
    public function index(): Response
    {
        return Inertia::render('Core/Pages/Organization/Index', [
            'branches' => Branch::query()
                ->withCount('memberships')
                ->orderBy('name')
                ->get()
                ->map(fn (Branch $branch): array => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'phone' => $branch->phone,
                    'address' => $branch->address,
                    'members' => (int) $branch->getAttribute('memberships_count'),
                ])
                ->all(),
            'departments' => Department::query()
                ->withCount('memberships')
                ->orderBy('name')
                ->get()
                ->map(fn (Department $department): array => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'code' => $department->code,
                    'description' => $department->description,
                    'members' => (int) $department->getAttribute('memberships_count'),
                ])
                ->all(),
        ]);
    }
}
