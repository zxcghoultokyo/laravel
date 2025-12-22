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

    public function mount()
    {
        $settings = WidgetSettingsModel::where('domain', $this->domain)->first();

        if ($settings) {
            $this->fill($settings->only([
                'primary_color', 'text_color', 'position', 'start_state',
                'border_radius', 'welcome_message', 'input_placeholder',
                'consent_notice', 'enabled', 'api_token',
                'shop_phone', 'callback_form_url', 'nova_poshta_tracking_url',
                'enable_delivery_tracking', 'enable_faq_from_horoshop', 'horoshop_domain'
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
        ]);

        WidgetSettingsModel::updateOrCreate(
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
            ]
        );

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

    public function render()
    {
        return view('livewire.admin.widget-settings')->layout('admin.layout');
    }
}
