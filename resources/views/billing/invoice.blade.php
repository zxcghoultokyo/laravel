<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Квитанція #{{ $payment->id }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 40px 20px;
            color: #333;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e5e5e5;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #2563eb;
        }
        .invoice-title {
            text-align: right;
        }
        .invoice-title h1 {
            margin: 0;
            font-size: 28px;
            color: #333;
        }
        .invoice-number {
            color: #666;
            font-size: 14px;
        }
        .details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }
        .details h3 {
            margin: 0 0 10px;
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .details p {
            margin: 5px 0;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .table th {
            background: #f9fafb;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid #e5e5e5;
        }
        .table td {
            padding: 12px;
            border-bottom: 1px solid #e5e5e5;
        }
        .table .amount {
            text-align: right;
        }
        .total {
            text-align: right;
            padding-top: 20px;
        }
        .total-row {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 10px;
        }
        .total-label {
            width: 150px;
            text-align: right;
            padding-right: 20px;
            color: #666;
        }
        .total-value {
            width: 100px;
            text-align: right;
            font-weight: 600;
        }
        .total-final {
            font-size: 20px;
            border-top: 2px solid #333;
            padding-top: 10px;
        }
        .footer {
            margin-top: 60px;
            padding-top: 20px;
            border-top: 1px solid #e5e5e5;
            color: #666;
            font-size: 12px;
            text-align: center;
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-success {
            background: #d1fae5;
            color: #065f46;
        }
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer;">
            🖨️ Друкувати
        </button>
    </div>

    <div class="header">
        <div class="logo">Ailure AI</div>
        <div class="invoice-title">
            <h1>Квитанція</h1>
            <p class="invoice-number">#{{ str_pad($payment->id, 6, '0', STR_PAD_LEFT) }}</p>
            <span class="status status-success">Оплачено</span>
        </div>
    </div>

    <div class="details">
        <div class="from">
            <h3>Від</h3>
            <p><strong>Ailure AI</strong></p>
            <p>{{ config('app.url') }}</p>
        </div>
        <div class="to">
            <h3>Для</h3>
            <p><strong>{{ $tenant->name }}</strong></p>
            <p>{{ $tenant->email }}</p>
        </div>
    </div>

    <div class="details">
        <div>
            <h3>Дата оплати</h3>
            <p>{{ $payment->paid_at?->format('d.m.Y H:i') ?? $payment->created_at->format('d.m.Y H:i') }}</p>
        </div>
        <div>
            <h3>Спосіб оплати</h3>
            <p>
                {{ ucfirst($payment->provider) }}
                @if($payment->card_mask)
                    <br>{{ $payment->card_mask }}
                    @if($payment->card_type) ({{ $payment->card_type }}) @endif
                @endif
            </p>
        </div>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Опис</th>
                <th>Період</th>
                <th class="amount">Сума</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    {{ $payment->description ?? 'Підписка Ailure AI' }}
                    @if($payment->subscription)
                        <br><small style="color: #666;">План: {{ $payment->subscription->plan_id }}</small>
                    @endif
                </td>
                <td>
                    @if($payment->subscription && $payment->subscription->current_period_start)
                        {{ $payment->subscription->current_period_start->format('d.m.Y') }} -
                        {{ $payment->subscription->current_period_end->format('d.m.Y') }}
                    @else
                        {{ $payment->created_at->format('F Y') }}
                    @endif
                </td>
                <td class="amount">{{ $payment->formatted_amount }}</td>
            </tr>
        </tbody>
    </table>

    <div class="total">
        <div class="total-row total-final">
            <div class="total-label">Всього:</div>
            <div class="total-value">{{ $payment->formatted_amount }}</div>
        </div>
    </div>

    <div class="footer">
        <p>Дякуємо за використання Ailure AI!</p>
        <p>ID транзакції: {{ $payment->provider_payment_id ?? $payment->provider_order_id ?? '-' }}</p>
        <p style="margin-top: 20px; font-size: 11px; color: #999;">
            Цей документ є підтвердженням оплати і не є податковим документом.
            Для отримання фіскального чеку зверніться до підтримки.
        </p>
    </div>
</body>
</html>
