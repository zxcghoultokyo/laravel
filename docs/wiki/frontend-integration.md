# 🎨 Frontend Integration — Widget.js

> **Остання оновлення**: 22.12.2025  
> **Файл**: [public/widget.js](../../public/widget.js)  
> **Тип**: Standalone Vanilla JS Widget

---

## 📋 Зміст
1. [Огляд](#огляд)
2. [Архітектура](#архітектура)
3. [API Integration](#api-integration)
4. [Customization](#customization)
5. [Deployment](#deployment)

---

## Огляд

**Widget.js** — standalone JavaScript чат-віджет для інтеграції AI-асистента на будь-який сайт.

### Ключові Особливості
- 🚀 **Zero Dependencies** — чистий Vanilla JS, без React/Vue
- 📦 **Single File** — `<script src="widget.js"></script>` і готово
- 🎨 **Customizable** — кольори, позиція, тексти
- 📱 **Responsive** — працює на мобільних і десктопах
- 🔒 **Token-based Auth** — кожен клієнт має унікальний токен

---

## Архітектура

### File Structure
```
public/
  └── widget.js          # Головний файл (standalone)

resources/views/
  └── chat.blade.php     # Demo page (для тестування)
```

### Widget Components
```
┌─────────────────────────────────────┐
│  Widget Toggle Button (FAB)         │
│  • Фіксований в правому нижньому куті│
│  • Z-index 9998                      │
└─────────────────────────────────────┘
         ↓ onClick
┌─────────────────────────────────────┐
│  Chat Window (Modal)                │
│  • Header (заголовок + close)       │
│  • Messages Container               │
│  • Input + Send Button              │
│  • Z-index 9999                     │
└─────────────────────────────────────┘
```

### Initialization Flow
```javascript
document.addEventListener('DOMContentLoaded', initWidget);

function initWidget() {
    // 1. Знайти container з data-ailure-token
    const container = document.querySelector('[data-ailure-token]');
    if (!container) return;
    
    const token = container.dataset.ailureToken;
    
    // 2. Fetch widget settings від API
    fetch('https://aintento.laravel.cloud/api/widget/settings', {
        headers: { 'X-Widget-Token': token }
    })
    .then(res => res.json())
    .then(settings => renderWidget(container, settings, token));
    
    // 3. Render UI
    renderWidget(container, settings, token);
}
```

---

## API Integration

### Backend Endpoints

#### 1. Widget Settings
```http
GET /api/widget/settings
Headers:
  X-Widget-Token: abc123xyz
```

**Response**:
```json
{
  "chatTitle": "AI Помічник",
  "primaryColor": "#4F46E5",
  "headerBg": "#1E293B",
  "position": "bottom-right",
  "welcomeMessage": "Вітаю! Чим можу допомогти?"
}
```

**Використання**:
- Кастомізація UI (кольори, заголовок)
- Персоналізація привітання

---

#### 2. Send Message
```http
POST /api/chat
Headers:
  Content-Type: application/json
  X-Widget-Token: abc123xyz
Body:
{
  "message": "Шукаю плитоноску зелену",
  "session_id": "uuid-v4" (optional)
}
```

**Response**:
```json
{
  "type": "products",
  "text": "Ось плитоноски зеленого кольору:",
  "data": {
    "products": [
      {
        "id": 123,
        "title": "Плитоноска АТАКА",
        "price": 12000,
        "images": ["url1", "url2"],
        "link": "https://shop.com/product/123"
      }
    ]
  },
  "session_id": "uuid-v4",
  "meta": {
    "intent": "product_search",
    "refined_query": "плитоноска",
    "filters": {"color": "зелена"}
  }
}
```

**Response Types**:
- `type: "products"` — показати товари
- `type: "text"` — тільки текст (FAQ, smalltalk)
- `type: "order_status"` — статус замовлення

---

### Session Management

**Session ID** зберігається в:
1. `localStorage.getItem('ailure_session_id')`
2. Генерується на клієнті якщо немає:
   ```javascript
   function generateSessionId() {
       return 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
   }
   ```

**Чому важливо**:
- Трекінг історії розмови
- Context-aware відповіді (AI пам'ятає попередній контекст)
- Analytics (які користувачі повертаються)

---

## Customization

### Widget Settings

#### Via HTML Attributes
```html
<div 
    data-ailure-token="YOUR_TOKEN"
    data-chat-title="Військовий AI"
    data-primary-color="#FF5733"
    data-position="bottom-left"
></div>
```

#### Via API Response
Backend може override settings через API endpoint `/api/widget/settings`.

**Priority**: API response > HTML attributes > defaults

---

### Styling Customization

#### CSS Variables (Future)
```css
:root {
    --ailure-primary: #4F46E5;
    --ailure-header-bg: #1E293B;
    --ailure-text: #1F2937;
    --ailure-border: #E5E7EB;
}
```

**Current State**: ⚠️ Hardcoded в JS (треба винести в CSS)

---

### Text Customization

#### Default Texts
```javascript
const defaults = {
    chatTitle: 'AI Помічник',
    welcomeMessage: 'Вітаю! Чим можу допомогти?',
    placeholder: 'Напишіть повідомлення...',
    sendButton: 'Відправити',
};
```

#### Override via Settings API
```json
{
  "chatTitle": "Contractor AI",
  "welcomeMessage": "Шукаєте тактичне спорядження? Питайте!",
  "placeholder": "Наприклад: шолом мультикам"
}
```

---

## Deployment

### Інтеграція на Сторонній Сайт

#### Step 1: Include Widget Script
```html
<!DOCTYPE html>
<html>
<head>
    <title>Магазин Тактичного Екіпірування</title>
</head>
<body>
    <!-- Ваш контент сайту -->
    
    <!-- Widget Container -->
    <div data-ailure-token="YOUR_UNIQUE_TOKEN"></div>
    
    <!-- Widget Script (в кінці body) -->
    <script src="https://aintento.laravel.cloud/widget.js"></script>
</body>
</html>
```

**Важливо**:
- Script в кінці `<body>` для швидшого завантаження
- Token унікальний для кожного клієнта

---

#### Step 2: Отримати Token

**Через Admin Panel** (майбутня фіча):
1. Login → Admin → Widget Settings
2. Create New Widget → Generate Token
3. Copy token → paste в HTML

**Наразі**: Hardcoded токен або генерується вручну

---

#### Step 3: Налаштувати CORS

**Backend** повинен дозволити CORS для сторонніх доменів:

```php
// app/Http/Middleware/WidgetCors.php
public function handle($request, Closure $next)
{
    $response = $next($request);
    
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Widget-Token');
    
    return $response;
}
```

**Routes**:
```php
// routes/api.php
Route::middleware('widget.cors')->group(function() {
    Route::get('/widget/settings', [WidgetController::class, 'settings']);
    Route::post('/chat', [ChatController::class, 'sendMessage']);
});
```

---

### Testing Integration

#### Local Testing
```html
<!-- test.html -->
<div data-ailure-token="test-token-123"></div>
<script src="http://localhost:8000/widget.js"></script>
```

```bash
php artisan serve
# Open test.html in browser
```

---

#### Production Testing
```html
<div data-ailure-token="prod-token-abc"></div>
<script src="https://aintento.laravel.cloud/widget.js"></script>
```

---

### Performance Considerations

#### Loading Time
- Widget.js file size: ~15KB (uncompressed)
- Initial load: ~200-300ms (fetch settings + render UI)
- Message send: ~600-900ms (AI processing)

#### Optimization Ideas
1. **Lazy Load**: Завантажувати widget тільки коли користувач скролить
2. **CDN**: Розмістити widget.js на CDN (CloudFlare, AWS CloudFront)
3. **Minification**: Uglify + Gzip → ~5KB
4. **Service Worker**: Cache widget.js на клієнті

---

## Widget Lifecycle

### 1. Initialization
```
Page Load
  ↓
DOMContentLoaded event
  ↓
initWidget()
  ↓
Fetch /api/widget/settings
  ↓
renderWidget() → Create UI elements
  ↓
Widget Ready ✅
```

---

### 2. User Interaction
```
User clicks toggle button
  ↓
openWidget() → show chat window
  ↓
User types message
  ↓
sendMessage()
  ↓
POST /api/chat with message + session_id
  ↓
Show "typing..." indicator
  ↓
Receive response
  ↓
renderMessage() → display products/text
  ↓
Scroll to bottom
```

---

### 3. Session Persistence
```javascript
// Save session after first message
localStorage.setItem('ailure_session_id', sessionId);

// Load session on next visit
const sessionId = localStorage.getItem('ailure_session_id') || generateSessionId();
```

**TTL**: Немає expiration (можна додати через Date.now() check)

---

## Known Issues

### 🔴 Widget Settings Hardcoded
**Проблема**: Settings API endpoint не реалізований, завжди fallback на defaults

**Файл**: widget.js L47-67

**Рішення**: Implement `/api/widget/settings` endpoint з БД

---

### ⚠️ No Error Handling for Network Failures
**Проблема**: Якщо API недоступний → widget crashes

**Рішення**:
```javascript
fetch('/api/chat', {...})
    .catch(err => {
        showMessage('❌ Помилка з\'єднання. Спробуйте пізніше.', 'error');
    });
```

---

### 💡 No Typing Indicator Animation
**Проблема**: Статичний текст "Друкує..."

**Рішення**: Animated dots (CSS animation)

---

## Future Enhancements

### 1. Rich Messages
- 📦 Product carousels (swipe/scroll)
- 🎨 Image previews
- 🔘 Quick reply buttons ("Так", "Ні", "Покажи ще")

### 2. Voice Input
- 🎤 Speech-to-text (Web Speech API)
- 🔊 Text-to-speech для відповідей

### 3. Analytics
- 📊 Track message count
- 📈 Conversion rate (messages → orders)
- 🕒 Average response time

### 4. Multi-language
- 🇺🇦 Українська (default)
- 🇬🇧 English
- 🇵🇱 Polski

---

## Code References

### Files
- [public/widget.js](../../public/widget.js) — головний файл
- [app/Http/Controllers/Api/ChatController.php](../../app/Http/Controllers/Api/ChatController.php) — backend API
- [app/Http/Middleware/WidgetCors.php](../../app/Http/Middleware/WidgetCors.php) — CORS middleware
- [resources/views/chat.blade.php](../../resources/views/chat.blade.php) — demo page

---

**Попередній документ**: [← Roadmap](roadmap.md)  
**Наступний документ**: [Chat Implementation →](chat-implementation.md)
