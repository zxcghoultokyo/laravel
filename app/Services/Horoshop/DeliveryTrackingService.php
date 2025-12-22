<?php

namespace App\Services\Horoshop;

use App\Models\WidgetSettings;

/**
 * Service для обробки інформації про доставку замовлення
 * 
 * Правила:
 * - Якщо є Nova Poshta TTN → показати статус + посилання на tracking
 * - Якщо є статус → показати статус
 * - Якщо клієнт скаржиться → дати контакти магазина + форма зворотного зв'язку
 */
class DeliveryTrackingService
{
    private HoroshopDataService $dataService;

    public function __construct(HoroshopDataService $dataService)
    {
        $this->dataService = $dataService;
    }

    /**
     * Обробити та сформувати відповідь про доставку замовлення
     * 
     * @param array $order - замовлення з Horoshop API
     * @return array - структурована відповідь про доставку
     */
    public function formatDeliveryInfo(array $order): array
    {
        // Support both raw Horoshop response and normalized order from OrderService
        $raw = $order['_raw'] ?? $order;
        $deliveryData = $order['delivery']['data'] ?? ($order['delivery_data'] ?? ($raw['delivery_data'] ?? []));
        $status = $order['status_code'] ?? ($raw['stat_status'] ?? null);
        
        // TTN з Nova Poshta
        $novaPoshta = $this->extractNovaPoshta($deliveryData);
        
        // TTN з інших перевізників
        $otherTtn = $this->extractOtherTtn($deliveryData);
        
        // Статус доставки
        $deliveryStatus = $this->getDeliveryStatus($status);
        
        // Налаштування магазина
        $settings = WidgetSettings::first();
        
        $result = [
            'has_ttn' => !empty($novaPoshta) || !empty($otherTtn),
            'nova_poshta_ttn' => $novaPoshta,
            'other_ttn' => $otherTtn,
            'status' => $deliveryStatus,
            'delivery_type' => $this->getDeliveryType($deliveryData),
            'delivery_data' => $deliveryData,
        ];
        
        if (!empty($novaPoshta) && $settings) {
            $result['tracking_url'] = rtrim($settings->nova_poshta_tracking_url, '/') . "#{$novaPoshta}";
            $result['message'] = "🚚 Ваше замовлення відправлено!\n\n"
                . "Номер відправлення (ТТН): **{$novaPoshta}**\n\n"
                . "Перевірити статус доставки можна на сайті Нової Пошти:\n"
                . "[Відстежити замовлення]({$result['tracking_url']})";
        } elseif ($deliveryStatus) {
            $result['message'] = "📦 Статус замовлення: **{$deliveryStatus}**";
        } else {
            $result['message'] = "🔄 Інформація про доставку поки не доступна";
        }
        
        return $result;
    }

    /**
     * Сформувати відповідь для скарги/проблеми з замовленням
     * 
     * @return array - інформація про контакти та форму зворотного зв'язку
     */
    public function getIssueResolutionInfo(): array
    {
        $settings = WidgetSettings::first();
        
        return [
            'type' => 'order_issue',
            'message' => "😞 На жаль, виникла проблема з вашим замовленням.\n\n"
                . "Наша команда готова вам допомогти!\n\n"
                . "**Способи зв'язку:**\n\n"
                . "📞 Телефон: **{$settings->shop_phone}**\n\n"
                . "[Форма зворотного зв'язку]({$settings->callback_form_url})\n\n"
                . "Будь ласка, опишіть проблему, і ми швидко все вирішимо!",
            'phone' => $settings->shop_phone,
            'callback_url' => $settings->callback_form_url,
        ];
    }

    /**
     * Витягти TTN з Nova Poshta з delivery_data
     * 
     * @param array $deliveryData
     * @return string|null
     */
    private function extractNovaPoshta(array $deliveryData): ?string
    {
        // Шукаємо у різних можливих місцях
        if (isset($deliveryData['novaposhta_ttn'])) {
            return $deliveryData['novaposhta_ttn'];
        }
        
        if (isset($deliveryData['ttn'])) {
            return $deliveryData['ttn'];
        }
        
        if (isset($deliveryData['tracking_number'])) {
            return $deliveryData['tracking_number'];
        }
        
        return null;
    }

    /**
     * Витягти TTN з інших перевізників
     * 
     * @param array $deliveryData
     * @return array
     */
    private function extractOtherTtn(array $deliveryData): array
    {
        $ttn = [];
        
        // Укрпошта
        if (isset($deliveryData['ukrposhta_ttn'])) {
            $ttn['ukrposhta'] = $deliveryData['ukrposhta_ttn'];
        }
        
        // Інші перевізники
        if (isset($deliveryData['courier_ttn'])) {
            $ttn['courier'] = $deliveryData['courier_ttn'];
        }
        
        if (isset($deliveryData['intime_ttn'])) {
            $ttn['intime'] = $deliveryData['intime_ttn'];
        }
        
        if (isset($deliveryData['delyvery_ttn'])) {
            $ttn['delivery'] = $deliveryData['delyvery_ttn'];
        }
        
        return $ttn;
    }

    /**
     * Визначити статус доставки з Horoshop статус кодів
     * 
     * @param string|int|null $status
     * @return string|null
     */
    private function getDeliveryStatus($status): ?string
    {
        if (!$status) {
            return null;
        }
        
        $statusMap = [
            'new' => 'Нове замовлення',
            'processing' => 'Обробляється',
            'sent' => 'Відправлено',
            'delivered' => 'Доставлено',
            'cancelled' => 'Скасовано',
            'returned' => 'Повернено',
            'completed' => 'Завершено',
            1 => 'новий',
            2 => 'в обробці',
            3 => 'доставлено',
            4 => 'не доставлено',
            6 => 'доставляється',
        ];
        
        return $statusMap[$status] ?? $status;
    }

    /**
     * Визначити тип доставки
     * 
     * @param array $deliveryData
     * @return string|null
     */
    private function getDeliveryType(array $deliveryData): ?string
    {
        if (isset($deliveryData['delivery_type'])) {
            return $deliveryData['delivery_type'];
        }
        
        if (isset($deliveryData['delivery_title'])) {
            return $deliveryData['delivery_title'];
        }
        
        return null;
    }

    /**
     * Перевірити, чи це проблема з замовленням за текстом повідомлення
     * 
     * @param string $message
     * @return bool
     */
    public function isProblemReport(string $message): bool
    {
        $problemKeywords = [
            'не прийшло',
            'не пришло',
            'не пришол',
            'не пришла',
            'не прибыло',
            'не получил',
            'не получила',
            'не дошло',
            'невже',
            'не та',
            'не те',
            'неправильно',
            'неправильный',
            'помилка',
            'помилку',
            'ошибка',
            'ошибке',
            'проблема',
            'проблема',
            'не работает',
            'не роботит',
            'не робит',
            'сломано',
            'сломалось',
            'зламалось',
            'зламано',
            'дефект',
            'зарядить',
            'зарядилось',
            'зарядился',
            'батарея',
            'батареї',
            'скребеться',
            'скребется',
            'шумит',
            'шумить',
            'стучит',
            'стучить',
            'дребезжит',
            'дребезжить',
        ];
        
        $msgLower = mb_strtolower($message);
        
        foreach ($problemKeywords as $keyword) {
            if (str_contains($msgLower, $keyword)) {
                return true;
            }
        }
        
        return false;
    }
}
