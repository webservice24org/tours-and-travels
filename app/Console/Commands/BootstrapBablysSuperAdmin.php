<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cms\CmsRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-time console-only bootstrap. Never exposed via HTTP or public signup.
 * Guarded by an exact repeat-email challenge and an existing verified user.
 */
final class BootstrapBablysSuperAdmin extends Command
{
    protected $signature = 'bablys:bootstrap-super-admin
        {email : Existing, verified account email}
        {--confirm-email= : Repeat the exact email to authorize promotion}
        {--allow-production : Explicitly allow the one-time action in production}';

    protected $description = 'Assign the first Bablys super_admin role to an existing verified account (one-time only).';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $confirmation = trim((string) $this->option('confirm-email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $email !== $confirmation) {
            $this->error('Use a valid existing email and repeat it exactly with --confirm-email.');

            return self::FAILURE;
        }

        if ($this->laravel->environment('production') && ! $this->option('allow-production')) {
            $this->error('Production bootstrap requires the explicit --allow-production option.');

            return self::FAILURE;
        }

        try {
            return DB::transaction(function () use ($email): int {
                $role = CmsRole::query()->where('key', 'super_admin')->lockForUpdate()->first();

                if ($role === null) {
                    $this->error('Missing super_admin role. Run the P2B.1 RBAC seeder first.');

                    return self::FAILURE;
                }

                // The role-row lock serializes concurrent bootstrap commands.
                if ($role->users()->exists()) {
                    $this->error('A super administrator already exists. Bootstrap is permanently closed.');

                    return self::FAILURE;
                }

                $user = User::query()->where('email', $email)->lockForUpdate()->first();
                if ($user === null) {
                    $this->error('Account not found. No account will be created by this command.');

                    return self::FAILURE;
                }

                if ($user->getAttribute('email_verified_at') === null) {
                    $this->error('Account email is not verified. Complete verification before assigning staff access.');

                    return self::FAILURE;
                }

                if ($role->permissions()->count() === 0) {
                    $this->error('The super_admin role has no grants. Review the RBAC seeder first.');

                    return self::FAILURE;
                }

                $role->users()->syncWithoutDetaching([$user->getKey()]);
                $this->info('Initial Bablys super administrator assigned to the existing verified user.');

                return self::SUCCESS;
            }, 3);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Bootstrap failed; review application logs and database connectivity.');

            return self::FAILURE;
        }
    }
}
