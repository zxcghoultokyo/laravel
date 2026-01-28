/**
 * AIntento Analytics Module
 * Трекінг подій користувача для атрибуції конверсій
 */
(function() {
    'use strict';

    const ANALYTICS_VERSION = '1.0.0';
    const ATTRIBUTION_WINDOW_HOURS = 72;
    const COOKIE_NAME = 'aintento_client_id';
    const SESSION_COOKIE = 'aintento_session';
    
    // Singleton instance
    let instance = null;

    class AintentoAnalytics {
        constructor(baseUrl, sessionId) {
            this.baseUrl = baseUrl;
            this.sessionId = sessionId;
            this.clientId = this.getOrCreateClientId();
            this.merchantId = this.extractMerchantId();
            this.eventQueue = [];
            this.isProcessing = false;
            this.shownProductIds = new Set();
            this.clickedProductIds = new Set();
            this.pageUrl = window.location.href;
            this.referrer = document.referrer;
            this.deviceType = this.detectDeviceType();
            
            // Track session start
            this.trackEvent('session_start');
            
            // Setup page tracking
            this.setupPageTracking();
            
            // Setup cart tracking
            this.setupCartTracking();
            
            // Flush events periodically and on page unload
            this.setupFlush();
            
            console.log('[AIntento Analytics] Initialized', {
                sessionId: this.sessionId,
                clientId: this.clientId,
                merchantId: this.merchantId
            });
        }

        static getInstance(baseUrl, sessionId) {
            if (!instance) {
                instance = new AintentoAnalytics(baseUrl, sessionId);
            } else if (sessionId && instance.sessionId !== sessionId) {
                instance.sessionId = sessionId;
            }
            return instance;
        }

        /**
         * Get or create persistent client ID (survives session changes)
         */
        getOrCreateClientId() {
            let clientId = this.getCookie(COOKIE_NAME);
            if (!clientId) {
                clientId = 'cli_' + Date.now() + '_' + Math.random().toString(36).substr(2, 12);
                // Set cookie for attribution window
                this.setCookie(COOKIE_NAME, clientId, ATTRIBUTION_WINDOW_HOURS);
            }
            return clientId;
        }

        /**
         * Extract merchant ID from page or widget settings
         */
        extractMerchantId() {
            // Try from widget container
            const container = document.getElementById('aintento-chat');
            if (container?.dataset?.token) {
                return container.dataset.token;
            }
            // Try from domain
            return window.location.hostname;
        }

        /**
         * Detect device type
         */
        detectDeviceType() {
            const ua = navigator.userAgent;
            if (/tablet|ipad|playbook|silk/i.test(ua)) return 'tablet';
            if (/mobile|iphone|ipod|android|blackberry|opera mini|iemobile/i.test(ua)) return 'mobile';
            return 'desktop';
        }

        /**
         * Track an event
         */
        trackEvent(eventType, data = {}) {
            const event = {
                session_id: this.sessionId,
                client_id: this.clientId,
                merchant_id: this.merchantId,
                event_type: eventType,
                event_source: 'widget',
                device_type: this.deviceType,
                page_url: this.pageUrl,
                referrer: this.referrer,
                timestamp: new Date().toISOString(),
                ...data
            };

            // Extract UTM params
            const utmParams = this.getUtmParams();
            if (Object.keys(utmParams).length > 0) {
                Object.assign(event, utmParams);
            }

            this.eventQueue.push(event);
            console.log('[AIntento Analytics] Event tracked:', eventType, data);

            // Immediate flush for important events
            if (['add_to_cart', 'purchase', 'checkout'].includes(eventType)) {
                this.flush();
            }
        }

        /**
         * Track chat message
         */
        trackMessage(role, text, messageType = null) {
            this.trackEvent('message', {
                message_type: messageType || role,
                message_text: text?.substring(0, 500) // Truncate long messages
            });
        }

        /**
         * Track products shown in chat
         */
        trackProductsShown(products) {
            products.forEach(product => {
                if (!this.shownProductIds.has(product.id)) {
                    this.shownProductIds.add(product.id);
                    this.trackEvent('product_shown', {
                        product_id: product.id,
                        product_article: product.article,
                        product_price: product.price
                    });
                }
            });
        }

        /**
         * Track product click from chat
         */
        trackProductClick(product) {
            this.clickedProductIds.add(product.id);
            this.trackEvent('product_click', {
                product_id: product.id,
                product_article: product.article,
                product_price: product.price
            });

            // Store clicked product for attribution
            this.storeClickedProduct(product);
        }

        /**
         * Store clicked product in localStorage for later attribution
         */
        storeClickedProduct(product) {
            const key = 'aintento_clicked_products';
            let clicked = JSON.parse(localStorage.getItem(key) || '[]');
            
            // Add with timestamp
            clicked.push({
                id: product.id,
                article: product.article,
                price: product.price,
                session_id: this.sessionId,
                timestamp: Date.now()
            });

            // Keep only last 72 hours
            const cutoff = Date.now() - (ATTRIBUTION_WINDOW_HOURS * 60 * 60 * 1000);
            clicked = clicked.filter(p => p.timestamp > cutoff);

            // Keep max 50 products
            if (clicked.length > 50) clicked = clicked.slice(-50);

            localStorage.setItem(key, JSON.stringify(clicked));
        }

        /**
         * Check if product was clicked in chat
         */
        wasProductClickedInChat(productId) {
            const key = 'aintento_clicked_products';
            const clicked = JSON.parse(localStorage.getItem(key) || '[]');
            return clicked.some(p => p.id === productId || p.article === productId);
        }

        /**
         * Track cross-sell interaction
         */
        trackCrossSell(action, product) {
            this.trackEvent('cross_sell_' + action, {
                product_id: product?.id,
                product_article: product?.article,
                product_price: product?.price
            });
        }

        /**
         * Track chat opened/closed
         */
        trackChatToggle(isOpen) {
            this.trackEvent(isOpen ? 'chat_opened' : 'chat_closed');
        }

        /**
         * Setup tracking for add-to-cart on the page
         */
        setupCartTracking() {
            // Common selectors for add-to-cart buttons
            const cartSelectors = [
                '.add-to-cart',
                '.btn-cart',
                '[data-action="add-to-cart"]',
                'button[name="add"]',
                '.product-buy',
                '.buy-button',
                // Horoshop specific
                '.hs-btn-cart',
                '.hs-add-to-cart',
                '[data-hs-action="cart"]',
                // Horoshop "Купити" button - various patterns
                '.j-buy-button-add',
                '.j-buy-button',
                '.product-order__block--buy .btn',
                '[id^="j-buy-button"]',
                '[class*="j-buy"]',
                '[class*="buy-button"]',
                // Generic patterns
                'button[class*="buy"]',
                'a[class*="buy"]',
                '.btn-buy',
                '.product-buy-btn',
                // Data attributes
                '[data-buy]',
                '[data-cart]',
                '[data-add-cart]'
            ];

            // Click listener
            document.addEventListener('click', (e) => {
                // Debug: log all clicks to see what's being clicked
                if (this.debug) {
                    console.log('[AIntento Analytics] Click detected:', e.target.tagName, e.target.className, e.target.id);
                }
                
                const target = e.target.closest(cartSelectors.join(','));
                if (target) {
                    console.log('[AIntento Analytics] Cart button matched:', target);
                    this.handleAddToCartClick(target);
                }
            }, true);

            // Intercept fetch requests to cart API
            this.interceptFetch();

            // Intercept XHR requests
            this.interceptXHR();

            // Listen for custom cart events
            document.addEventListener('aintento:add_to_cart', (e) => {
                this.handleAddToCart(e.detail);
            });

            // Listen for storage changes (some sites use localStorage for cart)
            window.addEventListener('storage', (e) => {
                if (e.key?.includes('cart')) {
                    this.checkCartChanges();
                }
            });
        }

        /**
         * Handle click on add-to-cart button
         */
        handleAddToCartClick(button) {
            // Try to extract product info from button or parent
            let productData = this.extractProductFromElement(button);
            
            // Horoshop specific: extract from button ID (j-buy-button-widget-5212)
            if (!productData?.id && button.id) {
                const match = button.id.match(/j-buy-button-(?:widget-)?(\d+)/);
                if (match) {
                    productData = productData || {};
                    productData.id = match[1];
                }
            }
            
            // Check if user had chat conversation in this session
            const hadChatConversation = this.hadChatConversation();
            const wasClickedInChat = this.wasProductClickedInChat(productData?.id || productData?.article);
            
            this.trackEvent('add_to_cart', {
                product_id: productData?.id,
                product_article: productData?.article,
                product_price: productData?.price,
                product_from_chat: wasClickedInChat,
                had_chat_conversation: hadChatConversation,
                chat_session_id: hadChatConversation ? this.getChatSessionId() : null
            });
            
            // Track as conversion if related to chat
            if (hadChatConversation || wasClickedInChat) {
                this.trackConversion('add_to_cart', {
                    ...productData,
                    attributed_to_chat: true,
                    was_shown_in_chat: wasClickedInChat
                });
            }
            
            console.log('[AIntento Analytics] Add to cart tracked:', {
                productData,
                hadChatConversation,
                wasClickedInChat
            });
        }
        
        /**
         * Check if user had chat conversation in current session
         */
        hadChatConversation() {
            // Check localStorage for chat messages
            const sessionId = localStorage.getItem('aintento_session_id') || '';
            const messagesKey = 'aintento_messages_' + sessionId;
            const messages = localStorage.getItem(messagesKey);
            
            // Check products shown in chat
            const shownProductsKey = 'aintento_shown_products_' + sessionId;
            const shownProducts = JSON.parse(localStorage.getItem(shownProductsKey) || '[]');
            
            if (messages) {
                try {
                    const parsed = JSON.parse(messages);
                    // Count user messages - need at least 2 for real conversation
                    const userMessageCount = parsed.filter(m => m.role === 'user').length;
                    // Real chat = 2+ user messages OR products were shown in chat
                    return userMessageCount >= 2 || shownProducts.length > 0;
                } catch (e) {}
            }
            
            // If products were shown in chat, that's meaningful
            if (shownProducts.length > 0) {
                return true;
            }
            
            return false;
        }
        
        /**
         * Get chat session ID if available
         */
        getChatSessionId() {
            return localStorage.getItem('aintento_session_id') || this.sessionId;
        }

        /**
         * Handle confirmed add to cart
         */
        handleAddToCart(productData) {
            const isFromChat = this.wasProductClickedInChat(productData?.id || productData?.article);
            
            this.trackEvent('add_to_cart', {
                product_id: productData?.id,
                product_article: productData?.article,
                product_price: productData?.price,
                product_from_chat: isFromChat
            });

            // Track conversion
            if (isFromChat) {
                this.trackConversion('add_to_cart', productData);
            }
        }

        /**
         * Track conversion
         */
        trackConversion(type, data = {}) {
            const conversionData = {
                conversion_type: type,
                session_id: this.sessionId,
                client_id: this.clientId,
                merchant_id: this.merchantId,
                ...data
            };

            // Send immediately
            this.sendToServer('/api/analytics/conversion', conversionData);
        }

        /**
         * Extract product data from DOM element
         */
        extractProductFromElement(element) {
            // Look for data attributes
            const productCard = element.closest('[data-product-id], [data-article], .product-card, .product-item');
            if (!productCard) return null;

            return {
                id: productCard.dataset.productId || productCard.dataset.id,
                article: productCard.dataset.article || productCard.dataset.sku,
                price: productCard.dataset.price,
                title: productCard.dataset.title || productCard.querySelector('.product-title, .title')?.textContent
            };
        }

        /**
         * Intercept fetch for cart API calls
         */
        interceptFetch() {
            const originalFetch = window.fetch;
            const analytics = this;

            window.fetch = function(...args) {
                const [url, options] = args;
                const urlStr = typeof url === 'string' ? url : url?.url || '';

                // Check if this is a cart request
                if (analytics.isCartRequest(urlStr, options?.method)) {
                    return originalFetch.apply(this, args).then(response => {
                        // Clone response to read body
                        response.clone().json().then(data => {
                            analytics.handleCartApiResponse(urlStr, options?.method, data);
                        }).catch(() => {});
                        return response;
                    });
                }

                return originalFetch.apply(this, args);
            };
        }

        /**
         * Intercept XHR for cart API calls
         */
        interceptXHR() {
            const originalOpen = XMLHttpRequest.prototype.open;
            const originalSend = XMLHttpRequest.prototype.send;
            const analytics = this;

            XMLHttpRequest.prototype.open = function(method, url, ...rest) {
                this._aintentoUrl = url;
                this._aintentoMethod = method;
                return originalOpen.apply(this, [method, url, ...rest]);
            };

            XMLHttpRequest.prototype.send = function(body) {
                if (analytics.isCartRequest(this._aintentoUrl, this._aintentoMethod)) {
                    this.addEventListener('load', function() {
                        try {
                            const data = JSON.parse(this.responseText);
                            analytics.handleCartApiResponse(this._aintentoUrl, this._aintentoMethod, data);
                        } catch (e) {}
                    });
                }
                return originalSend.apply(this, arguments);
            };
        }

        /**
         * Check if URL is a cart-related request
         */
        isCartRequest(url, method) {
            const cartPatterns = [
                /\/cart/i,
                /\/basket/i,
                /\/add.*item/i,
                /\/api.*cart/i,
                // Horoshop
                /\/hs-api.*cart/i,
                /action=cart/i,
                /AddCart/i,
                /add_to_cart/i,
                /product.*add/i,
                /cart.*add/i
            ];
            return cartPatterns.some(pattern => pattern.test(url));
        }

        /**
         * Handle cart API response
         */
        handleCartApiResponse(url, method, data) {
            // Detect add to cart
            if (method?.toUpperCase() === 'POST' || /add/i.test(url)) {
                const productData = this.extractProductFromApiResponse(data);
                if (productData) {
                    this.handleAddToCart(productData);
                }
            }
        }

        /**
         * Extract product from API response
         */
        extractProductFromApiResponse(data) {
            // Common response structures
            if (data.product) return data.product;
            if (data.item) return data.item;
            if (data.added) return data.added;
            if (data.items?.[0]) return data.items[0];
            return null;
        }

        /**
         * Setup page tracking
         */
        setupPageTracking() {
            // Track page views within attribution window
            this.trackEvent('page_view');

            // Track when user leaves
            window.addEventListener('beforeunload', () => {
                this.trackEvent('page_leave');
                this.flush(true); // Sync flush
            });

            // Track visibility changes
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.flush();
                }
            });
        }

        /**
         * Setup periodic flush
         */
        setupFlush() {
            // Flush every 30 seconds
            setInterval(() => this.flush(), 30000);

            // Flush on page unload
            window.addEventListener('pagehide', () => this.flush(true));
        }

        /**
         * Flush event queue to server
         */
        async flush(sync = false) {
            if (this.eventQueue.length === 0 || this.isProcessing) return;

            this.isProcessing = true;
            const events = [...this.eventQueue];
            this.eventQueue = [];

            try {
                if (sync && navigator.sendBeacon) {
                    // Use sendBeacon for page unload
                    navigator.sendBeacon(
                        this.baseUrl + '/api/analytics/events',
                        JSON.stringify({ events })
                    );
                } else {
                    await this.sendToServer('/api/analytics/events', { events });
                }
            } catch (e) {
                // Re-add events on failure
                this.eventQueue = [...events, ...this.eventQueue];
                console.error('[AIntento Analytics] Flush failed:', e);
            } finally {
                this.isProcessing = false;
            }
        }

        /**
         * Send data to server
         */
        async sendToServer(endpoint, data) {
            return fetch(this.baseUrl + endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data),
                keepalive: true
            });
        }

        /**
         * Get UTM parameters from URL
         */
        getUtmParams() {
            const params = new URLSearchParams(window.location.search);
            const utm = {};
            ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(key => {
                const value = params.get(key);
                if (value) utm[key] = value;
            });
            return utm;
        }

        /**
         * Cookie helpers
         */
        setCookie(name, value, hours) {
            const expires = new Date(Date.now() + hours * 60 * 60 * 1000).toUTCString();
            document.cookie = `${name}=${value}; expires=${expires}; path=/; SameSite=Lax`;
        }

        getCookie(name) {
            const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
            return match ? match[2] : null;
        }

        /**
         * Check cart changes (for localStorage-based carts)
         */
        checkCartChanges() {
            // Implementation depends on specific cart implementation
            console.log('[AIntento Analytics] Cart storage changed');
        }

        /**
         * Get session outcome
         */
        getSessionOutcome() {
            return {
                messages_count: this.messageCount || 0,
                products_shown: this.shownProductIds.size,
                products_clicked: this.clickedProductIds.size,
                had_conversion: this.conversionCount > 0
            };
        }
    }

    // Export to global scope
    window.AintentoAnalytics = AintentoAnalytics;

})();
