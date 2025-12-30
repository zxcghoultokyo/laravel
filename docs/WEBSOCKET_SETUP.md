# WebSocket Setup for Live Chat Takeover

## Overview

The system supports real-time operator takeover of AI chats using WebSocket broadcasting. This allows operators to:
- See all active chat sessions in real-time
- Take over a chat from AI and respond manually
- Release chat back to AI when done

## Architecture

```
┌─────────────┐         ┌─────────────┐         ┌─────────────┐
│   Widget    │◀───────▶│  WebSocket  │◀───────▶│   Admin     │
│  (User)     │         │   Server    │         │  Dashboard  │
└─────────────┘         └─────────────┘         └─────────────┘
      │                       │                       │
      │    HTTP POST          │                       │
      ▼                       │                       │
┌─────────────┐               │                       │
│  Laravel    │───────────────┘                       │
│    API      │          broadcast                    │
└─────────────┘                                       │
      │                                               │
      │    Admin API (takeover, message)              │
      ◀───────────────────────────────────────────────┘
```

## Option 1: Laravel Reverb (Recommended)

Laravel Reverb is the official WebSocket server for Laravel.

### Installation

```bash
composer require laravel/reverb
php artisan reverb:install
```

### Configuration

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

### Running

```bash
# Development
php artisan reverb:start

# Production (use supervisor)
php artisan reverb:start --host=0.0.0.0 --port=8080
```

## Option 2: Pusher (Easiest)

Pusher is a hosted WebSocket service. No server management needed.

### Installation

```bash
composer require pusher/pusher-php-server
```

### Configuration

```env
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=your-app-id
PUSHER_APP_KEY=your-app-key
PUSHER_APP_SECRET=your-app-secret
PUSHER_APP_CLUSTER=eu
```

## Frontend Integration

### Widget (User Side)

```javascript
// Using Laravel Echo + Pusher
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;
window.Echo = new Echo({
    broadcaster: 'pusher',
    key: 'your-pusher-key',
    cluster: 'eu',
    forceTLS: true
});

// Subscribe to chat channel
const channel = Echo.channel(`chat.${sessionId}`);

channel.listen('.operator.message', (data) => {
    if (data.type === 'takeover') {
        showNotification('Оператор приєднався до чату');
        setOperatorMode(true);
    } else if (data.type === 'release') {
        showNotification('AI-асистент знову з вами');
        setOperatorMode(false);
    } else if (data.type === 'message') {
        appendMessage({
            text: data.text,
            sender: 'operator',
            timestamp: data.timestamp
        });
    }
});
```

### Admin Dashboard

```javascript
// Subscribe to all chat activity
const adminChannel = Echo.channel('admin.chats');

adminChannel.listen('.chat.message', (data) => {
    // New message from user or AI
    updateChatList(data.session_id, data);
});

// Actions
async function takeoverChat(sessionId) {
    const response = await fetch(`/api/admin/chats/${sessionId}/takeover`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${adminToken}`,
            'Content-Type': 'application/json'
        }
    });
    return response.json();
}

async function sendOperatorMessage(sessionId, message) {
    const response = await fetch(`/api/admin/chats/${sessionId}/message`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${adminToken}`,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ message })
    });
    return response.json();
}

async function releaseChat(sessionId) {
    const response = await fetch(`/api/admin/chats/${sessionId}/release`, {
        method: 'POST',
        headers: {
            'Authorization': `Bearer ${adminToken}`
        }
    });
    return response.json();
}
```

## Events Reference

### OperatorMessage

Broadcast from operator to user.

```json
{
    "type": "takeover|release|message",
    "text": "Message content",
    "operator_id": 1,
    "timestamp": "2025-12-30T12:00:00Z"
}
```

Channels: `chat.{sessionId}`
Event name: `operator.message`

### NewChatMessage

Broadcast for admin dashboard monitoring.

```json
{
    "session_id": "abc123",
    "message": "User message text",
    "type": "user|ai",
    "meta": {
        "request_id": "uuid",
        "products_count": 5
    },
    "timestamp": "2025-12-30T12:00:00Z"
}
```

Channels: `admin.chats`, `chat.{sessionId}`
Event name: `chat.message`

## Admin API Reference

All endpoints require `Authorization: Bearer {ADMIN_API_TOKEN}` header.

### GET /api/admin/chats/active

Returns active chat sessions (last 30 minutes).

```json
{
    "sessions": [
        {
            "session_id": "abc123",
            "status": "ai",
            "last_message_at": "2025-12-30T12:00:00Z",
            "message_count": 5,
            "last_query": "плитоноска до 10000"
        }
    ],
    "count": 1
}
```

### POST /api/admin/chats/{sessionId}/takeover

Take over a chat session.

```json
{
    "operator_id": 1
}
```

Response:
```json
{
    "message": "Session taken over successfully",
    "session_id": "abc123",
    "operator_id": 1,
    "context": { /* session context */ }
}
```

### POST /api/admin/chats/{sessionId}/message

Send message as operator (requires takeover first).

```json
{
    "message": "Привіт! Чим можу допомогти?"
}
```

### POST /api/admin/chats/{sessionId}/release

Release session back to AI.

## Flow Diagram

```
User                    System                  Operator
  │                        │                        │
  │──── "плитоноска" ─────▶│                        │
  │                        │──── AI response ─────▶│ (broadcast)
  │◀──── products ─────────│                        │
  │                        │                        │
  │                        │◀──── takeover ─────────│
  │◀── "Оператор joined" ──│                        │
  │                        │                        │
  │──── "привіт" ──────────▶│──── (to operator) ───▶│
  │                        │                        │
  │◀── operator message ───│◀──── "Вітаю!" ────────│
  │                        │                        │
  │                        │◀──── release ──────────│
  │◀── "AI back" ──────────│                        │
```

## Production Checklist

- [ ] Configure ADMIN_API_TOKEN in .env
- [ ] Set up WebSocket server (Reverb or Pusher)
- [ ] Configure BROADCAST_CONNECTION
- [ ] Run migrations: `php artisan migrate`
- [ ] Set up queue worker for broadcast: `php artisan queue:work`
- [ ] Configure frontend Echo client
- [ ] Test takeover flow end-to-end
