<?php

namespace App\Http\Controllers\Api;

use App\Models\CannedResponse;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Canned Responses Controller - CRUD for operator quick responses.
 */
class CannedResponseController extends Controller
{
    /**
     * List canned responses for tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $query = CannedResponse::where('tenant_id', $tenantId);

        // Filters
        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        if ($request->has('search')) {
            $query->search($request->search);
        }

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        // Sorting
        $sortBy = $request->get('sort', 'popular');
        if ($sortBy === 'popular') {
            $query->popular();
        } elseif ($sortBy === 'recent') {
            $query->latest();
        } elseif ($sortBy === 'title') {
            $query->orderBy('title');
        }

        $responses = $query->get();

        return response()->json([
            'data' => $responses->map(fn($r) => $this->formatResponse($r)),
            'categories' => $this->getCategories(),
        ]);
    }

    /**
     * Get single response.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        $response = CannedResponse::where('tenant_id', $tenantId)
            ->findOrFail($id);

        return response()->json([
            'data' => $this->formatResponse($response, true),
        ]);
    }

    /**
     * Create new canned response.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:5000',
            'shortcut' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[a-z0-9_-]+$/i',
                Rule::unique('canned_responses')->where('tenant_id', $tenantId),
            ],
            'category' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        $response = CannedResponse::create([
            'tenant_id' => $tenantId,
            'title' => $validated['title'],
            'content' => $validated['content'],
            'shortcut' => $validated['shortcut'] ?? null,
            'category' => $validated['category'] ?? CannedResponse::CATEGORY_OTHER,
            'is_active' => $validated['is_active'] ?? true,
            'variables' => $this->extractVariables($validated['content']),
        ]);

        // Clear cache
        $this->clearCache($tenantId);

        return response()->json([
            'success' => true,
            'message' => 'Шаблон створено',
            'data' => $this->formatResponse($response),
        ], 201);
    }

    /**
     * Update canned response.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        $response = CannedResponse::where('tenant_id', $tenantId)
            ->findOrFail($id);

        $validated = $request->validate([
            'title' => 'string|max:255',
            'content' => 'string|max:5000',
            'shortcut' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[a-z0-9_-]+$/i',
                Rule::unique('canned_responses')
                    ->where('tenant_id', $tenantId)
                    ->ignore($id),
            ],
            'category' => 'nullable|string|max:50',
            'is_active' => 'boolean',
        ]);

        if (isset($validated['content'])) {
            $validated['variables'] = $this->extractVariables($validated['content']);
        }

        $response->update($validated);

        // Clear cache
        $this->clearCache($tenantId);

        return response()->json([
            'success' => true,
            'message' => 'Шаблон оновлено',
            'data' => $this->formatResponse($response),
        ]);
    }

    /**
     * Delete canned response.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        $response = CannedResponse::where('tenant_id', $tenantId)
            ->findOrFail($id);

        $response->delete();

        // Clear cache
        $this->clearCache($tenantId);

        return response()->json([
            'success' => true,
            'message' => 'Шаблон видалено',
        ]);
    }

    /**
     * Use canned response - increment counter and return processed content.
     */
    public function use(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        $response = CannedResponse::where('tenant_id', $tenantId)
            ->active()
            ->findOrFail($id);

        // Get context for variable replacement
        $context = $request->input('context', []);
        
        $processedContent = $response->processContent($context);
        
        // Increment usage counter
        $response->incrementUsage();

        return response()->json([
            'success' => true,
            'content' => $processedContent,
            'variables_used' => array_keys($context),
        ]);
    }

    /**
     * Find by shortcut.
     */
    public function findByShortcut(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        $shortcut = $request->input('shortcut');
        
        if (!$shortcut) {
            return response()->json(['error' => 'Shortcut required'], 400);
        }

        $response = CannedResponse::where('tenant_id', $tenantId)
            ->where('shortcut', $shortcut)
            ->active()
            ->first();

        if (!$response) {
            return response()->json(['found' => false]);
        }

        // Get context for variable replacement
        $context = $request->input('context', []);
        $processedContent = $response->processContent($context);

        // Increment usage counter
        $response->incrementUsage();

        return response()->json([
            'found' => true,
            'content' => $processedContent,
            'title' => $response->title,
            'variables' => $response->extractVariables(),
        ]);
    }

    /**
     * Get suggestions based on partial input.
     */
    public function suggestions(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        $input = $request->input('input', '');
        
        if (strlen($input) < 2) {
            return response()->json(['suggestions' => []]);
        }

        // Check if input starts with / or # (shortcut trigger)
        $isShortcutSearch = str_starts_with($input, '/') || str_starts_with($input, '#');
        
        if ($isShortcutSearch) {
            $search = ltrim($input, '/#');
            $responses = CannedResponse::where('tenant_id', $tenantId)
                ->active()
                ->where('shortcut', 'like', "{$search}%")
                ->limit(5)
                ->get();
        } else {
            // Full text search
            $responses = CannedResponse::where('tenant_id', $tenantId)
                ->active()
                ->where(function ($q) use ($input) {
                    $q->where('title', 'like', "%{$input}%")
                      ->orWhere('content', 'like', "%{$input}%");
                })
                ->limit(5)
                ->get();
        }

        return response()->json([
            'suggestions' => $responses->map(fn($r) => [
                'id' => $r->id,
                'shortcut' => $r->shortcut,
                'title' => $r->title,
                'preview' => mb_substr($r->content, 0, 100) . (mb_strlen($r->content) > 100 ? '...' : ''),
                'category' => $r->category,
            ]),
        ]);
    }

    /**
     * Seed default responses for new tenant.
     */
    public function seedDefaults(Request $request): JsonResponse
    {
        $tenantId = $this->getTenantId($request);
        
        if (!$tenantId) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Check if tenant already has responses
        $existing = CannedResponse::where('tenant_id', $tenantId)->count();
        
        if ($existing > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Шаблони вже існують',
                'count' => $existing,
            ]);
        }

        $defaults = $this->getDefaultResponses();
        
        foreach ($defaults as $default) {
            CannedResponse::create([
                'tenant_id' => $tenantId,
                'title' => $default['title'],
                'content' => $default['content'],
                'shortcut' => $default['shortcut'],
                'category' => $default['category'],
                'is_active' => true,
                'variables' => $this->extractVariables($default['content']),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Базові шаблони створено',
            'count' => count($defaults),
        ]);
    }

    /**
     * Get tenant ID from request.
     */
    private function getTenantId(Request $request): ?int
    {
        // From auth
        if ($request->user() && $request->user()->tenant_id) {
            return $request->user()->tenant_id;
        }

        // From header
        if ($tenantId = $request->header('X-Tenant-Id')) {
            return (int) $tenantId;
        }

        // From query (for API key auth)
        if ($apiKey = $request->input('api_key') ?? $request->header('X-API-Key')) {
            $tenant = Tenant::where('api_key', $apiKey)->first();
            return $tenant?->id;
        }

        return null;
    }

    /**
     * Format response for API.
     */
    private function formatResponse(CannedResponse $response, bool $detailed = false): array
    {
        $data = [
            'id' => $response->id,
            'title' => $response->title,
            'shortcut' => $response->shortcut,
            'category' => $response->category,
            'is_active' => $response->is_active,
            'usage_count' => $response->usage_count,
        ];

        if ($detailed) {
            $data['content'] = $response->content;
            $data['variables'] = $response->extractVariables();
            $data['created_at'] = $response->created_at->toISOString();
            $data['updated_at'] = $response->updated_at->toISOString();
        } else {
            $data['preview'] = mb_substr($response->content, 0, 80) . 
                (mb_strlen($response->content) > 80 ? '...' : '');
        }

        return $data;
    }

    /**
     * Extract variables from content.
     */
    private function extractVariables(string $content): array
    {
        preg_match_all('/\{\{([^}]+)\}\}/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Get available categories.
     */
    private function getCategories(): array
    {
        return [
            ['key' => CannedResponse::CATEGORY_GREETING, 'label' => 'Привітання'],
            ['key' => CannedResponse::CATEGORY_FAREWELL, 'label' => 'Прощання'],
            ['key' => CannedResponse::CATEGORY_DELIVERY, 'label' => 'Доставка'],
            ['key' => CannedResponse::CATEGORY_PAYMENT, 'label' => 'Оплата'],
            ['key' => CannedResponse::CATEGORY_RETURNS, 'label' => 'Повернення'],
            ['key' => CannedResponse::CATEGORY_PRODUCT, 'label' => 'Товари'],
            ['key' => CannedResponse::CATEGORY_OTHER, 'label' => 'Інше'],
        ];
    }

    /**
     * Clear cache for tenant.
     */
    private function clearCache(int $tenantId): void
    {
        Cache::forget("canned_responses_{$tenantId}");
    }

    /**
     * Get default responses for Ukrainian e-commerce.
     */
    private function getDefaultResponses(): array
    {
        return [
            // Greetings
            [
                'title' => 'Вітання',
                'content' => "Вітаю! 👋 Я онлайн-консультант магазину. Чим можу допомогти?",
                'shortcut' => 'hi',
                'category' => CannedResponse::CATEGORY_GREETING,
            ],
            [
                'title' => 'Вітання з імʼям',
                'content' => "Вітаю, {{customer_name}}! 👋 Радий вас бачити. Чим можу допомогти?",
                'shortcut' => 'hiname',
                'category' => CannedResponse::CATEGORY_GREETING,
            ],
            
            // Farewell
            [
                'title' => 'Прощання',
                'content' => "Дякую за звернення! Якщо виникнуть питання — пишіть. Гарного дня! 😊",
                'shortcut' => 'bye',
                'category' => CannedResponse::CATEGORY_FAREWELL,
            ],
            [
                'title' => 'Прощання з подякою',
                'content' => "Дякую за замовлення! Очікуйте на дзвінок менеджера для підтвердження. До зустрічі! 🛍️",
                'shortcut' => 'byeorder',
                'category' => CannedResponse::CATEGORY_FAREWELL,
            ],

            // Delivery
            [
                'title' => 'Способи доставки',
                'content' => "Ми доставляємо по всій Україні:\n📦 Нова Пошта — 1-3 дні\n📦 Укрпошта — 3-7 днів\n🚗 Кур'єр по Києву — в день замовлення\n\nДоставка безкоштовна при замовленні від 1500 грн.",
                'shortcut' => 'delivery',
                'category' => CannedResponse::CATEGORY_DELIVERY,
            ],
            [
                'title' => 'Терміни доставки',
                'content' => "Зазвичай відправляємо замовлення в день оформлення (до 15:00) або наступного робочого дня.\n\nОрієнтовний термін доставки Новою Поштою — 1-2 дні.",
                'shortcut' => 'time',
                'category' => CannedResponse::CATEGORY_DELIVERY,
            ],
            [
                'title' => 'ТТН',
                'content' => "Номер вашої накладної: {{ttn}}\n\nВідстежити можна тут: https://novaposhta.ua/tracking/?cargo_number={{ttn}}",
                'shortcut' => 'ttn',
                'category' => CannedResponse::CATEGORY_DELIVERY,
            ],

            // Payment
            [
                'title' => 'Способи оплати',
                'content' => "Доступні способи оплати:\n💳 Карткою онлайн (Visa/Mastercard)\n💰 Накладений платіж (оплата при отриманні)\n🏦 Безготівковий розрахунок для юр. осіб\n\nПри оплаті онлайн — знижка 3%!",
                'shortcut' => 'pay',
                'category' => CannedResponse::CATEGORY_PAYMENT,
            ],
            [
                'title' => 'Оплачено',
                'content' => "Дякуємо! Оплату отримано ✅\n\nВаше замовлення #{{order_id}} передано на комплектацію. ТТН надішлемо SMS-кою.",
                'shortcut' => 'paid',
                'category' => CannedResponse::CATEGORY_PAYMENT,
            ],

            // Returns
            [
                'title' => 'Обмін та повернення',
                'content' => "Обмін або повернення можливий протягом 14 днів:\n• Товар не був у використанні\n• Збережено бірки та упаковку\n• Є чек або номер замовлення\n\nДля оформлення напишіть нам номер замовлення та причину повернення.",
                'shortcut' => 'return',
                'category' => CannedResponse::CATEGORY_RETURNS,
            ],
            [
                'title' => 'Не підійшов розмір',
                'content' => "Не біда! Обмін на інший розмір — безкоштовний.\n\n1. Відправте товар Новою Поштою (накладений платіж)\n2. Вкажіть потрібний розмір\n3. Ми відправимо заміну того ж дня\n\nПотрібна допомога з розміром?",
                'shortcut' => 'size',
                'category' => CannedResponse::CATEGORY_RETURNS,
            ],

            // Product
            [
                'title' => 'Наявність',
                'content' => "Перевіряю наявність товару... ⏳\n\nЗараз уточню на складі та повернуся з відповіддю.",
                'shortcut' => 'stock',
                'category' => CannedResponse::CATEGORY_PRODUCT,
            ],
            [
                'title' => 'Немає в наявності',
                'content' => "На жаль, цього товару зараз немає в наявності 😔\n\nМожу:\n• Повідомити, коли з'явиться\n• Запропонувати схожі варіанти\n\nЩо вам більше підходить?",
                'shortcut' => 'nostock',
                'category' => CannedResponse::CATEGORY_PRODUCT,
            ],

            // Other
            [
                'title' => 'Зачекайте',
                'content' => "Гарне питання! Дайте хвилинку, уточню інформацію... ⏳",
                'shortcut' => 'wait',
                'category' => CannedResponse::CATEGORY_OTHER,
            ],
            [
                'title' => 'Передаю менеджеру',
                'content' => "Зараз передам ваше питання спеціалісту. Він зв'яжеться з вами найближчим часом.\n\nДякую за очікування! 🙏",
                'shortcut' => 'manager',
                'category' => CannedResponse::CATEGORY_OTHER,
            ],
        ];
    }
}
