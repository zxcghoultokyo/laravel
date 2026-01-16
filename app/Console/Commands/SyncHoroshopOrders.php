<?php

namespace App\Console\Commands;

use App\Jobs\FetchHoroshopOrdersJob;
use Illuminate\Console\Command;

class SyncHoroshopOrders extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'horoshop:sync-orders 
                            {--hours=24 : Fetch orders from last N hours}
                            {--from= : From date (YYYY-MM-DD)}
                            {--to= : To date (YYYY-MM-DD)}
                            {--sync : Run synchronously instead of queuing}';

    /**
     * The console command description.
     */
    protected $description = 'Sync orders from Horoshop API and link to chat sessions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $fromDate = $this->option('from');
        $toDate = $this->option('to');
        $sync = $this->option('sync');

        if (!$fromDate) {
            $fromDate = now()->subHours($hours)->format('Y-m-d H:i:s');
        }
        if (!$toDate) {
            $toDate = now()->format('Y-m-d H:i:s');
        }

        $this->info("Syncing orders from Horoshop...");
        $this->info("From: {$fromDate}");
        $this->info("To: {$toDate}");

        $job = new FetchHoroshopOrdersJob(
            sessionId: null,
            fromDate: $fromDate,
            toDate: $toDate,
            orderIds: null,
            linkToChat: true
        );

        if ($sync) {
            $this->info("Running synchronously...");
            dispatch_sync($job);
        } else {
            $this->info("Dispatching to queue...");
            dispatch($job);
        }

        $this->info("✅ Done! Check logs for details.");
        
        return Command::SUCCESS;
    }
}
