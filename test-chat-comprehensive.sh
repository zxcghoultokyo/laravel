#!/bin/bash

# Comprehensive Chat Testing Script
# Tests SSE streaming endpoint with various use cases

TOKEN="<WIDGET_TOKEN>"
BASE_URL="https://aintento.laravel.cloud/api/chat/stream"
REPORT_FILE="/workspaces/laravel/tests/CHAT_TEST_REPORT_$(date +%Y-%m-%d_%H%M).md"
SESSION_ID="test_comprehensive_$(date +%s)_$$"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo "# 🧪 Comprehensive Chat Test Report" > "$REPORT_FILE"
echo "" >> "$REPORT_FILE"
echo "**Date:** $(date '+%Y-%m-%d %H:%M:%S')" >> "$REPORT_FILE"
echo "**Tenant:** 2 (attack.kiev.ua - тактичний магазин)" >> "$REPORT_FILE"
echo "**Session ID:** $SESSION_ID" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

test_query() {
    local query="$1"
    local description="$2"
    local session="$3"
    local expected="$4"
    
    echo -e "${YELLOW}Testing: $query${NC}"
    
    # URL encode the query
    local encoded_query=$(python3 -c "import urllib.parse; print(urllib.parse.quote('$query'))")
    
    # Make SSE request and capture response
    local response=$(curl -s --max-time 30 "$BASE_URL?message=$encoded_query&session_id=$session&token=$TOKEN" 2>/dev/null)
    
    # Extract products data
    local products_line=$(echo "$response" | grep -o 'data: {"products":\[.*\],"count":[0-9]*' | head -1)
    local done_line=$(echo "$response" | grep 'type":"done"')
    
    # Parse response
    local product_count=$(echo "$response" | grep -o '"count":[0-9]*' | head -1 | grep -o '[0-9]*')
    local first_product=$(echo "$response" | grep -o '"title":"[^"]*"' | head -1 | sed 's/"title":"//;s/"$//')
    local has_images=$(echo "$response" | grep -o '"images":\[' | head -1)
    local text_chunks=$(echo "$response" | grep '"type":"chunk"' | head -5)
    
    # Check for errors
    local has_error=$(echo "$response" | grep -i 'error\|exception')
    
    echo "" >> "$REPORT_FILE"
    echo "### Test: $description" >> "$REPORT_FILE"
    echo "**Query:** \`$query\`" >> "$REPORT_FILE"
    echo "**Session:** \`$session\`" >> "$REPORT_FILE"
    echo "" >> "$REPORT_FILE"
    
    if [ -n "$has_error" ]; then
        echo "**Status:** ❌ ERROR" >> "$REPORT_FILE"
        echo "\`\`\`" >> "$REPORT_FILE"
        echo "$has_error" | head -3 >> "$REPORT_FILE"
        echo "\`\`\`" >> "$REPORT_FILE"
        echo -e "${RED}❌ ERROR${NC}"
        return 1
    elif [ -z "$product_count" ] || [ "$product_count" = "0" ]; then
        # Check if it's a text response
        local text_response=$(echo "$response" | grep '"type":"chunk"' | grep -o '"text":"[^"]*"' | head -10 | sed 's/"text":"//g;s/"$//g' | tr -d '\n')
        if [ -n "$text_response" ]; then
            echo "**Status:** ⚠️ TEXT ONLY (no products)" >> "$REPORT_FILE"
            echo "**Response:** $text_response" >> "$REPORT_FILE"
            echo -e "${YELLOW}⚠️ TEXT ONLY${NC}"
        else
            echo "**Status:** ❌ NO RESPONSE" >> "$REPORT_FILE"
            echo -e "${RED}❌ NO RESPONSE${NC}"
            return 1
        fi
    else
        echo "**Status:** ✅ OK" >> "$REPORT_FILE"
        echo "**Products found:** $product_count" >> "$REPORT_FILE"
        echo "**First product:** $first_product" >> "$REPORT_FILE"
        if [ -n "$has_images" ]; then
            echo "**Images:** ✅ Yes" >> "$REPORT_FILE"
        else
            echo "**Images:** ❌ No" >> "$REPORT_FILE"
        fi
        echo -e "${GREEN}✅ OK - $product_count products${NC}"
    fi
    
    # Small delay between requests
    sleep 1
}

echo "=== Starting Comprehensive Chat Tests ==="
echo ""

# ============================================
echo "## 1. 🎯 Прості запити (1 слово)" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

test_query "шоломи" "Single word - helmets" "${SESSION_ID}_simple" "products"
test_query "підсумки" "Single word - pouches" "${SESSION_ID}_simple" "products"
test_query "берці" "Single word - boots" "${SESSION_ID}_simple" "products"
test_query "рюкзаки" "Single word - backpacks" "${SESSION_ID}_simple" "products"
test_query "куртки" "Single word - jackets" "${SESSION_ID}_simple" "products"
test_query "аптечка" "Single word - medkit" "${SESSION_ID}_simple" "products"
test_query "турнікет" "Single word - tourniquet" "${SESSION_ID}_simple" "products"
test_query "навушники" "Single word - headphones" "${SESSION_ID}_simple" "products"

# ============================================
echo "" >> "$REPORT_FILE"
echo "## 2. 🔍 Складні запити (2+ слова)" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

test_query "покажи шоломи" "Multi-word - show helmets" "${SESSION_ID}_multi" "products"
test_query "тактичний рюкзак" "Multi-word - tactical backpack" "${SESSION_ID}_multi" "products"
test_query "зимова куртка" "Multi-word - winter jacket" "${SESSION_ID}_multi" "products"
test_query "балістичний шолом" "Multi-word - ballistic helmet" "${SESSION_ID}_multi" "products"
test_query "медичний підсумок" "Multi-word - medical pouch" "${SESSION_ID}_multi" "products"

# ============================================
echo "" >> "$REPORT_FILE"
echo "## 3. ❄️ Сезонні запити" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

test_query "що беруть взимку" "Seasonal - what to buy in winter (UA)" "${SESSION_ID}_season" "winter products"
test_query "что берут зимой" "Seasonal - what to buy in winter (RU)" "${SESSION_ID}_season" "winter products"
test_query "що популярне влітку" "Seasonal - summer popular" "${SESSION_ID}_season" "summer products"
test_query "зимове спорядження" "Seasonal - winter gear" "${SESSION_ID}_season" "winter products"

# ============================================
echo "" >> "$REPORT_FILE"
echo "## 4. 🧠 Семантичні запити" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

test_query "чим зупинити кров" "Semantic - how to stop bleeding" "${SESSION_ID}_semantic" "medical"
test_query "захист голови" "Semantic - head protection" "${SESSION_ID}_semantic" "helmets"
test_query "що одягнути в холод" "Semantic - what to wear in cold" "${SESSION_ID}_semantic" "winter clothing"
test_query "для нічного бачення" "Semantic - for night vision" "${SESSION_ID}_semantic" "NVG accessories"
test_query "захист від куль" "Semantic - bullet protection" "${SESSION_ID}_semantic" "armor"

# ============================================
echo "" >> "$REPORT_FILE"
echo "## 5. 🏷️ Брендові запити" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

test_query "Ops-Core" "Brand - Ops-Core" "${SESSION_ID}_brand" "Ops-Core products"
test_query "опс кор шолом" "Brand transliteration - ops core helmet" "${SESSION_ID}_brand" "Ops-Core helmets"
test_query "Salomon" "Brand - Salomon" "${SESSION_ID}_brand" "Salomon boots"
test_query "саломон берці" "Brand transliteration - salomon boots" "${SESSION_ID}_brand" "Salomon boots"
test_query "Carinthia" "Brand - Carinthia" "${SESSION_ID}_brand" "Carinthia jackets"
test_query "Aegis плити" "Brand - Aegis plates" "${SESSION_ID}_brand" "Aegis armor"

# ============================================
echo "" >> "$REPORT_FILE"
echo "## 6. 💬 Сесійність та контекст" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

CONTEXT_SESSION="test_context_$(date +%s)"

echo "### Тест контексту розмови" >> "$REPORT_FILE"
echo "Session: \`$CONTEXT_SESSION\`" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

test_query "шоломи" "Context 1 - initial helmets query" "$CONTEXT_SESSION" "helmets"
sleep 2
test_query "а які є розміри?" "Context 2 - follow-up about sizes" "$CONTEXT_SESSION" "size info"
sleep 2
test_query "покажи ще" "Context 3 - show more" "$CONTEXT_SESSION" "more helmets"
sleep 2
test_query "а дешевші є?" "Context 4 - cheaper options" "$CONTEXT_SESSION" "cheaper helmets"

# ============================================
echo "" >> "$REPORT_FILE"
echo "## 7. 🔄 Зміна теми (заплутування)" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

CONFUSE_SESSION="test_confuse_$(date +%s)"

test_query "шоломи" "Confuse 1 - start with helmets" "$CONFUSE_SESSION" "helmets"
sleep 2
test_query "ні, краще берці" "Confuse 2 - switch to boots" "$CONFUSE_SESSION" "boots"
sleep 2
test_query "а рукавички є?" "Confuse 3 - switch to gloves" "$CONFUSE_SESSION" "gloves"
sleep 2
test_query "поверніся до шоломів" "Confuse 4 - back to helmets" "$CONFUSE_SESSION" "helmets"

# ============================================
echo "" >> "$REPORT_FILE"
echo "## 8. 🇷🇺🇺🇦🇬🇧 Мультимовність" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

test_query "helmets" "Language - English" "${SESSION_ID}_lang" "helmets"
test_query "шлемы" "Language - Russian" "${SESSION_ID}_lang" "helmets"
test_query "каски" "Language - Ukrainian alt" "${SESSION_ID}_lang" "helmets"
test_query "plate carrier" "Language - English term" "${SESSION_ID}_lang" "plate carriers"
test_query "плитоноска" "Language - Ukrainian term" "${SESSION_ID}_lang" "plate carriers"

# ============================================
echo "" >> "$REPORT_FILE"
echo "## 9. ⚠️ Граничні випадки" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

test_query "привіт" "Edge - greeting" "${SESSION_ID}_edge" "greeting response"
test_query "дякую" "Edge - thanks" "${SESSION_ID}_edge" "polite response"
test_query "ааааа" "Edge - gibberish" "${SESSION_ID}_edge" "clarification"
test_query "123" "Edge - numbers only" "${SESSION_ID}_edge" "clarification"
test_query "до 1000 грн" "Edge - price filter" "${SESSION_ID}_edge" "budget products"
test_query "найдешевше" "Edge - cheapest" "${SESSION_ID}_edge" "sorted by price"

# ============================================
echo "" >> "$REPORT_FILE"
echo "## 10. 🎭 Складні сценарії" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"

COMPLEX_SESSION="test_complex_$(date +%s)"

test_query "потрібен комплект для новачка" "Complex 1 - beginner kit" "$COMPLEX_SESSION" "recommendations"
sleep 2
test_query "бюджет до 20000" "Complex 2 - budget constraint" "$COMPLEX_SESSION" "filtered products"
sleep 2
test_query "а що з доставкою?" "Complex 3 - delivery question" "$COMPLEX_SESSION" "delivery info"
sleep 2
test_query "покажи найпопулярніше" "Complex 4 - most popular" "$COMPLEX_SESSION" "popular products"

# ============================================
echo "" >> "$REPORT_FILE"
echo "---" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"
echo "## 📊 Summary" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"
echo "Report generated: $(date '+%Y-%m-%d %H:%M:%S')" >> "$REPORT_FILE"
echo "" >> "$REPORT_FILE"
echo "**Test session base:** \`$SESSION_ID\`" >> "$REPORT_FILE"

echo ""
echo "=== Tests Complete ==="
echo "Report saved to: $REPORT_FILE"
