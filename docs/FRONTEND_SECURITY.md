# Frontend Security Guide: Input Sanitization & XSS Prevention

## Overview

The chat API returns user-generated content that may contain XSS attacks. This document describes how to safely render API responses.

## API Response Structure

```typescript
interface ChatResponse {
  type: 'text' | 'products' | 'error';
  text?: string;           // ⚠️ May contain user input
  message?: string;        // ⚠️ May contain user input  
  products?: Product[];
  session_id: string;
  meta?: {
    request_id: string;
    error?: boolean;
    rate_limited?: boolean;
  };
}

interface Product {
  id: number;
  title: string;           // ⚠️ From database, generally safe
  description?: string;    // ⚠️ May contain HTML from vendor
  price: number;
  image?: string;          // URL - validate before use
  // ...other fields
}
```

## ⚠️ Dangerous Fields

These fields may contain malicious content:

| Field | Risk Level | Source |
|-------|------------|--------|
| `text` | 🔴 HIGH | AI response based on user input |
| `message` | 🔴 HIGH | AI response based on user input |
| `product.description` | 🟠 MEDIUM | Vendor data (may contain HTML) |
| `product.title` | 🟢 LOW | Vendor data (usually clean) |

## Safe Rendering Patterns

### React

```tsx
// ❌ DANGEROUS - Never do this
<div dangerouslySetInnerHTML={{ __html: response.text }} />

// ✅ SAFE - Text content is auto-escaped
<div>{response.text}</div>

// ✅ SAFE - For markdown, use a sanitizing library
import DOMPurify from 'dompurify';
import { marked } from 'marked';

const SafeMarkdown = ({ content }: { content: string }) => {
  const html = DOMPurify.sanitize(marked.parse(content));
  return <div dangerouslySetInnerHTML={{ __html: html }} />;
};
```

### Vue

```vue
<!-- ❌ DANGEROUS -->
<div v-html="response.text"></div>

<!-- ✅ SAFE -->
<div>{{ response.text }}</div>

<!-- ✅ SAFE with sanitization -->
<script setup>
import DOMPurify from 'dompurify';
const safeHtml = computed(() => DOMPurify.sanitize(response.text));
</script>
<div v-html="safeHtml"></div>
```

### Vanilla JavaScript

```javascript
// ❌ DANGEROUS
element.innerHTML = response.text;

// ✅ SAFE
element.textContent = response.text;

// ✅ SAFE with sanitization (if you need HTML)
import DOMPurify from 'dompurify';
element.innerHTML = DOMPurify.sanitize(response.text);
```

## URL Validation

Product images come from external sources. Always validate:

```typescript
const isValidImageUrl = (url: string): boolean => {
  if (!url) return false;
  
  try {
    const parsed = new URL(url);
    // Only allow HTTPS
    if (parsed.protocol !== 'https:') return false;
    // Block javascript: URLs
    if (url.toLowerCase().startsWith('javascript:')) return false;
    return true;
  } catch {
    return false;
  }
};

// Usage
<img src={isValidImageUrl(product.image) ? product.image : '/placeholder.png'} />
```

## Content Security Policy (CSP)

Recommended CSP headers for your frontend:

```
Content-Security-Policy: 
  default-src 'self';
  script-src 'self';
  style-src 'self' 'unsafe-inline';
  img-src 'self' https: data:;
  connect-src 'self' https://your-api-domain.com;
  font-src 'self';
  frame-ancestors 'none';
```

## Streaming (SSE) Safety

When using `/api/chat/stream`:

```javascript
const eventSource = new EventSource('/api/chat/stream?message=' + encodeURIComponent(message));

eventSource.onmessage = (event) => {
  const data = JSON.parse(event.data);
  
  // ✅ SAFE - use textContent
  statusElement.textContent = data.text;
  
  // ❌ DANGEROUS
  statusElement.innerHTML = data.text;
};
```

## Recommended Libraries

| Library | Purpose | Install |
|---------|---------|---------|
| [DOMPurify](https://github.com/cure53/DOMPurify) | HTML sanitization | `npm i dompurify` |
| [xss](https://github.com/leizongmin/js-xss) | XSS filter | `npm i xss` |
| [sanitize-html](https://github.com/apostrophecms/sanitize-html) | HTML sanitization | `npm i sanitize-html` |

## Testing for XSS

Test your frontend with these payloads:

```
<script>alert('xss')</script>
<img src=x onerror=alert('xss')>
<svg onload=alert('xss')>
javascript:alert('xss')
<a href="javascript:alert('xss')">click</a>
{{constructor.constructor('alert(1)')()}}
```

If any of these execute JavaScript, you have an XSS vulnerability.

## Summary

1. **Never use `innerHTML` with API data** without sanitization
2. **Always use `textContent`** for plain text
3. **Use DOMPurify** if you need to render HTML/Markdown
4. **Validate image URLs** before rendering
5. **Set CSP headers** on your frontend
6. **Test with XSS payloads** before going live
