<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MigrateDatabase extends Command
{
    protected $signature = 'migrates {--force : Force the operation to run when in production}';

    protected $description = 'Run the database migrations (alias of migrate)';

    public function handle()
    {
        $force = $this->option('force') || app()->environment('production');

        $this->call('migrate', ['--force' => $force]);

        $this->info('All database migrations completed successfully.');
    }
}
