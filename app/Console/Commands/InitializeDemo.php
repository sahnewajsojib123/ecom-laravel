<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('commerce:initialize-demo')]
#[Description('Initialize an empty demo store without overwriting existing catalog or credentials')]
class InitializeDemo extends Command
{
    public function handle(): int
    {
        return DB::transaction(function (): int {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(?)', [24681022]);
            }

            if (Setting::exists() || User::exists() || Outlet::exists() || Product::exists()) {
                $this->info('Existing store found. Keeping its data and credentials unchanged.');

                return self::SUCCESS;
            }

            if (strlen((string) config('commerce.admin_password')) < 12) {
                $this->error('Set ADMIN_PASSWORD to at least 12 characters before initializing the demo.');

                return self::FAILURE;
            }

            $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
            $this->info('Demo store initialized. Future restarts will preserve this data.');

            return self::SUCCESS;
        });
    }
}
