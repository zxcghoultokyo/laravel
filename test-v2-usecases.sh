#!/bin/bash
API="https://aimbot.laravel.cloud/api/chat/v2"
TENANT='{"tenant_domain": "contractor.kiev.ua"}'

# Function to test and extract key info
test_query() {
    local name="$1"
    local query="$2"
    local session="${3:-test_v2_$(date +%s)}"
    
    result=$(curl -s -X POST "$API" \
      -H "Content-Type: application/json" \
      -d "{\"message\": \"$query\", \"session_id\": \"$session\", \"tenant_domain\": \"contractor.kiev.ua\"}")
    
    text=$(echo "$result" | jq -r '.text // "ERROR"' | head -c 80)
    count=$(echo "$result" | jq '.products | length')
    time=$(echo "$result" | jq '.meta.response_time_ms // 0')
    
    echo "$name|$query|$count|$time|$text"
}

echo "=== БАЗОВИЙ ПОШУК ==="
test_query "1. Проста категорія" "Покажи плитоноски"
test_query "2. Бренд+категорія" "M-TAC куртки"
test_query "3. Артикул" "Артикул 000-c6c"
test_query "4. Англійський" "plate carrier multicam"
test_query "5. Сленг" "броник"
test_query "6. Складний" "Куртка softshell олива M до 3000"

echo "=== FOLLOW-UP ==="
SESSION="test_followup_$(date +%s)"
test_query "7a. Куртки" "Куртки" "$SESSION"
sleep 1
test_query "7b. Розмір L" "Розмір L є?" "$SESSION"

SESSION2="test_followup2_$(date +%s)"
test_query "8a. Рюкзаки" "Рюкзаки" "$SESSION2"
sleep 1
test_query "8b. Мультикам" "Тільки мультикам" "$SESSION2"

SESSION3="test_followup3_$(date +%s)"
test_query "9a. Підсумки" "Підсумки" "$SESSION3"
sleep 1
test_query "9b. Покажи ще" "Покажи ще" "$SESSION3"

SESSION4="test_followup4_$(date +%s)"
test_query "10a. Футболка" "Чорна футболка" "$SESSION4"
sleep 1
test_query "10b. Заперечення" "Ні, хочу оліву" "$SESSION4"

SESSION5="test_followup5_$(date +%s)"
test_query "11a. Навушники" "Навушники Peltor" "$SESSION5"
sleep 1
test_query "11b. Дешевше" "А є дешевше?" "$SESSION5"

echo "=== EDGE CASES ==="
test_query "12. Неіснуючий" "Танк Т-72"
test_query "13. Жіноча термо" "Жіноча термобілизна"
test_query "14. Ціна" "Штани до 2000 грн"
test_query "15. Багатозначний" "щось для захисту"
test_query "16. Наявність" "Чи є в наявності шоломи?"

echo "=== НЕ-ТОВАРНІ ==="
test_query "17. Привітання" "Привіт!"
test_query "18. Подяка" "Дякую"
test_query "19. FAQ замовлення" "Як замовити товар?"
test_query "20. Порівняння" "Порівняй Peltor і Earmor"
test_query "21. Новинки" "Що нового?"

echo "=== КОНТЕКСТ ==="
SESSION6="test_context_$(date +%s)"
test_query "22a. Плитоноски" "Плитоноски" "$SESSION6"
sleep 1
test_query "22b. Берці" "А берці?" "$SESSION6"
