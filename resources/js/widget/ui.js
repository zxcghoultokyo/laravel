/**
 * Widget UI Module
 * HTML generation, styles, UI components
 */

import { BOT_AVATAR, getSettings } from './config.js';
import { adjustBrightness, hexToRgb } from './utils.js';

/**
 * Inject widget styles into document head
 */
export function injectStyles(settings) {
    if (document.getElementById('aintento-styles')) return;
    
    const glowColor = settings.glow_color || settings.primary_color || '#2563eb';
    const glowRgb = hexToRgb(glowColor);
    
    const style = document.createElement('style');
    style.id = 'aintento-styles';
    style.textContent = `
        @keyframes aintento-fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes aintento-pulse {
            0%, 80%, 100% { opacity: 0.3; }
            40% { opacity: 1; }
        }
        @keyframes aintento-glow {
            0%, 100% { box-shadow: 0 0 5px rgba(${glowRgb}, 0.5); }
            50% { box-shadow: 0 0 15px rgba(${glowRgb}, 0.8); }
        }
        .aintento-messages::-webkit-scrollbar { width: 6px; }
        .aintento-messages::-webkit-scrollbar-track { background: #f1f1f1; }
        .aintento-messages::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        .aintento-messages::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        #aintento-overlay { transition: opacity 0.3s ease; }
        .aintento-avatar { animation: aintento-glow 2s ease-in-out infinite; }
        
        /* Mobile styles */
        @media (max-width: 480px) {
            .aintento-widget {
                position: fixed !important;
                bottom: 0 !important;
                left: 0 !important;
                right: 0 !important;
                width: 100% !important;
                touch-action: manipulation;
            }
            .aintento-window {
                position: fixed !important;
                bottom: 0 !important;
                left: 0 !important;
                right: 0 !important;
                top: auto !important;
                width: 100% !important;
                max-width: 100% !important;
                height: 80vh !important;
                max-height: 80vh !important;
                border-radius: 20px 20px 0 0 !important;
                z-index: 10001 !important;
            }
            .aintento-toggle {
                position: fixed !important;
                bottom: 16px !important;
                right: 16px !important;
                z-index: 10000 !important;
                width: 56px !important;
                height: 56px !important;
            }
            .aintento-messages {
                font-size: 15px !important;
            }
            #aintento-input {
                font-size: 16px !important; /* Prevents zoom on iOS */
            }
        }
    `;
    document.head.appendChild(style);
}

/**
 * Create main widget HTML
 */
export function createWidgetHTML(settings) {
    const s = settings;
    return `
        <div id="aintento-overlay" style="
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9998;
        "></div>
        
        <div class="aintento-widget" style="
            position: fixed;
            bottom: 20px;
            ${s.position === 'right' ? 'right: 20px;' : 'left: 20px;'}
            z-index: 9999;
            font-family: ${s.font_family || "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif"};
        ">
            <!-- Chat bubble hint -->
            <div id="aintento-bubble" class="aintento-bubble" style="
                display: none;
                opacity: 0;
                position: absolute;
                bottom: 70px;
                ${s.position === 'right' ? 'right: 0;' : 'left: 0;'}
                background: white;
                padding: 12px 16px;
                border-radius: 16px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                min-width: 200px;
                max-width: 280px;
                width: max-content;
                font-size: 14px;
                line-height: 1.4;
                color: #1f2937;
                cursor: pointer;
                transition: opacity 0.4s ease;
                white-space: normal;
            ">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                    <span style="font-size: 16px;">👋</span>
                    <span style="font-weight: 600; color: ${s.primary_color};">Привіт!</span>
                </div>
                <span style="color: #4b5563;">Потрібна допомога з вибором? Запитайте мене!</span>
                <svg style="
                    position: absolute;
                    bottom: -10px;
                    ${s.position === 'right' ? 'right: 20px;' : 'left: 20px;'}
                    width: 20px;
                    height: 12px;
                    filter: drop-shadow(0 2px 2px rgba(0,0,0,0.1));
                " viewBox="0 0 20 12">
                    <path d="M10 12 L0 0 L20 0 Z" fill="white"/>
                </svg>
                <button id="aintento-bubble-close" style="
                    position: absolute;
                    top: -8px;
                    right: -8px;
                    width: 20px;
                    height: 20px;
                    border-radius: 50%;
                    background: #e5e7eb;
                    border: none;
                    font-size: 12px;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    color: #6b7280;
                ">×</button>
            </div>
            
            <button id="aintento-toggle" class="aintento-toggle" style="
                width: 60px;
                height: 60px;
                border-radius: 50%;
                background: ${s.primary_color};
                color: white;
                border: none;
                cursor: pointer;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 28px;
                transition: all 0.3s ease;
                overflow: hidden;
            ">
                <img src="${BOT_AVATAR}" alt="Chat" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
            </button>

            <div id="aintento-window" class="aintento-window" style="
                display: none;
                position: fixed;
                bottom: 90px;
                ${s.position === 'right' ? 'right: 20px;' : 'left: 20px;'}
                width: min(400px, calc(100vw - 40px));
                max-width: 400px;
                height: min(600px, calc(100vh - 120px));
                background: white;
                border-radius: ${s.border_radius}px;
                box-shadow: 0 12px 48px rgba(0,0,0,0.25);
                flex-direction: column;
                overflow: hidden;
            ">
                ${createHeaderHTML(s)}
                
                <!-- Beta warning banner -->
                <div style="
                    background: rgba(254, 243, 199, 0.7);
                    padding: 4px 12px;
                    font-size: 10px;
                    color: #b45309;
                    text-align: center;
                    border-bottom: 1px solid rgba(252, 211, 77, 0.5);
                ">
                    ⚠️ Бета-версія
                </div>

                <div id="aintento-messages" class="aintento-messages" style="
                    flex: 1;
                    overflow-y: auto;
                    padding: 16px;
                    background: #f9fafb;
                    min-height: 300px;
                "></div>

                ${createInputContainerHTML(s)}
            </div>
        </div>
    `;
}

/**
 * Create header HTML
 */
function createHeaderHTML(settings) {
    const s = settings;
    return `
        <div class="aintento-header" style="
            background: linear-gradient(135deg, ${s.primary_color} 0%, ${adjustBrightness(s.primary_color, -15)} 100%);
            color: ${s.text_color || 'white'};
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        ">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="aintento-avatar" style="
                    width: 40px; 
                    height: 40px; 
                    border-radius: 50%; 
                    display: flex; 
                    align-items: center; 
                    justify-content: center;
                    overflow: hidden;
                ">
                    <img src="${BOT_AVATAR}" alt="${s.bot_name || 'AIntento'}" style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-weight: 600; font-size: 15px; color: ${s.text_color || 'white'};">${s.bot_name || 'AIntento'}</span>
                    <span style="font-size: 12px; opacity: 0.9; color: ${s.text_color || 'white'};">🟢 ${s.bot_status_text || 'Завжди онлайн'}</span>
                </div>
            </div>
            <button id="aintento-close" style="
                background: rgba(255,255,255,0.2);
                border: none;
                color: ${s.text_color || 'white'};
                font-size: 18px;
                cursor: pointer;
                padding: 4px;
                width: 28px;
                height: 28px;
                border-radius: 50%;
                transition: all 0.2s;
            ">✕</button>
        </div>
    `;
}

/**
 * Create input container HTML
 */
function createInputContainerHTML(settings) {
    const s = settings;
    return `
        <div class="aintento-input-container" style="
            padding: 16px;
            background: white;
            border-top: 1px solid #e5e7eb;
            box-shadow: 0 -2px 8px rgba(0,0,0,0.05);
        ">
            <div id="aintento-quick-actions-bar" style="
                display: none;
                margin-bottom: 12px;
                overflow-x: auto;
                overflow-y: hidden;
                white-space: nowrap;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                -ms-overflow-style: none;
                padding-right: 16px;
            "></div>
            ${s.consent_notice ? `
            <div style="font-size: 11px; color: #6b7280; margin-bottom: 12px; line-height: 1.4;">
                ${s.consent_notice}
            </div>` : ''}
            <div style="display: flex; gap: 8px;">
                <input 
                    type="text" 
                    id="aintento-input" 
                    placeholder="${s.input_placeholder}"
                    style="
                        flex: 1;
                        padding: 12px 16px;
                        border: 1.5px solid #e5e7eb;
                        border-radius: 24px;
                        outline: none;
                        font-size: 14px;
                        transition: all 0.2s;
                    "
                >
                <button 
                    id="aintento-send" 
                    style="
                        width: 44px;
                        height: 44px;
                        border-radius: 50%;
                        background: ${s.primary_color};
                        color: white;
                        border: none;
                        cursor: pointer;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        transition: all 0.2s;
                    "
                >
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                </button>
            </div>
        </div>
    `;
}

/**
 * Create loading indicator
 */
export function createLoader(settings) {
    const s = settings || getSettings();
    const div = document.createElement('div');
    div.className = 'aintento-loader';
    div.style.cssText = 'margin-bottom: 16px; display: flex; justify-content: flex-start;';
    div.innerHTML = `
        <div style="background: #f3f4f6; padding: 12px 16px; border-radius: 18px 18px 18px 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); display: flex; align-items: center; gap: 8px;">
            <div style="display: flex; gap: 2px;">
                <span style="display: inline-block; color: ${s.primary_color}; font-size: 16px; animation: aintento-pulse 1.4s infinite;">●</span>
                <span style="display: inline-block; color: ${s.primary_color}; font-size: 16px; animation: aintento-pulse 1.4s 0.2s infinite;">●</span>
                <span style="display: inline-block; color: ${s.primary_color}; font-size: 16px; animation: aintento-pulse 1.4s 0.4s infinite;">●</span>
            </div>
            <span class="aintento-loader-text" style="color: #6b7280; font-size: 13px;">Думаю...</span>
        </div>
    `;
    return div;
}

/**
 * Remove loader from DOM
 */
export function removeLoader(loader) {
    loader?.parentNode?.removeChild(loader);
}

/**
 * Get widget elements from DOM
 */
export function getWidgetElements() {
    return {
        toggle: document.getElementById('aintento-toggle'),
        close: document.getElementById('aintento-close'),
        window: document.getElementById('aintento-window'),
        overlay: document.getElementById('aintento-overlay'),
        input: document.getElementById('aintento-input'),
        send: document.getElementById('aintento-send'),
        messages: document.getElementById('aintento-messages'),
        bubble: document.getElementById('aintento-bubble'),
        bubbleClose: document.getElementById('aintento-bubble-close')
    };
}
