<!DOCTYPE html>
<html lang="uk" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <!-- Primary Meta Tags -->
        <title>AIntento — AI Асистент для e-commerce | Збільшення конверсії магазину</title>
        <meta name="title" content="AIntento — AI Асистент для e-commerce | Збільшення конверсії магазину">
        <meta name="description" content="Розумний AI-асистент для інтернет-магазинів. Автоматичний підбір товарів, консультації 24/7, персоналізовані рекомендації та глибока аналітика продажів. Збільшує конверсію на 15-30%.">
        <meta name="keywords" content="AI асистент, чат-бот для магазину, e-commerce AI, штучний інтелект для продажів, чат консультант, автоматизація продажів, Horoshop інтеграція, збільшення конверсії">
        <meta name="author" content="AIntento">
        <meta name="robots" content="index, follow">
        <link rel="canonical" href="https://aintento.laravel.cloud/">

        <!-- Open Graph / Facebook -->
        <meta property="og:type" content="website">
        <meta property="og:url" content="https://aintento.laravel.cloud/">
        <meta property="og:title" content="AIntento — AI Асистент для e-commerce">
        <meta property="og:description" content="Розумний AI-асистент для інтернет-магазинів. Автоматичний підбір товарів, консультації 24/7, персоналізовані рекомендації. Збільшує конверсію на 15-30%.">
        <meta property="og:image" content="https://aintento.laravel.cloud/images/og-image.png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:locale" content="uk_UA">
        <meta property="og:site_name" content="AIntento">

        <!-- Twitter -->
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:url" content="https://aintento.laravel.cloud/">
        <meta name="twitter:title" content="AIntento — AI Асистент для e-commerce">
        <meta name="twitter:description" content="Розумний AI-асистент для інтернет-магазинів. Автоматичний підбір товарів, консультації 24/7, персоналізовані рекомендації.">
        <meta name="twitter:image" content="https://aintento.laravel.cloud/images/og-image.png">

        <!-- Favicon -->
        <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🤖</text></svg>">
        <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">

        <!-- Schema.org JSON-LD -->
        @verbatim
        <script type="application/ld+json">
        {
            "@context": "https://schema.org",
            "@type": "SoftwareApplication",
            "name": "AIntento",
            "applicationCategory": "BusinessApplication",
            "operatingSystem": "Web",
            "description": "AI-асистент для інтернет-магазинів. Автоматичний підбір товарів, консультації 24/7, персоналізовані рекомендації.",
            "offers": {
                "@type": "Offer",
                "price": "0",
                "priceCurrency": "UAH",
                "description": "Безкоштовний тестовий період"
            },
            "aggregateRating": {
                "@type": "AggregateRating",
                "ratingValue": "4.8",
                "ratingCount": "47"
            },
            "provider": {
                "@type": "Organization",
                "name": "AIntento",
                "url": "https://aintento.laravel.cloud"
            }
        }
        </script>
        @endverbatim

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:600,700" rel="stylesheet" />
        
        <!-- AOS.js Animation Library -->
        <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            html, body {
                overflow-x: hidden;
                width: 100%;
                position: relative;
            }
            
            :root {
                --primary: #10b981;
                --primary-dark: #059669;
                --primary-light: #d1fae5;
                --secondary: #065f46;
                --text-dark: #0f172a;
                --text-gray: #64748b;
                --bg-light: #f0fdf4;
                --border-color: #d1fae5;
            }
            
            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
                line-height: 1.6;
                color: var(--text-dark);
                background: #ffffff;
            }
            
            h1, h2, h3, h4 {
                font-family: 'Space Grotesk', sans-serif;
                font-weight: 700;
                line-height: 1.2;
            }
            
            .container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 24px;
            }
            
            /* Header */
            header {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(12px);
                border-bottom: 1px solid var(--border-color);
                z-index: 100;
            }
            
            header .container {
                display: flex;
                justify-content: space-between;
                align-items: center;
                height: 72px;
            }
            
            .logo {
                font-size: 24px;
                font-weight: 700;
                font-family: 'Space Grotesk', sans-serif;
                color: var(--primary);
                text-decoration: none;
            }
            
            nav {
                display: flex;
                gap: 32px;
                align-items: center;
            }
            
            nav a {
                color: var(--text-dark);
                text-decoration: none;
                font-weight: 500;
                font-size: 15px;
                transition: color 0.2s;
            }
            
            nav a:hover {
                color: var(--primary);
            }
            
            .btn {
                display: inline-flex;
                align-items: center;
                padding: 12px 24px;
                border-radius: 10px;
                font-weight: 600;
                font-size: 15px;
                text-decoration: none;
                transition: all 0.2s;
                cursor: pointer;
                border: none;
            }
            
            .btn-primary {
                background: var(--primary);
                color: white;
                box-shadow: 0 4px 14px rgba(16, 185, 129, 0.3);
            }
            
            .btn-primary:hover {
                background: var(--primary-dark);
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
            }
            
            .btn-outline {
                border: 2px solid var(--primary);
                color: var(--primary);
                background: transparent;
            }
            
            .btn-outline:hover {
                background: var(--primary);
                color: white;
            }
            
            .btn-small {
                padding: 8px 16px;
                font-size: 13px;
            }
            
            /* Hero */
            .hero {
                padding: 140px 0 80px;
                text-align: center;
                background: linear-gradient(180deg, var(--bg-light) 0%, #ffffff 100%);
            }
            
            .hero h1 {
                font-size: clamp(36px, 5vw, 72px);
                margin-bottom: 24px;
                background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
            }
            
            .hero p {
                font-size: 18px;
                color: var(--text-gray);
                max-width: 700px;
                margin: 0 auto 40px;
            }
            
            .hero-actions {
                display: flex;
                gap: 16px;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            /* Trust Badges */
            .trust-badges {
                display: flex;
                justify-content: center;
                gap: 32px;
                margin-top: 48px;
                flex-wrap: wrap;
            }
            
            .trust-badge {
                display: flex;
                align-items: center;
                gap: 8px;
                color: var(--text-gray);
                font-size: 14px;
                font-weight: 500;
            }
            
            .trust-icon {
                font-size: 20px;
            }
            
            /* Features Section - Split Layout */
            .features {
                padding: 100px 0;
            }
            
            .section-title {
                text-align: center;
                font-size: clamp(28px, 4vw, 48px);
                margin-bottom: 16px;
            }
            
            .section-subtitle {
                text-align: center;
                font-size: 16px;
                color: var(--text-gray);
                max-width: 600px;
                margin: 0 auto 48px;
            }
            
            .features-layout {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 48px;
                align-items: start;
            }
            
            .features-list {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            
            .feature-item {
                background: white;
                border-radius: 16px;
                padding: 20px 24px;
                border: 2px solid var(--border-color);
                cursor: pointer;
                transition: all 0.3s;
                display: flex;
                align-items: center;
                gap: 16px;
            }
            
            .feature-item:hover {
                border-color: var(--primary);
                transform: translateX(4px);
            }
            
            .feature-item.active {
                border-color: var(--primary);
                background: var(--bg-light);
                box-shadow: 0 8px 30px rgba(16, 185, 129, 0.15);
            }
            
            .feature-item .icon {
                width: 48px;
                height: 48px;
                border-radius: 12px;
                background: linear-gradient(135deg, var(--primary), var(--secondary));
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
                flex-shrink: 0;
            }
            
            .feature-item .info h3 {
                font-size: 16px;
                margin-bottom: 4px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .feature-item .info p {
                color: var(--text-gray);
                font-size: 13px;
                line-height: 1.5;
            }
            
            .badge {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 20px;
                font-size: 10px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .badge-success {
                background: var(--primary-light);
                color: var(--primary-dark);
            }
            
            .badge-soon {
                background: #fef3c7;
                color: #d97706;
            }
            
            /* Demo Preview */
            .demo-preview {
                position: sticky;
                top: 100px;
            }
            
            .demo-window {
                background: white;
                border-radius: 24px;
                box-shadow: 0 25px 80px rgba(0, 0, 0, 0.12);
                overflow: hidden;
            }
            
            .demo-header {
                background: linear-gradient(135deg, var(--primary), var(--secondary));
                padding: 16px 20px;
                color: white;
                display: flex;
                align-items: center;
                gap: 12px;
            }
            
            .demo-header-icon {
                font-size: 24px;
            }
            
            .demo-header h4 {
                font-size: 15px;
                margin-bottom: 2px;
            }
            
            .demo-header span {
                font-size: 12px;
                opacity: 0.9;
            }
            
            .demo-body {
                padding: 20px;
                min-height: 320px;
                max-height: 400px;
                overflow-y: auto;
                background: #fafafa;
            }
            
            .demo-message {
                margin-bottom: 12px;
                display: flex;
                gap: 10px;
                align-items: flex-start;
                animation: fadeIn 0.3s ease;
            }
            
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            .demo-message.user {
                flex-direction: row-reverse;
            }
            
            .demo-avatar {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                background: var(--primary);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 14px;
                flex-shrink: 0;
            }
            
            .demo-message.user .demo-avatar {
                background: #6366f1;
            }
            
            .demo-bubble {
                background: white;
                padding: 10px 14px;
                border-radius: 12px;
                font-size: 13px;
                max-width: 85%;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
                line-height: 1.5;
            }
            
            .demo-message.user .demo-bubble {
                background: #6366f1;
                color: white;
            }
            
            .demo-product {
                display: flex;
                gap: 10px;
                background: white;
                padding: 10px;
                border-radius: 10px;
                margin-top: 8px;
                border: 1px solid var(--border-color);
            }
            
            .demo-product-img {
                width: 50px;
                height: 50px;
                background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 20px;
            }
            
            .demo-product-info h5 {
                font-size: 12px;
                margin-bottom: 3px;
            }
            
            .demo-product-info span {
                color: var(--primary);
                font-weight: 700;
                font-size: 14px;
            }
            
            .typing-indicator {
                display: flex;
                gap: 4px;
                padding: 12px 14px;
                background: white;
                border-radius: 12px;
                width: fit-content;
                box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            }
            
            .typing-indicator span {
                width: 6px;
                height: 6px;
                background: var(--primary);
                border-radius: 50%;
                animation: typing 1.4s infinite;
            }
            
            .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
            .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
            
            @keyframes typing {
                0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
                30% { transform: translateY(-3px); opacity: 1; }
            }
            
            /* Demo Section - Interactive Scenarios */
            .demo-section {
                padding: 100px 0;
                background: var(--bg-light);
            }
            
            .demo-container {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 48px;
                align-items: center;
                max-width: 1100px;
                margin: 0 auto;
            }
            
            .demo-info h2 {
                font-size: clamp(28px, 4vw, 36px);
                margin-bottom: 16px;
            }
            
            .demo-info p {
                color: var(--text-gray);
                font-size: 16px;
                margin-bottom: 32px;
            }
            
            .scenario-buttons {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            
            .scenario-btn {
                padding: 16px 20px;
                border-radius: 12px;
                border: 2px solid var(--border-color);
                background: white;
                font-size: 15px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s;
                text-align: left;
                display: flex;
                align-items: center;
                gap: 12px;
            }
            
            .scenario-btn:hover {
                border-color: var(--primary);
                transform: translateX(4px);
            }
            
            .scenario-btn.active {
                background: var(--primary);
                border-color: var(--primary);
                color: white;
            }
            
            .scenario-btn .emoji {
                font-size: 24px;
            }
            
            /* Coming Soon / Roadmap */
            .coming-soon, .roadmap {
                padding: 100px 0;
            }
            
            .coming-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 20px;
                max-width: 1000px;
                margin: 0 auto;
            }
            
            .coming-card {
                background: white;
                border-radius: 16px;
                padding: 24px;
                border: 2px dashed var(--border-color);
                transition: all 0.2s;
            }
            
            .coming-card:hover {
                border-color: var(--primary);
                transform: translateY(-4px);
            }
            
            .coming-card h4 {
                font-size: 16px;
                margin-bottom: 10px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .coming-card p {
                color: var(--text-gray);
                font-size: 13px;
                line-height: 1.6;
            }
            
            /* Roadmap Accordion */
            .roadmap-accordion {
                max-width: 800px;
                margin: 0 auto;
            }
            
            .accordion-item {
                background: white;
                border-radius: 16px;
                margin-bottom: 12px;
                border: 1px solid var(--border-color);
                overflow: hidden;
                transition: all 0.3s ease;
            }
            
            .accordion-item:hover {
                border-color: var(--primary);
            }
            
            .accordion-header {
                width: 100%;
                padding: 20px 24px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                background: none;
                border: none;
                cursor: pointer;
                font-size: 16px;
                font-weight: 600;
                color: var(--text-dark);
                text-align: left;
            }
            
            .accordion-title {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            
            .accordion-icon {
                font-size: 24px;
            }
            
            .accordion-arrow {
                transition: transform 0.3s ease;
                color: var(--text-gray);
            }
            
            .accordion-item.open .accordion-arrow {
                transform: rotate(180deg);
            }
            
            .accordion-content {
                max-height: 0;
                overflow: hidden;
                transition: max-height 0.3s ease, padding 0.3s ease;
                padding: 0 24px;
            }
            
            .accordion-item.open .accordion-content {
                max-height: 500px;
                padding: 0 24px 24px 24px;
            }
            
            .feature-list {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            
            .feature-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 16px;
                background: var(--bg-light);
                border-radius: 10px;
                font-size: 14px;
                color: var(--text-dark);
            }
            
            .feature-item .badge {
                flex-shrink: 0;
            }

            /* Advantages Section */
            .advantages {
                padding: 100px 0;
                background: linear-gradient(180deg, #ffffff 0%, var(--bg-light) 100%);
            }
            
            .advantages-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
                gap: 24px;
                max-width: 1100px;
                margin: 0 auto;
            }
            
            .advantage-card {
                background: white;
                border-radius: 20px;
                padding: 28px;
                border: 1px solid var(--border-color);
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            
            .advantage-card:hover {
                transform: translateY(-8px);
                box-shadow: 0 20px 40px rgba(16, 185, 129, 0.15);
                border-color: var(--primary);
            }
            
            .advantage-icon {
                font-size: 40px;
                margin-bottom: 16px;
            }
            
            .advantage-card h4 {
                font-size: 18px;
                margin-bottom: 12px;
            }
            
            .advantage-card p {
                color: var(--text-gray);
                font-size: 14px;
                line-height: 1.6;
            }
            
            /* Pricing Section */
            .pricing {
                padding: 100px 0;
                background: var(--bg-light);
            }
            
            .pricing-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 24px;
                max-width: 1000px;
                margin: 0 auto;
            }
            
            .pricing-card {
                background: white;
                border-radius: 24px;
                padding: 32px;
                border: 2px solid var(--border-color);
                position: relative;
                transition: all 0.3s;
            }
            
            .pricing-card.featured {
                border-color: var(--primary);
                transform: scale(1.05);
                box-shadow: 0 25px 60px rgba(16, 185, 129, 0.2);
            }
            
            .pricing-badge {
                position: absolute;
                top: -12px;
                left: 50%;
                transform: translateX(-50%);
                background: var(--primary);
                color: white;
                padding: 6px 16px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 600;
            }
            
            .pricing-header {
                text-align: center;
                margin-bottom: 24px;
            }
            
            .pricing-header h3 {
                font-size: 20px;
                margin-bottom: 12px;
            }
            
            .pricing-price {
                display: flex;
                align-items: baseline;
                justify-content: center;
                gap: 4px;
            }
            
            .price-amount {
                font-size: 42px;
                font-weight: 700;
                color: var(--primary);
            }
            
            .price-currency {
                font-size: 16px;
                color: var(--text-gray);
            }
            
            .pricing-desc {
                color: var(--text-gray);
                font-size: 14px;
                margin-top: 8px;
            }
            
            .pricing-features {
                list-style: none;
                margin-bottom: 24px;
            }
            
            .pricing-features li {
                padding: 10px 0;
                border-bottom: 1px solid var(--border-color);
                font-size: 14px;
            }
            
            .pricing-features li:last-child {
                border-bottom: none;
            }
            
            .btn-full {
                width: 100%;
                justify-content: center;
            }
            
            /* Footer */
            footer {
                padding: 48px 0;
                background: var(--bg-light);
                border-top: 1px solid var(--border-color);
            }
            
            .footer-content {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 24px;
            }
            
            footer p {
                color: var(--text-gray);
                font-size: 14px;
            }
            
            .footer-links {
                display: flex;
                gap: 24px;
            }
            
            .footer-links a {
                color: var(--text-gray);
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
                transition: color 0.2s;
            }
            
            .footer-links a:hover {
                color: var(--primary);
            }
            
            /* Mobile Styles */
            @media (max-width: 900px) {
                .features-layout {
                    grid-template-columns: 1fr;
                    gap: 32px;
                }
                
                .demo-preview {
                    position: relative;
                    top: 0;
                    order: -1;
                }
                
                .demo-container {
                    grid-template-columns: 1fr;
                }
                
                .scenario-buttons {
                    flex-direction: row;
                    flex-wrap: wrap;
                }
                
                .scenario-btn {
                    flex: 1;
                    min-width: 140px;
                    justify-content: center;
                    text-align: center;
                    padding: 12px 16px;
                }
                
                .scenario-btn .emoji {
                    font-size: 20px;
                }
                
                .advantages-grid {
                    grid-template-columns: 1fr;
                }
                
                .pricing-grid {
                    grid-template-columns: 1fr;
                    max-width: 400px;
                }
                
                .pricing-card.featured {
                    transform: none;
                }
            }
            
            @media (max-width: 768px) {
                nav {
                    gap: 8px;
                }
                
                nav a:not(.btn) {
                    display: none;
                }
                
                .btn-small {
                    padding: 8px 14px;
                    font-size: 13px;
                }
                
                header .container {
                    padding: 0 16px;
                }
                
                .hero {
                    padding: 100px 0 60px;
                }
                
                .hero p {
                    font-size: 16px;
                }
                
                .features, .demo-section, .coming-soon, .advantages, .pricing, .roadmap {
                    padding: 60px 0;
                }
                
                .trust-badges {
                    flex-direction: column;
                    gap: 16px;
                }
                
                .feature-item {
                    padding: 16px;
                }
                
                .feature-item .icon {
                    width: 40px;
                    height: 40px;
                    font-size: 20px;
                }
                
                .feature-item .info h3 {
                    font-size: 14px;
                }
                
                .feature-item .info p {
                    font-size: 12px;
                }
                
                .demo-body {
                    min-height: 280px;
                    max-height: 320px;
                }
                
                .footer-content {
                    flex-direction: column;
                    text-align: center;
                }
                
                .footer-links {
                    flex-wrap: wrap;
                    justify-content: center;
                }
                
                /* Partner section tablet */
                .partner-grid {
                    grid-template-columns: 1fr !important;
                    gap: 32px !important;
                }
            }
            
            @media (max-width: 480px) {
                .container {
                    padding: 0 16px;
                }
                
                .hero h1 {
                    font-size: 32px;
                }
                
                .hero-actions {
                    flex-direction: column;
                    align-items: stretch;
                }
                
                .btn {
                    justify-content: center;
                }
                
                .scenario-btn {
                    min-width: 100%;
                }
                
                /* Partner section mobile */
                .partner-grid {
                    grid-template-columns: 1fr !important;
                    gap: 24px !important;
                }
                
                .partner-stats {
                    grid-template-columns: 1fr !important;
                    gap: 12px !important;
                }
            }
        </style>
    </head>
    <body>
        <!-- Header -->
        <header>
            <div class="container">
                <a href="/" class="logo">🤖 AIntento</a>
                <nav>
                    @if (Route::has('login'))
                        @auth
                            <a href="{{ url('/dashboard') }}" class="btn btn-primary btn-small">Dashboard</a>
                        @else
                            <a href="{{ route('login') }}">Увійти</a>
                            @if (Route::has('register'))
                                <a href="{{ route('register') }}" class="btn btn-primary btn-small">Реєстрація</a>
                            @endif
                        @endauth
                    @endif
                </nav>
            </div>
        </header>

        <!-- Hero Section -->
        <section class="hero">
            <div class="container">
                <div data-aos="fade-up" data-aos-duration="800">
                    <h1>AI що знає ваші<br>товари</h1>
                    <p>Не просто чат-бот — розумний консультант, який дійсно розуміє ваш каталог. Підбирає, рекомендує, продає. 24/7 без перерв і вихідних.</p>
                </div>
                <div class="hero-actions" data-aos="fade-up" data-aos-delay="200">
                    @auth
                        <a href="{{ url('/dashboard') }}" class="btn btn-primary">Перейти в Dashboard</a>
                    @else
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="btn btn-primary">Спробувати 14 днів безкоштовно</a>
                        @endif
                    @endauth
                    <a href="#demo" class="btn btn-outline">Подивитись демо</a>
                </div>
                
                <!-- Trust badges -->
                <div class="trust-badges" data-aos="fade-up" data-aos-delay="400">
                    <div class="trust-badge">
                        <span class="trust-icon">🇺🇦</span>
                        <span>Українська локалізація</span>
                    </div>
                    <div class="trust-badge">
                        <span class="trust-icon">⚡</span>
                        <span>Відповідь &lt; 2 сек</span>
                    </div>
                    <div class="trust-badge">
                        <span class="trust-icon">🔗</span>
                        <span>Horoshop інтеграція</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section class="features" id="features">
            <div class="container">
                <h2 class="section-title" data-aos="fade-up">Що вміє AIntento</h2>
                <p class="section-subtitle" data-aos="fade-up" data-aos-delay="100">Не обіцянки — реальні функції, що працюють прямо зараз</p>
                
                <div class="features-layout">
                    <div class="features-list">
                        <div class="feature-item active" onclick="showFeatureDemo(0)" data-aos="fade-right" data-aos-delay="100">
                            <div class="icon">🤖</div>
                            <div class="info">
                                <h3>Розумний підбір товарів <span class="badge badge-success">Працює</span></h3>
                                <p>GPT аналізує запит і підбирає товари з урахуванням бюджету, розміру, кольору</p>
                            </div>
                        </div>
                        
                        <div class="feature-item" onclick="showFeatureDemo(1)" data-aos="fade-right" data-aos-delay="200">
                            <div class="icon">⚡</div>
                            <div class="info">
                                <h3>Streaming відповіді <span class="badge badge-success">Працює</span></h3>
                                <p>Миттєва реакція — текст з'являється посимвольно як у ChatGPT</p>
                            </div>
                        </div>
                        
                        <div class="feature-item" onclick="showFeatureDemo(2)" data-aos="fade-right" data-aos-delay="300">
                            <div class="icon">🧠</div>
                            <div class="info">
                                <h3>Контекстна пам'ять <span class="badge badge-success">Працює</span></h3>
                                <p>Бот запам'ятовує історію розмови, розміри та бюджет</p>
                            </div>
                        </div>
                        
                        <div class="feature-item" onclick="showFeatureDemo(3)" data-aos="fade-right" data-aos-delay="400">
                            <div class="icon">🔍</div>
                            <div class="info">
                                <h3>Meilisearch пошук <span class="badge badge-success">Працює</span></h3>
                                <p>Блискавичний пошук за 10-50ms з виправленням помилок</p>
                            </div>
                        </div>
                        
                        <div class="feature-item" onclick="showFeatureDemo(4)" data-aos="fade-right" data-aos-delay="500">
                            <div class="icon">🎯</div>
                            <div class="info">
                                <h3>Proactive тригери <span class="badge badge-success">Працює</span></h3>
                                <p>Бот сам пропонує уточнити розмір, колір чи питає про оформлення</p>
                            </div>
                        </div>
                        
                        <div class="feature-item" onclick="showFeatureDemo(5)" data-aos="fade-right" data-aos-delay="600">
                            <div class="icon">🎨</div>
                            <div class="info">
                                <h3>Кастомний віджет <span class="badge badge-success">Працює</span></h3>
                                <p>Кольори, шрифти, положення — все під ваш бренд</p>
                            </div>
                        </div>
                        
                        <div class="feature-item" onclick="showFeatureDemo(6)" data-aos="fade-right" data-aos-delay="700">
                            <div class="icon">📊</div>
                            <div class="info">
                                <h3>Детальна аналітика <span class="badge badge-success">Працює</span></h3>
                                <p>Статистика діалогів, конверсії, популярні запити</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="demo-preview" data-aos="fade-left" data-aos-delay="300">
                        <div class="demo-window">
                            <div class="demo-header">
                                <span class="demo-header-icon">🤖</span>
                                <div>
                                    <h4>AIntento Assistant</h4>
                                    <span>Онлайн • Відповідаю миттєво</span>
                                </div>
                            </div>
                            <div class="demo-body" id="feature-demo-body">
                                <!-- Demo content will be inserted here -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Interactive Demo Section -->
        <section class="demo-section" id="demo">
            <div class="container">
                <div class="demo-container">
                    <div class="demo-info" data-aos="fade-right">
                        <h2>Спробуйте прямо зараз</h2>
                        <p>Оберіть сценарій і подивіться, як AIntento веде діалог з клієнтом магазину.</p>
                        <div class="scenario-buttons">
                            <button class="scenario-btn active" onclick="runScenario(0)">
                                <span class="emoji">🎒</span>
                                <span>Пошук рюкзака</span>
                            </button>
                            <button class="scenario-btn" onclick="runScenario(1)">
                                <span class="emoji">👕</span>
                                <span>Підбір одягу</span>
                            </button>
                            <button class="scenario-btn" onclick="runScenario(2)">
                                <span class="emoji">❓</span>
                                <span>Питання про доставку</span>
                            </button>
                        </div>
                    </div>
                    <div class="demo-window" data-aos="fade-left">
                        <div class="demo-header">
                            <span class="demo-header-icon">🤖</span>
                            <div>
                                <h4>AIntento Assistant</h4>
                                <span>Онлайн • Відповідаю миттєво</span>
                            </div>
                        </div>
                        <div class="demo-body" id="demo-chat-body">
                            <div class="demo-message">
                                <div class="demo-avatar">🤖</div>
                                <div class="demo-bubble">Привіт! Я AI-асистент магазину. Чим можу допомогти?</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Why AIntento Section (Advantages) -->
        <section class="advantages">
            <div class="container">
                <h2 class="section-title" data-aos="fade-up">Чому AIntento</h2>
                <p class="section-subtitle" data-aos="fade-up" data-aos-delay="100">Глибока інтеграція, а не черговий чат-бот з шаблонами</p>
                
                <div class="advantages-grid">
                    <div class="advantage-card" data-aos="zoom-in" data-aos-delay="100">
                        <div class="advantage-icon">🎯</div>
                        <h4>Знає ваші товари</h4>
                        <p>AI індексує весь каталог: назви, описи, характеристики, залишки. Відповідає на питання про конкретні товари.</p>
                    </div>

                    <div class="advantage-card" data-aos="zoom-in" data-aos-delay="200">
                        <div class="advantage-icon">💰</div>
                        <h4>30× дешевше менеджера</h4>
                        <p>Менеджер — 25 000 грн/міс + навантаження. AI-бот від 799 грн працює 24/7 без відпусток і лікарняних.</p>
                    </div>

                    <div class="advantage-card" data-aos="zoom-in" data-aos-delay="300">
                        <div class="advantage-icon">🇺🇦</div>
                        <h4>Для UA ринку</h4>
                        <p>Українська локалізація, суржик-толерантність, розуміння місцевих реалій.</p>
                    </div>

                    <div class="advantage-card" data-aos="zoom-in" data-aos-delay="400">
                        <div class="advantage-icon">⚡</div>
                        <h4>Підключення за годину</h4>
                        <p>Horoshop API інтеграція автоматично. Один скрипт на сайт — готово.</p>
                    </div>

                    <div class="advantage-card" data-aos="zoom-in" data-aos-delay="500">
                        <div class="advantage-icon">🧠</div>
                        <h4>Не скриптовий</h4>
                        <p>AI на базі OpenAI (GPT-4o/5). Реальний AI, а не дерево відповідей з 90-х.</p>
                    </div>

                    <div class="advantage-card" data-aos="zoom-in" data-aos-delay="600">
                        <div class="advantage-icon">📈</div>
                        <h4>Proactive продажі</h4>
                        <p>Тригери: "Залишилось 3 шт", "Який розмір?", "Оформлюємо?" — сам штовхає до покупки.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Pricing Section -->
        <section class="pricing" id="pricing">
            <div class="container">
                <h2 class="section-title" data-aos="fade-up">Прозоре ціноутворення</h2>
                <p class="section-subtitle" data-aos="fade-up" data-aos-delay="100">14 днів безкоштовно, без картки</p>
                
                <div class="pricing-grid">
                    <div class="pricing-card" data-aos="fade-up" data-aos-delay="100">
                        <div class="pricing-header">
                            <h3>Starter</h3>
                            <div class="pricing-price">
                                <span class="price-amount">799</span>
                                <span class="price-currency">₴/міс</span>
                            </div>
                            <p class="pricing-desc">~$20 • Для малого бізнесу</p>
                        </div>
                        <ul class="pricing-features">
                            <li>✓ 1 000 повідомлень/міс</li>
                            <li>✓ До 500 товарів</li>
                            <li>✓ Базова аналітика</li>
                            <li>✓ Email підтримка</li>
                        </ul>
                        @auth
                            <a href="{{ url('/dashboard') }}" class="btn btn-outline btn-full">Перейти в Dashboard</a>
                        @else
                            <a href="{{ route('register') }}" class="btn btn-outline btn-full">Спробувати</a>
                        @endauth
                    </div>

                    <div class="pricing-card featured" data-aos="fade-up" data-aos-delay="200">
                        <div class="pricing-badge">Популярний</div>
                        <div class="pricing-header">
                            <h3>Pro</h3>
                            <div class="pricing-price">
                                <span class="price-amount">1 999</span>
                                <span class="price-currency">₴/міс</span>
                            </div>
                            <p class="pricing-desc">~$50 • Для зростаючих магазинів</p>
                        </div>
                        <ul class="pricing-features">
                            <li>✓ 5 000 повідомлень/міс</li>
                            <li>✓ До 10 000 товарів</li>
                            <li>✓ Розширена аналітика</li>
                            <li>✓ Proactive тригери</li>
                            <li>✓ Пріоритетна підтримка</li>
                        </ul>
                        @auth
                            <a href="{{ url('/dashboard') }}" class="btn btn-primary btn-full">Перейти в Dashboard</a>
                        @else
                            <a href="{{ route('register') }}" class="btn btn-primary btn-full">Почати безкоштовно</a>
                        @endauth
                    </div>

                    <div class="pricing-card" data-aos="fade-up" data-aos-delay="300">
                        <div class="pricing-header">
                            <h3>Enterprise</h3>
                            <div class="pricing-price">
                                <span class="price-amount">Custom</span>
                            </div>
                            <p class="pricing-desc">Для великих каталогів</p>
                        </div>
                        <ul class="pricing-features">
                            <li>✓ Необмежені діалоги</li>
                            <li>✓ Необмежені товари</li>
                            <li>✓ Кастомні інтеграції</li>
                            <li>✓ Виділений менеджер</li>
                            <li>✓ SLA гарантії</li>
                        </ul>
                        <a href="https://t.me/AIntento" target="_blank" class="btn btn-outline btn-full">💬 Написати в Telegram</a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Roadmap Section -->
        <section class="roadmap" id="roadmap">
            <div class="container">
                <h2 class="section-title" data-aos="fade-up">🚀 Дорожня карта</h2>
                <p class="section-subtitle" data-aos="fade-up" data-aos-delay="100">Більше ніж чат-бот — AI-центр керування продажами</p>
                
                <!-- Platforms Grid -->
                <h3 style="font-size: 20px; margin-bottom: 24px; color: var(--text-dark);" data-aos="fade-up">📦 Платформи та інтеграції</h3>
                <div class="coming-grid">
                    <div class="coming-card" data-aos="fade-up" data-aos-delay="100" style="border: 2px solid var(--primary-color); background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, transparent 100%);">
                        <h4>🛒 Horoshop <span class="badge" style="background: var(--primary-color); color: white;">✓ Готово</span></h4>
                        <p>Повна інтеграція з українською e-commerce платформою. Автоматична синхронізація каталогу.</p>
                    </div>

                    <div class="coming-card" data-aos="fade-up" data-aos-delay="200">
                        <h4>🛍️ Shopify <span class="badge badge-soon">Q1 2026</span></h4>
                        <p>Офіційний Shopify App для глобальних магазинів. OAuth, webhook синхронізація.</p>
                    </div>

                    <div class="coming-card" data-aos="fade-up" data-aos-delay="300">
                        <h4>🔮 WooCommerce <span class="badge badge-soon">Q1 2026</span></h4>
                        <p>WordPress плагін для найпопулярнішої CMS. Інтеграція з REST API.</p>
                    </div>

                    <div class="coming-card" data-aos="fade-up" data-aos-delay="400">
                        <h4>🇺🇦 Prom.ua <span class="badge badge-soon">Q2 2026</span></h4>
                        <p>Підключення до найбільшого маркетплейсу України. Синхронізація товарів та замовлень.</p>
                    </div>

                    <div class="coming-card" data-aos="fade-up" data-aos-delay="500">
                        <h4>🌹 Rozetka <span class="badge badge-soon">Q2 2026</span></h4>
                        <p>Інтеграція з Rozetka Marketplace для продавців на майданчику.</p>
                    </div>

                    <div class="coming-card" data-aos="fade-up" data-aos-delay="600">
                        <h4>🛠 OpenCart <span class="badge badge-soon">Q2 2026</span></h4>
                        <p>Модуль для OcStore / OpenCart 3-4. Популярна CMS в Україні.</p>
                    </div>
                </div>
                
                <!-- Future Features Accordion -->
                <div class="roadmap-features" style="margin-top: 48px;" data-aos="fade-up">
                    <h3 style="font-size: 20px; margin-bottom: 24px; color: var(--text-dark);">🎯 Майбутні можливості</h3>
                    
                    <!-- Accordion -->
                    <div class="roadmap-accordion">
                        <!-- Omnichannel -->
                        <div class="accordion-item">
                            <button class="accordion-header" onclick="toggleAccordion(this)">
                                <div class="accordion-title">
                                    <span class="accordion-icon">📱</span>
                                    <span>Омніканальність</span>
                                    <span class="badge badge-soon">Q1-Q2 2026</span>
                                </div>
                                <svg class="accordion-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M6 9l6 6 6-6"/>
                                </svg>
                            </button>
                            <div class="accordion-content">
                                <p style="margin-bottom: 16px; color: var(--text-muted);">Єдиний inbox для всіх каналів комунікації з клієнтами:</p>
                                <div class="feature-list">
                                    <div class="feature-item"><span class="badge" style="background: #E4405F; color: white;">Instagram</span> DM інтеграція через Meta API</div>
                                    <div class="feature-item"><span class="badge" style="background: #0088cc; color: white;">Telegram</span> Bot для бізнесу</div>
                                    <div class="feature-item"><span class="badge" style="background: #1877F2; color: white;">Facebook</span> Messenger інтеграція</div>
                                    <div class="feature-item"><span class="badge" style="background: #7360F2; color: white;">Viber</span> Бізнес-чати</div>
                                    <div class="feature-item"><span class="badge" style="background: #25D366; color: white;">WhatsApp</span> Business API</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- SEO & Content -->
                        <div class="accordion-item">
                            <button class="accordion-header" onclick="toggleAccordion(this)">
                                <div class="accordion-title">
                                    <span class="accordion-icon">📊</span>
                                    <span>AI SEO & Контент</span>
                                    <span class="badge badge-soon">Q2-Q3 2026</span>
                                </div>
                                <svg class="accordion-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M6 9l6 6 6-6"/>
                                </svg>
                            </button>
                            <div class="accordion-content">
                                <p style="margin-bottom: 16px; color: var(--text-muted);">Автоматична оптимізація контенту:</p>
                                <div class="feature-list">
                                    <div class="feature-item">🎯 <strong>SEO Score товарів</strong> — оцінка та рекомендації для кожного товару</div>
                                    <div class="feature-item">✏️ <strong>AI Copywriting</strong> — генерація унікальних описів товарів</div>
                                    <div class="feature-item">🏷️ <strong>Meta Tags</strong> — автоматичні title, description, alt-тексти</div>
                                    <div class="feature-item">🔍 <strong>Keyword Analysis</strong> — аналіз конкурентів та підбір ключових слів</div>
                                    <div class="feature-item">📝 <strong>Тексти категорій</strong> — SEO-тексти для розділів магазину</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Smart Analytics -->
                        <div class="accordion-item">
                            <button class="accordion-header" onclick="toggleAccordion(this)">
                                <div class="accordion-title">
                                    <span class="accordion-icon">📈</span>
                                    <span>Smart Analytics</span>
                                    <span class="badge badge-soon">Q3 2026</span>
                                </div>
                                <svg class="accordion-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M6 9l6 6 6-6"/>
                                </svg>
                            </button>
                            <div class="accordion-content">
                                <p style="margin-bottom: 16px; color: var(--text-muted);">Розумна аналітика з AI-рекомендаціями:</p>
                                <div class="feature-list">
                                    <div class="feature-item">🎯 <strong>Conversion Funnel</strong> — де втрачаються клієнти</div>
                                    <div class="feature-item">💬 <strong>Chat-to-Sale</strong> — атрибуція продажів з чату</div>
                                    <div class="feature-item">💡 <strong>AI Insights</strong> — автоматичні рекомендації що покращити</div>
                                    <div class="feature-item">🔥 <strong>Lead Scoring</strong> — визначення "гарячих" клієнтів</div>
                                    <div class="feature-item">📊 <strong>Product Performance</strong> — аналітика ефективності товарів</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Sales Automation -->
                        <div class="accordion-item">
                            <button class="accordion-header" onclick="toggleAccordion(this)">
                                <div class="accordion-title">
                                    <span class="accordion-icon">🛒</span>
                                    <span>Автоматизація продажів</span>
                                    <span class="badge badge-soon">Q3-Q4 2026</span>
                                </div>
                                <svg class="accordion-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M6 9l6 6 6-6"/>
                                </svg>
                            </button>
                            <div class="accordion-content">
                                <p style="margin-bottom: 16px; color: var(--text-muted);">Збільшення продажів на автопілоті:</p>
                                <div class="feature-list">
                                    <div class="feature-item">🛍️ <strong>Smart Bundles</strong> — AI пропонує комплекти товарів</div>
                                    <div class="feature-item">🛒 <strong>Abandoned Cart</strong> — повернення покинутих кошиків</div>
                                    <div class="feature-item">📦 <strong>Restock Alerts</strong> — "товар знову в наявності"</div>
                                    <div class="feature-item">💸 <strong>Price Drop</strong> — автоповідомлення про знижки</div>
                                    <div class="feature-item">⭐ <strong>Review Requests</strong> — автозбір відгуків</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Marketing -->
                        <div class="accordion-item">
                            <button class="accordion-header" onclick="toggleAccordion(this)">
                                <div class="accordion-title">
                                    <span class="accordion-icon">📣</span>
                                    <span>Marketing Automation</span>
                                    <span class="badge badge-soon">2027</span>
                                </div>
                                <svg class="accordion-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M6 9l6 6 6-6"/>
                                </svg>
                            </button>
                            <div class="accordion-content">
                                <p style="margin-bottom: 16px; color: var(--text-muted);">Повноцінна маркетингова автоматизація:</p>
                                <div class="feature-list">
                                    <div class="feature-item">🎯 <strong>Smart Ad Feed</strong> — оптимізований фід для Google/Facebook Ads</div>
                                    <div class="feature-item">✍️ <strong>AI Ad Copy</strong> — генерація текстів оголошень</div>
                                    <div class="feature-item">📱 <strong>Social Auto-posting</strong> — публікація товарів в соцмережі</div>
                                    <div class="feature-item">📧 <strong>Email Marketing</strong> — персоналізовані розсилки</div>
                                    <div class="feature-item">🔬 <strong>A/B Testing</strong> — автотести креативів</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-8" data-aos="fade-up" data-aos-delay="200">
                    <p style="color: var(--text-muted); margin-bottom: 16px;">Не знайшли свою платформу чи потрібну функцію?</p>
                    <a href="https://t.me/AIntento" target="_blank" class="btn btn-outline">💬 Напишіть нам — додамо в пріоритет!</a>
                </div>
            </div>
        </section>

        <!-- Partner Program Section -->
        <section class="partner-program" id="partner" style="padding: 80px 0; background: linear-gradient(135deg, #065f46 0%, #047857 50%, #10b981 100%); color: white;">
            <div class="container">
                <div class="partner-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 48px; align-items: center;" data-aos="fade-up">
                    <div>
                        <span style="display: inline-block; background: rgba(255,255,255,0.2); padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 600; margin-bottom: 16px;">🤝 Партнерська програма</span>
                        <h2 style="font-size: clamp(28px, 4vw, 40px); margin-bottom: 20px; font-family: 'Space Grotesk', sans-serif;">Заробляйте з AIntento</h2>
                        <p style="font-size: 18px; opacity: 0.9; margin-bottom: 24px; line-height: 1.7;">
                            Приведіть клієнта — отримуйте <strong style="color: #fef3c7; font-size: 24px;">15% від його платежів назавжди</strong>. 
                            Без обмежень за кількістю рефералів.
                        </p>
                        
                        <div class="partner-stats" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 32px;">
                            <div style="background: rgba(255,255,255,0.15); padding: 20px 16px; border-radius: 12px; text-align: center;">
                                <div style="font-size: 32px; font-weight: 700; font-family: 'Space Grotesk', sans-serif;">15%</div>
                                <div style="font-size: 13px; opacity: 0.9;">від кожного платежу</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.15); padding: 20px 16px; border-radius: 12px; text-align: center;">
                                <div style="font-size: 32px; font-weight: 700; font-family: 'Space Grotesk', sans-serif;">∞</div>
                                <div style="font-size: 13px; opacity: 0.9;">назавжди</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.15); padding: 20px 16px; border-radius: 12px; text-align: center;">
                                <div style="font-size: 32px; font-weight: 700; font-family: 'Space Grotesk', sans-serif;">5+</div>
                                <div style="font-size: 13px; opacity: 0.9;">рефералів без ліміту</div>
                            </div>
                        </div>

                        <a href="https://t.me/AIntento" target="_blank" class="btn" style="background: white; color: #065f46; font-size: 16px; padding: 16px 32px;">
                            💬 Стати партнером
                        </a>
                    </div>
                    
                    <div style="background: rgba(255,255,255,0.1); border-radius: 24px; padding: 32px; backdrop-filter: blur(10px);">
                        <h3 style="font-size: 18px; margin-bottom: 24px; font-family: 'Space Grotesk', sans-serif;">Як це працює</h3>
                        <div style="display: flex; flex-direction: column; gap: 20px;">
                            <div style="display: flex; gap: 16px; align-items: flex-start;">
                                <div style="width: 36px; height: 36px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">1</div>
                                <div>
                                    <strong style="display: block; margin-bottom: 4px;">Отримайте унікальне посилання</strong>
                                    <span style="font-size: 14px; opacity: 0.8;">Напишіть нам у Telegram і отримайте персональний реферальний код</span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 16px; align-items: flex-start;">
                                <div style="width: 36px; height: 36px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">2</div>
                                <div>
                                    <strong style="display: block; margin-bottom: 4px;">Рекомендуйте AIntento</strong>
                                    <span style="font-size: 14px; opacity: 0.8;">Діліться з власниками інтернет-магазинів, веб-студіями, агенціями</span>
                                </div>
                            </div>
                            <div style="display: flex; gap: 16px; align-items: flex-start;">
                                <div style="width: 36px; height: 36px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0;">3</div>
                                <div>
                                    <strong style="display: block; margin-bottom: 4px;">Отримуйте виплати</strong>
                                    <span style="font-size: 14px; opacity: 0.8;">15% від кожного платежу рефералів — щомісячно на вашу карту</span>
                                </div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.2);">
                            <div style="font-size: 13px; opacity: 0.8;">💡 Приклад: 5 клієнтів на Pro плані = <strong>1 500 ₴/міс</strong> пасивного доходу</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer>
            <div class="container">
                <div class="footer-content">
                    <div>
                        <a href="/" class="logo" style="font-size: 20px;">🤖 AIntento</a>
                        <p style="margin-top: 8px;">AI-асистент, що знає ваші товари</p>
                    </div>
                    <div class="footer-links">
                        <a href="#features">Функції</a>
                        <a href="#pricing">Ціни</a>
                        <a href="#partner">Партнерам</a>
                        <a href="https://t.me/AIntento" target="_blank">💬 Підтримка</a>
                    </div>
                </div>
                <div style="display: flex; justify-content: center; gap: 24px; margin-top: 24px; flex-wrap: wrap;">
                    <a href="/privacy" style="color: var(--text-gray); font-size: 13px; text-decoration: none;">Політика конфіденційності</a>
                    <a href="/terms" style="color: var(--text-gray); font-size: 13px; text-decoration: none;">Умови використання</a>
                    <a href="/refund" style="color: var(--text-gray); font-size: 13px; text-decoration: none;">Повернення коштів</a>
                    <a href="/offer" style="color: var(--text-gray); font-size: 13px; text-decoration: none;">Публічна оферта</a>
                </div>
                <div style="text-align: center; margin-top: 16px; color: var(--text-gray); font-size: 12px;">
                    <p>ФОП Цяцько В.О. • ІПН 3547513490 • <a href="tel:+380936490518" style="color: var(--text-gray);">+38 (093) 649-05-18</a> • <a href="mailto:v.tsiatsko@gmail.com" style="color: var(--text-gray);">v.tsiatsko@gmail.com</a></p>
                </div>
                <div style="text-align: center; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-color);">
                    <p>© {{ date('Y') }} AIntento — зроблено в 🇺🇦 Україні</p>
                </div>
            </div>
        </footer>

        <script>
            // Feature demos data
            const featureDemos = [
                // 0: Smart search
                [
                    { role: 'user', text: 'Шукаю тактичний рюкзак до 3000 грн' },
                    { role: 'bot', text: 'Ось що підібрав для вас:', products: [
                        { name: 'Рюкзак M-TAC Assault Pack', price: '2 450 ₴', emoji: '🎒' },
                        { name: 'Рюкзак Helikon-Tex EDC', price: '2 890 ₴', emoji: '🎒' }
                    ]}
                ],
                // 1: Streaming
                [
                    { role: 'user', text: 'Розкажи про цей товар' },
                    { role: 'bot', text: 'Цей рюкзак має об\'єм 35 літрів, виготовлений з водостійкого нейлону 1000D. Є MOLLE-система для кріплення підсумків. ⚡', streaming: true }
                ],
                // 2: Context memory
                [
                    { role: 'user', text: 'Покажи футболки розмір L' },
                    { role: 'bot', text: 'Ось футболки розміру L...' },
                    { role: 'user', text: 'А є в чорному?' },
                    { role: 'bot', text: 'Так! Ось чорні футболки розміру L:', products: [
                        { name: 'Футболка M-TAC Black L', price: '650 ₴', emoji: '👕' }
                    ]}
                ],
                // 3: Meilisearch
                [
                    { role: 'user', text: 'тактічна разгрузка олива' },
                    { role: 'bot', text: '🔍 Знайдено за 12ms:', products: [
                        { name: 'Розвантажувальний жилет Olive', price: '3 200 ₴', emoji: '🦺' }
                    ], note: '✓ Виправлено: "тактічна" → "тактична"' }
                ],
                // 4: Proactive triggers
                [
                    { role: 'user', text: 'Подобається ця куртка' },
                    { role: 'bot', text: 'Чудовий вибір! 🎯', products: [
                        { name: 'Тактична куртка SoftShell', price: '2 890 ₴', emoji: '🧥' }
                    ]},
                    { role: 'bot', text: '⚠️ Залишилось лише 3 шт. Який розмір вам потрібен — S, M, L, XL?', trigger: true }
                ],
                // 5: Customization
                [
                    { role: 'bot', text: 'Віджет можна налаштувати під ваш бренд:', themes: true }
                ],
                // 6: Analytics
                [
                    { role: 'bot', text: '📊 Статистика за сьогодні:', stats: true },
                    { role: 'bot', text: 'Топ запити:\n• "плитоноска" — 47 разів\n• "футболка олива" — 32\n• "ціна доставки" — 28' }
                ]
            ];
            
            let currentFeature = 0;
            
            function showFeatureDemo(index) {
                // Update active state
                document.querySelectorAll('.feature-item').forEach((item, i) => {
                    item.classList.toggle('active', i === index);
                });
                
                currentFeature = index;
                const demoBody = document.getElementById('feature-demo-body');
                demoBody.innerHTML = '';
                
                // Play the demo
                playFeatureDemo(featureDemos[index], demoBody);
            }
            
            function playFeatureDemo(messages, container, index = 0) {
                if (index >= messages.length) return;
                
                const message = messages[index];
                
                // Add typing indicator for bot
                if (message.role === 'bot' && index > 0) {
                    showTyping(container, () => {
                        addDemoMessage(container, message);
                        setTimeout(() => playFeatureDemo(messages, container, index + 1), 800);
                    });
                } else {
                    setTimeout(() => {
                        addDemoMessage(container, message);
                        setTimeout(() => playFeatureDemo(messages, container, index + 1), 600);
                    }, index === 0 ? 0 : 400);
                }
            }
            
            function showTyping(container, callback) {
                const typingDiv = document.createElement('div');
                typingDiv.className = 'demo-message';
                typingDiv.innerHTML = '<div class="demo-avatar">🤖</div><div class="typing-indicator"><span></span><span></span><span></span></div>';
                container.appendChild(typingDiv);
                container.scrollTop = container.scrollHeight;
                
                setTimeout(() => {
                    typingDiv.remove();
                    callback();
                }, 800);
            }
            
            function addDemoMessage(container, message) {
                const div = document.createElement('div');
                div.className = 'demo-message ' + (message.role === 'user' ? 'user' : '');
                
                let content = '<div class="demo-avatar">' + (message.role === 'user' ? '👤' : '🤖') + '</div><div class="demo-bubble">';
                
                if (message.text) {
                    content += message.text;
                }
                
                if (message.products) {
                    message.products.forEach(p => {
                        content += '<div class="demo-product"><div class="demo-product-img">' + p.emoji + '</div><div class="demo-product-info"><h5>' + p.name + '</h5><span>' + p.price + '</span></div></div>';
                    });
                }
                
                if (message.note) {
                    content += '<div style="font-size: 11px; color: var(--text-gray); margin-top: 8px;">' + message.note + '</div>';
                }
                
                if (message.trigger) {
                    div.querySelector('.demo-bubble') && (div.style.animation = 'pulse 2s infinite');
                }
                
                if (message.themes) {
                    content += '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 10px;">';
                    content += '<div style="background: linear-gradient(135deg, #10b981, #059669); padding: 12px 8px; border-radius: 8px; color: white; text-align: center; font-size: 11px;">🌿 Green</div>';
                    content += '<div style="background: linear-gradient(135deg, #6366f1, #4f46e5); padding: 12px 8px; border-radius: 8px; color: white; text-align: center; font-size: 11px;">💜 Purple</div>';
                    content += '<div style="background: linear-gradient(135deg, #1f2937, #111827); padding: 12px 8px; border-radius: 8px; color: white; text-align: center; font-size: 11px;">🖤 Dark</div>';
                    content += '</div>';
                }
                
                if (message.stats) {
                    content += '<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-top: 10px;">';
                    content += '<div style="background: var(--bg-light); padding: 12px; border-radius: 8px; text-align: center;"><div style="font-size: 20px; font-weight: 700; color: var(--primary);">1,247</div><div style="font-size: 10px; color: var(--text-gray);">Діалогів</div></div>';
                    content += '<div style="background: var(--bg-light); padding: 12px; border-radius: 8px; text-align: center;"><div style="font-size: 20px; font-weight: 700; color: var(--primary);">23%</div><div style="font-size: 10px; color: var(--text-gray);">Конверсія</div></div>';
                    content += '</div>';
                }
                
                content += '</div>';
                div.innerHTML = content;
                container.appendChild(div);
                container.scrollTop = container.scrollHeight;
            }
            
            // Scenario demos
            const scenarios = [
                [
                    { role: 'user', text: 'Привіт, шукаю рюкзак для походів' },
                    { role: 'bot', text: 'Вітаю! 🎒 Який об\'єм вам потрібен? І є якийсь бюджет?' },
                    { role: 'user', text: 'Десь 30-40 літрів, до 4000 грн' },
                    { role: 'bot', text: 'Чудово! Ось що підібрав:', products: [
                        { name: 'Рюкзак M-TAC Large', price: '3 450 ₴', emoji: '🎒' },
                        { name: 'Helikon-Tex Raccoon', price: '3 890 ₴', emoji: '🎒' }
                    ]}
                ],
                [
                    { role: 'user', text: 'Потрібна тактична футболка' },
                    { role: 'bot', text: 'Який розмір носите? І є переваги по кольору?' },
                    { role: 'user', text: 'L, краще олива або койот' },
                    { role: 'bot', text: 'Маю варіанти в обох кольорах:', products: [
                        { name: 'Футболка CoolMax Olive L', price: '890 ₴', emoji: '👕' },
                        { name: 'Футболка M-TAC Coyote L', price: '750 ₴', emoji: '👕' }
                    ]}
                ],
                [
                    { role: 'user', text: 'Як швидко доставите у Львів?' },
                    { role: 'bot', text: 'До Львова 1-2 дні 🚚\n\n• Нова Пошта — 1-2 дні\n• УкрПошта — 3-5 днів\n• Самовивіз — безкоштовно' },
                    { role: 'user', text: 'А безкоштовна доставка є?' },
                    { role: 'bot', text: 'Так! 🎁 Безкоштовна від 2000 грн. Давайте підберемо щось?' }
                ]
            ];
            
            let currentScenario = 0;
            let scenarioIndex = 0;
            let isPlaying = false;
            
            function runScenario(index) {
                document.querySelectorAll('.scenario-btn').forEach((btn, i) => {
                    btn.classList.toggle('active', i === index);
                });
                
                currentScenario = index;
                scenarioIndex = 0;
                isPlaying = false;
                
                const chatBody = document.getElementById('demo-chat-body');
                chatBody.innerHTML = '<div class="demo-message"><div class="demo-avatar">🤖</div><div class="demo-bubble">Привіт! Я AI-асистент магазину. Чим можу допомогти?</div></div>';
                
                setTimeout(playScenario, 500);
            }
            
            function playScenario() {
                if (isPlaying) return;
                const scenario = scenarios[currentScenario];
                if (scenarioIndex >= scenario.length) return;
                
                isPlaying = true;
                const message = scenario[scenarioIndex];
                const chatBody = document.getElementById('demo-chat-body');
                
                if (message.role === 'bot') {
                    showTyping(chatBody, () => {
                        addDemoMessage(chatBody, message);
                        scenarioIndex++;
                        isPlaying = false;
                        setTimeout(playScenario, 1200);
                    });
                } else {
                    setTimeout(() => {
                        addDemoMessage(chatBody, message);
                        scenarioIndex++;
                        isPlaying = false;
                        setTimeout(playScenario, 800);
                    }, 400);
                }
            }
            
            // Initialize
            document.addEventListener('DOMContentLoaded', () => {
                showFeatureDemo(0);
                setTimeout(() => runScenario(0), 500);
            });
            
            // Roadmap Accordion
            function toggleAccordion(header) {
                const item = header.parentElement;
                const isOpen = item.classList.contains('open');
                
                // Close all other accordions
                document.querySelectorAll('.accordion-item').forEach(i => {
                    i.classList.remove('open');
                });
                
                // Toggle current if it wasn't open
                if (!isOpen) {
                    item.classList.add('open');
                }
            }
        </script>
        
        <!-- AOS.js Animation Library -->
        <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
        <script>
            AOS.init({
                duration: 700,
                easing: 'ease-out-cubic',
                once: true,
                offset: 50
            });
        </script>
    </body>
</html>
