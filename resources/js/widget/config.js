/**
 * Widget Configuration Module
 * Constants, settings, and global state
 */

export const WIDGET_VERSION = '2.7.0';
export const DEBUG = true;

// Capture script reference immediately (before DOMContentLoaded makes it null)
const CURRENT_SCRIPT = document.currentScript;

// Determine API base URL from script src
let baseUrl = 'https://aimbot.laravel.cloud';
if (CURRENT_SCRIPT?.src) {
    try {
        const scriptUrl = new URL(CURRENT_SCRIPT.src);
        baseUrl = scriptUrl.origin;
    } catch (e) {
        // fallback to production
    }
}

export const BASE_URL = baseUrl;
export let BOT_AVATAR = BASE_URL + '/images/aintento-avatar.svg';

export function setBotAvatar(url) {
    BOT_AVATAR = url;
}

/**
 * Default widget settings
 */
export function getDefaultSettings() {
    return {
        primary_color: '#2563eb',
        text_color: '#ffffff',
        position: 'right',
        border_radius: 12,
        font_family: null,
        show_shadow: true,
        bot_name: 'AIntento',
        bot_avatar_url: null,
        bot_avatar_base64: null,
        glow_color: null,
        bot_status_text: 'Завжди онлайн',
        welcome_message: 'Вітаю! 👋 Я AIntento — ваш персональний помічник з підбору спорядження. Чим можу допомогти?',
        input_placeholder: 'Напишіть повідомлення...',
        consent_notice: null,
        enabled: true,
        start_state: 'closed'
    };
}

/**
 * Global widget state (shared across modules)
 */
export const widgetState = {
    isOpen: false,
    hasShownWelcome: false,
    sessionId: null,
    operatorMode: false,
    lastOperatorMessageId: 0,
    pollInterval: null,
    settings: null,
    elements: null
};

/**
 * Set widget state properties
 */
export function updateState(updates) {
    Object.assign(widgetState, updates);
}

/**
 * Store settings globally
 */
export function setSettings(settings) {
    widgetState.settings = settings;
    window.aintentoSettings = settings;
    window.aintentoTenantId = settings.tenant_id || null;
    window.aintentoGlowColor = settings.glow_color || settings.primary_color;
}

/**
 * Get current settings
 */
export function getSettings() {
    return widgetState.settings || window.aintentoSettings || getDefaultSettings();
}
