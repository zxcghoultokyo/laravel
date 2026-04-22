<?php

namespace App\Console\Commands;

use App\Models\CannedResponse;
use App\Models\PromptPreset;
use App\Models\Tenant;
use App\Models\TenantKnowledge;
use App\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports the Bavka (T20) knowledge base from PDF exports converted
 * into storage/app/t20-knowledge/*.txt.
 *
 * Idempotent via external_id / shortcut / slug.
 */
class ImportT20KnowledgeCommand extends Command
{
    protected $signature = 't20:import-knowledge
        {--tenant-id= : Target tenant id (defaults to bavka tenant auto-detect)}
        {--templates=storage/app/t20-knowledge/templates.txt : Path to templates text}
        {--scripts=storage/app/t20-knowledge/scripts.txt : Path to scripts text}
        {--dry-run : Parse only, do not write}
        {--force : Overwrite existing entries}';

    protected $description = 'Import Bavka (T20) knowledge base: FAQ, product hints, sales scripts, tone overlay';

    private const SECTION_QA = 'qa';

    private const SECTION_SITUATIONAL = 'situational';

    private const SECTION_PRODUCT_HINTS = 'product_hints';

    private const SECTION_TRANSACTIONAL = 'transactional';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = (int) ($this->option('tenant-id') ?? 0);
        $tenant = null;
        if (! $tenantId) {
            $tenant = Tenant::query()
                ->where('horoshop_domain', 'like', '%bavka%')
                ->orWhere('name', 'like', '%bavka%')
                ->first();
            if ($tenant) {
                $tenantId = $tenant->id;
            }
        } else {
            $tenant = Tenant::query()->find($tenantId);
        }

        if (! $dryRun && ! $tenant) {
            $this->error('Tenant not found — pass --tenant-id=<id> (use --dry-run to parse without a tenant).');

            return self::FAILURE;
        }

        $templatesPath = base_path($this->option('templates'));
        if (! is_file($templatesPath)) {
            $this->error("Templates file not found: {$templatesPath}");

            return self::FAILURE;
        }

        $this->info('Tenant: '.($tenant ? "#{$tenantId} — {$tenant->name}" : '(none — dry-run)'));
        $this->info("Templates: {$templatesPath}");
        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be written.');
        }

        $blocks = $this->parseTemplateBlocks(file_get_contents($templatesPath));
        $this->info('Parsed '.count($blocks).' template blocks.');

        $faq = [];
        $productHints = [];
        $canned = [];
        foreach ($blocks as $block) {
            $row = $this->classifyBlock($block);
            if (! $row) {
                continue;
            }
            match ($row['_bucket']) {
                'faq' => $faq[] = $row,
                'product_hint' => $productHints[] = $row,
                'canned' => $canned[] = $row,
                default => null,
            };
        }

        $scripts = $this->buildCuratedScripts();

        $this->table(['Type', 'Count'], [
            ['FAQ', count($faq)],
            ['Product hints', count($productHints)],
            ['Canned templates', count($canned)],
            ['Scripts (curated)', count($scripts)],
        ]);

        if ($dryRun) {
            $this->line('');
            $this->line('Sample FAQ:');
            foreach (array_slice($faq, 0, 3) as $row) {
                $this->line('  • '.Str::limit($row['question'] ?? '(no q)', 80));
            }
            $this->line('Sample product hints:');
            foreach (array_slice($productHints, 0, 3) as $row) {
                $this->line('  • '.Str::limit($row['question'] ?? $row['answer'], 80));
            }

            return self::SUCCESS;
        }

        DB::transaction(function () use ($tenantId, $faq, $productHints, $canned, $scripts) {
            foreach ($faq as $row) {
                $this->writeKnowledge($tenantId, TenantKnowledge::TYPE_FAQ, $row);
            }
            foreach ($productHints as $row) {
                $this->writeKnowledge($tenantId, TenantKnowledge::TYPE_PRODUCT_HINT, $row);
            }
            foreach ($scripts as $row) {
                $this->writeKnowledge($tenantId, TenantKnowledge::TYPE_SCRIPT, $row);
            }
            foreach ($canned as $row) {
                $this->writeCanned($tenantId, $row);
            }
            $this->writeToneOverlay($tenantId);
        });

        $this->info('Import complete.');

        return self::SUCCESS;
    }

    /**
     * @return array<int,array{date:string,author:string,content:string,section:string}>
     */
    private function parseTemplateBlocks(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $sectionMarkers = [
            'Запитання - відповідь' => self::SECTION_QA,
            'Ситуативні' => self::SECTION_SITUATIONAL,
            'Рекомендація товарів' => self::SECTION_PRODUCT_HINTS,
            'Малюки:' => self::SECTION_PRODUCT_HINTS,
            'Тодлери:' => self::SECTION_PRODUCT_HINTS,
            'Дошкільнята:' => self::SECTION_PRODUCT_HINTS,
        ];

        $lines = explode("\n", $text);
        $currentSection = self::SECTION_TRANSACTIONAL;
        $blocks = [];
        $current = null;

        foreach ($lines as $line) {
            $stripped = trim(preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00A0}\x{2060}\x{00AD}\s]+$/u', '', $line));

            foreach ($sectionMarkers as $marker => $section) {
                if (mb_stripos($stripped, $marker) !== false && mb_strlen($stripped) < 60) {
                    $currentSection = $section;

                    continue 2;
                }
            }

            if (preg_match('/^\[(\d{1,2}\.\d{1,2}\.\d{4} \d{1,2}:\d{2})\]\s+([^:]+?):\s*(.*)$/u', $line, $m)) {
                if ($current) {
                    $blocks[] = $current;
                }
                $current = [
                    'date' => $m[1],
                    'author' => trim($m[2]),
                    'content' => trim($m[3]),
                    'section' => $currentSection,
                ];

                continue;
            }

            if ($current !== null) {
                $current['content'] .= ($current['content'] === '' ? '' : "\n").$line;
            }
        }
        if ($current) {
            $blocks[] = $current;
        }

        foreach ($blocks as &$b) {
            $b['content'] = trim(preg_replace("/\n{3,}/", "\n\n", $b['content']));
        }
        unset($b);

        return array_values(array_filter($blocks, fn ($b) => $b['content'] !== ''));
    }

    /**
     * @param  array{date:string,author:string,content:string,section:string}  $block
     * @return array<string,mixed>|null
     */
    private function classifyBlock(array $block): ?array
    {
        $content = $block['content'];
        if (mb_strlen($content) < 20) {
            return null;
        }

        $firstLine = $this->firstLine($content);
        $trimmed = rtrim($firstLine, " \t\n\r\0\x0B​");
        $looksLikeQuestion = str_ends_with($trimmed, '?');

        $hasPriceMarker = (bool) preg_match('/\d{2,}\s*грн/u', $content);
        $inProductSection = $block['section'] === self::SECTION_PRODUCT_HINTS;

        // Strict: product hints only inside product-hint sections AND with a price.
        if ($inProductSection && $hasPriceMarker) {
            return $this->buildProductHint($block);
        }

        // FAQ: explicit Q&A section OR the first line is clearly a question.
        if ($block['section'] === self::SECTION_QA || $looksLikeQuestion) {
            return [
                '_bucket' => 'faq',
                'question' => mb_substr($firstLine, 0, 255),
                'answer' => $content,
                'keywords' => $this->extractKeywords($firstLine.' '.$content),
                'category' => $block['section'],
                'external_id' => 'bavka-faq-'.substr(md5('faq|'.$firstLine), 0, 12),
                'source' => 'templates.pdf#'.$block['date'],
                'priority' => 50,
            ];
        }

        return [
            '_bucket' => 'canned',
            'title' => $this->deriveTitle($firstLine, $block['date']),
            'content' => $content,
            'shortcut' => 'bavka-'.substr(md5($content), 0, 10),
            'category' => $this->guessCannedCategory($content),
        ];
    }

    /**
     * @param  array<string,mixed>  $block
     * @return array<string,mixed>
     */
    private function buildProductHint(array $block): array
    {
        $content = $block['content'];
        $firstLine = $this->firstLine($content);
        preg_match('/https?:\/\/[^\s)]+/u', $content, $urlMatch);
        $url = $urlMatch[0] ?? null;

        $articleHints = [];
        if ($url && preg_match('#bavkatoys\.com/[^\s]*product-page/([^/?\s]+)#u', $url, $m)) {
            $articleHints[] = $m[1];
        }

        return [
            '_bucket' => 'product_hint',
            'question' => mb_substr($firstLine, 0, 255),
            'answer' => $content,
            'keywords' => $this->extractKeywords($firstLine),
            'articles' => $articleHints,
            'category' => $block['section'],
            'external_id' => 'bavka-hint-'.substr(md5('hint|'.$firstLine), 0, 12),
            'source' => 'templates.pdf#'.$block['date'],
            'priority' => 40,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function buildCuratedScripts(): array
    {
        return [
            [
                'question' => 'Стиль спілкування bavka',
                'answer' => "- Звертайся на «Ви» та називай клієнта по імені, якщо воно відоме.\n".
                    "- Представляйся як помічник bavka.\n".
                    "- Вітайся через «Добрий день/ранок/вечір» (без «Привіт»).\n".
                    "- Уникай канцеляризмів та кліше типу «Нам цінна ваша думка» — веди живу розмову.\n".
                    "- Закінчуй теплим побажанням.\n".
                    '- Смайли — максимум 2 на абзац.',
                'keywords' => ['тон', 'стиль', 'привітання', 'bavka'],
                'external_id' => 'bavka-script-tone',
                'priority' => 80,
            ],
            [
                'question' => 'Як діяти, коли товар є в наявності',
                'answer' => "1) Привітайся, назви імʼя.\n".
                    "2) Підтверди наявність іграшки та її вартість.\n".
                    "3) Скажи про способи відправки: накладним платежем (тільки Нова Пошта) або повна оплата за реквізитами.\n".
                    '4) Запитай, що зручніше клієнту.',
                'keywords' => ['наявності', 'купити', 'оплата', 'відправка'],
                'external_id' => 'bavka-script-in-stock',
                'priority' => 70,
            ],
            [
                'question' => 'Як діяти, коли товару немає в наявності',
                'answer' => "- Вибач, що немає зараз.\n".
                    "- Повідом орієнтовний термін появи.\n".
                    "- Запитай, чи готовий клієнт зачекати — обіцяй сповістити.\n".
                    '- За бажанням запропонуй аналог по функціоналу.',
                'keywords' => ['немає', 'очікуємо', 'аналог'],
                'external_id' => 'bavka-script-oos',
                'priority' => 70,
            ],
            [
                'question' => 'Запитання про знижку',
                'answer' => 'Дякуємо за запит! Окремої програми лояльності немає, великих сезонних розпродажів теж не робимо. '.
                    'Є вигідні набори, акція «Разом вигідніше», безкоштовна подарункова упаковка до свят. Постійним клієнтам '.
                    '(від 10 000 грн замовлень) можемо запропонувати безкоштовну доставку, сертифікат на 250 грн на наступну '.
                    'покупку або 5% знижку. Підкажіть, які матеріали Ви обрали — перевіримо можливість вигідної пропозиції.',
                'keywords' => ['знижка', 'акція', 'розпродаж', 'промокод'],
                'external_id' => 'bavka-script-discount',
                'priority' => 75,
            ],
            [
                'question' => 'Дитячий центр / навчальний заклад',
                'answer' => 'Дякуємо, що розглядаєте bavka для дитячого центру! Пропонуємо готові набори з включеною знижкою '.
                    'та пропозицію «Разом вигідніше». Підкажіть, що саме плануєте замовити — перевіримо можливість додаткової вигоди.',
                'keywords' => ['центр', 'навчальний', 'заклад', 'садочок'],
                'external_id' => 'bavka-script-b2b',
                'priority' => 70,
            ],
            [
                'question' => 'Дропшипінг / співпраця',
                'answer' => 'Дякуємо за інтерес! Наразі ми не працюємо за моделлю дропшипінгу — зосереджені на внутрішніх '.
                    'процесах, які дозволяють тримати якість сервісу. Дуже приємно, що розглядали партнерство саме з нами.',
                'keywords' => ['дропшипінг', 'співпраця', 'партнер'],
                'external_id' => 'bavka-script-dropship',
                'priority' => 60,
            ],
        ];
    }

    private function writeToneOverlay(int $tenantId): void
    {
        $existing = PromptPreset::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('slug', 'bavka-tone')
            ->first();

        $payload = [
            'tenant_id' => $tenantId,
            'name' => 'bavka tone',
            'slug' => 'bavka-tone',
            'description' => 'Тон розмови для магазину bavka.',
            'system_prompt' => "ТОН РОЗМОВИ (bavka):\n".
                "- Звертайся до клієнта на «Ви», називай по імені, якщо воно відоме.\n".
                "- Вітайся «Добрий день/ранок/вечір», не «Привіт».\n".
                "- Тепло, живо, без канцеляризмів («Нам цінна ваша думка»).\n".
                "- Смайли помірно — максимум 2 на абзац.\n".
                '- Якщо клієнт питає про доставку/оплату/повернення/безпеку/знижки/сертифікати — спочатку виклич lookup_knowledge_base, не вигадуй відповідь.',
            'language' => 'uk',
            'tone' => 'friendly',
            'is_active' => true,
            'is_default' => false,
            'priority' => 90,
        ];

        if ($existing) {
            if ($this->option('force')) {
                $existing->fill($payload)->save();
                $this->line('  ↻ updated tone overlay preset');
            } else {
                $this->line('  · tone overlay preset already exists (use --force to overwrite)');
            }

            return;
        }

        PromptPreset::create($payload);
        $this->line('  + created tone overlay preset');
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function writeKnowledge(int $tenantId, string $type, array $row): void
    {
        $externalId = $row['external_id'];
        $existing = TenantKnowledge::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('external_id', $externalId)
            ->first();

        $data = [
            'tenant_id' => $tenantId,
            'type' => $type,
            'question' => $row['question'] ?? null,
            'answer' => $row['answer'],
            'keywords' => $row['keywords'] ?? [],
            'articles' => $row['articles'] ?? [],
            'category' => $row['category'] ?? null,
            'language' => 'uk',
            'priority' => $row['priority'] ?? 50,
            'is_active' => true,
            'source' => $row['source'] ?? 'bavka-curated',
            'external_id' => $externalId,
        ];

        if ($existing) {
            if ($this->option('force')) {
                $existing->fill($data)->save();
            }

            return;
        }

        TenantKnowledge::create($data);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function writeCanned(int $tenantId, array $row): void
    {
        $shortcut = $row['shortcut'];
        $existing = CannedResponse::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('shortcut', $shortcut)
            ->first();

        $data = [
            'tenant_id' => $tenantId,
            'title' => mb_substr($row['title'], 0, 255),
            'content' => $row['content'],
            'shortcut' => $shortcut,
            'category' => $row['category'],
            'is_active' => true,
        ];

        if ($existing) {
            if ($this->option('force')) {
                $existing->fill($data)->save();
            }

            return;
        }

        CannedResponse::create($data);
    }

    private function firstLine(string $text): string
    {
        foreach (preg_split("/\n+/", $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }

    private function deriveTitle(string $firstLine, string $date): string
    {
        $title = Str::limit(strip_tags($firstLine), 80, '…');

        return $title !== '' ? $title : 'Шаблон '.$date;
    }

    private function guessCannedCategory(string $content): string
    {
        $lc = mb_strtolower($content);

        return match (true) {
            str_contains($lc, 'ттн') || str_contains($lc, 'відстеження') => CannedResponse::CATEGORY_DELIVERY,
            str_contains($lc, 'реквізит') || str_contains($lc, 'iban') || str_contains($lc, 'оплат') => CannedResponse::CATEGORY_PAYMENT,
            str_contains($lc, 'повернен') => CannedResponse::CATEGORY_RETURNS,
            str_contains($lc, 'добрий день') || str_contains($lc, 'добрий вечір') || str_contains($lc, 'добрий ранок') => CannedResponse::CATEGORY_GREETING,
            str_contains($lc, 'дякуємо, що обрали') => CannedResponse::CATEGORY_FAREWELL,
            default => CannedResponse::CATEGORY_OTHER,
        };
    }

    /**
     * @return array<int,string>
     */
    private function extractKeywords(string $text): array
    {
        $stop = [
            'і', 'й', 'та', 'а', 'але', 'або', 'чи', 'як', 'що', 'це', 'є', 'не', 'на', 'в', 'у',
            'до', 'з', 'для', 'про', 'від', 'при', 'за', 'по', 'так', 'ні', 'ви', 'ти', 'ми',
            'будь', 'ласка', 'можна', 'треба', 'хочу', 'хочемо', 'який', 'яка', 'яке', 'які', 'коли',
            'ось', 'бо', 'вже', 'ще', 'також', 'тут', 'там', 'де', 'куди', 'звідки', 'чому', 'тож', 'адже',
        ];
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) < 4) {
                continue;
            }
            if (in_array($p, $stop, true)) {
                continue;
            }
            $out[$p] = true;
            if (count($out) >= 10) {
                break;
            }
        }

        return array_keys($out);
    }
}
