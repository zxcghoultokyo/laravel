<?php

namespace App\DTO;

/**
 * DTO for product search result item.
 */
readonly class ProductResultDTO
{
    public function __construct(
        public int $id,
        public string $article,
        public ?string $parentArticle,
        public string $title,
        public ?float $price,
        public ?float $priceOld,
        public ?string $categoryPath,
        public ?string $brand,
        public ?string $color,
        public bool $inStock,
        public int $popularity,
        public ?string $aiProductType,
        public array $images,
        public ?string $description,
        public array $characteristics,
        public ?float $aiScore = null,
        public ?string $aiReasoning = null,
    ) {}

    /**
     * Create from array (e.g., from Meilisearch hit or DB record).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            article: (string) ($data['article'] ?? ''),
            parentArticle: $data['parent_article'] ?? null,
            title: (string) ($data['title'] ?? 'Товар'),
            price: isset($data['price']) ? (float) $data['price'] : null,
            priceOld: isset($data['price_old']) ? (float) $data['price_old'] : null,
            categoryPath: $data['category_path'] ?? null,
            brand: $data['brand'] ?? null,
            color: $data['color'] ?? null,
            inStock: (bool) ($data['in_stock'] ?? false),
            popularity: (int) ($data['popularity'] ?? 0),
            aiProductType: $data['ai_product_type'] ?? null,
            images: $data['images'] ?? [],
            description: $data['description'] ?? null,
            characteristics: $data['characteristics'] ?? [],
            aiScore: isset($data['ai_score']) ? (float) $data['ai_score'] : null,
            aiReasoning: $data['ai_reasoning'] ?? null,
        );
    }

    /**
     * Convert to array for API response.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'article' => $this->article,
            'parent_article' => $this->parentArticle,
            'title' => $this->title,
            'price' => $this->price,
            'price_old' => $this->priceOld,
            'category_path' => $this->categoryPath,
            'brand' => $this->brand,
            'color' => $this->color,
            'in_stock' => $this->inStock,
            'popularity' => $this->popularity,
            'ai_product_type' => $this->aiProductType,
            'images' => $this->images,
            'description' => $this->description,
            'characteristics' => $this->characteristics,
        ];
    }

    /**
     * Get formatted price string.
     */
    public function formattedPrice(): string
    {
        if ($this->price === null) {
            return 'ціна не вказана';
        }
        return round($this->price) . ' ₴';
    }

    /**
     * Check if product has discount.
     */
    public function hasDiscount(): bool
    {
        return $this->priceOld !== null && $this->priceOld > ($this->price ?? 0);
    }

    /**
     * Get discount percentage.
     */
    public function discountPercent(): ?int
    {
        if (!$this->hasDiscount() || $this->priceOld === 0.0) {
            return null;
        }
        return (int) round((1 - ($this->price / $this->priceOld)) * 100);
    }

    /**
     * Get main category from path.
     */
    public function mainCategory(): ?string
    {
        if (!$this->categoryPath) {
            return null;
        }
        $parts = explode('/', $this->categoryPath);
        return $parts[1] ?? $parts[0] ?? null;
    }

    /**
     * Get first image URL or null.
     */
    public function mainImage(): ?string
    {
        return $this->images[0] ?? null;
    }
}
