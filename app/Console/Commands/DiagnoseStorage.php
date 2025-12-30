<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DiagnoseStorage extends Command
{
    protected $signature = 'storage:diagnose';
    protected $description = 'Diagnose what is consuming disk space';

    public function handle(): int
    {
        $this->info('🔍 Storage Diagnosis');
        $this->newLine();

        // 1. MySQL total size
        $this->mysqlSize();

        // 2. Laravel storage folder
        $this->storageSize();

        // 3. Cache tables
        $this->cacheSize();

        // 4. Sessions
        $this->sessionsSize();

        // 5. Jobs
        $this->jobsSize();

        // 6. Logs
        $this->logsSize();

        return self::SUCCESS;
    }

    private function mysqlSize(): void
    {
        $this->info('📊 MySQL Database Size:');
        
        try {
            $dbName = DB::connection()->getDatabaseName();
            
            $size = DB::selectOne("
                SELECT 
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                FROM information_schema.tables
                WHERE table_schema = ?
            ", [$dbName]);

            $this->line("  Total DB size: " . ($size->size_mb ?? 0) . " MB");

            // Binary logs size (if accessible)
            try {
                $binlogs = DB::select("SHOW BINARY LOGS");
                $totalBinlog = 0;
                foreach ($binlogs as $log) {
                    $totalBinlog += $log->File_size ?? 0;
                }
                $this->line("  Binary logs: " . round($totalBinlog / 1024 / 1024, 2) . " MB");
            } catch (\Exception $e) {
                $this->line("  Binary logs: N/A (no permission)");
            }

        } catch (\Exception $e) {
            $this->error("  Could not query MySQL: " . $e->getMessage());
        }

        $this->newLine();
    }

    private function storageSize(): void
    {
        $this->info('📁 Storage Folder Sizes:');

        $paths = [
            'storage/logs' => storage_path('logs'),
            'storage/framework/cache' => storage_path('framework/cache'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/app' => storage_path('app'),
        ];

        foreach ($paths as $name => $path) {
            if (is_dir($path)) {
                $size = $this->dirSize($path);
                $this->line("  {$name}: " . $this->formatBytes($size));
            } else {
                $this->line("  {$name}: N/A");
            }
        }

        $this->newLine();
    }

    private function cacheSize(): void
    {
        $this->info('🗄️ Database Cache:');

        try {
            if (\Schema::hasTable('cache')) {
                $count = DB::table('cache')->count();
                $size = DB::selectOne("
                    SELECT ROUND(SUM(LENGTH(value)) / 1024 / 1024, 2) as size_mb
                    FROM cache
                ");
                $this->line("  Cache entries: " . number_format($count));
                $this->line("  Cache size: " . ($size->size_mb ?? 0) . " MB");

                // Expired entries
                $expired = DB::table('cache')
                    ->where('expiration', '<', now()->timestamp)
                    ->count();
                $this->line("  Expired entries: " . number_format($expired));
            } else {
                $this->line("  No cache table");
            }
        } catch (\Exception $e) {
            $this->line("  Error: " . $e->getMessage());
        }

        $this->newLine();
    }

    private function sessionsSize(): void
    {
        $this->info('👤 Database Sessions:');

        try {
            if (\Schema::hasTable('sessions')) {
                $count = DB::table('sessions')->count();
                $size = DB::selectOne("
                    SELECT ROUND(SUM(LENGTH(payload)) / 1024 / 1024, 2) as size_mb
                    FROM sessions
                ");
                $this->line("  Sessions count: " . number_format($count));
                $this->line("  Sessions size: " . ($size->size_mb ?? 0) . " MB");

                // Old sessions (>7 days)
                $old = DB::table('sessions')
                    ->where('last_activity', '<', now()->subDays(7)->timestamp)
                    ->count();
                $this->line("  Sessions older than 7 days: " . number_format($old));
            } else {
                $this->line("  No sessions table");
            }
        } catch (\Exception $e) {
            $this->line("  Error: " . $e->getMessage());
        }

        $this->newLine();
    }

    private function jobsSize(): void
    {
        $this->info('⚙️ Jobs Queue:');

        try {
            if (\Schema::hasTable('jobs')) {
                $count = DB::table('jobs')->count();
                $size = DB::selectOne("
                    SELECT ROUND(SUM(LENGTH(payload)) / 1024 / 1024, 2) as size_mb
                    FROM jobs
                ");
                $this->line("  Pending jobs: " . number_format($count));
                $this->line("  Jobs payload size: " . ($size->size_mb ?? 0) . " MB");
            }

            if (\Schema::hasTable('failed_jobs')) {
                $failed = DB::table('failed_jobs')->count();
                $failedSize = DB::selectOne("
                    SELECT ROUND(SUM(LENGTH(payload) + LENGTH(exception)) / 1024 / 1024, 2) as size_mb
                    FROM failed_jobs
                ");
                $this->line("  Failed jobs: " . number_format($failed));
                $this->line("  Failed jobs size: " . ($failedSize->size_mb ?? 0) . " MB");
            }
        } catch (\Exception $e) {
            $this->line("  Error: " . $e->getMessage());
        }

        $this->newLine();
    }

    private function logsSize(): void
    {
        $this->info('📝 Log Files:');

        $logsPath = storage_path('logs');
        if (is_dir($logsPath)) {
            $files = File::files($logsPath);
            $totalSize = 0;
            $fileInfo = [];

            foreach ($files as $file) {
                $size = $file->getSize();
                $totalSize += $size;
                $fileInfo[] = [$file->getFilename(), $this->formatBytes($size)];
            }

            if (count($fileInfo) > 0) {
                $this->table(['File', 'Size'], array_slice($fileInfo, 0, 10));
                $this->line("  Total logs: " . $this->formatBytes($totalSize));
            } else {
                $this->line("  No log files");
            }
        }

        $this->newLine();
    }

    private function dirSize(string $path): int
    {
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
