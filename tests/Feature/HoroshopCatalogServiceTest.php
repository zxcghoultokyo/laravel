<?php

namespace Tests\Feature;

use App\Models\HoroshopProduct;
use App\Models\RozetkaProduct;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\Horoshop\HoroshopCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoroshopCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Store',
            'slug' => 'test-store',
            'domain' => 'test.example.com',
            'email' => 'test@example.com',
            'status' => 'active',
            'platform' => 'horoshop',
            'platform_credentials' => [
                'domain' => 'https://test.horoshop.ua',
                'login' => 'test_login',
                'password' => 'test_password',
            ],
        ]);
    }

    public function test_upsert_creates_new_horoshop_product(): void
    {
        $service = new HoroshopCatalogService;

        // Use reflection to test protected upsertHoroshopProduct
        $method = new \ReflectionMethod($service, 'upsertHoroshopProduct');
        $method->setAccessible(true);

        $item = $this->makeFakeHoroshopItem('TEST-001');
        $result = $method->invoke($service, $this->tenant, $item);

        $this->assertEquals('created', $result);

        $product = HoroshopProduct::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenant->id)
            ->where('article', 'TEST-001')
            ->first();

        $this->assertNotNull($product);
        $this->assertEquals('Тестовий товар', $product->title);
        $this->assertEquals(999.00, (float) $product->price);
        $this->assertEquals('TestBrand', $product->brand);
        $this->assertTrue($product->in_stock);
        $this->assertNotNull($product->synced_at);
    }

    public function test_upsert_updates_existing_horoshop_product(): void
    {
        HoroshopProduct::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->tenant->id,
            'article' => 'TEST-002',
            'title' => 'Old Title',
            'price' => 100,
        ]);

        $service = new HoroshopCatalogService;
        $method = new \ReflectionMethod($service, 'upsertHoroshopProduct');
        $method->setAccessible(true);

        $item = $this->makeFakeHoroshopItem('TEST-002', 'Нова назва', 500);
        $result = $method->invoke($service, $this->tenant, $item);

        $this->assertEquals('updated', $result);

        $product = HoroshopProduct::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenant->id)
            ->where('article', 'TEST-002')
            ->first();

        $this->assertEquals('Нова назва', $product->title);
        $this->assertEquals(500.00, (float) $product->price);
    }

    public function test_upsert_skips_item_without_article(): void
    {
        $service = new HoroshopCatalogService;
        $method = new \ReflectionMethod($service, 'upsertHoroshopProduct');
        $method->setAccessible(true);

        $result = $method->invoke($service, $this->tenant, ['title' => ['ua' => 'No article']]);

        $this->assertEquals('skipped', $result);
    }

    public function test_match_with_rozetka_links_products_by_article(): void
    {
        // Create Rozetka product
        $rozetka = RozetkaProduct::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'article' => 'MATCH-001',
            'title' => 'Rozetka Product',
            'price' => 1000,
        ]);

        // Create Horoshop product with same article
        HoroshopProduct::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->tenant->id,
            'article' => 'MATCH-001',
            'title' => 'Horoshop Product',
            'price' => 900,
        ]);

        // Create Horoshop product without matching Rozetka
        HoroshopProduct::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->tenant->id,
            'article' => 'NO-MATCH-001',
            'title' => 'No Match Product',
            'price' => 500,
        ]);

        $service = new HoroshopCatalogService;
        $matched = $service->matchWithRozetka($this->tenant);

        $this->assertEquals(1, $matched);

        $hp = HoroshopProduct::withoutGlobalScope(TenantScope::class)
            ->where('article', 'MATCH-001')
            ->first();
        $this->assertEquals($rozetka->id, $hp->rozetka_product_id);

        $noMatch = HoroshopProduct::withoutGlobalScope(TenantScope::class)
            ->where('article', 'NO-MATCH-001')
            ->first();
        $this->assertNull($noMatch->rozetka_product_id);
    }

    public function test_horoshop_product_has_rozetka_relationship(): void
    {
        $rozetka = RozetkaProduct::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'article' => 'REL-001',
            'title' => 'Rozetka',
            'price' => 500,
        ]);

        $hp = HoroshopProduct::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->tenant->id,
            'article' => 'REL-001',
            'title' => 'Horoshop',
            'price' => 450,
            'rozetka_product_id' => $rozetka->id,
        ]);

        $this->assertNotNull($hp->rozetkaProduct);
        $this->assertEquals($rozetka->id, $hp->rozetkaProduct->id);
    }

    public function test_rozetka_product_has_horoshop_relationship(): void
    {
        $rozetka = RozetkaProduct::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'article' => 'REL-002',
            'title' => 'Rozetka',
            'price' => 500,
        ]);

        HoroshopProduct::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $this->tenant->id,
            'article' => 'REL-002',
            'title' => 'Horoshop',
            'price' => 450,
            'rozetka_product_id' => $rozetka->id,
        ]);

        $loaded = RozetkaProduct::withoutGlobalScopes()
            ->with('horoshopProduct')
            ->find($rozetka->id);

        $this->assertNotNull($loaded->horoshopProduct);
        $this->assertEquals('Horoshop', $loaded->horoshopProduct->title);
    }

    public function test_extract_color_from_various_formats(): void
    {
        $service = new HoroshopCatalogService;
        $method = new \ReflectionMethod($service, 'extractColor');
        $method->setAccessible(true);

        // Standard format
        $this->assertEquals('Чорний', $method->invoke($service, [
            'color' => ['value' => ['ua' => 'Чорний']],
        ]));

        // Kolir format
        $this->assertEquals('Зелений', $method->invoke($service, [
            'Kolir' => ['value' => ['ua' => 'Зелений']],
        ]));

        // No color
        $this->assertNull($method->invoke($service, []));
    }

    protected function makeFakeHoroshopItem(string $article, string $title = 'Тестовий товар', float $price = 999): array
    {
        return [
            'article' => $article,
            'parent_article' => null,
            'title' => ['ua' => $title, 'ru' => $title],
            'price' => $price,
            'price_old' => null,
            'brand' => ['value' => ['ua' => 'TestBrand']],
            'color' => ['value' => ['ua' => 'Чорний']],
            'parent' => ['value' => 'Категорія > Підкатегорія'],
            'description' => ['value' => ['ua' => 'Опис товару', 'ru' => 'Описание товара']],
            'short_description' => ['value' => ['ua' => 'Короткий опис']],
            'images' => [['url' => 'https://example.com/img.jpg']],
            'characteristics' => [
                ['name' => 'Матеріал', 'value' => 'Нейлон'],
                ['name' => 'Вага', 'value' => '500г'],
            ],
            'slug' => 'test-product',
            'link' => '/test-product',
            'quantity' => 10,
            'presence' => ['value' => ['ua' => 'В наявності']],
            'display_in_showcase' => true,
            'popularity' => 5,
            'we_recommended' => false,
            'seo_title' => ['ua' => 'SEO Title'],
            'seo_keywords' => ['ua' => 'test, product'],
            'seo_description' => ['ua' => 'SEO description'],
        ];
    }
}
