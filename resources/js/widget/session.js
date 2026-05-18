/**
 * Widget Session Module
 * Session management, localStorage operations, history
 */

import { log, logError } from './utils.js';

function secureRandomToken(byteLength = 16) {
    const bytes = new Uint8Array(byteLength);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Get or create session ID
 */
export function getOrCreateSessionId() {
    let sessionId = localStorage.getItem('aintento_session_id') || localStorage.getItem('ailure_session_id');
    if (!sessionId) {
        sessionId = 'session_' + Date.now() + '_' + secureRandomToken(8);
    }
    localStorage.setItem('aintento_session_id', sessionId);
    return sessionId;
}

/**
 * Save session ID
 */
export function saveSessionId(sessionId) {
    localStorage.setItem('aintento_session_id', sessionId);
}

/**
 * Get or create client ID (stored in cookie)
 */
export function getOrCreateClientId() {
    const cookieName = 'aintento_client_id';
    let clientId = document.cookie.match(new RegExp('(^| )' + cookieName + '=([^;]+)'))?.[2];
    if (!clientId) {
        clientId = 'cli_' + Date.now() + '_' + secureRandomToken(10);
        const expires = new Date(Date.now() + 72 * 60 * 60 * 1000).toUTCString();
        document.cookie = `${cookieName}=${clientId}; expires=${expires}; path=/; SameSite=Lax`;
    }
    return clientId;
}

/**
 * Get merchant ID from settings or hostname
 */
export function getMerchantId() {
    if (window.aintentoSettings?.merchant_id) {
        return window.aintentoSettings.merchant_id;
    }
    return window.location.hostname;
}

/**
 * Save message to localStorage history
 */
export function saveMessage(sessionId, message) {
    const key = `aintento_messages_${sessionId}`;
    try {
        const messages = JSON.parse(localStorage.getItem(key) || '[]');
        messages.push(message);
        // Keep only last 50 messages
        if (messages.length > 50) {
            messages.shift();
        }
        localStorage.setItem(key, JSON.stringify(messages));
    } catch (e) {
        logError('Failed to save message:', e);
    }
}

/**
 * Load messages from localStorage
 */
export function loadMessages(sessionId) {
    const newKey = `aintento_messages_${sessionId}`;
    const oldKey = `ailure_messages_${sessionId}`;
    const messages = localStorage.getItem(newKey) || localStorage.getItem(oldKey);
    return JSON.parse(messages || '[]');
}

/**
 * Save cross-sell data to localStorage
 */
export function saveCrossSell(sessionId, crossSell) {
    if (!crossSell) return;
    const key = `aintento_cross_sell_${sessionId}`;
    localStorage.setItem(key, JSON.stringify(crossSell));
}

/**
 * Load cross-sell data from localStorage
 */
export function loadCrossSell(sessionId) {
    const key = `aintento_cross_sell_${sessionId}`;
    const data = localStorage.getItem(key);
    return data ? JSON.parse(data) : null;
}

/**
 * Track products shown in chat
 * Only tracks each product ONCE per session to avoid duplicate counting
 */
export function trackProductsShown(products, sessionId) {
    if (!sessionId) return;
    
    // Get already tracked products for this session
    const trackedKey = 'aintento_tracked_shown_' + sessionId;
    const alreadyTracked = new Set(JSON.parse(localStorage.getItem(trackedKey) || '[]'));
    
    // Save shown products to localStorage for add-to-cart attribution
    const shownKey = 'aintento_shown_products_' + sessionId;
    const existingShown = JSON.parse(localStorage.getItem(shownKey) || '[]');
    
    const newProducts = [];
    
    products.forEach(product => {
        const productKey = product.id ? String(product.id) : product.article;
        
        // Add to shown products list (for attribution)
        if (product.id && !existingShown.includes(String(product.id))) {
            existingShown.push(String(product.id));
        }
        if (product.article && !existingShown.includes(product.article)) {
            existingShown.push(product.article);
        }
        
        // Only track if not already tracked in this session
        if (productKey && !alreadyTracked.has(productKey)) {
            alreadyTracked.add(productKey);
            newProducts.push(product);
        }
    });
    
    localStorage.setItem(shownKey, JSON.stringify(existingShown));
    localStorage.setItem(trackedKey, JSON.stringify([...alreadyTracked]));
    
    return newProducts;
}

/**
 * Store clicked product for attribution
 */
export function storeClickedProduct(product, sessionId) {
    const key = 'aintento_clicked_products';
    let clicked = [];
    try {
        clicked = JSON.parse(localStorage.getItem(key) || '[]');
    } catch (e) {}

    clicked.push({
        id: product.id,
        article: product.article,
        price: product.price,
        session_id: sessionId,
        timestamp: Date.now()
    });

    // Keep only last 72 hours, max 50 items
    const cutoff = Date.now() - (72 * 60 * 60 * 1000);
    clicked = clicked.filter(p => p.timestamp > cutoff).slice(-50);
    localStorage.setItem(key, JSON.stringify(clicked));
}

/**
 * Check if session had chat conversation
 */
export function hadChatConversation(sessionId) {
    if (!sessionId) return false;
    const messagesKey = 'aintento_messages_' + sessionId;
    const messages = localStorage.getItem(messagesKey);
    return messages && JSON.parse(messages).length > 0;
}

/**
 * Get products shown in chat for session
 */
export function getShownProducts(sessionId) {
    if (!sessionId) return [];
    const shownKey = 'aintento_shown_products_' + sessionId;
    return JSON.parse(localStorage.getItem(shownKey) || '[]');
}
