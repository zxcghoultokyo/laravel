/**
 * Widget Utilities Module
 * Helper functions for logging, color manipulation, DOM operations
 */

import { DEBUG } from './config.js';

/**
 * Console log with prefix (only in debug mode)
 */
export function log(...args) {
    if (DEBUG) console.log('[AIntento]', ...args);
}

/**
 * Console error with prefix (always shown)
 */
export function logError(...args) {
    console.error('[AIntento]', ...args);
}

/**
 * Adjust color brightness
 * @param {string} color - Hex color (#RRGGBB)
 * @param {number} amount - Amount to adjust (-255 to 255)
 * @returns {string} Adjusted hex color
 */
export function adjustBrightness(color, amount) {
    const usePound = color[0] === '#';
    const col = usePound ? color.slice(1) : color;
    const num = parseInt(col, 16);
    let r = (num >> 16) + amount;
    let g = ((num >> 8) & 0x00FF) + amount;
    let b = (num & 0x0000FF) + amount;
    r = Math.max(Math.min(255, r), 0);
    g = Math.max(Math.min(255, g), 0);
    b = Math.max(Math.min(255, b), 0);
    return (usePound ? '#' : '') + (r << 16 | g << 8 | b).toString(16).padStart(6, '0');
}

/**
 * Convert hex color to RGB string
 * @param {string} hex - Hex color
 * @returns {string} RGB values "r, g, b"
 */
export function hexToRgb(hex) {
    const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
    return result 
        ? `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}` 
        : '37, 99, 235';
}

/**
 * Truncate string to max length
 * @param {string} str - Input string
 * @param {number} maxLen - Maximum length
 * @returns {string} Truncated string with ellipsis if needed
 */
export function truncate(str, maxLen) {
    if (!str) return '';
    str = str.trim();
    if (str.length <= maxLen) return str;
    return str.substring(0, maxLen) + '...';
}

/**
 * Detect device type from user agent
 * @returns {'mobile'|'tablet'|'desktop'}
 */
export function detectDeviceType() {
    const ua = navigator.userAgent;
    if (/tablet|ipad|playbook|silk/i.test(ua)) return 'tablet';
    if (/mobile|iphone|ipod|android|blackberry|opera mini|iemobile/i.test(ua)) return 'mobile';
    return 'desktop';
}

/**
 * Format price for display
 * @param {string|number} price - Price value
 * @returns {string|null} Formatted price with currency
 */
export function formatPrice(price) {
    if (!price) return null;
    const num = parseInt(price.toString().replace(/\D/g, ''));
    if (isNaN(num)) return null;
    return num.toLocaleString('uk-UA') + ' ₴';
}

/**
 * Parse simple markdown to HTML
 * Supports: **bold**, *italic*, [link](url), - lists
 */
export function parseMarkdown(text) {
    if (!text) return '';
    
    // Escape HTML first
    let html = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    
    // Bold **text**
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    
    // Italic *text*
    html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
    
    // Links [text](url)
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" style="color: inherit; text-decoration: underline;">$1</a>');
    
    // List items (- item)
    html = html.replace(/^- (.+)$/gm, '<li style="margin-left: 16px;">$1</li>');
    
    // Line breaks
    html = html.replace(/\n/g, '<br>');
    
    return html;
}

/**
 * Scroll element into view smoothly
 * @param {HTMLElement} element - Element to scroll to
 * @param {string} block - Block alignment ('start', 'center', 'end')
 */
export function scrollToElement(element, block = 'end') {
    element?.scrollIntoView({ behavior: 'smooth', block });
}

/**
 * Wait for condition to be true
 * @param {Function} condition - Function that returns boolean
 * @param {number} timeout - Max wait time in ms
 * @param {number} interval - Check interval in ms
 * @returns {Promise<boolean>}
 */
export function waitFor(condition, timeout = 5000, interval = 100) {
    return new Promise((resolve) => {
        const startTime = Date.now();
        const check = () => {
            if (condition()) {
                resolve(true);
            } else if (Date.now() - startTime > timeout) {
                resolve(false);
            } else {
                setTimeout(check, interval);
            }
        };
        check();
    });
}

/**
 * Debounce function calls
 */
export function debounce(fn, delay) {
    let timeout = null;
    return function(...args) {
        if (timeout) clearTimeout(timeout);
        timeout = setTimeout(() => fn.apply(this, args), delay);
    };
}

/**
 * Extract category from current URL path
 */
export function extractCategoryFromUrl() {
    const path = window.location.pathname;
    const parts = path.split('/').filter(p => p && !['product', 'products', 'p', 'item'].includes(p.toLowerCase()));
    return parts[0] || '';
}

/**
 * Check if user has visited before
 */
export function hasVisitedBefore() {
    try {
        const visited = localStorage.getItem('aintento_has_visited');
        if (!visited) {
            localStorage.setItem('aintento_has_visited', 'true');
            return false;
        }
        return true;
    } catch (e) {
        return false;
    }
}
