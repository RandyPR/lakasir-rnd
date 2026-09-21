<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class MigrateDatabase extends Command
{
    protected $signature = 'migrates {--force : Force the operation to run when in production}';

    public function handle()
    {
        $force = $this->option('force') || app()->environment('production');

        $this->info('Migrating central database...');
        $this->call('migrate', ['--force' => $force]);

        $this->info('Migrating tenant databases...');
        $this->call('tenants:migrate', ['--force' => $force]);

        $this->info('All database migrations completed successfully.');
    }
}
