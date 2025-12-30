<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateReverbKeys extends Command
{
    protected $signature = 'reverb:generate-keys {--show : Show the keys without writing to .env}';
    protected $description = 'Generate Reverb app_id, app_key and app_secret';

    public function handle(): int
    {
        $appId = Str::random(8);
        $appKey = Str::random(32);
        $appSecret = Str::random(32);

        if ($this->option('show')) {
            $this->info('Generated Reverb credentials:');
            $this->line("REVERB_APP_ID={$appId}");
            $this->line("REVERB_APP_KEY={$appKey}");
            $this->line("REVERB_APP_SECRET={$appSecret}");
            return self::SUCCESS;
        }

        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            $this->error('.env file not found');
            return self::FAILURE;
        }

        $env = file_get_contents($envPath);

        // Update or add each key
        $keys = [
            'REVERB_APP_ID' => $appId,
            'REVERB_APP_KEY' => $appKey,
            'REVERB_APP_SECRET' => $appSecret,
        ];

        foreach ($keys as $key => $value) {
            if (preg_match("/^{$key}=.*/m", $env)) {
                $env = preg_replace("/^{$key}=.*/m", "{$key}={$value}", $env);
            } else {
                $env .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envPath, $env);

        $this->info('✓ Reverb keys generated and saved to .env');
        $this->newLine();
        $this->table(['Key', 'Value'], [
            ['REVERB_APP_ID', $appId],
            ['REVERB_APP_KEY', $appKey],
            ['REVERB_APP_SECRET', $appSecret],
        ]);

        return self::SUCCESS;
    }
}
