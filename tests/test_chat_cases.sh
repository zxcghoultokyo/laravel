#!/bin/bash
# Chat Use Cases Test Script
# Usage: bash tests/test_chat_cases.sh

API_URL="https://aimbot.laravel.cloud/api/chat"
SESSION_ID="test_$(date +%s)"

echo "=========================================="
echo "  CHAT USE CASES TEST"
echo "  Session: $SESSION_ID"
echo "=========================================="
echo ""

test_chat() {
    local name="$1"
    local message="$2"
    
    echo "🔹 Test: $name"
    echo "   Query: $message"
    
    response=$(curl -s -X POST "$API_URL" \
        -H "Content-Type: application/json" \
        -d "{\"message\": \"$message\", \"session_id\": \"$SESSION_ID\"}")
    
    # Extract key info
    type=$(echo "$response" | jq -r '.type // "unknown"')
    text=$(echo "$response" | jq -r '.text // ""' | head -c 200)
    products_count=$(echo "$response" | jq '.products | length // 0')
    
    echo "   Type: $type"
    echo "   Products: $products_count"
    echo "   Text: ${text:0:150}..."
    echo ""
    
    # Small delay between requests
    sleep 1
}

echo "=== BASIC QUERIES ==="
echo ""

test_chat "1. Привітання" "Привіт"

test_chat "2. Пошук рюкзаків" "Покажи тактичні рюкзаки"

test_chat "3. Пошук за кольором" "Шукаю щось в кольорі мультикам"

test_chat "4. Пошук за бюджетом" "Що є до 1000 грн?"

test_chat "5. Уточнення (чорні)" "А є чорні?"

SESSION_ID="test_$(date +%s)_2"

test_chat "6. Футболка L" "Футболка розмір L"

test_chat "7. Плитоноска чорна" "Плитоноска чорна до 5000"

test_chat "8. Бронежилети" "Чи є в наявності бронежилети?"

test_chat "9. Доставка" "Як швидко доставите?"

echo "=== COMPLEX SCENARIOS ==="
echo ""

SESSION_ID="test_$(date +%s)_3"

test_chat "10. Плитоноски" "Покажи плитоноски"

test_chat "11. Follow-up" "Ще покажи"

SESSION_ID="test_$(date +%s)_4"

test_chat "12. Сленг" "Маєш плитники?"

test_chat "13. Помилка" "Мультікам рюкзаг"

test_chat "14. Рекомендація" "Що порекомендуєш для страйкболу?"

echo "=========================================="
echo "  TEST COMPLETE"
echo "=========================================="
