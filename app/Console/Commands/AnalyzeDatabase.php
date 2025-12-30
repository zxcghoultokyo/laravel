<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzeDatabase extends Command
{
    protected $signature = 'db:analyze {--fix-duplicates : Remove duplicate products}';
    protected $description = 'Analyze database size and find potential issues';

    public function handle(): int
    {
        $this->info('📊 Database Analysis');
        $this->newLine();

        // 1. Table sizes
        $this->tableSize();

        // 2. Row counts
        $this->rowCounts();

        // 3. Check for duplicates
        $this->checkDuplicates();

        // 4. Large JSON columns
        $this->checkLargeJson();

        return self::SUCCESS;
    }

    private function tableSize(): void
    {
        $this->info('📦 Table Sizes (top 15):');
        
        $dbName = DB::connection()->getDatabaseName();
        
        $sizes = DB::select("
            SELECT 
                table_name AS `table`,
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb,
                ROUND((data_length / 1024 / 1024), 2) AS data_mb,
                ROUND((index_length / 1024 / 1024), 2) AS index_mb,
                table_rows AS row_estimate
            FROM information_schema.tables
            WHERE table_schema = ?
            ORDER BY (data_length + index_length) DESC
            LIMIT 15
        ", [$dbName]);

        $total = 0;
        $rows = [];
        foreach ($sizes as $t) {
            $rows[] = [$t->table, $t->size_mb . ' MB', $t->data_mb . ' MB', $t->index_mb . ' MB', number_format($t->row_estimate)];
            $total += $t->size_mb;
        }

        $this->table(['Table', 'Total Size', 'Data', 'Indexes', 'Rows (est)'], $rows);
        $this->info("Total: {$total} MB");
        $this->newLine();
    }

    private function rowCounts(): void
    {
        $this->info('📝 Exact Row Counts:');

        $tables = ['products', 'chat_messages', 'chat_sessions', 'ai_generation_logs', 'jobs', 'failed_jobs', 'cache'];
        
        $rows = [];
        foreach ($tables as $table) {
            try {
                $count = DB::table($table)->count();
                $rows[] = [$table, number_format($count)];
            } catch (\Exception $e) {
                $rows[] = [$table, 'N/A'];
            }
        }

        $this->table(['Table', 'Rows'], $rows);
        $this->newLine();
    }

    private function checkDuplicates(): void
    {
        $this->info('🔍 Checking for Duplicates in products:');

        // By article
        $duplicateArticles = DB::select("
            SELECT article, COUNT(*) as cnt 
            FROM products 
            WHERE article IS NOT NULL AND article != ''
            GROUP BY article 
            HAVING cnt > 1
            ORDER BY cnt DESC
            LIMIT 10
        ");

        if (count($duplicateArticles) > 0) {
            $this->warn('⚠️  Duplicate articles found:');
            $rows = [];
            foreach ($duplicateArticles as $d) {
                $rows[] = [$d->article, $d->cnt];
            }
            $this->table(['Article', 'Count'], $rows);

            $totalDupes = DB::selectOne("
                SELECT SUM(cnt - 1) as total FROM (
                    SELECT COUNT(*) as cnt 
                    FROM products 
                    WHERE article IS NOT NULL AND article != ''
                    GROUP BY article 
                    HAVING cnt > 1
                ) t
            ");
            $this->warn("Total duplicate rows: " . ($totalDupes->total ?? 0));

            if ($this->option('fix-duplicates')) {
                $this->fixDuplicates();
            } else {
                $this->line("Run with --fix-duplicates to remove duplicates");
            }
        } else {
            $this->info('✅ No duplicate articles found');
        }

        // By title
        $duplicateTitles = DB::select("
            SELECT title, COUNT(*) as cnt 
            FROM products 
            GROUP BY title 
            HAVING cnt > 1
            ORDER BY cnt DESC
            LIMIT 5
        ");

        if (count($duplicateTitles) > 0) {
            $this->newLine();
            $this->warn('⚠️  Duplicate titles (might be variants):');
            foreach ($duplicateTitles as $d) {
                $this->line("  {$d->title}: {$d->cnt}x");
            }
        }

        $this->newLine();
    }

    private function checkLargeJson(): void
    {
        $this->info('📄 Large JSON columns (products.raw):');

        $stats = DB::selectOne("
            SELECT 
                AVG(LENGTH(raw)) as avg_size,
                MAX(LENGTH(raw)) as max_size,
                MIN(LENGTH(raw)) as min_size,
                SUM(LENGTH(raw)) / 1024 / 1024 as total_mb
            FROM products
            WHERE raw IS NOT NULL
        ");

        if ($stats) {
            $this->line("  Average raw size: " . number_format($stats->avg_size) . " bytes");
            $this->line("  Max raw size: " . number_format($stats->max_size) . " bytes");
            $this->line("  Total raw column: " . round($stats->total_mb, 2) . " MB");
        }

        // Check search_index size
        $searchStats = DB::selectOne("
            SELECT 
                SUM(LENGTH(search_index)) / 1024 / 1024 as total_mb
            FROM products
        ");
        
        if ($searchStats) {
            $this->line("  Total search_index: " . round($searchStats->total_mb ?? 0, 2) . " MB");
        }

        $this->newLine();
    }

    private function fixDuplicates(): void
    {
        $this->warn('🔧 Fixing duplicates...');

        // Keep only the most recent product per article
        $deleted = DB::affectingStatement("
            DELETE p1 FROM products p1
            INNER JOIN products p2 
            WHERE p1.article = p2.article 
            AND p1.id < p2.id
            AND p1.article IS NOT NULL 
            AND p1.article != ''
        ");

        $this->info("✅ Deleted {$deleted} duplicate products");
    }
}
