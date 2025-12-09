<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiRecommender;
use App\Services\FaqService;
use App\Services\Horoshop\ProductService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function __construct(
        protected FaqService $faqService,
        protected ProductService $productService,
        protected AiRecommender $aiRecommender,
    ) {}

    public function handle(Request $request)
    {
        $data = $request->validate([
            'message'      => ['required', 'string'],
            'page_url'     => ['nullable', 'string'],
            'product_id'   => ['nullable', 'integer'],
            'category_id'  => ['nullable', 'integer'],
            'language'     => ['nullable', 'string', 'max:5'],
        ]);

        $message  = $data['message'];
        $language = $data['language'] ?? 'uk';

        // 1️⃣ Спробуємо знайти відповідь у FAQ
        if ($faqAnswer = $this->faqService->findAnswer($message)) {
            return response()->json([
                'type'    => 'faq',
                'message' => $faqAnswer,
            ]);
        }

        // 2️⃣ Якщо не FAQ — шукаємо товари через Horoshop
        $filters = [];

        if (! empty($data['category_id'])) {
            $filters['category_id'] = $data['category_id'];
        }

        $products = $this->productService->search($message, $filters);

        if (empty($products)) {
            return response()->json([
                'type'    => 'no_results',
                'message' => "Я не знайшов товарів за запитом: «{$message}». Спробуй змінити формулювання або вкажи категорію/бренд.",
            ]);
        }

        // 3️⃣ Доручаємо AI обрати найкращі товари
        $aiResult = $this->aiRecommender->pickProducts($message, $products, $language);

        $selectedArticles = $aiResult['articles'] ?? [];
        $replyMessage     = $aiResult['message'] ?? "Ось що я можу запропонувати:";

        // Якщо AI з якоїсь причини не повернув артикулів — fallback: перші 5 товарів
        if (empty($selectedArticles)) {
            $selectedProducts = array_slice($products, 0, 5);
        } else {
            // Створюємо індекс: article → product
            $indexByArticle = [];
            foreach ($products as $product) {
                $article = $product['article'] ?? ($product['parent_article'] ?? null);
                if ($article) {
                    $indexByArticle[$article] = $product;
                }
            }

            $selectedProducts = [];
            foreach ($selectedArticles as $article) {
                if (isset($indexByArticle[$article])) {
                    $selectedProducts[] = $indexByArticle[$article];
                }
            }

            // Якщо раптом нічого не змогли знайти по артикулу — теж fallback
            if (empty($selectedProducts)) {
                $selectedProducts = array_slice($products, 0, 5);
            }
        }

        // 4️⃣ Нормалізуємо товари під фронт
        $normalizedProducts = collect($selectedProducts)
            ->take(5)
            ->map(function (array $product) {
                $titleUa = $product['title']['ua'] ?? null;
                $titleRu = $product['title']['ru'] ?? null;
                $name    = $titleUa ?: ($titleRu ?: ($product['title'] ?? ''));

                $short = $product['short_description'] ?? null;
                if (is_array($short)) {
                    $short = $short['ua'] ?? ($short['ru'] ?? null);
                }

                $image = null;
                if (!empty($product['images']) && is_array($product['images'])) {
                    $image = $product['images'][0] ?? null;
                }

                return [
                    'id'          => $product['article'] ?? $product['parent_article'] ?? null,
                    'name'        => $name,
                    'price'       => $product['price'] ?? null,
                    'image'       => $image,
                    'url'         => $product['link'] ?? null,
                    'description' => $short,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'type'     => 'products',
            'message'  => $replyMessage,
            'products' => $normalizedProducts,
        ]);
    }
}
