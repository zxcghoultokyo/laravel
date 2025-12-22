# 💬 Chat Implementation Guide — Як Інтегрувати Widget

> **Остання оновлення**: 22.12.2025  
> **Для**: Клієнтів, які хочуть додати чат на свій сайт  
> **Складність**: ⭐ Легко (5 хвилин)

---

## 📋 Зміст
1. [Швидкий Старт](#швидкий-старт)
2. [Детальна Інструкція](#детальна-інструкція)
3. [Налаштування](#налаштування)
4. [Стилізація](#стилізація)
5. [Troubleshooting](#troubleshooting)

---

## Швидкий Старт

### 3 Кроки до Робочого Чату

#### 1. Додайте код перед `</body>`
```html
<div id="ailure-chat" data-token="YOUR_TOKEN"></div>
<script src="https://aimbot.laravel.cloud/widget.js"></script>
```

#### 2. Замініть `YOUR_TOKEN` на ваш унікальний токен
Отримайте токен від адміністратора або в панелі управління.

#### 3. Відкрийте сайт
Повинна з'явитися кнопка чату в правому нижньому куті 🎉

---

## Детальна Інструкція

### Крок 1: Підготовка

#### Вимоги
- ✅ HTML сайт (WordPress, Shopify, custom)
- ✅ Доступ до редагування HTML коду
- ✅ Токен від Contractor AI Shop

---

### Крок 2: Отримання Токену

#### Метод 1: Через Admin Panel (Recommended)
```
1. Перейдіть на https://aimbot.laravel.cloud/admin
2. Login → Widget Settings
3. Click "Generate New Token"
4. Copy token (наприклад: abc123xyz789)
```

#### Метод 2: Запит у Support
```
Email: support@contractor.ua
Subject: Widget Token Request
Body: 
  Сайт: example.com
  Тип бізнесу: Військовий магазин
  Очікувана кількість користувачів: 1000/month
```

**Response Time**: 1-2 години в робочий час

---

### Крок 3: Додавання Коду

#### Для WordPress

**Метод 1: Theme Footer**
```php
// wp-content/themes/your-theme/footer.php
// Перед <?php wp_footer(); ?>

<div id="ailure-chat" data-token="YOUR_TOKEN"></div>
<script src="https://aimbot.laravel.cloud/widget.js"></script>
```

**Метод 2: Plugin "Insert Headers and Footers"**
```
1. Install plugin "Insert Headers and Footers"
2. Settings → Insert Headers and Footers
3. Footer Scripts → Paste code
4. Save
```

---

#### Для Shopify

```liquid
// Layout → theme.liquid
// Перед </body>

<div id="ailure-chat" data-token="YOUR_TOKEN"></div>
<script src="https://aimbot.laravel.cloud/widget.js"></script>
```

---

#### Для Custom HTML Site

```html
<!DOCTYPE html>
<html>
<head>
    <title>Мій Магазин</title>
</head>
<body>
    <!-- Ваш контент -->
    
    <h1>Тактичне Спорядження</h1>
    <p>Каталог товарів...</p>
    
    <!-- Widget (в кінці body) -->
    <div id="ailure-chat" data-token="abc123xyz789"></div>
    <script src="https://aimbot.laravel.cloud/widget.js"></script>
</body>
</html>
```

**Важливо**: Код повинен бути перед закриваючим тегом `</body>`, не в `<head>`!

---

### Крок 4: Перевірка

#### Відкрийте сайт у браузері
1. Refresh сторінку (Ctrl+R / Cmd+R)
2. Перевірте правий нижній кут — має з'явитись кнопка 💬
3. Натисніть кнопку → має відкритись вікно чату
4. Напишіть "привіт" → має прийти відповідь

#### Якщо не працює
1. Відкрийте Developer Console (F12)
2. Перевірте Console на помилки:
   - ❌ `404 Not Found widget.js` → неправильний URL
   - ❌ `CORS error` → зверніться в support
   - ❌ `Token invalid` → перевірте токен

---

## Налаштування

### Базові Налаштування (через HTML)

#### Заголовок Чату
```html
<div 
    id="ailure-chat" 
    data-token="YOUR_TOKEN"
    data-chat-title="Військовий AI Консультант"
></div>
```

#### Колір Теми
```html
<div 
    id="ailure-chat" 
    data-token="YOUR_TOKEN"
    data-primary-color="#FF5733"
    data-header-bg="#1A1A1A"
></div>
```

#### Позиція
```html
<div 
    id="ailure-chat" 
    data-token="YOUR_TOKEN"
    data-position="bottom-left"
></div>
```

**Варіанти**: `bottom-right` (default), `bottom-left`, `top-right`, `top-left`

---

### Розширені Налаштування (через API)

Widget може завантажувати налаштування з API автоматично.

**Backend Response** (`/api/widget/settings`):
```json
{
  "chatTitle": "Contractor AI",
  "welcomeMessage": "Вітаю! Шукаєте тактичне спорядження?",
  "primaryColor": "#4F46E5",
  "headerBg": "#1E293B",
  "position": "bottom-right",
  "placeholder": "Наприклад: шолом мультикам",
  "sendButtonText": "Відправити"
}
```

**Priority**: API settings > HTML data-* attributes > defaults

---

## Стилізація

### Custom CSS (Override Styles)

#### Змінити розмір кнопки
```css
/* Додайте в ваш CSS файл */
#ailure-chat-toggle {
    width: 70px !important;
    height: 70px !important;
}
```

#### Змінити колір кнопки
```css
#ailure-chat-toggle {
    background-color: #FF5733 !important;
}

#ailure-chat-toggle:hover {
    background-color: #E64A2E !important;
}
```

#### Змінити позицію чату
```css
.ailure-widget {
    right: auto !important;
    left: 20px !important;
}
```

#### Змінити шрифт
```css
.ailure-widget * {
    font-family: 'Roboto', sans-serif !important;
}
```

---

### Responsive Design

Widget автоматично адаптується під екран:

#### Desktop (>768px)
- Chat window: 400px ширина, 600px висота
- Toggle button: 60px × 60px

#### Mobile (<768px)
- Chat window: 100% ширина, 80% висота
- Toggle button: 50px × 50px (менша)

**Custom Breakpoint**:
```css
@media (max-width: 480px) {
    .ailure-widget {
        bottom: 10px !important;
        right: 10px !important;
    }
}
```

---

## Troubleshooting

### 🔴 Кнопка Не З'являється

**Можливі причини**:

1. **Неправильний ID контейнера**
   ```html
   <!-- ❌ Неправильно -->
   <div data-token="abc"></div>
   
   <!-- ✅ Правильно -->
   <div id="ailure-chat" data-token="abc"></div>
   ```

2. **Script не завантажився**
   - Перевірте F12 → Network → widget.js (має бути 200 OK)
   - Перевірте Console на помилки

3. **Токен відсутній**
   ```html
   <!-- ❌ Неправильно -->
   <div id="ailure-chat"></div>
   
   <!-- ✅ Правильно -->
   <div id="ailure-chat" data-token="YOUR_TOKEN"></div>
   ```

---

### 🔴 Чат Відкривається, Але Не Відповідає

**Можливі причини**:

1. **CORS помилка**
   - Console: `Access-Control-Allow-Origin`
   - **Рішення**: Зверніться в support для whitelist вашого домену

2. **Невалідний токен**
   - Response: `401 Unauthorized`
   - **Рішення**: Перевірте токен або згенеруйте новий

3. **API недоступний**
   - Response: `500 Internal Server Error`
   - **Рішення**: Почекайте 5 хвилин або зверніться в support

---

### ⚠️ Повільна Відповідь (>5 секунд)

**Можливі причини**:

1. **AI processing** — нормально для складних запитів (600-900ms)
2. **Slow network** — перевірте швидкість інтернету
3. **High load** — піковий час використання

**Workaround**: Показуйте "typing..." indicator (вже реалізовано)

---

### 💡 Widget Конфліктує з Іншими Скриптами

**Симптоми**: Console errors, broken layout

**Рішення**:
1. **Завантажуйте widget останнім** (в кінці body)
2. **Перевірте z-index** конфліктів:
   ```css
   /* Якщо widget під іншими елементами */
   #ailure-chat-window {
       z-index: 999999 !important;
   }
   ```

---

## Best Practices

### 1. Розміщення Коду
✅ **DO**: Додавайте перед `</body>`  
❌ **DON'T**: Додавайте в `<head>` або після `</body>`

### 2. Кешування
✅ **DO**: Дозвольте браузеру кешувати widget.js (швидше завантаження)  
❌ **DON'T**: Force reload кожного разу (`?v=timestamp`)

### 3. Testing
✅ **DO**: Тестуйте на різних браузерах (Chrome, Firefox, Safari)  
❌ **DON'T**: Тестуйте тільки на localhost (CORS може працювати інакше)

### 4. Безпека
✅ **DO**: Тримайте токен в секреті (не публікуйте в GitHub)  
❌ **DON'T**: Використовуйте один токен для всіх клієнтів

---

## Advanced Features

### 1. Programmatic Control

#### Відкрити чат програмно
```javascript
// Після завантаження widget
window.ailureChat.open();
```

#### Закрити чат
```javascript
window.ailureChat.close();
```

#### Надіслати повідомлення з коду
```javascript
window.ailureChat.sendMessage("Шукаю плитоноску");
```

**Use Case**: Відкрити чат по кліку на кнопку "Запитати AI"

---

### 2. Event Listeners

#### Слухати події чату
```javascript
document.addEventListener('ailure:message-sent', (event) => {
    console.log('User sent:', event.detail.message);
});

document.addEventListener('ailure:message-received', (event) => {
    console.log('AI replied:', event.detail.response);
});
```

**Use Case**: Analytics, tracking conversions

---

### 3. Pre-filled Questions

#### Додати quick buttons
```html
<button onclick="window.ailureChat.sendMessage('Покажи шоломи')">
    🪖 Шоломи
</button>
<button onclick="window.ailureChat.sendMessage('Плитоноски до 10000 грн')">
    🦺 Плитоноски
</button>
```

---

## Support

### Contacts
- **Email**: support@contractor.ua
- **Telegram**: @contractor_support
- **Phone**: +380 XX XXX XXXX (робочі години 9:00-18:00)

### Response Time
- 🔴 Critical (widget down): 1 година
- 🟡 Medium (slow response): 4 години
- 🟢 Low (feature request): 1-2 дні

---

## Changelog

### v1.0 (22.12.2025)
- ✅ Initial release
- ✅ Basic chat functionality
- ✅ Product search integration
- ✅ Customizable colors

### v1.1 (Planned)
- 🚧 Admin panel для токенів
- 🚧 Rich messages (carousels)
- 🚧 Voice input

---

**Попередній документ**: [← Frontend Integration](frontend-integration.md)  
**На головну**: [📚 Wiki Home](README.md)
