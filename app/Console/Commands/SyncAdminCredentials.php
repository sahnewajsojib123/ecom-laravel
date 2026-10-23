<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SyncAdminCredentials extends Command
{
    protected $signature = 'commerce:sync-admin';

    protected $description = 'Sync the existing administrator password from deployment configuration';

    public function handle(): int
    {
        $email = trim((string) config('commerce.admin_email'));
        $password = (string) config('commerce.admin_password');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            $this->error('Set a valid ADMIN_EMAIL and ADMIN_PASSWORD of at least 12 characters.');

            return self::FAILURE;
        }

        return DB::transaction(function () use ($email, $password): int {
            $admin = User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->lockForUpdate()->first();
            if (! $admin || ! $admin->is_admin) {
                $this->error('No existing administrator matches ADMIN_EMAIL. No account was changed.');

                return self::FAILURE;
            }
            if (Hash::check($password, $admin->password) && ! Hash::needsRehash($admin->password)) {
                $this->info('Administrator credentials already match deployment configuration.');

                return self::SUCCESS;
            }
            $admin->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $admin->id)->delete();
            $this->info('Administrator password synced. Previous administrator sessions were signed out.');

            return self::SUCCESS;
        }, 3);
    }
}
