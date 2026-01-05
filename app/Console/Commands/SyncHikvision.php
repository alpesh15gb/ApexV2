<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\HikvisionService;
use Carbon\Carbon;

class SyncHikvision extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:hikvision 
                            {--since= : Sync records since this date (Y-m-d format)}
                            {--full : Perform full sync, ignoring last sync time}
                            {--test : Test connection only, do not sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync attendance events from Hikvision MySQL database to local attendance tables';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $service = new HikvisionService();

        // Test connection mode
        if ($this->option('test')) {
            $this->info('Testing Hikvision MySQL connection...');
            if ($service->testConnection()) {
                $this->info('✓ Connection successful!');
                return 0;
            } else {
                $this->error('✗ Connection failed. Check your .env configuration.');
                return 1;
            }
        }

        $this->info('Starting Hikvision event sync from MySQL...');

        // Determine sync start time
        $since = null;
        if ($this->option('since')) {
            $since = Carbon::parse($this->option('since'));
            $this->info("Syncing records since: {$since->format('Y-m-d H:i:s')}");
        } elseif (!$this->option('full')) {
            $since = $service->getLastSyncTime();
            if ($since) {
                $this->info("Resuming from last sync: {$since->format('Y-m-d H:i:s')}");
            } else {
                $this->info("No previous sync found. Performing full sync.");
            }
        } else {
            $this->info("Performing full sync (all records).");
        }

        // Test connection first
        if (!$service->testConnection()) {
            $this->error('Cannot connect to Hikvision MySQL database. Check your configuration.');
            return 1;
        }

        // Perform sync
        $this->info('Fetching and processing Hikvision events...');
        $stats = $service->syncToAttendance($since);

        // Display results
        $this->newLine();
        $this->info('Sync completed!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Records Fetched', $stats['fetched']],
                ['Check-ins Created', $stats['check_ins']],
                ['Check-outs Created', $stats['check_outs']],
                ['Skipped (duplicates)', $stats['skipped']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($stats['errors'] > 0) {
            $this->warn("Some records had errors. Check laravel.log for details.");
            return 1;
        }

        return 0;
    }
}
