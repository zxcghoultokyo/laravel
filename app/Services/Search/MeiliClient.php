<?php

namespace App\Services\Search;

use App\Exceptions\MeiliUnavailableException;
use Meilisearch\Client;

class MeiliClient
{
    public function assertAvailable(): void
    {
        if (!config('meilisearch.enabled')) {
            throw new MeiliUnavailableException('Meilisearch is disabled (MEILI_ENABLED=0).');
        }

        if (!config('meilisearch.host')) {
            throw new MeiliUnavailableException('MEILI_HOST is not set.');
        }
    }

    public function client(): Client
    {
        $this->assertAvailable();

        return new Client(
            (string) config('meilisearch.host'),
            config('meilisearch.key') ? (string) config('meilisearch.key') : null
        );
    }

    public function productsIndex()
    {
        return $this->client()->index(
            (string) config('meilisearch.indexes.products', 'products')
        );
    }
}
