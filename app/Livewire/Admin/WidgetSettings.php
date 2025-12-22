<?php

namespace App\Livewire\Admin;

use App\Models\WidgetSettings as WidgetSettingsModel;
use Livewire\Component;

class WidgetSettings extends Component
{
    public $domain = 'default';
    public $primary_color = '#2563eb';
    public $text_color = '#ffffff';
    public $position = 'right';
    public $start_state = 'closed';
    public $border_radius = 12;
    public $welcome_message = 'Вітаю! 👋 Я AILure Асистент. Напишіть, що шукаєте.';
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

        // Auto-ingest FAQ content if URLs provided
        try {
            $service = app(\App\Services\Support\FaqContentIngestService::class);
            $service->ingest($settings);
            session()->flash('message', 'Налаштування збережено! (FAQ імпортовано)');
        } catch (\Throwable $e) {
            // Non-fatal: show message but do not break save
            session()->flash('message', 'Налаштування збережено! (Імпорт FAQ: ' . $e->getMessage() . ')');
        }
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

    public function render()
    {
        return view('livewire.admin.widget-settings')->layout('admin.layout');
    }
}
