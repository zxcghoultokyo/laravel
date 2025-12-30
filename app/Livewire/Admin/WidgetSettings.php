<?php

namespace App\Livewire\Admin;

use App\Models\WidgetSettings as WidgetSettingsModel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Livewire\Component;

class WidgetSettings extends Component
{
    public $domain = 'default';
    public $primary_color = '#2563eb';
    public $text_color = '#ffffff';
    public $position = 'right';
    public $start_state = 'closed';
    public $border_radius = 12;
    public $welcome_message = 'Вітаю! 👋 Я AIntento — ваш персональний помічник з підбору спорядження. Чим можу допомогти?';
    public $input_placeholder = 'Напишіть, що шукаєте...';
    public $consent_notice = '';
    public $enabled = true;
    public $api_token = '';
    public $shop_phone = '+380 63 631 9919';
    public $callback_form_url = 'https://contractor.kiev.ua/kontaktna-informatsiya/#callback';
    public $nova_poshta_tracking_url = 'https://tracking.novaposhta.ua/';
    public $enable_delivery_tracking = true;
    public $enable_faq_from_horoshop = true;
    public $horoshop_domain = '';
    public $enable_faq_custom_content = true;
    public $faq_payment_delivery_url = 'https://contractor.kiev.ua/oplata-i-dostavka/';
    public $faq_payment_delivery_text = '';
    public $faq_returns_url = 'https://contractor.kiev.ua/obmin-ta-povernennya/';
    public $faq_returns_text = '';
    public $faq_contacts_url = 'https://contractor.kiev.ua/kontaktna-informatsiya/';
    public $faq_contacts_text = '';
    public $faq_about_url = 'https://contractor.kiev.ua/pro-nas/';
    public $faq_about_text = '';
    public $faq_last_ingest_at = null;
    public $can_fetch_now = true;
    public $next_fetch_time = null;

    public function mount()
    {
        $settings = WidgetSettingsModel::where('domain', $this->domain)->first();

        if ($settings) {
            $this->fill($settings->only([
                'primary_color', 'text_color', 'position', 'start_state',
                'border_radius', 'welcome_message', 'input_placeholder',
                'consent_notice', 'enabled', 'api_token',
                'shop_phone', 'callback_form_url', 'nova_poshta_tracking_url',
                'enable_delivery_tracking', 'enable_faq_from_horoshop', 'horoshop_domain',
                'enable_faq_custom_content',
                'faq_payment_delivery_url','faq_payment_delivery_text',
                'faq_returns_url','faq_returns_text',
                'faq_contacts_url','faq_contacts_text',
                'faq_about_url','faq_about_text'
            ]));
        }

        // Load last ingest time from cache and compute availability
        $key = $this->getIngestCacheKey();
        $last = Cache::get($key);
        if ($last) {
            $this->faq_last_ingest_at = $last;
            $lastAt = Carbon::parse($last);
            $hours = $lastAt->diffInHours(now());
            $this->can_fetch_now = $hours >= 24;
            $this->next_fetch_time = $lastAt->copy()->addHours(24)->format('Y-m-d H:i');
        } else {
            $this->can_fetch_now = true;
        }
    }

    public function save()
    {
        $this->validate([
            'primary_color' => 'required|string',
            'text_color' => 'required|string',
            'position' => 'required|in:left,right',
            'start_state' => 'required|in:open,closed',
            'border_radius' => 'required|integer|min:0|max:50',
            'welcome_message' => 'required|string|max:500',
            'input_placeholder' => 'required|string|max:200',
            'shop_phone' => 'required|string|max:50',
            'callback_form_url' => 'required|url|max:255',
            'nova_poshta_tracking_url' => 'required|url|max:255',
            'horoshop_domain' => 'nullable|url|max:255',
            'faq_payment_delivery_url' => 'nullable|url|max:255',
            'faq_returns_url' => 'nullable|url|max:255',
            'faq_contacts_url' => 'nullable|url|max:255',
            'faq_about_url' => 'nullable|url|max:255',
            'faq_payment_delivery_text' => 'nullable|string|max:2000',
            'faq_returns_text' => 'nullable|string|max:2000',
            'faq_contacts_text' => 'nullable|string|max:2000',
            'faq_about_text' => 'nullable|string|max:2000',
        ]);

        $settings = WidgetSettingsModel::updateOrCreate(
            ['domain' => $this->domain],
            [
                'primary_color' => $this->primary_color,
                'text_color' => $this->text_color,
                'position' => $this->position,
                'start_state' => $this->start_state,
                'border_radius' => $this->border_radius,
                'welcome_message' => $this->welcome_message,
                'input_placeholder' => $this->input_placeholder,
                'consent_notice' => $this->consent_notice,
                'enabled' => $this->enabled,
                'shop_phone' => $this->shop_phone,
                'callback_form_url' => $this->callback_form_url,
                'nova_poshta_tracking_url' => $this->nova_poshta_tracking_url,
                'enable_delivery_tracking' => $this->enable_delivery_tracking,
                'enable_faq_from_horoshop' => $this->enable_faq_from_horoshop,
                'horoshop_domain' => $this->horoshop_domain,
                'enable_faq_custom_content' => $this->enable_faq_custom_content,
                'faq_payment_delivery_url' => $this->faq_payment_delivery_url,
                'faq_payment_delivery_text' => $this->faq_payment_delivery_text,
                'faq_returns_url' => $this->faq_returns_url,
                'faq_returns_text' => $this->faq_returns_text,
                'faq_contacts_url' => $this->faq_contacts_url,
                'faq_contacts_text' => $this->faq_contacts_text,
                'faq_about_url' => $this->faq_about_url,
                'faq_about_text' => $this->faq_about_text,
            ]
        );

        // Manual-only: do NOT auto-ingest on save
        session()->flash('message', 'Налаштування збережено!');
    }

    public function regenerateToken()
    {
        $settings = WidgetSettingsModel::where('domain', $this->domain)->first();
        if ($settings) {
            $settings->update(['api_token' => bin2hex(random_bytes(32))]);
            $this->api_token = $settings->api_token;
            session()->flash('message', 'Токен оновлено!');
        }
    }

    public function fetchFaqNow()
    {
        // Manually re-ingest FAQ content from configured URLs without changing other settings
        $settings = WidgetSettingsModel::where('domain', $this->domain)->first();
        if (!$settings) {
            session()->flash('message', 'Немає налаштувань для домену. Спершу збережіть форму.');
            return;
        }

        // Rate limit: once per 24 hours
        $key = $this->getIngestCacheKey();
        $last = Cache::get($key);
        if ($last) {
            $lastAt = Carbon::parse($last);
            if ($lastAt->diffInHours(now()) < 24) {
                $next = $lastAt->copy()->addHours(24)->format('Y-m-d H:i');
                $this->faq_last_ingest_at = $last;
                $this->can_fetch_now = false;
                $this->next_fetch_time = $next;
                session()->flash('message', 'Оновлення доступне лише раз на день. Наступна спроба: ' . $next);
                return;
            }
        }

        try {
            $service = app(\App\Services\Support\FaqContentIngestService::class);
            $service->ingest($settings);
            // Reload texts into component state (so user sees updates immediately)
            $this->faq_payment_delivery_text = $settings->faq_payment_delivery_text;
            $this->faq_returns_text = $settings->faq_returns_text;
            $this->faq_contacts_text = $settings->faq_contacts_text;
            $this->faq_about_text = $settings->faq_about_text;

            // Store last ingest time in cache and update state
            $nowIso = now()->toISOString();
            Cache::put($key, $nowIso, 60 * 60 * 24 * 365); // keep record ~1 year
            $this->faq_last_ingest_at = $nowIso;
            $this->can_fetch_now = false;
            $this->next_fetch_time = now()->addDay()->format('Y-m-d H:i');

            session()->flash('message', 'FAQ контент перезавантажено з сторінок! (доступно не частіше 1 разу на день)');
        } catch (\Throwable $e) {
            session()->flash('message', 'Помилка імпорту FAQ: ' . $e->getMessage());
        }
    }

    private function getIngestCacheKey(): string
    {
        return 'faq_ingest_last_' . ($this->domain ?: 'default');
    }

    public function render()
    {
        return view('livewire.admin.widget-settings')->layout('admin.layout');
    }
}
