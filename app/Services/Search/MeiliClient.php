<?php

namespace App\Services\Search;

use App\Exceptions\MeiliUnavailableException;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;

class MeiliClient
{
    public function assertAvailable(): void
    {
        if (!config('meilisearch.enabled')) {
            throw new MeiliUnavailableException('Meilisearch is disabled (MEILI_ENABLED=0).');
        }

        if (!config('meilisearch.host')) {
            throw new MeiliUnavailableException('Meilisearch host is not configured (MEILI_HOST).');
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

    /**
     * Generic index accessor (so jobs/services can call $meili->index('products')).
     */
    public function index(string $name): Indexes
    {
        return $this->client()->index($name);
    }

    /**
     * Dedicated products index accessor (preferred).
     */
    public function productsIndex(): Indexes
    {
        return $this->index(
            (string) config('meilisearch.indexes.products', 'products')
        );
    }
}
