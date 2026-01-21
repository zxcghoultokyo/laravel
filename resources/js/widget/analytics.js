/**
 * Widget Analytics Module
 * Event tracking, attribution, conversions
 */

import { BASE_URL } from './config.js';
import { log, logError, detectDeviceType } from './utils.js';
import { getOrCreateClientId, getMerchantId, hadChatConversation, getShownProducts } from './session.js';

// Analytics queue for batching
const analyticsQueue = [];
let analyticsFlushTimeout = null;

/**
 * Send analytics event to server
 */
export function sendAnalyticsEvent(eventType, data = {}) {
    log('Analytics event queued:', eventType);
    
    const event = {
        session_id: localStorage.getItem('aintento_session_id') || '',
        client_id: getOrCreateClientId(),
        merchant_id: getMerchantId(),
        tenant_id: window.aintentoTenantId,
        event_type: eventType,
        event_source: 'widget',
        device_type: detectDeviceType(),
        page_url: window.location.href,
        referrer: document.referrer,
        timestamp: new Date().toISOString(),
        ...data
    };

    analyticsQueue.push(event);
    log('Analytics queue size:', analyticsQueue.length);

    // Debounced flush
    if (analyticsFlushTimeout) clearTimeout(analyticsFlushTimeout);
    analyticsFlushTimeout = setTimeout(flushAnalytics, 2000);

    // Immediate flush for important events
    if (['add_to_cart', 'purchase', 'checkout_success'].includes(eventType)) {
        flushAnalytics();
    }
}

/**
 * Flush analytics queue to server
 */
export function flushAnalytics() {
    if (analyticsQueue.length === 0) return;

    const events = [...analyticsQueue];
    analyticsQueue.length = 0;

    const payload = JSON.stringify({ events });
    log('Flushing analytics:', events.length, 'events', events.map(e => e.event_type));

    fetch(BASE_URL + '/api/analytics/events', {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain' },
        body: payload,
        keepalive: true
    }).then(response => {
        log('Analytics fetch response:', response.status);
        return response.json();
    }).then(data => {
        log('Analytics response data:', data);
    }).catch(err => {
        logError('Analytics fetch error:', err.message);
    });
}

/**
 * Send conversion event (goes to chat_conversions table)
 */
export function sendConversionEvent(conversionType, data = {}) {
    const sessionId = localStorage.getItem('aintento_session_id');
    const clientId = getOrCreateClientId();
    const merchantId = getMerchantId();
    
    const payload = {
        conversion_type: conversionType,
        session_id: sessionId || '',
        client_id: clientId,
        merchant_id: merchantId,
        tenant_id: window.aintentoTenantId,
        order_total: data.order_total || null,
        items_count: data.items_count || null,
        product_ids: data.products_from_chat || [],
        had_chat_conversation: data.had_chat_conversation || false
    };
    
    log('Sending conversion event:', conversionType, payload);
    
    fetch(BASE_URL + '/api/analytics/conversion', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        keepalive: true
    }).then(response => {
        log('Conversion event response:', response.status);
    }).catch(err => {
        logError('Conversion event failed:', err);
    });
}

/**
 * Track product click
 */
export function trackProductClick(product) {
    sendAnalyticsEvent('product_click', {
        product_id: product.id,
        product_article: product.article,
        product_price: product.price
    });
}

/**
 * Track products shown - returns new products for analytics
 */
export function trackProductsShownAnalytics(products, sessionId) {
    const shownKey = 'aintento_tracked_shown_' + sessionId;
    const alreadyTracked = new Set(JSON.parse(localStorage.getItem(shownKey) || '[]'));
    
    const newProducts = products.filter(product => {
        const productKey = product.id ? String(product.id) : product.article;
        if (productKey && !alreadyTracked.has(productKey)) {
            alreadyTracked.add(productKey);
            return true;
        }
        return false;
    });
    
    localStorage.setItem(shownKey, JSON.stringify([...alreadyTracked]));
    
    if (newProducts.length > 0) {
        log('Tracking product_shown for', newProducts.length, 'new products');
        newProducts.forEach(product => {
            sendAnalyticsEvent('product_shown', {
                product_id: product.id,
                product_article: product.article,
                product_price: product.price
            });
        });
    }
    
    return newProducts;
}

/**
 * Add UTM parameters to product link for attribution
 */
export function addUtmToLink(link, sessionId, productId) {
    if (!link || link === '#') return link;
    try {
        const url = new URL(link);
        url.searchParams.set('utm_source', 'aintento');
        url.searchParams.set('utm_medium', 'chat');
        url.searchParams.set('utm_campaign', 'widget');
        if (sessionId) url.searchParams.set('utm_content', sessionId);
        if (productId) url.searchParams.set('utm_term', String(productId));
        return url.toString();
    } catch (e) {
        return link;
    }
}

// Initialize flush on page unload
if (typeof window !== 'undefined') {
    window.addEventListener('pagehide', flushAnalytics);
    window.addEventListener('beforeunload', flushAnalytics);
}
