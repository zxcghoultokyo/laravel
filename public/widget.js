/**
 * AIntento Chat Widget v2.3.8
 * Embeddable chat widget for e-commerce sites
 * SSE Streaming support for real-time responses
 * 
 * Usage: <div id="aintento-chat" data-token="YOUR_TOKEN"></div>
 *        <script src="https://aimbot.laravel.cloud/widget.js?v=2.3.7"></script>
 * 
 * API: window.openChat() - opens the chat widget
 *      window.aintentoClose() - closes the chat widget
 */
(function() {
    'use strict';

    const WIDGET_VERSION = '2.6.5';
    const DEBUG = true; // Enable for troubleshooting
    
    // Capture script reference immediately (before DOMContentLoaded makes it null)
    const CURRENT_SCRIPT = document.currentScript;
    
    // Determine API base URL from script src
    let BASE_URL = 'https://aimbot.laravel.cloud';
    if (CURRENT_SCRIPT && CURRENT_SCRIPT.src) {
        try {
            const scriptUrl = new URL(CURRENT_SCRIPT.src);
            BASE_URL = scriptUrl.origin;
        } catch (e) {
            // fallback to production
        }
    }
    
    // Bot avatar URL
    let BOT_AVATAR = BASE_URL + '/images/aintento-avatar.svg';

    function log(...args) {
        if (DEBUG) console.log('[AIntento]', ...args);
    }

    function logError(...args) {
        console.error('[AIntento]', ...args);
    }

    // Helper function to adjust color brightness
    function adjustBrightness(color, amount) {
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

    // Wait for DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWidget);
    } else {
        initWidget();
    }

    function initWidget() {
        log('Initializing widget v' + WIDGET_VERSION);
        
        // Support both old and new container IDs
        const container = document.getElementById('aintento-chat') || document.getElementById('ailure-chat');
        if (!container) {
            logError('Container #aintento-chat not found');
            return;
        }

        const token = container.dataset.token;
        if (!token) {
            logError('data-token not specified');
            return;
        }

        // Read tenant_id from data attribute (more reliable than resolving from token)
        const tenantIdFromAttr = container.dataset.tenantId;
        if (tenantIdFromAttr) {
            window.aintentoTenantId = tenantIdFromAttr;
            log('Tenant ID from data attribute:', tenantIdFromAttr);
        }

        log('Token received, loading settings...');

        const apiUrl = BASE_URL + '/api/widget/settings';

        fetch(apiUrl, {
            headers: {
                'X-Widget-Token': token,
                'Content-Type': 'application/json'
            }
        })
        .then(res => res.json())
        .then(settings => {
            log('Settings loaded', settings);
            
            // Check if widget is blocked (subscription/trial issue)
            if (settings.blocked) {
                log('Widget blocked:', settings.reason, settings.message);
                // Don't render widget at all - just silently exit
                // The widget won't appear on the site
                return;
            }
            
            renderWidget(container, settings, token);
        })
        .catch(err => {
            logError('Failed to load settings', err);
            renderWidget(container, getDefaultSettings(), token);
        });
    }

    function getDefaultSettings() {
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
            glow_color: null, // defaults to primary_color if null
            bot_status_text: 'Завжди онлайн',
            welcome_message: 'Вітаю! 👋 Я AIntento — ваш персональний помічник з підбору спорядження. Чим можу допомогти?',
            input_placeholder: 'Напишіть повідомлення...',
            consent_notice: null,
            enabled: true,
            start_state: 'closed'
        };
    }

    function renderWidget(container, settings, token) {
        if (!settings.enabled) {
            log('Widget disabled in settings');
            return;
        }

        // Bot avatar - use base64 if available, then URL, otherwise default
        BOT_AVATAR = settings.bot_avatar_base64 || settings.bot_avatar_url || (BASE_URL + '/images/aintento-avatar.svg');
        
        // Glow color - use custom if set, otherwise primary color
        window.aintentoGlowColor = settings.glow_color || settings.primary_color;

        // Store settings globally (including tenant_id for API calls)
        window.aintentoSettings = settings;
        // Priority: data-tenant-id attribute > settings.tenant_id (attribute is more reliable)
        window.aintentoTenantId = window.aintentoTenantId || settings.tenant_id || null;
        log('Final tenant_id:', window.aintentoTenantId);

        // Inject CSS (only once)
        injectStyles(settings);

        const sessionId = getOrCreateSessionId();
        const savedMessages = loadMessages(sessionId);

        // Create HTML structure
        container.innerHTML = createWidgetHTML(settings);

        // Get DOM elements
        const elements = {
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

        // Widget state
        const state = {
            isOpen: false,
            hasShownWelcome: false,
            sessionId: sessionId,
            eventListeners: [], // Track listeners for cleanup
            operatorMode: false,
            lastOperatorMessageId: 0,
            pollInterval: null
        };

        // Setup event handlers with cleanup tracking
        setupEventHandlers(elements, state, settings, token, savedMessages);
        
        // Setup add-to-cart tracking on the page
        setupAddToCartTracking();
        
        // Setup checkout form tracking
        setupCheckoutTracking();
        
        // Track page view (widget loaded on page)
        sendAnalyticsEvent('page_view', {
            widget_version: WIDGET_VERSION
        });
    }
    
    /**
     * Setup tracking for add-to-cart buttons on the merchant's site
     */
    function setupAddToCartTracking() {
        // Common selectors for add-to-cart buttons
        const cartSelectors = [
            '.add-to-cart',
            '.btn-cart',
            '[data-action="add-to-cart"]',
            'button[name="add"]',
            '.product-buy',
            '.buy-button',
            // Horoshop specific
            '.j-buy-button-add',
            '.j-buy-button',
            '[id^="j-buy-button"]',
            '[class*="j-buy"]',
            '.hs-btn-cart',
            '.hs-add-to-cart',
            '[data-hs-action="cart"]',
            // Generic patterns
            'button[class*="buy"]',
            'a[class*="buy"]',
            '.btn-buy',
            '.product-buy-btn'
        ];

        // Click listener for cart buttons
        document.addEventListener('click', (e) => {
            const target = e.target.closest(cartSelectors.join(','));
            if (target) {
                log('Add to cart button clicked:', target);
                
                // Extract product info
                const productData = extractProductFromButton(target);
                
                // Check if user had chat conversation
                const sessionId = localStorage.getItem('aintento_session_id');
                const messagesKey = sessionId ? 'aintento_messages_' + sessionId : null;
                const messages = messagesKey ? localStorage.getItem(messagesKey) : null;
                const hadChatConversation = messages && JSON.parse(messages).length > 0;
                
                // Check if product was shown in chat
                const shownProductsKey = sessionId ? 'aintento_shown_products_' + sessionId : null;
                const shownProducts = shownProductsKey ? JSON.parse(localStorage.getItem(shownProductsKey) || '[]') : [];
                const productFromChat = shownProducts.includes(productData.id) || shownProducts.includes(productData.article);
                
                sendAnalyticsEvent('add_to_cart', {
                    product_id: productData.id,
                    product_article: productData.article,
                    product_title: productData.title,
                    product_price: productData.price,
                    product_from_chat: productFromChat,
                    had_chat_conversation: hadChatConversation
                });
                
                log('Add to cart tracked:', productData, { hadChatConversation, productFromChat });
            }
        }, true);
        
        log('Add-to-cart tracking initialized');
    }
    
    /**
     * Setup tracking for checkout form submission
     * Tracks when user submits order on Horoshop checkout page
     */
    function setupCheckoutTracking() {
        // Hook into Horoshop's marketingEvents system
        setupHoroshopCheckoutHook();
        
        // Fallback: Check if we're on checkout page for form-based tracking
        const isCheckoutPage = window.location.pathname.includes('/checkout') ||
                               document.querySelector('form[action*="/order/submit"]') ||
                               document.querySelector('.checkout');
        
        if (!isCheckoutPage) {
            log('Not a checkout page, skipping form-based checkout tracking');
            return;
        }
        
        log('Checkout page detected, setting up form tracking');
        
        // Horoshop checkout form selectors
        const checkoutFormSelectors = [
            'form[action*="/order/submit"]',
            'form#checkout-container',
            '.checkout form',
            'form[action*="checkout"]',
            '#checkout form',
            '.order-form'
        ];
        
        // Submit button selectors
        const submitButtonSelectors = [
            '.j-submit',
            'button[type="submit"]',
            '.checkout-footer button',
            'button:contains("Оформити")',
            '[class*="submit"]'
        ];
        
        // Find the checkout form with retry (form may load dynamically)
        const findAndHookForm = (attempt = 1) => {
            let checkoutForm = null;
            for (const selector of checkoutFormSelectors) {
                checkoutForm = document.querySelector(selector);
                if (checkoutForm) break;
            }
            
            if (!checkoutForm) {
                if (attempt < 5) {
                    // Retry up to 5 times with increasing delay
                    setTimeout(() => findAndHookForm(attempt + 1), attempt * 500);
                    log('Checkout form not found, retrying... (attempt ' + attempt + ')');
                    return;
                }
                log('Checkout form not found after ' + attempt + ' attempts');
                return;
            }
            
            log('Checkout form found:', checkoutForm);
            setupFormSubmitHandler(checkoutForm);
        };
        
        findAndHookForm();
    }
    
    function setupFormSubmitHandler(checkoutForm) {
        if (checkoutForm._aintentoHooked) return; // Prevent double-hooking
        checkoutForm._aintentoHooked = true;
        
        log('Checkout tracking initialized');
        
        // Track form submission
        checkoutForm.addEventListener('submit', function(e) {
            log('Checkout form submitted');
            
            // Extract order data from form
            const formData = new FormData(checkoutForm);
            const orderData = extractCheckoutData(formData, checkoutForm);
            
            // Check if user had chat conversation
            const sessionId = localStorage.getItem('aintento_session_id');
            const messagesKey = sessionId ? 'aintento_messages_' + sessionId : null;
            const messages = messagesKey ? localStorage.getItem(messagesKey) : null;
            const hadChatConversation = messages && JSON.parse(messages).length > 0;
            
            // Skip tracking if no chat conversation - we only care about chat-attributed checkouts
            if (!hadChatConversation) {
                log('Skipping checkout_submit tracking - no chat conversation');
                return;
            }
            
            // Get products shown in chat
            const shownProductsKey = sessionId ? 'aintento_shown_products_' + sessionId : null;
            const shownProducts = shownProductsKey ? JSON.parse(localStorage.getItem(shownProductsKey) || '[]') : [];
            
            // Track checkout submit event
            sendAnalyticsEvent('checkout_submit', {
                order_items_count: orderData.itemsCount,
                order_total: orderData.total,
                has_email: orderData.hasEmail,
                has_phone: orderData.hasPhone,
                delivery_type: orderData.deliveryType,
                had_chat_conversation: hadChatConversation,
                products_from_chat: shownProducts.length,
                city: orderData.city
            });
            
            log('Checkout submit tracked:', orderData, { hadChatConversation });
            
            // Also track as conversion (lead type - потенційний клієнт оформив замовлення)
            sendConversionEvent('checkout', {
                order_total: orderData.total,
                items_count: orderData.itemsCount,
                had_chat_conversation: hadChatConversation,
                products_from_chat: shownProducts
            });
        });
        
        log('Checkout tracking initialized');
    }
    
    /**
     * Hook into Horoshop's marketing events system
     * This is the most reliable way to track checkout completion
     */
    function setupHoroshopCheckoutHook() {
        // Wait for Horoshop's INIT system
        const hookHoroshop = () => {
            // Method 1: Hook into marketingEvents.checkout_success array
            if (window.marketingEvents && Array.isArray(window.marketingEvents.checkout_success)) {
                window.marketingEvents.checkout_success.push(function(order_id, cart, name, email, phone, eventId) {
                    log('Horoshop checkout_success triggered:', { order_id, cart, name, email, phone });
                    trackCheckoutSuccess(order_id, cart, name, email, phone);
                });
                log('Hooked into Horoshop marketingEvents.checkout_success');
                return true;
            }
            
            // Method 2: Override triggerMarketingEvent
            if (window.triggerMarketingEvent) {
                const originalTrigger = window.triggerMarketingEvent;
                window.triggerMarketingEvent = function(eventName, args) {
                    if (eventName === 'checkout_success') {
                        log('Intercepted triggerMarketingEvent checkout_success:', args);
                        const [order_id, cart, name, email, phone] = args || [];
                        trackCheckoutSuccess(order_id, cart, name, email, phone);
                    }
                    return originalTrigger.apply(this, arguments);
                };
                log('Hooked triggerMarketingEvent');
                return true;
            }
            
            return false;
        };
        
        // Try immediately
        if (!hookHoroshop()) {
            // Retry after DOM ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => {
                    setTimeout(hookHoroshop, 500);
                });
            } else {
                setTimeout(hookHoroshop, 500);
            }
            // Retry after window load (for lazy-loaded scripts)
            window.addEventListener('load', () => {
                setTimeout(hookHoroshop, 1000);
            });
        }
        
        // Method 3: Listen for .j-submit button click as backup
        document.addEventListener('click', (e) => {
            const submitBtn = e.target.closest('.j-submit, button[type="submit"].btn');
            if (submitBtn && !submitBtn.disabled) {
                const isCheckoutPage = window.location.pathname.includes('/checkout') ||
                                       document.querySelector('.checkout-footer');
                if (isCheckoutPage) {
                    log('Checkout submit button clicked');
                    // Store pending checkout for success tracking
                    sessionStorage.setItem('aintento_pending_checkout', JSON.stringify({
                        timestamp: Date.now(),
                        session_id: localStorage.getItem('aintento_session_id')
                    }));
                }
            }
        }, true);
        
        // Method 4: Listen for URL change to thank-you page
        const checkThankYouPage = () => {
            const isThankYou = window.location.pathname.includes('/checkout/success') ||
                               window.location.pathname.includes('/thank') ||
                               document.querySelector('.checkout-success, .order-success, .thank-you');
            if (isThankYou) {
                const pending = sessionStorage.getItem('aintento_pending_checkout');
                if (pending) {
                    log('Thank you page detected with pending checkout');
                    const data = JSON.parse(pending);
                    // Only track if within last 5 minutes
                    if (Date.now() - data.timestamp < 300000) {
                        trackCheckoutSuccess(null, null, null, null, null);
                    }
                    sessionStorage.removeItem('aintento_pending_checkout');
                }
            }
        };
        
        // Check on page load
        checkThankYouPage();
    }
    
    /**
     * Track checkout success event
     */
    function trackCheckoutSuccess(order_id, cart, name, email, phone) {
        const sessionId = localStorage.getItem('aintento_session_id');
        const messagesKey = sessionId ? 'aintento_messages_' + sessionId : null;
        const messages = messagesKey ? localStorage.getItem(messagesKey) : null;
        const hadChatConversation = messages && JSON.parse(messages).length > 0;
        
        // Skip tracking if no chat conversation - we only care about chat-attributed checkouts
        if (!hadChatConversation) {
            log('Skipping checkout_success tracking - no chat conversation');
            return;
        }
        
        // Get products shown in chat
        const shownProductsKey = sessionId ? 'aintento_shown_products_' + sessionId : null;
        const shownProducts = shownProductsKey ? JSON.parse(localStorage.getItem(shownProductsKey) || '[]') : [];
        
        // Extract cart data
        let orderTotal = 0;
        let itemsCount = 0;
        let products = [];
        
        if (cart && cart.products) {
            products = cart.products.map(p => ({
                id: p.id,
                title: p.title,
                price: p.price?.value || p.price,
                quantity: p.quantity
            }));
            itemsCount = products.reduce((sum, p) => sum + (p.quantity || 1), 0);
            orderTotal = cart.total?.total?.sum || cart.total?.sum || 0;
        }
        
        // Check if any product in order was shown in chat
        const hasProductFromChat = products.some(p => 
            shownProducts.includes(String(p.id)) || 
            shownProducts.includes(p.article)
        );
        
        sendAnalyticsEvent('checkout_success', {
            order_id: order_id,
            order_total: orderTotal,
            items_count: itemsCount,
            has_email: !!email,
            has_phone: !!phone,
            had_chat_conversation: hadChatConversation,
            has_product_from_chat: hasProductFromChat,
            products_from_chat_count: shownProducts.length
        });
        
        log('Checkout success tracked:', {
            order_id,
            orderTotal,
            itemsCount,
            hadChatConversation,
            hasProductFromChat
        });
    }
    
    /**
     * Extract checkout data from form
     */
    function extractCheckoutData(formData, form) {
        const data = {
            itemsCount: 0,
            total: 0,
            hasEmail: false,
            hasPhone: false,
            deliveryType: null,
            city: null
        };
        
        // Check for email field
        const emailField = form.querySelector('input[type="email"], input[name*="email"]');
        data.hasEmail = emailField && emailField.value && emailField.value.includes('@');
        
        // Check for phone field
        const phoneField = form.querySelector('input[name*="phone"], .j-phone');
        data.hasPhone = phoneField && phoneField.value && phoneField.value.length > 5;
        
        // Get delivery type
        const deliverySelect = form.querySelector('select[name*="delivery_type"], .j-delivery-type');
        if (deliverySelect) {
            const selectedOption = deliverySelect.options ? deliverySelect.options[deliverySelect.selectedIndex] : null;
            data.deliveryType = selectedOption ? selectedOption.text : null;
        }
        
        // Get city
        const cityField = form.querySelector('input[name*="city"]');
        data.city = cityField ? cityField.value : null;
        
        // Try to get cart data from page
        const cartTotal = document.querySelector('.cart-total, .order-total, [class*="total"] .price');
        if (cartTotal) {
            const priceMatch = cartTotal.textContent.match(/[\d\s]+/);
            if (priceMatch) {
                data.total = parseFloat(priceMatch[0].replace(/\s/g, '')) || 0;
            }
        }
        
        // Count cart items
        const cartItems = document.querySelectorAll('.cart-item, .order-item, [class*="cart"] [class*="item"]');
        data.itemsCount = cartItems.length || 1;
        
        return data;
    }
    
    /**
     * Send conversion event (goes to chat_conversions table)
     */
    function sendConversionEvent(conversionType, data = {}) {
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
     * Extract product info from add-to-cart button
     * Supports Horoshop product pages and listing pages
     */
    function extractProductFromButton(button) {
        const data = {};
        
        // Try button ID (Horoshop: j-buy-button-widget-2784) - this is Horoshop internal ID
        if (button.id) {
            const match = button.id.match(/(\d+)/);
            if (match) data.horoshop_id = match[1];
        }
        
        // Try data attributes
        if (button.dataset.id) data.id = button.dataset.id;
        if (button.dataset.productId) data.id = button.dataset.productId;
        if (button.dataset.article) data.article = button.dataset.article;
        
        // ============ HOROSHOP PRODUCT PAGE DETECTION ============
        // Check if we're on a product page (has product schema or specific elements)
        const isProductPage = document.querySelector('[itemtype*="schema.org/Product"]') || 
                              document.querySelector('.product-title') ||
                              document.querySelector('.product-header');
        
        if (isProductPage) {
            // --- Extract Article (SKU) ---
            // Horoshop: <div class="product-header__code">Артикул: g6l-k26-0bd</div>
            const articleEl = document.querySelector('.product-header__code, [itemprop="sku"], .product-article, .sku');
            if (articleEl) {
                const text = articleEl.textContent.trim();
                const articleMatch = text.match(/(?:Артикул[:\s]*)?([a-zA-Z0-9_-]+)/i);
                if (articleMatch) data.article = articleMatch[1];
            }
            
            // --- Extract Title ---
            // Horoshop: <h1 class="product-title" itemprop="name">...</h1>
            const titleEl = document.querySelector('h1.product-title, [itemprop="name"], h1');
            if (titleEl) data.title = titleEl.textContent.trim();
            
            // --- Extract Price ---
            // Horoshop: <meta itemprop="price" content="1300">
            const priceMeta = document.querySelector('[itemprop="price"]');
            if (priceMeta) {
                data.price = parseFloat(priceMeta.getAttribute('content')) || null;
            }
            if (!data.price) {
                const priceEl = document.querySelector('.product-price__item, .product-price');
                if (priceEl) {
                    const priceText = priceEl.textContent.replace(/[^\d.,]/g, '').replace(',', '.');
                    data.price = parseFloat(priceText) || null;
                }
            }
            
            // --- Extract Brand ---
            const brandEl = document.querySelector('[itemprop="brand"] [itemprop="name"]');
            if (brandEl) data.brand = brandEl.getAttribute('content') || brandEl.textContent.trim();
            
            // --- Extract Category ---
            const categoryEl = document.querySelector('[itemprop="category"]');
            if (categoryEl) data.category = categoryEl.getAttribute('content') || categoryEl.textContent.trim();
            
            // --- Extract Image ---
            const imageEl = document.querySelector('.gallery__photo-img, [itemprop="image"]');
            if (imageEl) data.image = imageEl.src;
        }
        
        // ============ FALLBACK: Article from page text ============
        if (!data.article) {
            const pageText = document.body.innerText;
            const articleMatch = pageText.match(/Артикул[:\s]*([a-zA-Z0-9_-]+)/i);
            if (articleMatch) data.article = articleMatch[1];
        }
        
        // ============ PRODUCT BLOCK (listing pages) ============
        const productBlock = button.closest('[data-id], .j-product-block, .product-card, .product, .productsSlider-i');
        if (productBlock) {
            if (productBlock.dataset.id && !data.id) data.id = productBlock.dataset.id;
            
            // Title from block
            if (!data.title) {
                const titleEl = productBlock.querySelector('.product-title, .productsSlider-title, h1, h2, h3, a');
                if (titleEl) data.title = titleEl.textContent.trim();
            }
            
            // Price from block
            if (!data.price) {
                const priceEl = productBlock.querySelector('.product-price, .productsSlider-price, [class*="price"]');
                if (priceEl) {
                    const priceText = priceEl.textContent.replace(/[^\d.,]/g, '').replace(',', '.');
                    data.price = parseFloat(priceText) || null;
                }
            }
            
            // Article from link href
            if (!data.article) {
                const link = productBlock.querySelector('a[href]');
                if (link) {
                    // Try to extract article from URL or data
                    const href = link.getAttribute('href');
                    data.page_url = href;
                }
            }
        }
        
        // ============ FINAL FALLBACK: Page H1 ============
        if (!data.title) {
            const h1 = document.querySelector('h1');
            if (h1) data.title = h1.textContent.trim();
        }
        
        // Try counter sibling (Horoshop structure)
        const counter = button.closest('.product-order__row')?.querySelector('[data-id]');
        if (counter?.dataset.id && !data.id) data.id = counter.dataset.id;
        
        // Get current page URL
        data.page_url = data.page_url || window.location.href;
        
        log('Extracted product data:', data);
        return data;
    }

    function injectStyles(settings) {
        if (document.getElementById('aintento-styles')) return;
        
        // Glow color - use custom if set, otherwise primary color
        const glowColor = settings.glow_color || settings.primary_color || '#2563eb';
        
        // Convert hex to rgb for rgba support
        const hexToRgb = (hex) => {
            const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}` : '37, 99, 235';
        };
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
                    right: 20px !important;
                    z-index: 10000 !important;
                }
            }
        `;
        document.head.appendChild(style);
    }

    function createWidgetHTML(settings) {
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
                    <!-- Speech bubble tail - SVG arrow -->
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
                    <!-- Close bubble button -->
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

                    <div class="aintento-input-container" style="
                        padding: 16px;
                        background: white;
                        border-top: 1px solid #e5e7eb;
                        box-shadow: 0 -2px 8px rgba(0,0,0,0.05);
                    ">
                        <!-- Quick Actions Carousel (persistent) -->
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
                                    border: 1.5px solid #d1d5db;
                                    border-radius: 24px;
                                    font-size: 14px;
                                    outline: none;
                                    transition: all 0.2s;
                                "
                            >
                            <button id="aintento-send" style="
                                background: ${s.primary_color};
                                color: white;
                                border: none;
                                padding: 0;
                                width: 44px;
                                height: 44px;
                                border-radius: 50%;
                                cursor: pointer;
                                font-size: 18px;
                                transition: all 0.2s;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                box-shadow: 0 2px 8px ${s.primary_color}40;
                            ">➤</button>
                        </div>
                        <div style="text-align: center; margin-top: 8px; font-size: 10px; color: #9ca3af;">
                            Powered by <a href="https://aimbot.laravel.cloud/" target="_blank" style="color: #6b7280; text-decoration: none;">Aintento</a> AI
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function setupEventHandlers(elements, state, settings, token, savedMessages) {
        const { toggle, close, window: chatWindow, overlay, input, send, messages, bubble, bubbleClose } = elements;

        // Hide bubble helper function
        function hideBubble() {
            if (bubble) {
                bubble.style.display = 'none';
                // Remember that user dismissed bubble
                try {
                    localStorage.setItem('aintento_bubble_dismissed', 'true');
                } catch (e) {}
            }
        }
        
        // Check if bubble was already dismissed
        let bubbleDismissed = false;
        try {
            bubbleDismissed = localStorage.getItem('aintento_bubble_dismissed') === 'true';
        } catch (e) {}
        
        // Show bubble after 30 second delay (if not dismissed and chat is closed)
        if (!bubbleDismissed && bubble) {
            setTimeout(function() {
                // Only show if chat is still closed and bubble wasn't dismissed
                if (!state.isOpen) {
                    try {
                        if (localStorage.getItem('aintento_bubble_dismissed') === 'true') return;
                    } catch (e) {}
                    bubble.style.display = 'block';
                    // Trigger reflow then animate
                    bubble.offsetHeight;
                    bubble.style.opacity = '1';
                }
            }, 30000); // 30 seconds delay
        }
        
        // Bubble close button click
        if (bubbleClose) {
            bubbleClose.addEventListener('click', function(e) {
                e.stopPropagation();
                hideBubble();
            });
        }
        
        // Bubble click opens chat
        if (bubble) {
            bubble.addEventListener('click', function() {
                hideBubble();
                openWidget();
            });
        }

        // Quick action responses
        const quickActionResponses = {
            order_info: 'Для пошуку замовлення мені потрібно:\n\n📝 Номер замовлення (наприклад: 12345)\n\nабо\n\n📱 Номер телефону з якого робили замовлення\n\nНапиши будь-що з цього і я знайду твоє замовлення!',
            store_info: null // Will be fetched from settings
        };

        // Handle quick action click
        function handleQuickAction(action) {
            if (action === 'store_info') {
                // Build store info from settings
                const storeInfo = buildStoreInfo(settings);
                addMessage(messages, storeInfo, 'assistant', state.sessionId, true);
            } else if (action === 'top_products') {
                // Send "Покажи топ товари" to backend to get popular products
                sendMessage('покажи топ товари');
            } else if (action === 'order_info') {
                // Show order search form instead of text message
                showOrderSearchForm(messages, settings, state.sessionId, token, sendMessage);
            } else {
                const response = quickActionResponses[action];
                if (response) {
                    addMessage(messages, response, 'assistant', state.sessionId, true);
                }
            }
        }

        // Build store info from settings
        function buildStoreInfo(s) {
            let info = '';
            
            // Store name - use from settings or generic fallback
            const storeName = s.store_name || 'Магазин';
            info += `🏪 **${storeName}**\n\n`;
            
            // Parse address from faq_contacts_text if store_address contains full FAQ
            let address = '';
            let phone = s.store_phone || '';
            let hours = s.store_hours || '';
            let email = '';
            let instagram = '';
            let telegram = '';
            
            // If store_address contains FAQ text, parse it
            if (s.store_address && s.store_address.length > 50) {
                const text = s.store_address;
                
                // Extract address (м. Київ, ...)
                const addressMatch = text.match(/м\.\s*Київ[^\\n]+/i) || text.match(/Адреса[:\s]+([^\n]+)/i);
                if (addressMatch) {
                    address = addressMatch[0].replace(/^Адреса[:\s]+/i, '').trim();
                }
                
                // Extract hours
                const hoursMatch = text.match(/Пн-Пт[:\s]+[\d:–\s]+/i) || text.match(/Графік[^:]*:[^\n]+/i);
                if (hoursMatch) {
                    hours = hoursMatch[0].replace(/^Графік[^:]*:/i, '').trim();
                }
                
                // Extract email
                const emailMatch = text.match(/E-mail[:\s]+([^\n\s]+)/i);
                if (emailMatch) email = emailMatch[1];
                
                // Extract Instagram
                const igMatch = text.match(/@contractor_kyiv|Instagram[:\s]+@?([^\n\s]+)/i);
                if (igMatch) instagram = igMatch[1] || igMatch[0];
                
            } else if (s.store_address) {
                address = s.store_address;
            }
            
            // Build clean info
            if (address) {
                info += `📍 ${address}\n`;
            }
            if (phone) {
                info += `📞 ${phone}\n`;
            }
            if (hours) {
                info += `🕐 ${hours}\n`;
            }
            if (email) {
                info += `✉️ ${email}\n`;
            }
            if (instagram) {
                info += `📸 Instagram: ${instagram}\n`;
            }
            
            // Callback button hint
            info += `\n💬 [Замовити дзвінок](#callback)\n`;
            
            // Short description from store_about (first sentence only)
            if (s.store_about) {
                const firstSentence = s.store_about
                    .replace(/\n+/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim()
                    .split(/[.!?]\s/)[0];
                if (firstSentence && firstSentence.length < 150) {
                    info += `\n_${firstSentence}._\n`;
                }
            }
            
            info += '\nЧим можу допомогти? 😊';
            return info;
        }

        // Open widget function
        function openWidget() {
            state.isOpen = true;
            chatWindow.style.display = 'flex';
            overlay.style.display = 'block';
            toggle.style.display = 'none';
            hideBubble(); // Hide bubble when chat opens
            input?.focus();
            
            // Track chat opened
            sendAnalyticsEvent('chat_opened');
            
            // Notify ProactiveTriggers
            if (window.aintentoTriggers) {
                window.aintentoTriggers.onChatOpened();
            }
            
            // Start polling for operator messages (will auto-stop if not in operator mode)
            startOperatorPolling();
            
            // Always show quick actions bar (persistent)
            addQuickActions(messages, settings, handleQuickAction);
            
            if (!state.hasShownWelcome && savedMessages.length === 0) {
                // Track session start (first time opening)
                sendAnalyticsEvent('session_start');
                
                // Fetch dynamic greeting based on context
                fetchDynamicGreeting(settings, messages, state);
            }
            
            // Always scroll to bottom when opening chat (show latest messages)
            setTimeout(() => {
                messages.scrollTo({ top: messages.scrollHeight, behavior: 'auto' });
            }, 50);
        }

        // Close widget function
        function closeWidget() {
            state.isOpen = false;
            chatWindow.style.display = 'none';
            overlay.style.display = 'none';
            toggle.style.display = 'flex';
            // Stop polling when chat closed
            stopOperatorPolling();
            // Track chat closed
            sendAnalyticsEvent('chat_closed');
            // Notify ProactiveTriggers
            if (window.aintentoTriggers) {
                window.aintentoTriggers.onChatClosed();
            }
        }

        // Send message function with SSE streaming support
        function sendMessage(customMessage = null) {
            // Ignore event objects passed from click handlers
            if (customMessage && typeof customMessage === 'object' && customMessage.target) {
                customMessage = null;
            }
            
            const message = customMessage || input.value.trim();
            if (!message) return;

            addMessage(messages, message, 'user', state.sessionId, true);
            if (!customMessage) input.value = '';

            // Check if streaming is enabled (default: true)
            const useStreaming = settings.enable_streaming !== false;
            
            if (useStreaming) {
                sendMessageStreaming(message);
            } else {
                sendMessageFetch(message);
            }
        }
        
        // Streaming version using SSE (Server-Sent Events)
        function sendMessageStreaming(message) {
            const loader = addLoader(messages);
            let currentTextElement = null;
            let accumulatedText = '';
            let hasReceivedProducts = false;
            let receivedProducts = [];
            let textAlreadySaved = false; // Track if text was saved before products
            
            // Smooth scroll to loader on start
            setTimeout(() => {
                messages.scrollTo({ top: messages.scrollHeight, behavior: 'smooth' });
            }, 100);
            
            // Build stream URL with tenant_id for proper data isolation
            let streamUrl = BASE_URL + '/api/chat/stream?message=' + encodeURIComponent(message) + '&session_id=' + encodeURIComponent(state.sessionId);
            if (window.aintentoTenantId) {
                streamUrl += '&tenant_id=' + encodeURIComponent(window.aintentoTenantId);
            }
            
            log('Starting SSE stream:', streamUrl);
            
            const eventSource = new EventSource(streamUrl);
            
            eventSource.onopen = function() {
                log('SSE connection opened');
            };
            
            eventSource.onmessage = function(event) {
                try {
                    const data = JSON.parse(event.data);
                    log('SSE event:', data.type, data);
                    
                    // Update session ID if provided
                    if (data.session_id) {
                        state.sessionId = data.session_id;
                        saveSessionId(data.session_id);
                    }
                    
                    switch (data.type) {
                        case 'status':
                            // Update loader text with status
                            if (loader) {
                                const statusText = loader.querySelector('.aintento-loader-text');
                                if (statusText) {
                                    statusText.textContent = data.text || 'Обробляю...';
                                }
                            }
                            break;
                            
                        case 'chunk':
                            // Remove loader on first content chunk
                            if (loader && loader.parentNode) {
                                removeLoader(loader);
                            }
                            
                            // Accumulate text
                            const chunkText = data.text || '';
                            accumulatedText += chunkText;
                            
                            // Check if this is operator mode notification
                            if (chunkText.includes('оператору') || chunkText.includes('Очікуйте відповіді')) {
                                state.operatorMode = true;
                                // Start polling for operator messages
                                startOperatorPolling();
                            }
                            
                            // Create or update text element
                            if (!currentTextElement) {
                                currentTextElement = createStreamingTextElement(messages);
                            }
                            
                            // Update text content with typing effect
                            updateStreamingText(currentTextElement, accumulatedText);
                            break;
                            
                        case 'products':
                            // Remove loader if still present
                            if (loader && loader.parentNode) {
                                removeLoader(loader);
                            }
                            
                            // FIRST: Save accumulated text BEFORE products (for correct order in history)
                            if (accumulatedText.trim() && currentTextElement) {
                                const displayText = extractDisplayText(accumulatedText);
                                if (displayText && displayText !== 'Шукаю для вас...') {
                                    saveMessage(state.sessionId, { role: 'assistant', content: displayText });
                                }
                                textAlreadySaved = true;
                            }
                            
                            // Store products for later display
                            hasReceivedProducts = true;
                            // Support both formats: data.data.products (from streaming agent) and data.products (legacy)
                            receivedProducts = data.data?.products || data.products || [];
                            
                            // Display products immediately after text
                            if (receivedProducts.length > 0) {
                                addProducts(messages, receivedProducts, state.sessionId, true);
                                // Smooth scroll after products added
                                setTimeout(() => {
                                    messages.scrollTo({ top: messages.scrollHeight, behavior: 'smooth' });
                                }, 100);
                            }
                            break;
                            
                        case 'error':
                            // Remove loader
                            if (loader && loader.parentNode) {
                                removeLoader(loader);
                            }
                            
                            addMessage(messages, data.text || 'Сталася помилка', 'assistant', state.sessionId, false);
                            eventSource.close();
                            break;
                            
                        case 'done':
                            log('SSE stream done');
                            
                            // Remove loader if still present
                            if (loader && loader.parentNode) {
                                removeLoader(loader);
                            }
                            
                            // Finalize or remove streaming text element
                            if (currentTextElement) {
                                if (accumulatedText.trim()) {
                                    // Has text - finalize (remove cursor)
                                    finalizeStreamingText(currentTextElement, accumulatedText);
                                    
                                    // Save text message if not already saved (happens when no products were shown)
                                    if (!textAlreadySaved) {
                                        const displayText = extractDisplayText(accumulatedText);
                                        if (displayText && displayText !== 'Шукаю для вас...') {
                                            saveMessage(state.sessionId, { role: 'assistant', content: displayText });
                                            log('Saved text message on done (no products case)');
                                        }
                                    }
                                } else {
                                    // No text - remove empty bubble
                                    currentTextElement.remove();
                                }
                            }
                            
                            // Fetch cross-sell for first product (skip if operator mode)
                            log('Cross-sell check: products=' + receivedProducts.length + ', operatorMode=' + state.operatorMode);
                            if (receivedProducts.length > 0 && !state.operatorMode) {
                                const firstProduct = receivedProducts[0];
                                const productId = firstProduct.id || firstProduct.article;
                                log('Cross-sell: productId=' + productId + ' from', firstProduct);
                                if (productId) {
                                    fetchCrossSellAsync(messages, productId, settings, state.sessionId);
                                }
                            }
                            
                            eventSource.close();
                            break;
                    }
                } catch (err) {
                    logError('SSE parse error:', err, event.data);
                }
            };
            
            eventSource.onerror = function(error) {
                logError('SSE error:', error);
                
                // Remove loader
                if (loader && loader.parentNode) {
                    removeLoader(loader);
                }
                
                // Only show error if we haven't received any content
                if (!accumulatedText && !hasReceivedProducts) {
                    addMessage(messages, 'Вибачте, не вдалося отримати відповідь. Спробуйте ще раз.', 'assistant', state.sessionId, false);
                }
                
                eventSource.close();
            };
        }
        
        // Create a streaming text element (initially empty)
        function createStreamingTextElement(container) {
            const wrapper = document.createElement('div');
            wrapper.className = 'aintento-message assistant';
            wrapper.style.cssText = 'display: flex; gap: 8px; margin-bottom: 8px;';
            
            // Avatar
            const avatar = document.createElement('img');
            avatar.src = BOT_AVATAR;
            avatar.alt = 'Bot';
            avatar.style.cssText = 'width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;';
            
            // Bubble
            const bubble = document.createElement('div');
            bubble.className = 'aintento-bubble streaming';
            bubble.style.cssText = `
                background: #f3f4f6;
                padding: 12px 16px;
                border-radius: 12px;
                max-width: 85%;
                line-height: 1.5;
                color: #374151;
            `;
            
            // Text content with cursor
            const textSpan = document.createElement('span');
            textSpan.className = 'streaming-text';
            
            const cursor = document.createElement('span');
            cursor.className = 'streaming-cursor';
            cursor.style.cssText = `
                display: inline-block;
                width: 8px;
                height: 16px;
                background: #6b7280;
                margin-left: 2px;
                animation: blink 1s infinite;
                vertical-align: middle;
            `;
            
            // Add cursor animation if not exists
            if (!document.querySelector('#aintento-cursor-style')) {
                const style = document.createElement('style');
                style.id = 'aintento-cursor-style';
                style.textContent = '@keyframes blink { 0%, 50% { opacity: 1; } 51%, 100% { opacity: 0; } }';
                document.head.appendChild(style);
            }
            
            bubble.appendChild(textSpan);
            bubble.appendChild(cursor);
            wrapper.appendChild(avatar);
            wrapper.appendChild(bubble);
            container.appendChild(wrapper);
            
            // Scroll to the new element
            wrapper.scrollIntoView({ behavior: 'smooth', block: 'end' });
            
            return wrapper;
        }
        
        // Update streaming text content
        function updateStreamingText(element, text) {
            const textSpan = element.querySelector('.streaming-text');
            if (textSpan) {
                // Default: show text as-is
                let displayText = text || '';
                
                // Check if this looks like a complete JSON structure
                const isJsonStructure = text.trim().startsWith('{') && text.trim().endsWith('}');
                
                // Try to extract intro from JSON response if present
                if (text.includes('"intro"')) {
                    try {
                        const firstBrace = text.indexOf('{');
                        const lastBrace = text.lastIndexOf('}');
                        if (firstBrace !== -1 && lastBrace > firstBrace) {
                            const jsonStr = text.substring(firstBrace, lastBrace + 1);
                            const parsed = JSON.parse(jsonStr);
                            if (parsed.intro) {
                                displayText = parsed.intro;
                            }
                        }
                    } catch (e) {
                        // JSON not complete yet - try regex
                        const introMatch = text.match(/"intro"\s*:\s*"([^"]+)"/);
                        if (introMatch) {
                            displayText = introMatch[1];
                        }
                    }
                } else if (isJsonStructure) {
                    // Complete JSON without intro - show "Обробляю..."
                    displayText = 'Обробляю...';
                }
                // Otherwise show text as-is (plain text response)
                
                textSpan.textContent = displayText;
                
                // Scroll to bottom if we have content
                if (displayText) {
                    element.scrollIntoView({ behavior: 'smooth', block: 'end' });
                }
            }
        }
        
        // Finalize streaming text (remove cursor)
        function finalizeStreamingText(element, text) {
            const cursor = element.querySelector('.streaming-cursor');
            if (cursor) {
                cursor.remove();
            }
            
            // Update bubble style to match regular messages (white background)
            const bubble = element.querySelector('.aintento-bubble');
            if (bubble) {
                bubble.style.background = 'white';
                bubble.style.boxShadow = '0 2px 8px rgba(0,0,0,0.08)';
            }
            
            // Final text cleanup - extract intro from JSON if needed, or show plain text
            let displayText = text || '';
            
            // Check if this looks like raw JSON structure (not just contains keywords)
            const isJsonStructure = text.trim().startsWith('{') && text.trim().endsWith('}');
            
            // Try to extract intro from JSON-like responses
            if (text.includes('"intro"')) {
                try {
                    const firstBrace = text.indexOf('{');
                    const lastBrace = text.lastIndexOf('}');
                    if (firstBrace !== -1 && lastBrace > firstBrace) {
                        const jsonStr = text.substring(firstBrace, lastBrace + 1);
                        const parsed = JSON.parse(jsonStr);
                        if (parsed.intro) {
                            displayText = parsed.intro;
                        }
                    }
                } catch (e) {
                    // Try regex fallback
                    const introMatch = text.match(/"intro"\s*:\s*"([^"]+)"/);
                    if (introMatch) {
                        displayText = introMatch[1];
                    }
                }
            } else if (isJsonStructure) {
                // Pure JSON without intro - try to extract any meaningful text
                try {
                    const parsed = JSON.parse(text.trim());
                    displayText = parsed.text || parsed.message || parsed.response || '';
                } catch (e) {
                    // Not valid JSON, show as is
                }
            }
            
            const textSpan = element.querySelector('.streaming-text');
            if (textSpan) {
                // Use parseMarkdown to format links and other markdown
                textSpan.innerHTML = parseMarkdown(displayText);
            }
            
            // Only hide if truly empty or just JSON garbage
            if (!displayText.trim() || (displayText.trim().startsWith('{') && displayText.trim().endsWith('}'))) {
                element.style.display = 'none';
            }
            // Note: text is now saved in 'products' event handler to preserve correct order
        }
        
        // Extract display text from accumulated stream text (helper function)
        function extractDisplayText(text) {
            if (!text) return '';
            
            const looksLikeJson = text.trim().startsWith('{') || 
                                  text.includes('```') ||
                                  text.includes('"action"') ||
                                  text.includes('"tool"') ||
                                  text.includes('search_products') ||
                                  text.includes('function_call');
            
            if (text.includes('"intro"')) {
                try {
                    const firstBrace = text.indexOf('{');
                    const lastBrace = text.lastIndexOf('}');
                    if (firstBrace !== -1 && lastBrace > firstBrace) {
                        const jsonStr = text.substring(firstBrace, lastBrace + 1);
                        const parsed = JSON.parse(jsonStr);
                        if (parsed.intro) return parsed.intro;
                    }
                } catch (e) {
                    const introMatch = text.match(/"intro"\s*:\s*"([^"]+)"/);
                    if (introMatch) return introMatch[1];
                }
            } else if (!looksLikeJson) {
                return text;
            }
            return '';
        }
        
        // Poll for operator messages
        function startOperatorPolling() {
            if (state.pollInterval) {
                clearInterval(state.pollInterval);
            }
            
            log('Starting operator message polling');
            
            function pollOperator() {
                let pollUrl = BASE_URL + '/api/chat/poll/' + encodeURIComponent(state.sessionId) + 
                    '?last_message_id=' + state.lastOperatorMessageId;
                
                // Add tenant_id for multi-tenant isolation
                if (window.aintentoTenantId) {
                    pollUrl += '&tenant_id=' + encodeURIComponent(window.aintentoTenantId);
                }
                
                fetch(pollUrl)
                    .then(res => res.json())
                    .then(data => {
                        log('Poll response:', data);
                        
                        state.operatorMode = data.operator_mode;
                        
                        // Display new operator messages
                        if (data.messages && data.messages.length > 0) {
                            data.messages.forEach(msg => {
                                // Add operator message to chat and save to localStorage
                                const operatorText = '👤 Оператор: ' + msg.content;
                                addMessage(messages, operatorText, 'assistant', state.sessionId, true);
                                state.lastOperatorMessageId = Math.max(state.lastOperatorMessageId, msg.id);
                            });
                            // Scroll to bottom after adding messages
                            messages.scrollTo({ top: messages.scrollHeight, behavior: 'smooth' });
                        }
                        
                        // Keep polling regardless of operator_mode - it's lightweight
                        // This ensures we catch when operator takes over
                    })
                    .catch(err => {
                        logError('Poll error:', err);
                    });
            }
            
            // Poll immediately and then every 5 seconds
            pollOperator();
            state.pollInterval = setInterval(pollOperator, 5000);
        }
        
        function stopOperatorPolling() {
            if (state.pollInterval) {
                log('Stopping operator message polling');
                clearInterval(state.pollInterval);
                state.pollInterval = null;
            }
            state.operatorMode = false;
        }
        
        // Fallback fetch version (non-streaming)
        function sendMessageFetch(message) {
            const loader = addLoader(messages);
            const chatApiUrl = BASE_URL + '/api/chat';

            fetch(chatApiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Widget-Token': token
                },
                body: JSON.stringify({
                    message: message,
                    session_id: state.sessionId,
                    tenant_id: window.aintentoTenantId
                })
            })
            .then(res => res.json())
            .then(data => {
                removeLoader(loader);
                
                if (data.session_id) {
                    state.sessionId = data.session_id;
                    saveSessionId(data.session_id);
                }

                // Track if we need to scroll (only for first element)
                let hasScrolled = false;

                if (data.text) {
                    const firstMsg = addMessage(messages, data.text, 'assistant', state.sessionId, true, !hasScrolled);
                    if (!hasScrolled && firstMsg) {
                        hasScrolled = true;
                    }
                }

                if (data.data?.product_cards?.length > 0) {
                    if (!hasScrolled) {
                        // Scroll to first product card area
                        setTimeout(() => {
                            const cards = messages.querySelectorAll('.aintento-message');
                            if (cards.length > 0) {
                                cards[cards.length - data.data.product_cards.length]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            }
                        }, 100);
                        hasScrolled = true;
                    }
                    addProductCards(messages, data.data.product_cards, state.sessionId, true);
                } else if (data.data?.products?.length > 0) {
                    if (!hasScrolled) {
                        setTimeout(() => {
                            const container = messages.lastElementChild;
                            container?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }, 100);
                        hasScrolled = true;
                    }
                    addProducts(messages, data.data.products, state.sessionId, true);
                }
                
                // Fetch cross-sell asynchronously (doesn't block main response)
                if (data.data?.cross_sell_product_id) {
                    fetchCrossSellAsync(messages, data.data.cross_sell_product_id, settings, state.sessionId);
                }
                // Legacy: show cross-sell if returned directly (for backwards compatibility)
                else if (data.data?.cross_sell) {
                    setTimeout(() => {
                        addCrossSell(messages, data.data.cross_sell, settings, state.sessionId);
                    }, 500);
                }
            })
            .catch(err => {
                removeLoader(loader);
                addMessage(messages, 'Вибачте, не вдалося надіслати повідомлення. Спробуйте ще раз.', 'assistant', state.sessionId, false);
                logError('Send error:', err);
            });
        }
        
        // Fetch cross-sell suggestions asynchronously
        function fetchCrossSellAsync(messagesContainer, productId, settings, sessionId) {
            let crossSellUrl = BASE_URL + '/api/cross-sell?product_id=' + productId;
            
            // Add tenant_id for multi-tenant isolation
            if (window.aintentoTenantId) {
                crossSellUrl += '&tenant_id=' + encodeURIComponent(window.aintentoTenantId);
            }
            
            fetch(crossSellUrl, {
                headers: {
                    'X-Widget-Token': token,
                    'Content-Type': 'application/json'
                }
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.cross_sell) {
                    setTimeout(() => {
                        addCrossSell(messagesContainer, data.cross_sell, settings, sessionId);
                    }, 300);
                }
            })
            .catch(err => {
                log('Cross-sell fetch failed (non-critical):', err);
            });
        }

        // Keyboard handler
        function handleKeydown(e) {
            if (e.key === 'Escape' && state.isOpen) {
                closeWidget();
            }
        }

        function handleEnter(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        }

        // Input focus/blur handlers
        function handleInputFocus() {
            input.style.borderColor = settings.primary_color;
            input.style.boxShadow = `0 0 0 3px ${settings.primary_color}20`;
        }

        function handleInputBlur() {
            input.style.borderColor = '#d1d5db';
            input.style.boxShadow = 'none';
        }

        // Close button hover
        function handleCloseHover() {
            close.style.background = 'rgba(255,255,255,0.3)';
        }
        function handleCloseLeave() {
            close.style.background = 'rgba(255,255,255,0.2)';
        }

        // Toggle button hover
        function handleToggleHover() {
            toggle.style.transform = 'scale(1.1)';
        }
        function handleToggleLeave() {
            toggle.style.transform = 'scale(1)';
        }

        // Send button hover
        function handleSendHover() {
            send.style.transform = 'scale(1.05)';
        }
        function handleSendLeave() {
            send.style.transform = 'scale(1)';
        }

        // Add event listeners
        toggle.addEventListener('click', openWidget);
        toggle.addEventListener('mouseenter', handleToggleHover);
        toggle.addEventListener('mouseleave', handleToggleLeave);
        
        close.addEventListener('click', closeWidget);
        close.addEventListener('mouseenter', handleCloseHover);
        close.addEventListener('mouseleave', handleCloseLeave);
        
        overlay.addEventListener('click', closeWidget);
        
        send.addEventListener('click', sendMessage);
        send.addEventListener('mouseenter', handleSendHover);
        send.addEventListener('mouseleave', handleSendLeave);
        
        input.addEventListener('keypress', handleEnter);
        input.addEventListener('focus', handleInputFocus);
        input.addEventListener('blur', handleInputBlur);
        
        document.addEventListener('keydown', handleKeydown);

        // Restore message history
        if (savedMessages.length > 0) {
            state.hasShownWelcome = true;
            savedMessages.forEach(msg => {
                if (msg.role === 'user') {
                    addMessage(messages, msg.content, 'user', state.sessionId, false);
                } else if (msg.role === 'assistant') {
                    addMessage(messages, msg.content, 'assistant', state.sessionId, false);
                } else if (msg.role === 'product_cards' && msg.cards) {
                    addProductCards(messages, msg.cards, state.sessionId, false);
                } else if (msg.role === 'products' && msg.products) {
                    addProducts(messages, msg.products, state.sessionId, false);
                }
            });
            
            // Restore cross-sell if saved
            const savedCrossSell = loadCrossSell(state.sessionId);
            if (savedCrossSell) {
                setTimeout(() => {
                    addCrossSell(messages, savedCrossSell, settings, state.sessionId);
                }, 100);
            }
        }

        // Store cleanup function on window for potential later use
        window.aintentoCleanup = function() {
            toggle.removeEventListener('click', openWidget);
            toggle.removeEventListener('mouseenter', handleToggleHover);
            toggle.removeEventListener('mouseleave', handleToggleLeave);
            close.removeEventListener('click', closeWidget);
            close.removeEventListener('mouseenter', handleCloseHover);
            close.removeEventListener('mouseleave', handleCloseLeave);
            overlay.removeEventListener('click', closeWidget);
            send.removeEventListener('click', sendMessage);
            send.removeEventListener('mouseenter', handleSendHover);
            send.removeEventListener('mouseleave', handleSendLeave);
            input.removeEventListener('keypress', handleEnter);
            input.removeEventListener('focus', handleInputFocus);
            input.removeEventListener('blur', handleInputBlur);
            document.removeEventListener('keydown', handleKeydown);
            log('Widget cleaned up');
        };

        // Expose openChat function globally for external buttons
        window.openChat = openWidget;
        window.aintentoOpen = openWidget;
        window.aintentoClose = closeWidget;
    }

    function addQuickActions(messagesContainer, settings, onActionClick) {
        const s = settings || window.aintentoSettings || { primary_color: '#2563eb' };
        
        const quickActions = [
            {
                icon: '🔥',
                label: 'Топ товари',
                action: 'top_products'
            },
            {
                icon: '📦',
                label: 'Моє замовлення',
                action: 'order_info'
            },
            {
                icon: 'ℹ️',
                label: 'Про магазин',
                action: 'store_info'
            }
        ];

        // Render to persistent bar above input instead of messages
        const quickActionsBar = document.getElementById('aintento-quick-actions-bar');
        if (!quickActionsBar) {
            log('Quick actions bar not found, falling back to messages');
            return;
        }

        quickActionsBar.innerHTML = '';
        quickActionsBar.style.display = 'flex';
        quickActionsBar.style.gap = '8px';

        quickActions.forEach(qa => {
            const btn = document.createElement('button');
            btn.className = 'aintento-quick-action';
            btn.dataset.action = qa.action;
            btn.style.cssText = `
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 12px;
                background: white;
                border: 1.5px solid #e5e7eb;
                border-radius: 18px;
                font-size: 12px;
                color: #374151;
                cursor: pointer;
                transition: all 0.2s;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
                white-space: nowrap;
                flex-shrink: 0;
            `;
            btn.innerHTML = `<span style="font-size: 14px;">${qa.icon}</span><span>${qa.label}</span>`;
            
            btn.onmouseenter = () => {
                btn.style.borderColor = s.primary_color;
                btn.style.background = `${s.primary_color}10`;
                btn.style.transform = 'translateY(-1px)';
                btn.style.boxShadow = '0 3px 8px rgba(0,0,0,0.1)';
            };
            btn.onmouseleave = () => {
                btn.style.borderColor = '#e5e7eb';
                btn.style.background = 'white';
                btn.style.transform = 'translateY(0)';
                btn.style.boxShadow = '0 1px 3px rgba(0,0,0,0.05)';
            };
            
            btn.onclick = () => {
                // Track quick action click
                sendAnalyticsEvent('quick_action_click', {
                    action: qa.action,
                    label: qa.label
                });
                // DON'T remove - keep persistent
                onActionClick(qa.action);
            };
            
            quickActionsBar.appendChild(btn);
        });
        
        // No longer append to messagesContainer - we use the persistent bar
    }

    /**
     * Parse basic markdown to HTML (safe subset)
     */
    function parseMarkdown(text) {
        if (!text) return '';
        
        // Escape HTML first for safety
        let html = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
        
        // Bold: **text** or __text__
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/__(.+?)__/g, '<strong>$1</strong>');
        
        // Italic: *text* or _text_
        html = html.replace(/\*([^*]+?)\*/g, '<em>$1</em>');
        html = html.replace(/_([^_]+?)_/g, '<em>$1</em>');
        
        // Special callback link: [text](#callback) -> opens site's callback modal
        html = html.replace(/\[([^\]]+)\]\(#callback\)/g, '<a href="#" onclick="window.aintentoOpenCallback(); return false;" style="color: inherit; text-decoration: underline; cursor: pointer;">$1</a>');
        
        // Regular links: [text](url)
        html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, '<a href="$2" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;">$1</a>');
        
        // === AUTO-LINKIFY CONTACT INFO ===
        
        // Phone numbers: +380 XX XXX XXXX or similar formats
        // Match: +380, then digits with optional spaces/dashes
        html = html.replace(/(\+38[\s\-]?0[\s\-]?\d{2}[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2})/g, function(match) {
            const cleanPhone = match.replace(/[\s\-]/g, '');
            return '<a href="tel:' + cleanPhone + '" style="color: inherit; text-decoration: underline;">' + match + '</a>';
        });
        
        // Email addresses
        html = html.replace(/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/g, 
            '<a href="mailto:$1" style="color: inherit; text-decoration: underline;">$1</a>');
        
        // Instagram: "Instagram: username" or "@username" (when preceded by Instagram mention)
        html = html.replace(/Instagram:\s*@?([a-zA-Z0-9_\.]+)/gi, 
            'Instagram: <a href="https://instagram.com/$1" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;">@$1</a>');
        html = html.replace(/📸\s*Instagram:\s*@?([a-zA-Z0-9_\.]+)/gi, 
            '📸 Instagram: <a href="https://instagram.com/$1" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;">@$1</a>');
        
        // Telegram: "Telegram: username" or "t.me/username"
        html = html.replace(/Telegram:\s*@?([a-zA-Z0-9_]+)/gi, 
            'Telegram: <a href="https://t.me/$1" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;">@$1</a>');
        html = html.replace(/💬\s*Telegram:\s*@?([a-zA-Z0-9_]+)/gi, 
            '💬 Telegram: <a href="https://t.me/$1" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;">@$1</a>');
        
        // Address with city: "м. Київ, вул. XXX" -> Google Maps link
        // Match Ukrainian address patterns
        html = html.replace(/(м\.\s*[А-Яа-яІіЇїЄєҐґ']+,\s*вул\.\s*[А-Яа-яІіЇїЄєҐґ'\s]+\d*[а-яА-Я]?)/g, function(match) {
            const encodedAddress = encodeURIComponent(match);
            return '<a href="https://www.google.com/maps/search/?api=1&query=' + encodedAddress + '" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;">' + match + '</a>';
        });
        
        // Also match "Адреса: м. Київ..." pattern
        html = html.replace(/Адреса:\s*(м\.\s*[^<\n]+)/gi, function(match, address) {
            // Skip if already linkified
            if (match.includes('href=')) return match;
            const encodedAddress = encodeURIComponent(address.trim());
            return 'Адреса: <a href="https://www.google.com/maps/search/?api=1&query=' + encodedAddress + '" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;">' + address + '</a>';
        });
        
        // Newlines to <br> (preserve pre-wrap behavior for multiple newlines)
        html = html.replace(/\n/g, '<br>');
        
        return html;
    }
    
    // Expose callback function to open site's modal
    window.aintentoOpenCallback = function() {
        // Try to find and click the site's callback link
        const callbackLink = document.querySelector('[data-modal="#call-me"], .phones__callback-link, [href="#call-me"]');
        if (callbackLink) {
            callbackLink.click();
        } else {
            // Fallback: show phone number if configured
            const phone = window.aintentoSettings?.store_phone;
            if (phone) {
                alert('Зателефонуйте нам: ' + phone);
            } else {
                alert('Зверніться до нас через сайт');
            }
        }
    };

    function addMessage(messagesContainer, text, role, sessionId, save = true, scrollToView = false) {
        const s = window.aintentoSettings || { primary_color: '#2563eb' };
        const div = document.createElement('div');
        div.className = `aintento-message aintento-${role}`;
        div.style.cssText = `
            margin-bottom: 12px;
            display: flex;
            justify-content: ${role === 'user' ? 'flex-end' : 'flex-start'};
            animation: aintento-fadeInUp 0.3s ease-out;
        `;

        const bubble = document.createElement('div');
        bubble.style.cssText = `
            background: ${role === 'user' ? s.primary_color : 'white'};
            color: ${role === 'user' ? 'white' : '#374151'};
            padding: 12px 16px;
            border-radius: ${role === 'user' ? '18px 18px 4px 18px' : '18px 18px 18px 4px'};
            max-width: 75%;
            font-size: 14px;
            line-height: 1.6;
            box-shadow: ${role === 'user' ? '0 2px 8px ' + s.primary_color + '30' : '0 2px 8px rgba(0,0,0,0.08)'};
        `;
        
        // Parse markdown for assistant messages, plain text for user
        if (role === 'assistant') {
            bubble.innerHTML = parseMarkdown(text);
        } else {
            bubble.textContent = text;
        }
        
        div.appendChild(bubble);
        messagesContainer.appendChild(div);
        
        if (scrollToView) {
            div.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        if (save) {
            saveMessage(sessionId, { role, content: text });
            // Track message event for analytics
            sendAnalyticsEvent('message', {
                message_type: role,
                message_text: text?.substring(0, 200) // Truncate for privacy
            });
        }
        
        return div;
    }

    function addProductCards(messagesContainer, productCards, sessionId, save = true) {
        const s = window.aintentoSettings || { primary_color: '#2563eb' };
        
        productCards.slice(0, 3).forEach((card, index) => {
            const product = card.product;
            const description = card.description;
            
            if (description?.trim()) {
                const descDiv = document.createElement('div');
                descDiv.className = 'aintento-message aintento-assistant';
                descDiv.style.cssText = `
                    margin-bottom: 8px;
                    display: flex;
                    justify-content: flex-start;
                    animation: aintento-fadeInUp 0.3s ease-out;
                    animation-delay: ${index * 0.1}s;
                `;
                const bubble = document.createElement('div');
                bubble.style.cssText = `
                    background: #f8fafc;
                    color: #64748b;
                    padding: 8px 12px;
                    border-radius: 12px;
                    max-width: 85%;
                    font-size: 13px;
                    line-height: 1.4;
                `;
                bubble.textContent = description;
                descDiv.appendChild(bubble);
                messagesContainer.appendChild(descDiv);
            }
            
            const cardEl = createProductCard(product, s, index);
            messagesContainer.appendChild(cardEl);
        });
        
        if (save) {
            saveMessage(sessionId, { role: 'product_cards', cards: productCards.slice(0, 3) });
        }
    }

    function addProducts(messagesContainer, products, sessionId, save = true) {
        const s = window.aintentoSettings || { primary_color: '#2563eb' };
        const container = document.createElement('div');
        container.style.cssText = 'margin-bottom: 12px;';
        
        products.slice(0, 3).forEach((product, index) => {
            const card = createProductCard(product, s, index);
            container.appendChild(card);
        });

        messagesContainer.appendChild(container);
        
        // Track products shown for analytics (only for new products, not restored from history)
        if (save) {
            trackProductsShown(products.slice(0, 3));
            saveMessage(sessionId, { role: 'products', products: products.slice(0, 3) });
        }
    }

    function createProductCard(product, settings, index) {
        const card = document.createElement('a');
        // Add UTM parameters to product link for attribution
        const sessionId = localStorage.getItem('aintento_session_id') || '';
        const productLink = addUtmToLink(product.link, sessionId, product.id);
        card.href = productLink || '#';
        card.target = '_blank';
        card.style.cssText = `
            display: block;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 8px;
            text-decoration: none;
            color: #374151;
            transition: all 0.2s;
            animation: aintento-fadeInUp 0.3s ease-out;
            animation-delay: ${index * 0.1}s;
        `;
        
        // Track product click for analytics
        card.addEventListener('click', () => {
            trackProductClick(product);
        });

        card.onmouseenter = () => {
            card.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            card.style.transform = 'translateY(-2px)';
            card.style.borderColor = settings.primary_color;
        };
        card.onmouseleave = () => {
            card.style.boxShadow = 'none';
            card.style.transform = 'translateY(0)';
            card.style.borderColor = '#e5e7eb';
        };

        let imgHtml = '';
        if (product.images?.length > 0) {
            const imgId = 'img-' + product.id + '-' + Date.now();
            const fallbackImages = product.images.slice(1).map(img => `'${img}'`).join(',');
            imgHtml = `
                <img id="${imgId}" 
                     src="${product.images[0]}" 
                     style="width: 70px; height: 70px; object-fit: cover; border-radius: 8px; flex-shrink: 0; background: #f3f4f6;" 
                     onerror="(function(img){
                         var fallbacks = [${fallbackImages}];
                         var idx = parseInt(img.dataset.fallbackIdx || '0');
                         if (idx < fallbacks.length) {
                             img.dataset.fallbackIdx = idx + 1;
                             img.src = fallbacks[idx];
                         } else {
                             img.src = 'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 70 70%22><rect fill=%22%23e5e7eb%22 width=%2270%22 height=%2270%22/><text x=%2235%22 y=%2240%22 text-anchor=%22middle%22 fill=%22%239ca3af%22 font-size=%2212%22>Фото</text></svg>';
                         }
                     })(this)"
                />
            `;
        } else {
            // Placeholder for products without images
            imgHtml = `
                <div style="width: 70px; height: 70px; background: #f3f4f6; border-radius: 8px; flex-shrink: 0; display: flex; align-items: center; justify-content: center;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <circle cx="8.5" cy="8.5" r="1.5"/>
                        <polyline points="21,15 16,10 5,21"/>
                    </svg>
                </div>
            `;
        }

        // Build product summary from description and characteristics
        let summaryText = '';
        
        // First try characteristics (object format: {name: value} from backend)
        if (product.characteristics && typeof product.characteristics === 'object') {
            const chars = product.characteristics;
            // Handle both object {key: value} and array [{name, value}] formats
            let charParts = [];
            
            if (Array.isArray(chars)) {
                // Array format: [{name, value}]
                charParts = chars.slice(0, 3).map(c => {
                    const name = c.name || c.n || '';
                    const value = c.value || c.v || '';
                    return name && value ? `${name}: ${value}` : '';
                }).filter(Boolean);
            } else {
                // Object format: {name: value}
                const entries = Object.entries(chars).slice(0, 3);
                charParts = entries.map(([name, value]) => {
                    if (name && value && typeof value === 'string') {
                        return `${name}: ${value}`;
                    }
                    return '';
                }).filter(Boolean);
            }
            
            if (charParts.length > 0) {
                summaryText = charParts.join(' • ');
            }
        }
        
        // If no characteristics, use description
        if (!summaryText && product.description) {
            summaryText = product.description.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
            if (summaryText.length > 120) {
                summaryText = summaryText.substring(0, 120) + '...';
            }
        }
        
        // Fallback: build from brand, category_path, color
        if (!summaryText) {
            const parts = [];
            if (product.brand) parts.push(product.brand);
            if (product.category_path) {
                // Show full category path (skip first generic part like "Уніформа, одяг і взуття")
                // "Уніформа, одяг і взуття/Футболки/Футболки АТАКА" → "Футболки / Футболки АТАКА"
                const cats = product.category_path.split('/').map(c => c.trim()).filter(Boolean);
                // Skip first generic category, join the rest
                const categoryParts = cats.length > 1 ? cats.slice(1) : cats;
                if (categoryParts.length > 0) {
                    parts.push(categoryParts.join(' / '));
                }
            }
            if (parts.length > 0) {
                summaryText = parts.join(' • ');
            }
        }
        
        const cardId = 'card-' + product.id + '-' + Date.now();
        
        // Build color variants HTML (if multiple colors available)
        let colorHtml = '';
        const colorVariants = (product.color_variants || []).filter(cv => cv.color && cv.color !== 'null' && cv.color.trim() !== '');
        const hasMultipleColors = colorVariants.length > 1;
        
        if (hasMultipleColors) {
            // Clickable color buttons
            const colorButtons = colorVariants.map(cv => {
                const isActive = cv.is_current;
                const activeStyle = isActive 
                    ? `background: ${settings.primary_color}; color: white; border-color: ${settings.primary_color};`
                    : 'background: #f3f4f6; color: #374151; border-color: #e5e7eb;';
                return `<button type="button" class="color-btn" 
                    data-color="${cv.color}" 
                    data-sizes='${JSON.stringify(cv.sizes || [])}'
                    style="padding: 2px 8px; font-size: 10px; border-radius: 4px; border: 1px solid; cursor: pointer; ${activeStyle}"
                >${cv.color}</button>`;
            }).join('');
            colorHtml = `<div class="color-variants" style="display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px; align-items: center;">
                <span style="font-size: 10px; color: #6b7280;">Колір:</span>${colorButtons}
            </div>`;
        } else if (product.color && product.color !== 'null' && !summaryText.includes(product.color)) {
            // Single color - just display
            colorHtml = `<div style="margin-top: 4px;"><span style="font-size: 10px; color: #6b7280;">Колір: </span><span style="font-size: 11px; font-weight: 500;">${product.color}</span></div>`;
        }
        
        // Build size variants HTML (clickable buttons that switch the card)
        // Use current color's sizes if available, otherwise use flat size_variants
        let currentColorSizes = [];
        if (hasMultipleColors) {
            const currentColorVariant = colorVariants.find(cv => cv.is_current);
            currentColorSizes = currentColorVariant?.sizes || [];
        }
        const sizesToShow = currentColorSizes.length > 0 ? currentColorSizes : (product.size_variants || []);
        
        let sizeHtml = '';
        // Filter out variants with null/empty size
        const validSizes = sizesToShow.filter(v => v.size && v.size !== 'null' && v.size !== '-');
        if (validSizes.length > 1) {
            const buttons = validSizes.map(v => {
                const isActive = v.id === product.id || v.article === product.article;
                const activeStyle = isActive 
                    ? `background: ${settings.primary_color}; color: white; border-color: ${settings.primary_color};`
                    : 'background: #f3f4f6; color: #374151; border-color: #e5e7eb;';
                // Store variant data as data attributes
                return `<button type="button" class="size-btn" 
                    data-size="${v.size}" 
                    data-link="${v.link || ''}" 
                    data-id="${v.id}"
                    data-article="${v.article || ''}"
                    style="padding: 2px 6px; font-size: 10px; border-radius: 4px; border: 1px solid; cursor: pointer; ${activeStyle}"
                >${v.size}</button>`;
            }).join('');
            sizeHtml = `<div class="size-variants" style="display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px;">${buttons}</div>`;
        } else if (product.size && product.size !== '-' && product.size !== 'null') {
            // Single size, just show label
            sizeHtml = `<div style="margin-top: 4px;"><span style="font-size: 10px; color: #6b7280;">Розмір: </span><span style="font-size: 11px; font-weight: 500;">${product.size}</span></div>`;
        }

        // Store all variants data on the card for switching
        card.dataset.variants = JSON.stringify(product.size_variants || []);
        card.dataset.colorVariants = JSON.stringify(product.color_variants || []);
        card.dataset.currentLink = product.link || '#';
        card.id = cardId;

        card.innerHTML = `
            <div style="display: flex; gap: 12px;">
                ${imgHtml}
                <div style="flex: 1; min-width: 0;">
                    <div style="font-weight: 600; font-size: 13px; margin-bottom: 4px; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${product.title}</div>
                    ${summaryText ? `<div style="font-size: 11px; color: #6b7280; margin-bottom: 4px; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${summaryText}</div>` : ''}
                    ${colorHtml}
                    <div class="size-container">${sizeHtml}</div>
                    <div style="color: ${settings.primary_color}; font-weight: 700; font-size: 16px; margin-top: 4px;">${product.price} ₴</div>
                </div>
            </div>
        `;

        // Add click handlers for color and size buttons
        setTimeout(() => {
            const primaryColor = settings.primary_color;
            
            // Color button handlers
            const colorButtons = card.querySelectorAll('.color-btn');
            colorButtons.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Update color button styles
                    colorButtons.forEach(b => {
                        b.style.background = '#f3f4f6';
                        b.style.color = '#374151';
                        b.style.borderColor = '#e5e7eb';
                    });
                    btn.style.background = primaryColor;
                    btn.style.color = 'white';
                    btn.style.borderColor = primaryColor;
                    
                    // Get sizes for this color
                    const sizes = JSON.parse(btn.dataset.sizes || '[]');
                    const sizeContainer = card.querySelector('.size-container');
                    
                    if (sizes.length > 0) {
                        // Update size buttons for this color
                        const sizeButtons = sizes.map((v, idx) => {
                            const isFirst = idx === 0;
                            const activeStyle = isFirst 
                                ? `background: ${primaryColor}; color: white; border-color: ${primaryColor};`
                                : 'background: #f3f4f6; color: #374151; border-color: #e5e7eb;';
                            return `<button type="button" class="size-btn" 
                                data-size="${v.size}" 
                                data-link="${v.link || ''}" 
                                data-id="${v.id}"
                                data-article="${v.article || ''}"
                                style="padding: 2px 6px; font-size: 10px; border-radius: 4px; border: 1px solid; cursor: pointer; ${activeStyle}"
                            >${v.size}</button>`;
                        }).join('');
                        sizeContainer.innerHTML = `<div class="size-variants" style="display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px;">${sizeButtons}</div>`;
                        
                        // Update card link to first size of selected color
                        if (sizes[0]?.link) {
                            card.href = sizes[0].link;
                            card.dataset.currentLink = sizes[0].link;
                        }
                        
                        // Re-attach size button handlers
                        attachSizeHandlers(card, primaryColor);
                    } else {
                        sizeContainer.innerHTML = '';
                    }
                });
            });
            
            // Size button handlers
            attachSizeHandlers(card, primaryColor);
        }, 0);
        
        function attachSizeHandlers(card, primaryColor) {
            const sizeButtons = card.querySelectorAll('.size-btn');
            sizeButtons.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const newLink = btn.dataset.link;
                    
                    // Update card link
                    card.href = newLink || '#';
                    card.dataset.currentLink = newLink;
                    
                    // Update button styles
                    sizeButtons.forEach(b => {
                        b.style.background = '#f3f4f6';
                        b.style.color = '#374151';
                        b.style.borderColor = '#e5e7eb';
                    });
                    btn.style.background = primaryColor;
                    btn.style.color = 'white';
                    btn.style.borderColor = primaryColor;
                });
            });
        }

        return card;
    }

    /**
     * Show order search form with phone (required), order number and name (optional)
     */
    function showOrderSearchForm(messagesContainer, settings, sessionId, token, sendMessageFn) {
        const s = settings || window.aintentoSettings || { primary_color: '#2563eb' };
        
        const wrapper = document.createElement('div');
        wrapper.className = 'aintento-order-form';
        wrapper.style.cssText = `
            margin-bottom: 16px;
            animation: aintento-fadeInUp 0.3s ease-out;
        `;
        
        const container = document.createElement('div');
        container.style.cssText = `
            background: white;
            border: 1.5px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        `;
        
        // Header
        const header = document.createElement('div');
        header.style.cssText = 'display: flex; align-items: center; gap: 8px; margin-bottom: 14px;';
        header.innerHTML = `
            <span style="font-size: 20px;">📦</span>
            <div>
                <div style="font-weight: 600; font-size: 14px; color: #1f2937;">Пошук замовлення</div>
                <div style="font-size: 11px; color: #6b7280;">Введіть дані для пошуку</div>
            </div>
        `;
        container.appendChild(header);
        
        // Form
        const form = document.createElement('form');
        form.style.cssText = 'display: flex; flex-direction: column; gap: 10px;';
        
        // Phone field (required)
        const phoneGroup = document.createElement('div');
        phoneGroup.innerHTML = `
            <label style="display: block; font-size: 12px; color: #374151; margin-bottom: 4px; font-weight: 500;">
                📱 Номер телефону <span style="color: #ef4444;">*</span>
            </label>
            <input type="tel" name="phone" placeholder="+380..." required style="
                width: 100%;
                padding: 10px 12px;
                border: 1.5px solid #d1d5db;
                border-radius: 8px;
                font-size: 14px;
                outline: none;
                transition: border-color 0.2s;
                box-sizing: border-box;
            ">
        `;
        form.appendChild(phoneGroup);
        
        // Order number field (optional)
        const orderGroup = document.createElement('div');
        orderGroup.innerHTML = `
            <label style="display: block; font-size: 12px; color: #374151; margin-bottom: 4px; font-weight: 500;">
                📝 Номер замовлення <span style="color: #9ca3af; font-weight: 400;">(опціонально)</span>
            </label>
            <input type="text" name="order_number" placeholder="12345" style="
                width: 100%;
                padding: 10px 12px;
                border: 1.5px solid #d1d5db;
                border-radius: 8px;
                font-size: 14px;
                outline: none;
                transition: border-color 0.2s;
                box-sizing: border-box;
            ">
        `;
        form.appendChild(orderGroup);
        
        // Name field (optional)
        const nameGroup = document.createElement('div');
        nameGroup.innerHTML = `
            <label style="display: block; font-size: 12px; color: #374151; margin-bottom: 4px; font-weight: 500;">
                👤 Прізвище та ім'я <span style="color: #9ca3af; font-weight: 400;">(опціонально)</span>
            </label>
            <input type="text" name="customer_name" placeholder="Шевченко Тарас" style="
                width: 100%;
                padding: 10px 12px;
                border: 1.5px solid #d1d5db;
                border-radius: 8px;
                font-size: 14px;
                outline: none;
                transition: border-color 0.2s;
                box-sizing: border-box;
            ">
        `;
        form.appendChild(nameGroup);
        
        // Submit button
        const submitBtn = document.createElement('button');
        submitBtn.type = 'submit';
        submitBtn.textContent = '🔍 Знайти замовлення';
        submitBtn.style.cssText = `
            width: 100%;
            padding: 12px;
            background: ${s.primary_color};
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 4px;
        `;
        submitBtn.onmouseenter = () => {
            submitBtn.style.opacity = '0.9';
            submitBtn.style.transform = 'translateY(-1px)';
        };
        submitBtn.onmouseleave = () => {
            submitBtn.style.opacity = '1';
            submitBtn.style.transform = 'translateY(0)';
        };
        form.appendChild(submitBtn);
        
        // Error message container
        const errorMsg = document.createElement('div');
        errorMsg.style.cssText = 'display: none; color: #ef4444; font-size: 12px; margin-top: 4px;';
        form.appendChild(errorMsg);
        
        // Form submit handler
        form.onsubmit = async (e) => {
            e.preventDefault();
            
            const formData = new FormData(form);
            const phone = formData.get('phone')?.trim();
            const orderNumber = formData.get('order_number')?.trim();
            const customerName = formData.get('customer_name')?.trim();
            
            if (!phone) {
                errorMsg.textContent = 'Введіть номер телефону';
                errorMsg.style.display = 'block';
                return;
            }
            
            // Hide error
            errorMsg.style.display = 'none';
            
            // Disable button and show loading
            submitBtn.disabled = true;
            submitBtn.textContent = '⏳ Шукаю...';
            
            // Build search query message
            let searchQuery = `Знайди замовлення: телефон ${phone}`;
            if (orderNumber) {
                searchQuery += `, номер ${orderNumber}`;
            }
            if (customerName) {
                searchQuery += `, клієнт ${customerName}`;
            }
            
            // Track form submission
            sendAnalyticsEvent('order_search_form_submit', {
                has_order_number: !!orderNumber,
                has_customer_name: !!customerName
            });
            
            // Remove the form
            wrapper.remove();
            
            // Send as message to backend
            sendMessageFn(searchQuery);
        };
        
        // Focus styling for inputs
        const inputs = form.querySelectorAll('input');
        inputs.forEach(input => {
            input.onfocus = () => {
                input.style.borderColor = s.primary_color;
                input.style.boxShadow = `0 0 0 3px ${s.primary_color}20`;
            };
            input.onblur = () => {
                input.style.borderColor = '#d1d5db';
                input.style.boxShadow = 'none';
            };
        });
        
        container.appendChild(form);
        wrapper.appendChild(container);
        messagesContainer.appendChild(wrapper);
        
        // Scroll to form
        setTimeout(() => {
            wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Focus first input
            form.querySelector('input[name="phone"]')?.focus();
        }, 100);
    }

    function addCrossSell(messagesContainer, crossSell, settings, sessionId) {
        if (!crossSell || !crossSell.suggestions?.length) return;
        
        // Check if cross-sell already exists in DOM - prevent duplicates
        const existingCrossSell = messagesContainer.querySelector('.aintento-cross-sell');
        if (existingCrossSell) {
            log('Cross-sell already exists, skipping duplicate');
            return;
        }
        
        const s = settings || window.aintentoSettings || { primary_color: '#2563eb' };
        
        // Save cross-sell to localStorage for persistence
        saveCrossSell(sessionId, crossSell);
        
        // Track cross-sell shown
        sendAnalyticsEvent('cross_sell_shown', {
            products_count: crossSell.suggestions.length,
            product_ids: crossSell.suggestions.map(p => p.id).join(',')
        });
        
        const wrapper = document.createElement('div');
        wrapper.className = 'aintento-cross-sell';
        wrapper.style.cssText = `
            margin-bottom: 16px;
            animation: aintento-fadeInUp 0.3s ease-out;
        `;
        
        const container = document.createElement('div');
        container.style.cssText = `
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 10px;
        `;
        
        // Header
        const header = document.createElement('div');
        header.style.cssText = 'display: flex; align-items: center; gap: 6px; margin-bottom: 8px;';
        header.innerHTML = `
            <span style="font-size: 14px;">🎯</span>
            <div>
                <div style="font-weight: 600; font-size: 12px; color: #1f2937;">${crossSell.title || 'Разом краще'}</div>
                <div style="font-size: 10px; color: #92400e;">${crossSell.subtitle || 'Часто беруть разом'}</div>
            </div>
        `;
        container.appendChild(header);
        
        // Carousel wrapper with navigation buttons
        const carouselWrapper = document.createElement('div');
        carouselWrapper.style.cssText = 'position: relative;';
        
        // Items CAROUSEL (horizontal scroll)
        const carousel = document.createElement('div');
        carousel.style.cssText = `
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 6px;
            margin: 0 -4px;
            padding: 4px;
            scroll-snap-type: x mandatory;
            scroll-behavior: smooth;
            scrollbar-width: none;
            -ms-overflow-style: none;
        `;
        // Hide scrollbar for webkit browsers
        carousel.style.setProperty('scrollbar-width', 'none');
        
        // Navigation buttons (PC only)
        const createNavButton = (direction) => {
            const btn = document.createElement('button');
            btn.style.cssText = `
                position: absolute;
                top: 50%;
                ${direction === 'left' ? 'left: -8px' : 'right: -8px'};
                transform: translateY(-50%);
                width: 28px;
                height: 28px;
                background: white;
                border: 1px solid #e5e7eb;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                z-index: 10;
                box-shadow: 0 2px 6px rgba(0,0,0,0.15);
                transition: all 0.2s;
                font-size: 14px;
                color: #374151;
            `;
            btn.innerHTML = direction === 'left' ? '‹' : '›';
            btn.onmouseenter = () => { 
                btn.style.background = s.primary_color; 
                btn.style.color = 'white';
                btn.style.borderColor = s.primary_color;
            };
            btn.onmouseleave = () => { 
                btn.style.background = 'white'; 
                btn.style.color = '#374151';
                btn.style.borderColor = '#e5e7eb';
            };
            btn.onclick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                const scrollAmount = 120; // card width + gap
                carousel.scrollBy({
                    left: direction === 'left' ? -scrollAmount : scrollAmount,
                    behavior: 'smooth'
                });
            };
            return btn;
        };
        
        const leftBtn = createNavButton('left');
        const rightBtn = createNavButton('right');
        
        // Update button visibility based on scroll position
        const updateNavButtons = () => {
            const maxScroll = carousel.scrollWidth - carousel.clientWidth;
            leftBtn.style.display = carousel.scrollLeft > 10 ? 'flex' : 'none';
            rightBtn.style.display = carousel.scrollLeft < maxScroll - 10 ? 'flex' : 'none';
        };
        
        carousel.addEventListener('scroll', updateNavButtons);
        
        // Initial visibility (after items are added)
        setTimeout(updateNavButtons, 100);
        
        crossSell.suggestions.forEach((item) => {
            const card = document.createElement('div');
            card.style.cssText = `
                flex-shrink: 0;
                width: 110px;
                background: white;
                border-radius: 8px;
                padding: 8px;
                border: 1px solid #e5e7eb;
                transition: all 0.2s;
                position: relative;
                scroll-snap-align: start;
                display: flex;
                flex-direction: column;
            `;
            
            // Info icon with tooltip (reason why AI suggested this)
            const infoIcon = document.createElement('div');
            infoIcon.style.cssText = `
                position: absolute;
                top: 6px;
                right: 6px;
                width: 18px;
                height: 18px;
                background: linear-gradient(135deg, ${s.primary_color}, #60a5fa);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 11px;
                color: white;
                cursor: pointer;
                font-weight: 700;
                z-index: 2;
                box-shadow: 0 2px 6px rgba(37, 99, 235, 0.4);
                transition: all 0.2s;
                animation: aintento-info-pulse 2s infinite;
            `;
            infoIcon.textContent = 'i';
            infoIcon.title = item.reason || 'Рекомендований товар';
            
            // Add pulse animation for info icon if not exists
            if (!document.querySelector('#aintento-info-pulse-style')) {
                const style = document.createElement('style');
                style.id = 'aintento-info-pulse-style';
                style.textContent = '@keyframes aintento-info-pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }';
                document.head.appendChild(style);
            }
            
            infoIcon.onmouseenter = () => { 
                infoIcon.style.transform = 'scale(1.2)';
                infoIcon.style.animation = 'none';
            };
            infoIcon.onmouseleave = () => { 
                infoIcon.style.transform = 'scale(1)';
                infoIcon.style.animation = 'aintento-info-pulse 2s infinite';
            };
            
            card.appendChild(infoIcon);
            
            // Image (clickable)
            const imgLink = document.createElement('a');
            imgLink.href = item.link || '#';
            imgLink.target = '_blank';
            imgLink.style.cssText = 'display: block; margin-bottom: 6px;';
            imgLink.innerHTML = item.image 
                ? `<img src="${item.image}" style="width: 100%; height: 55px; object-fit: cover; border-radius: 6px;" onerror="this.style.display='none'; this.parentElement.innerHTML='<div style=\\'width:100%;height:55px;background:#f1f5f9;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:20px\\'>📦</div>'">`
                : '<div style="width:100%;height:55px;background:#f1f5f9;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:20px">📦</div>';
            card.appendChild(imgLink);
            
            // Title (clickable)
            const titleLink = document.createElement('a');
            titleLink.href = item.link || '#';
            titleLink.target = '_blank';
            titleLink.style.cssText = `
                display: block;
                font-size: 10px;
                font-weight: 600;
                color: #1f2937;
                text-decoration: none;
                line-height: 1.2;
                height: 24px;
                overflow: hidden;
                margin-bottom: 3px;
            `;
            titleLink.textContent = item.title;
            titleLink.onmouseenter = () => { titleLink.style.color = s.primary_color; };
            titleLink.onmouseleave = () => { titleLink.style.color = '#1f2937'; };
            card.appendChild(titleLink);
            
            // Price
            const price = document.createElement('div');
            price.style.cssText = `font-size: 12px; font-weight: 700; color: ${s.primary_color}; margin-bottom: 6px;`;
            price.textContent = `${item.price} ₴`;
            card.appendChild(price);
            
            // Spacer to push button to bottom
            const spacer = document.createElement('div');
            spacer.style.cssText = 'flex: 1;';
            card.appendChild(spacer);
            
            // "+ Цікаво" button
            const interestedBtn = document.createElement('button');
            interestedBtn.style.cssText = `
                width: 100%;
                background: ${s.primary_color};
                color: white;
                padding: 5px 6px;
                border: none;
                border-radius: 5px;
                font-size: 10px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s;
            `;
            interestedBtn.textContent = '+ Цікаво';
            interestedBtn.onmouseenter = () => { interestedBtn.style.opacity = '0.85'; };
            interestedBtn.onmouseleave = () => { interestedBtn.style.opacity = '1'; };
            interestedBtn.onclick = (e) => {
                e.stopPropagation();
                
                // Track cross-sell click
                sendAnalyticsEvent('cross_sell_click', {
                    product_id: item.id,
                    product_article: item.article,
                    product_price: item.price
                });
                
                // Animate card removal
                card.style.opacity = '0';
                card.style.transform = 'scale(0.9)';
                card.style.transition = 'all 0.3s ease';
                
                setTimeout(() => {
                    card.remove();
                    
                    // Update nav buttons after card removal
                    updateNavButtons();
                    
                    // Add product to chat as clickable card
                    addCrossSellProductToChat(messagesContainer, item, s, sessionId);
                    
                    // If no more items, remove the whole block
                    if (carousel.children.length === 0) {
                        wrapper.style.opacity = '0';
                        wrapper.style.transform = 'translateY(-10px)';
                        wrapper.style.transition = 'all 0.3s ease';
                        setTimeout(() => wrapper.remove(), 300);
                    }
                }, 300);
            };
            card.appendChild(interestedBtn);
            
            // Hover effect
            card.onmouseenter = () => {
                card.style.borderColor = s.primary_color;
                card.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            };
            card.onmouseleave = () => {
                card.style.borderColor = '#e5e7eb';
                card.style.boxShadow = 'none';
            };
            
            carousel.appendChild(card);
        });
        
        // Add carousel and nav buttons to wrapper
        carouselWrapper.appendChild(carousel);
        carouselWrapper.appendChild(leftBtn);
        carouselWrapper.appendChild(rightBtn);
        container.appendChild(carouselWrapper);
        
        // Hint about adding to cart
        const hint = document.createElement('div');
        hint.style.cssText = 'margin-top: 8px; font-size: 9px; color: #92400e; text-align: center;';
        hint.innerHTML = `💡 ${crossSell.hint || 'Щоб замовити — додайте товар у кошик на сайті'}`;
        container.appendChild(hint);
        
        // Dismiss button
        const dismissBtn = document.createElement('button');
        dismissBtn.style.cssText = `
            display: block;
            width: 100%;
            margin-top: 6px;
            padding: 4px;
            background: transparent;
            border: none;
            color: #9ca3af;
            font-size: 10px;
            cursor: pointer;
            transition: color 0.2s;
        `;
        dismissBtn.textContent = 'Приховати';
        dismissBtn.onmouseenter = () => { dismissBtn.style.color = '#6b7280'; };
        dismissBtn.onmouseleave = () => { dismissBtn.style.color = '#9ca3af'; };
        dismissBtn.onclick = () => {
            wrapper.style.opacity = '0';
            wrapper.style.transform = 'translateY(-10px)';
            wrapper.style.transition = 'all 0.3s ease';
            setTimeout(() => wrapper.remove(), 300);
        };
        container.appendChild(dismissBtn);
        
        wrapper.appendChild(container);
        messagesContainer.appendChild(wrapper);
    }
    
    // Add cross-sell product as clickable card in chat
    function addCrossSellProductToChat(messagesContainer, item, settings, sessionId) {
        const s = settings || window.aintentoSettings || { primary_color: '#2563eb' };
        
        // Add intro message (no markdown, plain text)
        addMessage(messagesContainer, `Ви зацікавились: ${item.title}`, 'assistant', sessionId, false);
        
        // Create clickable product card
        const card = document.createElement('a');
        card.href = item.link || '#';
        card.target = '_blank';
        card.style.cssText = `
            display: block;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
            text-decoration: none;
            color: #374151;
            transition: all 0.2s;
            animation: aintento-fadeInUp 0.3s ease-out;
        `;
        
        card.onmouseenter = () => {
            card.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            card.style.transform = 'translateY(-2px)';
            card.style.borderColor = s.primary_color;
        };
        card.onmouseleave = () => {
            card.style.boxShadow = 'none';
            card.style.transform = 'translateY(0)';
            card.style.borderColor = '#e5e7eb';
        };
        
        const imgHtml = item.image 
            ? `<img src="${item.image}" style="width: 70px; height: 70px; object-fit: cover; border-radius: 8px; flex-shrink: 0;" onerror="this.style.display='none'">`
            : '';
        
        const summaryHtml = item.summary 
            ? `<div style="font-size: 11px; color: #6b7280; margin-bottom: 4px; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">${item.summary}</div>`
            : '';
        
        // Color display
        const colorHtml = item.color
            ? `<span style="font-size: 11px; color: #6b7280;">🎨 ${item.color}</span>`
            : '';
        
        // Size display
        const sizeHtml = item.size
            ? `<span style="font-size: 11px; color: #6b7280; ${item.color ? 'margin-left: 8px;' : ''}">📐 ${item.size}</span>`
            : '';
        
        const metaHtml = (colorHtml || sizeHtml) 
            ? `<div style="margin-bottom: 4px;">${colorHtml}${sizeHtml}</div>`
            : '';
        
        card.innerHTML = `
            <div style="display: flex; gap: 12px;">
                ${imgHtml}
                <div style="flex: 1; min-width: 0;">
                    <div style="font-weight: 600; font-size: 13px; margin-bottom: 4px; line-height: 1.3;">${item.title}</div>
                    ${summaryHtml}
                    ${metaHtml}
                    <div style="color: ${s.primary_color}; font-weight: 700; font-size: 16px;">${item.price} ₴</div>
                </div>
            </div>
            <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #f3f4f6; font-size: 11px; color: #6b7280; text-align: center;">
                👆 Натисніть щоб відкрити на сайті та додати в кошик
            </div>
        `;
        
        messagesContainer.appendChild(card);
        // Scroll to the product card
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        
        // Save to history
        saveMessage(sessionId, { role: 'cross_sell_interest', product: item });
    }

    function addLoader(messagesContainer) {
        const s = window.aintentoSettings || { primary_color: '#2563eb' };
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
        messagesContainer.appendChild(div);
        div.scrollIntoView({ behavior: 'smooth', block: 'end' });
        return div;
    }

    function removeLoader(loader) {
        loader?.parentNode?.removeChild(loader);
    }

    /**
     * Fetch dynamic greeting based on visitor context
     * Falls back to settings.welcome_message if API fails
     */
    function fetchDynamicGreeting(settings, messagesEl, state) {
        // Collect context for greeting matching
        const urlParams = new URLSearchParams(window.location.search);
        const context = {
            utm_campaign: urlParams.get('utm_campaign') || '',
            utm_source: urlParams.get('utm_source') || '',
            utm_medium: urlParams.get('utm_medium') || '',
            url: window.location.href,
            category: extractCategoryFromUrl(),
            device: window.innerWidth <= 768 ? 'mobile' : 'desktop',
            is_returning: hasVisitedBefore(),
            language: navigator.language || navigator.userLanguage || ''
        };
        
        // Build query string
        const params = new URLSearchParams();
        Object.keys(context).forEach(key => {
            if (context[key]) params.append(key, context[key]);
        });
        
        // Add tenant_id for multi-tenant isolation
        if (window.aintentoTenantId) {
            params.append('tenant_id', window.aintentoTenantId);
        }
        
        const greetingUrl = BASE_URL + '/api/widget/greeting?' + params.toString();
        
        log('Fetching dynamic greeting:', greetingUrl);
        
        fetch(greetingUrl, {
            headers: { 'Content-Type': 'application/json' }
        })
        .then(res => res.json())
        .then(data => {
            log('Greeting received:', data);
            
            const greetingMessage = data.message || settings.welcome_message;
            addMessage(messagesEl, greetingMessage, 'assistant', state.sessionId, true);
            state.hasShownWelcome = true;
            
            // If greeting has quick actions, update them
            if (data.quick_actions && data.quick_actions.length > 0) {
                updateQuickActionsFromGreeting(data.quick_actions);
            }
            
            // Track which greeting was shown
            if (data.matched_greeting_id) {
                sendAnalyticsEvent('greeting_shown', {
                    greeting_id: data.matched_greeting_id,
                    greeting_name: data.matched_greeting_name,
                    context: context
                });
            }
        })
        .catch(err => {
            logError('Failed to fetch greeting, using default:', err);
            addMessage(messagesEl, settings.welcome_message, 'assistant', state.sessionId, true);
            state.hasShownWelcome = true;
        });
    }
    
    /**
     * Try to extract category path from URL
     * e.g., /plate-carriers/crye-precision -> plate-carriers
     */
    function extractCategoryFromUrl() {
        const path = window.location.pathname;
        // Common patterns: /category/subcategory or /product/category
        const parts = path.split('/').filter(p => p && !['product', 'products', 'p', 'item'].includes(p.toLowerCase()));
        return parts[0] || '';
    }
    
    /**
     * Check if user has visited before (returning visitor)
     */
    function hasVisitedBefore() {
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
    
    /**
     * Update quick actions bar from greeting-specific actions
     */
    function updateQuickActionsFromGreeting(actions) {
        const bar = document.getElementById('aintento-quick-actions-bar');
        if (!bar || !actions || actions.length === 0) return;
        
        // Create greeting-specific quick action buttons
        const actionsHTML = actions.map(action => `
            <button class="aintento-greeting-action" style="
                flex-shrink: 0;
                background: white;
                border: 1px solid #e5e7eb;
                border-radius: 20px;
                padding: 8px 16px;
                font-size: 14px;
                cursor: pointer;
                white-space: nowrap;
                transition: all 0.2s ease;
                margin-right: 8px;
            " data-query="${action.query}">${action.label}</button>
        `).join('');
        
        // Prepend greeting actions to the bar
        const greetingActionsContainer = document.createElement('div');
        greetingActionsContainer.id = 'aintento-greeting-actions';
        greetingActionsContainer.style.cssText = 'display: inline-flex; margin-right: 12px; padding-right: 12px; border-right: 1px solid #e5e7eb;';
        greetingActionsContainer.innerHTML = actionsHTML;
        
        // Remove existing greeting actions if any
        const existingGreetingActions = document.getElementById('aintento-greeting-actions');
        if (existingGreetingActions) existingGreetingActions.remove();
        
        bar.insertBefore(greetingActionsContainer, bar.firstChild);
        
        // Add click handlers
        greetingActionsContainer.querySelectorAll('.aintento-greeting-action').forEach(btn => {
            btn.addEventListener('click', function() {
                const query = this.dataset.query;
                if (query) {
                    // Simulate sending this query
                    const input = document.getElementById('aintento-input');
                    if (input) {
                        input.value = query;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        document.getElementById('aintento-send')?.click();
                    }
                }
            });
        });
        
        bar.style.display = 'block';
    }

    // Session management - support both old and new keys for backward compatibility
    function getOrCreateSessionId() {
        let sessionId = localStorage.getItem('aintento_session_id') || localStorage.getItem('ailure_session_id');
        if (!sessionId) {
            sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        }
        localStorage.setItem('aintento_session_id', sessionId);
        return sessionId;
    }

    function saveSessionId(sessionId) {
        localStorage.setItem('aintento_session_id', sessionId);
    }

    function saveMessage(sessionId, message) {
        const key = `aintento_messages_${sessionId}`;
        const messages = JSON.parse(localStorage.getItem(key) || '[]');
        messages.push(message);
        if (messages.length > 50) {
            messages.shift();
        }
        localStorage.setItem(key, JSON.stringify(messages));
    }

    function loadMessages(sessionId) {
        // Check both new and old keys
        const newKey = `aintento_messages_${sessionId}`;
        const oldKey = `ailure_messages_${sessionId}`;
        const messages = localStorage.getItem(newKey) || localStorage.getItem(oldKey);
        return JSON.parse(messages || '[]');
    }

    // Cross-sell persistence
    function saveCrossSell(sessionId, crossSell) {
        if (!crossSell) return;
        const key = `aintento_cross_sell_${sessionId}`;
        localStorage.setItem(key, JSON.stringify(crossSell));
    }

    function loadCrossSell(sessionId) {
        const key = `aintento_cross_sell_${sessionId}`;
        const data = localStorage.getItem(key);
        return data ? JSON.parse(data) : null;
    }

    // === Analytics & Attribution ===
    
    /**
     * Add UTM parameters to product link for attribution tracking
     */
    function addUtmToLink(link, sessionId, productId) {
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

    /**
     * Track product click for attribution
     */
    function trackProductClick(product) {
        // Store clicked product in localStorage for attribution
        const key = 'aintento_clicked_products';
        let clicked = [];
        try {
            clicked = JSON.parse(localStorage.getItem(key) || '[]');
        } catch (e) {}

        clicked.push({
            id: product.id,
            article: product.article,
            price: product.price,
            session_id: localStorage.getItem('aintento_session_id'),
            timestamp: Date.now()
        });

        // Keep only last 72 hours, max 50 items
        const cutoff = Date.now() - (72 * 60 * 60 * 1000);
        clicked = clicked.filter(p => p.timestamp > cutoff).slice(-50);
        localStorage.setItem(key, JSON.stringify(clicked));

        // Send event to server
        sendAnalyticsEvent('product_click', {
            product_id: product.id,
            product_article: product.article,
            product_price: product.price
        });
    }

    /**
     * Track products shown in chat
     * Only tracks each product ONCE per session to avoid duplicate counting
     */
    function trackProductsShown(products) {
        const sessionId = localStorage.getItem('aintento_session_id');
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
            
            // Only track analytics if not already tracked in this session
            if (productKey && !alreadyTracked.has(productKey)) {
                alreadyTracked.add(productKey);
                newProducts.push(product);
            }
        });
        
        localStorage.setItem(shownKey, JSON.stringify(existingShown));
        localStorage.setItem(trackedKey, JSON.stringify([...alreadyTracked]));
        
        // Only send analytics for NEW products (not seen before in this session)
        if (newProducts.length > 0) {
            log('Tracking product_shown for', newProducts.length, 'new products (skipped', products.length - newProducts.length, 'duplicates)');
            newProducts.forEach(product => {
                sendAnalyticsEvent('product_shown', {
                    product_id: product.id,
                    product_article: product.article,
                    product_price: product.price
                });
            });
        } else {
            log('Skipping product_shown tracking - all', products.length, 'products already tracked');
        }
    }

    /**
     * Send analytics event to server
     */
    const analyticsQueue = [];
    let analyticsFlushTimeout = null;

    function sendAnalyticsEvent(eventType, data = {}) {
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
        if (['add_to_cart', 'purchase'].includes(eventType)) {
            flushAnalytics();
        }
    }

    function flushAnalytics() {
        if (analyticsQueue.length === 0) return;

        const events = [...analyticsQueue];
        analyticsQueue.length = 0;

        const payload = JSON.stringify({ events });
        log('Flushing analytics:', events.length, 'events', events.map(e => e.event_type));
        log('Payload size:', payload.length, 'bytes');
        log('Payload preview:', payload.substring(0, 200));

        // Use fetch with keepalive for reliable delivery
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
            log('Analytics fetch error:', err.message);
        });
    }

    function getOrCreateClientId() {
        const cookieName = 'aintento_client_id';
        let clientId = document.cookie.match(new RegExp('(^| )' + cookieName + '=([^;]+)'))?.[2];
        if (!clientId) {
            clientId = 'cli_' + Date.now() + '_' + Math.random().toString(36).substr(2, 12);
            const expires = new Date(Date.now() + 72 * 60 * 60 * 1000).toUTCString();
            document.cookie = `${cookieName}=${clientId}; expires=${expires}; path=/; SameSite=Lax`;
        }
        return clientId;
    }

    function getMerchantId() {
        // Use merchant_id from settings (tenant slug) for proper tenant isolation
        if (window.aintentoSettings?.merchant_id) {
            return window.aintentoSettings.merchant_id;
        }
        // Fallback to hostname if no settings loaded yet
        return window.location.hostname;
    }

    function detectDeviceType() {
        const ua = navigator.userAgent;
        if (/tablet|ipad|playbook|silk/i.test(ua)) return 'tablet';
        if (/mobile|iphone|ipod|android|blackberry|opera mini|iemobile/i.test(ua)) return 'mobile';
        return 'desktop';
    }

    // Flush analytics on page unload
    window.addEventListener('pagehide', flushAnalytics);
    window.addEventListener('beforeunload', flushAnalytics);

    // ============================================
    // PROACTIVE TRIGGERS SYSTEM
    // ============================================
    
    const ProactiveTriggers = {
        rules: [],
        state: {
            triggersShown: 0,
            lastTriggerTime: 0,
            sessionTriggersShown: [],
            pageStartTime: Date.now(),
            lastActivity: Date.now(),
            productsViewed: [],
            variantSelected: false,
            chatOpened: false,
            initialized: false,
            showingTrigger: false // Mutex to prevent simultaneous triggers
        },
        
        // Initialize triggers system
        init: function() {
            if (this.state.initialized) return;
            this.state.initialized = true;
            
            log('ProactiveTriggers: Initializing...');
            
            // Load state from localStorage
            this.loadState();
            
            // Fetch rules from server
            this.fetchRules();
            
            // Setup detectors
            this.setupExitIntentDetector();
            this.setupTimeOnPageDetector();
            this.setupActivityTracker();
            this.setupVariantSelectionDetector();
            this.setupReturningVisitorDetector();
            
            // Check UTM triggers on page load (wait for rules to load)
            setTimeout(() => this.checkUtmTriggers(), 2000);
            
            // Check returning visitor trigger
            setTimeout(() => this.checkReturningVisitorTrigger(), 3000);
            
            log('ProactiveTriggers: Initialized');
        },
        
        // Load state from localStorage
        loadState: function() {
            try {
                const saved = localStorage.getItem('aintento_triggers_state');
                if (saved) {
                    const parsed = JSON.parse(saved);
                    // Check if same day
                    if (parsed.date === new Date().toDateString()) {
                        this.state.triggersShown = parsed.triggersShown || 0;
                        this.state.sessionTriggersShown = parsed.sessionTriggersShown || [];
                    }
                }
            } catch (e) {
                log('ProactiveTriggers: Failed to load state', e);
            }
        },
        
        // Save state to localStorage
        saveState: function() {
            try {
                localStorage.setItem('aintento_triggers_state', JSON.stringify({
                    date: new Date().toDateString(),
                    triggersShown: this.state.triggersShown,
                    sessionTriggersShown: this.state.sessionTriggersShown,
                    lastTriggerTime: this.state.lastTriggerTime
                }));
            } catch (e) {
                log('ProactiveTriggers: Failed to save state', e);
            }
        },
        
        // Fetch rules from server
        fetchRules: function() {
            let url = BASE_URL + '/api/triggers/rules';
            if (window.aintentoTenantId) {
                url += '?tenant_id=' + encodeURIComponent(window.aintentoTenantId);
            }
            fetch(url)
                .then(res => res.json())
                .then(data => {
                    this.rules = data.rules || [];
                    log('ProactiveTriggers: Loaded', this.rules.length, 'rules');
                })
                .catch(err => {
                    log('ProactiveTriggers: Failed to fetch rules', err);
                });
        },
        
        // Check if can show trigger
        canShowTrigger: function(triggerType) {
            // Don't show if chat is open
            if (this.state.chatOpened || window.aintentoIsOpen) {
                return false;
            }
            
            // Prevent race condition - check if trigger is currently being shown
            if (this.state.showingTrigger) {
                return false;
            }
            
            // Check if this specific type was already shown
            if (this.state.sessionTriggersShown.includes(triggerType)) {
                return false;
            }
            
            // EXIT INTENT is highest priority - can show even if other triggers were shown
            // This is the last chance to engage user before they leave
            if (triggerType === 'exit_intent') {
                return true; // Only blocked if exit_intent itself was already shown (checked above)
            }
            
            // For other triggers: session limit (1 per session)
            // But don't count exit_intent towards this limit
            const nonExitTriggersShown = this.state.sessionTriggersShown.filter(t => t !== 'exit_intent');
            if (nonExitTriggersShown.length >= 1) {
                return false;
            }
            
            // Cooldown (5 minutes between non-exit triggers)
            const cooldown = 5 * 60 * 1000;
            if (Date.now() - this.state.lastTriggerTime < cooldown) {
                return false;
            }
            
            return true;
        },
        
        // Find matching rule
        findMatchingRule: function(triggerType, context = {}) {
            return this.rules.find(rule => {
                if (rule.type !== triggerType || !rule.conditions) return false;
                
                // For UTM rules, check UTM match
                if (triggerType === 'utm_campaign') {
                    const utm = this.getUtmParams();
                    const conditions = rule.conditions;
                    
                    if (conditions.utm_source && !this.matchUtmParam(utm.utm_source, conditions.utm_source)) {
                        return false;
                    }
                    if (conditions.utm_medium && !this.matchUtmParam(utm.utm_medium, conditions.utm_medium)) {
                        return false;
                    }
                    if (conditions.utm_campaign && !this.matchUtmParam(utm.utm_campaign, conditions.utm_campaign)) {
                        return false;
                    }
                    return true;
                }
                
                // For page-based rules, check page type
                // Support both page_type (string) and page_types (array)
                const pageTypeCondition = rule.conditions.page_types || 
                    (rule.conditions.page_type ? [rule.conditions.page_type] : null);
                
                if (pageTypeCondition) {
                    const pageType = context.pageType || this.detectPageType();
                    if (!pageTypeCondition.includes(pageType)) {
                        return false;
                    }
                }
                
                return true;
            });
        },
        
        // Match UTM parameter (case insensitive, partial match)
        // Empty pattern means "any value" - matches if param exists OR no requirement
        matchUtmParam: function(value, pattern) {
            if (!pattern || pattern.trim() === '') {
                // Empty pattern means no requirement for this param
                return true;
            }
            if (!value) return false;
            return value.toLowerCase().includes(pattern.toLowerCase());
        },
        
        // Get UTM parameters from URL
        getUtmParams: function() {
            const params = new URLSearchParams(window.location.search);
            return {
                utm_source: params.get('utm_source') || '',
                utm_medium: params.get('utm_medium') || '',
                utm_campaign: params.get('utm_campaign') || '',
                utm_content: params.get('utm_content') || '',
                utm_term: params.get('utm_term') || ''
            };
        },
        
        // Detect current page type
        detectPageType: function() {
            const url = window.location.pathname.toLowerCase();
            const body = document.body;
            
            // Check for product page indicators - must have specific product selectors
            const hasProductSelectors = 
                document.querySelector('[itemtype*="Product"]') ||
                document.querySelector('.product-page') ||
                document.querySelector('.product-detail') ||
                document.querySelector('[data-product-id]') ||
                // Horoshop specific
                document.querySelector('.hs-product-page') ||
                document.querySelector('.j-product-container') ||
                document.querySelector('.hs-product-main') ||
                document.querySelector('.product-main') ||
                // Product page typically has add-to-cart button
                document.querySelector('[data-add-to-cart], .add-to-cart, .buy-button, .hs-buy-btn');
            
            // Product page URL patterns - Horoshop uses /slug-123 or /category/slug-123
            const isProductUrl = 
                url.includes('/product/') ||
                url.includes('/tovar/') ||
                url.includes('/p/') ||
                // Horoshop pattern: /slug-123 or /category/slug-123 where 123 is product ID
                /\/[a-z0-9-]+-\d+\/?$/i.test(url) ||
                // Also match /{slug}/{product-name}-{id}
                /\/[a-z0-9-]+\/[a-z0-9-]+-\d+\/?$/i.test(url);
            
            // If we have strong product indicators (selectors), trust them even without perfect URL match
            if (hasProductSelectors && (isProductUrl || document.querySelector('.hs-product-page, .j-product-container'))) {
                return 'product';
            }
            
            // Check for category page - multiple products displayed
            const hasCategorySelectors = 
                document.querySelector('.category-page') ||
                document.querySelector('.product-list') ||
                document.querySelector('.products-grid') ||
                document.querySelector('.catalog-products') ||
                document.querySelector('[itemtype*="ItemList"]') ||
                // Horoshop specific
                document.querySelector('.hs-catalog') ||
                document.querySelector('.j-catalog-container') ||
                // Multiple product cards
                document.querySelectorAll('.product-card, .product-item, [data-product-id]').length >= 3;
            
            const isCategoryUrl =
                url.includes('/category/') ||
                url.includes('/catalog/') ||
                url.includes('/c/') ||
                // Generic category URL: /category-name/ or /category-name
                /^\/[a-z0-9-]+\/?$/.test(url);
            
            if (hasCategorySelectors || isCategoryUrl) {
                return 'category';
            }
            
            // Check for cart page
            if (
                url.includes('/cart') ||
                url.includes('/basket') ||
                url.includes('/korzina')
            ) {
                return 'cart';
            }
            
            // Check for checkout
            if (
                url.includes('/checkout') ||
                url.includes('/order') ||
                url.includes('/oformlenie')
            ) {
                return 'checkout';
            }
            
            // If we have an h1 with common category keywords, treat as category
            const h1 = document.querySelector('h1');
            if (h1) {
                const h1Text = h1.textContent.trim().toLowerCase();
                const categoryKeywords = ['тактич', 'військов', 'спорядження', 'одяг', 'взуття', 'рюкзак', 
                    'підсумк', 'плитоносц', 'бронежилет', 'шолом', 'камуфляж', 'форма', 'куртк', 
                    'штан', 'футболк', 'термобіл', 'аксесуар', 'захист'];
                if (categoryKeywords.some(kw => h1Text.includes(kw))) {
                    return 'category';
                }
            }
            
            return 'other';
        },
        
        // Helper: truncate string to max length
        truncate: function(str, maxLen) {
            if (!str) return '';
            str = str.trim();
            if (str.length <= maxLen) return str;
            return str.substring(0, maxLen) + '...';
        },
        
        // Detect current category name from page
        detectCurrentCategory: function() {
            // Try breadcrumbs first
            const breadcrumbs = document.querySelector('.breadcrumbs, .breadcrumb, [itemtype*="BreadcrumbList"]');
            if (breadcrumbs) {
                const items = breadcrumbs.querySelectorAll('a, span[itemprop="name"]');
                if (items.length > 1) {
                    // Last item is usually current, but if it's a link, use second-to-last
                    const lastLink = breadcrumbs.querySelector('a:last-of-type');
                    if (lastLink && lastLink.textContent.trim()) {
                        return lastLink.textContent.trim();
                    }
                    const lastName = items[items.length - 1];
                    if (lastName && lastName.textContent.trim()) {
                        return lastName.textContent.trim();
                    }
                }
            }
            
            // Try page title/h1 for category pages only
            const pageType = this.detectPageType();
            if (pageType === 'category') {
                const h1 = document.querySelector('h1');
                if (h1) {
                    const text = h1.textContent.trim();
                    if (text.length < 50) {
                        return text;
                    }
                }
            }
            
            // Try URL path - e.g., /bronezakhyst/1124 -> Бронезахист
            const pathParts = window.location.pathname.split('/').filter(p => p && !/^\d+$/.test(p));
            if (pathParts.length > 0) {
                // Decode and capitalize
                const lastPart = decodeURIComponent(pathParts[pathParts.length - 1])
                    .replace(/-/g, ' ')
                    .replace(/_/g, ' ');
                return lastPart;
            }
            
            return null;
        },
        
        // Detect current product name from product page
        detectCurrentProduct: function() {
            const pageType = this.detectPageType();
            if (pageType !== 'product') return null;
            
            // Try product schema
            const productSchema = document.querySelector('[itemtype*="Product"] [itemprop="name"]');
            if (productSchema) {
                return productSchema.textContent.trim();
            }
            
            // Try h1 on product pages
            const h1 = document.querySelector('h1');
            if (h1) {
                const text = h1.textContent.trim();
                // Product names are usually 10-100 chars
                if (text.length >= 5 && text.length <= 150) {
                    return text;
                }
            }
            
            // Try Horoshop product title
            const hsTitle = document.querySelector('.hs-product-title, .product-title, .product-name, [data-product-title]');
            if (hsTitle) {
                return hsTitle.textContent.trim();
            }
            
            // Try og:title meta
            const ogTitle = document.querySelector('meta[property="og:title"]');
            if (ogTitle && ogTitle.content) {
                // Clean common suffixes like " | Shop Name"
                return ogTitle.content.split('|')[0].trim();
            }
            
            return null;
        },
        
        // Detect product price from page
        detectProductPrice: function() {
            // Try schema.org price
            const priceSchema = document.querySelector('[itemprop="price"]');
            if (priceSchema) {
                const price = priceSchema.content || priceSchema.textContent;
                return this.formatPrice(price);
            }
            
            // Try common price selectors
            const priceSelectors = [
                '.product-price', '.price', '[data-price]',
                '.hs-price', '.current-price', '.sale-price'
            ];
            for (const selector of priceSelectors) {
                const el = document.querySelector(selector);
                if (el) {
                    const text = el.textContent.trim();
                    const match = text.match(/[\d\s]+/);
                    if (match) {
                        return this.formatPrice(match[0]);
                    }
                }
            }
            return null;
        },
        
        // Format price for display
        formatPrice: function(price) {
            if (!price) return null;
            const num = parseInt(price.toString().replace(/\D/g, ''));
            if (isNaN(num)) return null;
            return num.toLocaleString('uk-UA') + ' ₴';
        },
        
        // Show proactive trigger popup
        showTrigger: function(rule, context = {}) {
            if (!this.canShowTrigger(rule.type)) {
                log('ProactiveTriggers: Cannot show trigger', rule.type);
                return;
            }
            
            // Set mutex immediately to prevent race conditions
            this.state.showingTrigger = true;
            
            log('ProactiveTriggers: Showing trigger', rule.id, rule.type);
            
            // Collect all available context
            const category = this.detectCurrentCategory();
            const product = this.detectCurrentProduct();
            const price = this.detectProductPrice();
            const pageType = this.detectPageType();
            
            if (category) context.category = category;
            if (product) context.product = product;
            if (price) context.price = price;
            context.pageType = pageType;
            
            log('ProactiveTriggers: Context collected', { category, product, price, pageType });
            
            // Create trigger popup
            const popup = this.createTriggerPopup(rule, context);
            document.body.appendChild(popup);
            
            // Animate in
            setTimeout(() => {
                popup.style.opacity = '1';
                popup.style.transform = 'translateY(0)';
            }, 50);
            
            // Track shown event with category context
            this.trackEvent(rule.id, 'shown', context);
            
            // Update state
            this.state.triggersShown++;
            this.state.lastTriggerTime = Date.now();
            this.state.sessionTriggersShown.push(rule.type);
            this.saveState();
            
            // Auto-hide after 15 seconds if not interacted
            setTimeout(() => {
                if (popup.parentNode) {
                    this.dismissTrigger(popup, rule, context);
                }
            }, 15000);
        },
        
        // Create trigger popup element
        createTriggerPopup: function(rule, context) {
            const popup = document.createElement('div');
            popup.id = 'aintento-proactive-trigger';
            popup.className = 'aintento-trigger-popup';
            
            const primaryColor = window.aintentoSettings?.primary_color || '#2563eb';
            
            popup.style.cssText = `
                position: fixed;
                bottom: 100px;
                right: 24px;
                max-width: 320px;
                background: white;
                border-radius: 16px;
                box-shadow: 0 8px 32px rgba(0,0,0,0.15), 0 2px 8px rgba(0,0,0,0.1);
                padding: 16px;
                z-index: 999998;
                opacity: 0;
                transform: translateY(20px);
                transition: all 0.3s ease;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            `;
            
            // Replace variables in message
            let message = rule.message || '';
            
            // Replace all template variables with context
            const replacements = {
                '{{category}}': context.category || 'цю категорію',
                '{{product}}': context.product || 'цей товар',
                '{{price}}': context.price || '',
                '{{page_type}}': context.pageType || ''
            };
            
            for (const [key, value] of Object.entries(replacements)) {
                message = message.replace(new RegExp(key.replace(/[{}]/g, '\\$&'), 'g'), value);
            }
            
            // Smart fallbacks - shorten long product names
            if (context.product && context.product.length > 40) {
                const shortName = context.product.substring(0, 40) + '...';
                message = message.replace(context.product, shortName);
            }
            
            log('ProactiveTriggers: Final message:', message);
            
            popup.innerHTML = `
                <div style="display: flex; align-items: flex-start; gap: 12px;">
                    <div style="font-size: 24px; flex-shrink: 0;">${rule.icon || '💬'}</div>
                    <div style="flex: 1;">
                        <div style="font-size: 14px; color: #1f2937; line-height: 1.5; margin-bottom: 12px;">
                            ${message}
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button id="aintento-trigger-accept" style="
                                flex: 1;
                                padding: 10px 16px;
                                background: ${primaryColor};
                                color: white;
                                border: none;
                                border-radius: 8px;
                                font-size: 13px;
                                font-weight: 500;
                                cursor: pointer;
                                transition: all 0.2s;
                            ">${rule.button_text || 'Показати'}</button>
                            <button id="aintento-trigger-dismiss" style="
                                padding: 10px 12px;
                                background: #f3f4f6;
                                color: #6b7280;
                                border: none;
                                border-radius: 8px;
                                font-size: 13px;
                                cursor: pointer;
                                transition: all 0.2s;
                            ">✕</button>
                        </div>
                    </div>
                </div>
            `;
            
            // Event handlers
            const acceptBtn = popup.querySelector('#aintento-trigger-accept');
            const dismissBtn = popup.querySelector('#aintento-trigger-dismiss');
            
            acceptBtn.onmouseenter = () => {
                acceptBtn.style.filter = 'brightness(1.1)';
                acceptBtn.style.transform = 'translateY(-1px)';
            };
            acceptBtn.onmouseleave = () => {
                acceptBtn.style.filter = 'brightness(1)';
                acceptBtn.style.transform = 'translateY(0)';
            };
            acceptBtn.onclick = () => {
                this.acceptTrigger(popup, rule, context);
            };
            
            dismissBtn.onmouseenter = () => {
                dismissBtn.style.background = '#e5e7eb';
            };
            dismissBtn.onmouseleave = () => {
                dismissBtn.style.background = '#f3f4f6';
            };
            dismissBtn.onclick = () => {
                this.dismissTrigger(popup, rule, context);
            };
            
            return popup;
        },
        
        // Accept trigger (click action button)
        acceptTrigger: function(popup, rule, context) {
            log('ProactiveTriggers: Trigger accepted', rule.id);
            
            // Track clicked event
            this.trackEvent(rule.id, 'clicked', context);
            
            // Remove popup
            popup.style.opacity = '0';
            popup.style.transform = 'translateY(20px)';
            setTimeout(() => popup.remove(), 300);
            
            // Execute action
            if (rule.action_type === 'open_chat' || rule.action_type === 'open_chat_with_context') {
                // Open chat
                if (window.openChat) {
                    window.openChat();
                }
                
                // Build contextual message based on trigger type and context
                const pageType = context.pageType || this.detectPageType();
                const category = context.category || this.detectCurrentCategory();
                const product = context.product || this.detectCurrentProduct();
                let message = '';
                
                log('ProactiveTriggers: Building message for', { pageType, category, product, rule_type: rule.type });
                
                // Build message based on trigger type
                switch (rule.type) {
                    case 'exit_intent':
                        if (pageType === 'product' && product) {
                            // User was leaving product page
                            message = `Маю питання по товару "${this.truncate(product, 50)}"`;
                        } else if (pageType === 'category' && category) {
                            // User was leaving category - show bestsellers
                            message = `Покажи топ товари в категорії "${category}"`;
                        }
                        break;
                        
                    case 'time_on_page':
                        if (pageType === 'product' && product) {
                            // Long time on product - help with sizing
                            message = `Допоможіть підібрати розмір для "${this.truncate(product, 40)}"`;
                        } else if (pageType === 'category' && category) {
                            // Long time in category - show bestsellers
                            message = `Покажи найпопулярніші товари в "${category}"`;
                        }
                        break;
                        
                    case 'pdp_no_variant':
                        if (product) {
                            message = `Допоможіть підібрати розмір для "${this.truncate(product, 40)}"`;
                        } else {
                            message = 'Допоможіть підібрати розмір для цього товару';
                        }
                        break;
                        
                    case 'returning_visitor':
                        if (category) {
                            message = `Покажи новинки в категорії "${category}"`;
                        } else {
                            message = 'Що нового з\'явилось?';
                        }
                        break;
                        
                    case 'utm_campaign':
                        // UTM triggers - depend on source
                        const utm = this.getUtmParams();
                        if (utm.utm_source === 'tiktok') {
                            message = 'Покажи популярні товари, як у TikTok';
                        } else if (utm.utm_source === 'instagram') {
                            message = 'Покажи хіти, які постять в Instagram';
                        } else if (pageType === 'product' && product) {
                            message = `Маю питання по "${this.truncate(product, 50)}"`;
                        } else if (category) {
                            message = `Покажи топ в "${category}"`;
                        }
                        break;
                }
                
                // Fallback to rule's initial_message
                if (!message && rule.action_config?.initial_message) {
                    message = rule.action_config.initial_message;
                    // Replace placeholders
                    if (category) {
                        message = message.replace(/\{\{category\}\}/g, category);
                    }
                    if (product) {
                        message = message.replace(/\{\{product\}\}/g, this.truncate(product, 50));
                    }
                    log('ProactiveTriggers: Using rule initial message:', message);
                }
                
                // Final fallback
                if (!message) {
                    if (category) {
                        message = `Покажи топ товари в "${category}"`;
                    } else if (product) {
                        message = `Розкажи більше про "${this.truncate(product, 50)}"`;
                    } else {
                        message = 'Допоможіть обрати';
                    }
                }
                
                // Send message to chat
                if (message) {
                    setTimeout(() => {
                        const input = document.getElementById('aintento-input');
                        if (input) {
                            input.value = message;
                            log('ProactiveTriggers: Sending message:', message);
                            // Trigger send
                            const sendBtn = document.getElementById('aintento-send');
                            if (sendBtn) sendBtn.click();
                        }
                    }, 500);
                }
            }
        },
        
        // Dismiss trigger
        dismissTrigger: function(popup, rule, context) {
            log('ProactiveTriggers: Trigger dismissed', rule.id);
            
            // Track dismissed event
            this.trackEvent(rule.id, 'dismissed', context);
            
            // Remove popup with animation
            popup.style.opacity = '0';
            popup.style.transform = 'translateY(20px)';
            setTimeout(() => popup.remove(), 300);
        },
        
        // Track trigger event
        trackEvent: function(ruleId, eventType, context = {}) {
            const sessionId = localStorage.getItem('aintento_session_id') || 'unknown';
            
            fetch(BASE_URL + '/api/triggers/event', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    rule_id: ruleId,
                    session_id: sessionId,
                    tenant_id: window.aintentoTenantId,
                    event_type: eventType,
                    context: {
                        ...context,
                        page_url: window.location.href,
                        page_type: this.detectPageType(),
                        utm: this.getUtmParams(),
                        time_on_page: Math.floor((Date.now() - this.state.pageStartTime) / 1000)
                    }
                })
            }).catch(err => {
                log('ProactiveTriggers: Failed to track event', err);
            });
        },
        
        // ========================================
        // TRIGGER DETECTORS
        // ========================================
        
        // Exit intent detector
        setupExitIntentDetector: function() {
            let exitIntentTriggered = false;
            let exitIntentDebounce = null;
            let isNavigatingAway = false; // Flag to prevent trigger during page navigation
            
            // Detect when user is navigating away (link click, form submit, etc.)
            // This prevents exit-intent from firing when user clicks a link to another page
            document.addEventListener('click', (e) => {
                const link = e.target.closest('a[href]');
                if (link && link.href && !link.href.startsWith('javascript:') && !link.href.startsWith('#')) {
                    // User clicked a navigation link
                    isNavigatingAway = true;
                    log('ProactiveTriggers: Navigation detected, disabling exit intent');
                    // Reset after a delay in case navigation was cancelled
                    setTimeout(() => { isNavigatingAway = false; }, 2000);
                }
            }, true);
            
            // Also detect form submissions
            document.addEventListener('submit', () => {
                isNavigatingAway = true;
                log('ProactiveTriggers: Form submit detected, disabling exit intent');
            }, true);
            
            // Detect beforeunload (page refresh, close tab, navigate away)
            window.addEventListener('beforeunload', () => {
                isNavigatingAway = true;
                log('ProactiveTriggers: beforeunload - page is unloading');
            });
            
            // Mouse leave detection (desktop) - using mouseout on documentElement
            // This fires when mouse leaves the viewport to the TOP (e.g., to close tab or go to address bar)
            document.documentElement.addEventListener('mouseout', (e) => {
                if (exitIntentTriggered) return;
                if (isNavigatingAway) return; // Don't trigger during navigation
                
                // Only trigger when mouse actually leaves the document (relatedTarget is null)
                // and moves toward the TOP of the page (clientY <= 0)
                if (e.relatedTarget !== null && e.relatedTarget !== undefined) return;
                if (e.clientY > 0) return; // Only trigger when moving to top (Y <= 0)
                
                // Additional check: toElement should be null for real exit
                if (e.toElement !== null && e.toElement !== undefined) return;
                
                const timeOnPage = (Date.now() - this.state.pageStartTime) / 1000;
                if (timeOnPage < 5) return; // Minimum 5 seconds on page
                
                // Debounce to prevent false triggers during fast mouse movements
                if (exitIntentDebounce) {
                    clearTimeout(exitIntentDebounce);
                }
                
                exitIntentDebounce = setTimeout(() => {
                    // Double-check we're still on the page (not navigating away)
                    if (document.hidden) return;
                    if (isNavigatingAway) return;
                    
                    const rule = this.findMatchingRule('exit_intent');
                    if (rule && this.canShowTrigger('exit_intent')) {
                        exitIntentTriggered = true;
                        log('ProactiveTriggers: Exit intent triggered (mouse left viewport top)');
                        this.showTrigger(rule, {
                            trigger_reason: 'mouse_leave_top'
                        });
                    }
                }, 100); // Small delay to filter out false positives
            });
            
            // Fast scroll up detection
            let lastScrollY = window.scrollY;
            let lastScrollTime = Date.now();
            
            window.addEventListener('scroll', () => {
                if (exitIntentTriggered) return;
                
                const now = Date.now();
                const deltaY = lastScrollY - window.scrollY; // Positive = scrolling up
                const deltaTime = now - lastScrollTime;
                
                if (deltaTime > 0) {
                    const velocity = deltaY / deltaTime * 100; // px per 100ms
                    
                    // Fast scroll up near top of page
                    if (velocity > 50 && window.scrollY < 200) {
                        const timeOnPage = (Date.now() - this.state.pageStartTime) / 1000;
                        if (timeOnPage >= 5) {
                            const rule = this.findMatchingRule('exit_intent');
                            if (rule && this.canShowTrigger('exit_intent')) {
                                exitIntentTriggered = true;
                                this.showTrigger(rule, {
                                    trigger_reason: 'fast_scroll_up'
                                });
                            }
                        }
                    }
                }
                
                lastScrollY = window.scrollY;
                lastScrollTime = now;
            }, { passive: true });
            
            log('ProactiveTriggers: Exit intent detector setup');
        },
        
        // Time on page detector
        setupTimeOnPageDetector: function() {
            // Check every 5 seconds
            setInterval(() => {
                const timeOnPage = (Date.now() - this.state.pageStartTime) / 1000;
                const idleTime = (Date.now() - this.state.lastActivity) / 1000;
                const pageType = this.detectPageType();
                
                // Product page: use rule's min_seconds and idle_seconds (defaults: 45s, 15s idle)
                if (pageType === 'product') {
                    const rule = this.findMatchingRule('time_on_page', { pageType: 'product' });
                    if (rule) {
                        const minSeconds = rule.conditions?.min_seconds || 45;
                        const idleSeconds = rule.conditions?.idle_seconds || 15;
                        
                        if (timeOnPage >= minSeconds && idleTime >= idleSeconds) {
                            if (this.canShowTrigger('time_on_page')) {
                                log('ProactiveTriggers: Time on product page trigger!', { timeOnPage, idleTime, minSeconds, idleSeconds });
                                this.showTrigger(rule, {
                                    trigger_reason: 'time_on_product_page',
                                    time_on_page: Math.floor(timeOnPage),
                                    idle_time: Math.floor(idleTime)
                                });
                            }
                        }
                    }
                }
                
                // Category page: use rule's min_seconds, min_products_viewed is optional (default: 1)
                if (pageType === 'category') {
                    const rule = this.findMatchingRule('time_on_page', { pageType: 'category' });
                    if (rule) {
                        const minSeconds = rule.conditions?.min_seconds || 60;
                        const minProducts = rule.conditions?.min_products_viewed || 1; // Lowered default
                        
                        if (timeOnPage >= minSeconds && this.state.productsViewed.length >= minProducts) {
                            if (this.canShowTrigger('time_on_page')) {
                                log('ProactiveTriggers: Time on category page trigger!', { timeOnPage, productsViewed: this.state.productsViewed.length });
                                this.showTrigger(rule, {
                                    trigger_reason: 'browsing_category',
                                    products_viewed: this.state.productsViewed.length
                                });
                            }
                        }
                    }
                }
                
                // PDP no variant: 30 seconds without selection
                if (pageType === 'product' && timeOnPage >= 30 && !this.state.variantSelected) {
                    // Expanded variant selectors
                    const hasVariants = document.querySelector(
                        '[data-variant], .variant-select, .size-select, .hs-variants, ' +
                        '.product-variants, [data-size], [data-color], .sizes-list, .colors-list, ' +
                        'select[name*="size"], select[name*="variant"], .option-selector'
                    );
                    if (hasVariants) {
                        const rule = this.findMatchingRule('pdp_no_variant');
                        if (rule && this.canShowTrigger('pdp_no_variant')) {
                            log('ProactiveTriggers: PDP no variant trigger!');
                            this.showTrigger(rule, {
                                trigger_reason: 'no_variant_selected',
                                time_without_selection: Math.floor(timeOnPage)
                            });
                        }
                    }
                }
            }, 5000);
            
            log('ProactiveTriggers: Time on page detector setup');
        },
        
        // Activity tracker
        setupActivityTracker: function() {
            const updateActivity = () => {
                this.state.lastActivity = Date.now();
            };
            
            ['scroll', 'click', 'mousemove', 'keypress', 'touchstart'].forEach(event => {
                document.addEventListener(event, updateActivity, { passive: true });
            });
            
            // Track product views in category
            document.addEventListener('click', (e) => {
                const productLink = e.target.closest('a[href*="/product/"], a[href*="/tovar/"], a[href*="/p/"], .product-card, .product-item');
                if (productLink) {
                    const productId = productLink.dataset?.productId || productLink.href;
                    if (!this.state.productsViewed.includes(productId)) {
                        this.state.productsViewed.push(productId);
                    }
                }
            }, { passive: true });
            
            log('ProactiveTriggers: Activity tracker setup');
        },
        
        // Variant selection detector
        setupVariantSelectionDetector: function() {
            // Watch for clicks on variant selectors
            document.addEventListener('click', (e) => {
                const variantSelector = e.target.closest(
                    '[data-variant], .variant-select, .size-select, .color-select, ' +
                    '.hs-variants button, .hs-variant-item, [data-size], [data-color]'
                );
                if (variantSelector) {
                    this.state.variantSelected = true;
                    log('ProactiveTriggers: Variant selected');
                }
            }, { passive: true });
            
            // Watch for select changes
            document.addEventListener('change', (e) => {
                if (e.target.matches('select[name*="size"], select[name*="variant"], select[name*="color"]')) {
                    this.state.variantSelected = true;
                    log('ProactiveTriggers: Variant selected via dropdown');
                }
            }, { passive: true });
            
            log('ProactiveTriggers: Variant selection detector setup');
        },
        
        // Returning visitor detector
        setupReturningVisitorDetector: function() {
            try {
                // Save current visit info
                const now = Date.now();
                const visitInfo = {
                    timestamp: now,
                    page: window.location.pathname,
                    category: this.detectCurrentCategory()
                };
                
                // Get previous visits
                const visitsKey = 'aintento_visits';
                let visits = [];
                try {
                    visits = JSON.parse(localStorage.getItem(visitsKey) || '[]');
                } catch(e) {}
                
                // Check if returning visitor (visited before)
                this.state.isReturningVisitor = visits.length > 0;
                this.state.lastVisit = visits.length > 0 ? visits[visits.length - 1] : null;
                
                if (this.state.isReturningVisitor && this.state.lastVisit) {
                    const hoursSinceLastVisit = (now - this.state.lastVisit.timestamp) / (1000 * 60 * 60);
                    this.state.hoursSinceLastVisit = hoursSinceLastVisit;
                    log('ProactiveTriggers: Returning visitor detected, hours since last visit:', Math.round(hoursSinceLastVisit));
                }
                
                // Add current visit (keep last 10)
                visits.push(visitInfo);
                if (visits.length > 10) visits = visits.slice(-10);
                localStorage.setItem(visitsKey, JSON.stringify(visits));
                
            } catch(e) {
                log('ProactiveTriggers: Error in returning visitor detector', e);
            }
        },
        
        // Detect current category from page
        detectCurrentCategory: function() {
            // Try breadcrumbs
            const breadcrumbs = document.querySelectorAll('.breadcrumb a, .breadcrumbs a, [itemtype*="BreadcrumbList"] a');
            if (breadcrumbs.length > 1) {
                return breadcrumbs[breadcrumbs.length - 1].textContent.trim();
            }
            // Try h1
            const h1 = document.querySelector('h1');
            if (h1) return h1.textContent.trim();
            // Try URL
            const pathParts = window.location.pathname.split('/').filter(Boolean);
            return pathParts[0] || '';
        },
        
        // Check returning visitor trigger
        checkReturningVisitorTrigger: function() {
            if (!this.state.isReturningVisitor || !this.state.lastVisit) {
                log('ProactiveTriggers: Not a returning visitor');
                return;
            }
            
            // Find returning visitor rule
            const rule = this.findMatchingRule('returning_visitor');
            if (!rule) {
                log('ProactiveTriggers: No returning visitor rule found');
                return;
            }
            
            // Check conditions - default to 1 hour (instead of 24)
            const minHours = rule.conditions?.min_hours_since_last_visit || 1;
            if (this.state.hoursSinceLastVisit < minHours) {
                log('ProactiveTriggers: Not enough time since last visit', this.state.hoursSinceLastVisit, 'hours <', minHours, 'hours');
                return;
            }
            
            if (this.canShowTrigger('returning_visitor')) {
                const delay = (rule.conditions?.delay_seconds || 5) * 1000;
                setTimeout(() => {
                    if (this.canShowTrigger('returning_visitor')) {
                        log('ProactiveTriggers: Showing returning visitor trigger');
                        this.showTrigger(rule, {
                            trigger_reason: 'returning_visitor',
                            last_visit: this.state.lastVisit
                        });
                    }
                }, delay);
            }
        },

        // Check UTM triggers
        checkUtmTriggers: function() {
            const utm = this.getUtmParams();
            
            // Skip if no UTM params
            if (!utm.utm_source && !utm.utm_medium && !utm.utm_campaign) {
                return;
            }
            
            log('ProactiveTriggers: Checking UTM triggers', utm);
            
            // Find matching UTM rule
            const rule = this.findMatchingRule('utm_campaign', { utm });
            if (rule && this.canShowTrigger('utm_campaign')) {
                // Delay from conditions (default 10 seconds)
                const delay = (rule.conditions?.delay_seconds || 10) * 1000;
                
                setTimeout(() => {
                    // Re-check if can show (user might have opened chat)
                    if (this.canShowTrigger('utm_campaign')) {
                        this.showTrigger(rule, {
                            trigger_reason: 'utm_match',
                            utm: utm
                        });
                    }
                }, delay);
            }
        },
        
        // Notify that chat was opened (pause triggers)
        onChatOpened: function() {
            this.state.chatOpened = true;
            // Remove any visible trigger popups
            const popup = document.getElementById('aintento-proactive-trigger');
            if (popup) popup.remove();
        },
        
        // Notify that chat was closed
        onChatClosed: function() {
            this.state.chatOpened = false;
        }
    };
    
    // Initialize ProactiveTriggers after DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => ProactiveTriggers.init(), 2000);
        });
    } else {
        setTimeout(() => ProactiveTriggers.init(), 2000);
    }
    
    // Expose for external use
    window.aintentoTriggers = ProactiveTriggers;

})();

