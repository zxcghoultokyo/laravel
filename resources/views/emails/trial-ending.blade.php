@component('mail::message')
# Ваш тріал закінчується через {{ $daysLeft }} {{ $daysLeft == 1 ? 'день' : 'днів' }} ⏰

Привіт, **{{ $user->name }}**!

Ваш безкоштовний тріал-період закінчується **{{ $expiresAt->format('d.m.Y') }}**.

## Ваша статистика за тріал:

@component('mail::table')
| Показник | Значення |
|:---------|:---------|
| Діалогів | {{ $stats['dialogs'] ?? 0 }} |
| Повідомлень | {{ $stats['messages'] ?? 0 }} |
| Товарів показано | {{ $stats['products_shown'] ?? 0 }} |
@endcomponent

## Не втрачайте прогрес!

Оберіть тарифний план і продовжуйте користуватись AIntento без обмежень:

@component('mail::button', ['url' => $billingUrl, 'color' => 'success'])
Обрати тариф
@endcomponent

### Наші плани:

- **Starter** — 799 ₴/міс (1,000 повідомлень)
- **Pro** — 1,999 ₴/міс (5,000 повідомлень)
- **Enterprise** — індивідуально

Якщо потрібна допомога з вибором — напишіть нам!

З повагою,<br>
Команда {{ config('app.name') }}
@endcomponent
