<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\Support\TenantHost;
use App\Platform\Enums\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an account for the Super Admin panel.
 *
 * This is how the first administrator of an installation comes to exist:
 * the panel has no sign-up and the demo seeder refuses to run outside
 * `local`.
 *
 * The password is only ever asked for, never taken as an argument or an
 * option - those end up in shell history and in the process list.
 */
final class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'platform:create-admin
        {email : The email address the administrator signs in with}
        {--name= : Their full name (asked for when omitted)}
        {--role=super_admin : super_admin or support_admin}';

    protected $description = 'Create an account for the Super Admin panel';

    public function handle(AuditLogger $audit, TenantHost $hosts): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('Run this in a terminal: the password is typed in, never passed on the command line.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?? $this->ask('Full name');
        $password = $this->secret('Password');

        $input = [
            'name' => is_string($name) ? trim($name) : $name,
            'email' => Str::lower(trim((string) $this->argument('email'))),
            'role' => $this->option('role'),
            'password' => $password,
            'password_confirmation' => $password === null ? null : $this->secret('Confirm password'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(PlatformUser::class, 'email')],
            'role' => ['required', Rule::enum(PlatformRole::class)],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $role = PlatformRole::from($input['role']);

        DB::transaction(function () use ($input, $role, $audit): void {
            $admin = PlatformUser::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'role' => $role,
            ]);

            $audit->record('platform.admin.created', $admin, ['role' => $role->value, 'via' => 'console']);
        });

        $this->components->info(sprintf('%s can now sign in at %s.', $input['email'], $hosts->adminHost()));
        $this->components->bulletList([
            'Two-step verification is set up at the first sign-in.',
        ]);

        return self::SUCCESS;
    }
}
