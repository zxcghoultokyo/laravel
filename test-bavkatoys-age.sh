#!/bin/bash
# Comprehensive age-based test for bavkatoys (tenant 20)
# Runs all queries and saves results to test-bavkatoys-age-results.md

API="https://aintento.laravel.cloud/api/chat"
DIAG="https://aintento.laravel.cloud/api/diagnostic"
TENANT=20
OUT="/workspaces/laravel/test-bavkatoys-age-results.md"
TS=$(date +%s)

echo "# Тестування bavkatoys (tenant 20) — вікові та семантичні запити" > "$OUT"
echo "" >> "$OUT"
echo "**Дата:** $(date '+%Y-%m-%d %H:%M:%S')" >> "$OUT"
echo "" >> "$OUT"

# Function to test a query
test_query() {
    local num="$1"
    local query="$2"
    local expected="$3"
    local sid="age_test_${num}_${TS}"
    
    local resp
    resp=$(curl -s "$API" \
        -H "Content-Type: application/json" \
        -H "X-Tenant-Id: $TENANT" \
        -d "{\"message\":\"$query\",\"session_id\":\"$sid\"}" \
        --max-time 60)
    
    local source=$(echo "$resp" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('meta',{}).get('source',d.get('meta',{}).get('agent','?')))" 2>/dev/null || echo "error")
    local prod_count=$(echo "$resp" | python3 -c "import sys,json;d=json.load(sys.stdin);print(len(d.get('products',[])))" 2>/dev/null || echo "0")
    local text=$(echo "$resp" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('text','')[:300])" 2>/dev/null || echo "error")
    local products=$(echo "$resp" | python3 -c "
import sys,json
d=json.load(sys.stdin)
for p in d.get('products',[])[:5]:
    t=p.get('title','')[:55]
    c=p.get('category_path','')[:35]
    pr=p.get('price','')
    print(f'  - {t} | {c} | {pr} грн')
" 2>/dev/null || echo "  (помилка парсингу)")
    local tools=$(echo "$resp" | python3 -c "import sys,json;d=json.load(sys.stdin);print(', '.join(d.get('meta',{}).get('tools_called',[])))" 2>/dev/null || echo "?")
    local search_q=$(echo "$resp" | python3 -c "import sys,json;d=json.load(sys.stdin);print(d.get('meta',{}).get('search_query','') or 'N/A')" 2>/dev/null || echo "?")
    
    # Determine status
    local status="⚠️"
    if [ "$prod_count" -gt 0 ]; then
        status="✅"
    elif echo "$text" | grep -qi "уточн\|вік\|скільки"; then
        status="❓ (уточнення)"
    else
        status="❌ (0 товарів)"
    fi
    
    echo "---" >> "$OUT"
    echo "" >> "$OUT"
    echo "## Тест $num: \"$query\"" >> "$OUT"
    echo "" >> "$OUT"
    echo "- **Очікування:** $expected" >> "$OUT"
    echo "- **Статус:** $status" >> "$OUT"
    echo "- **Джерело:** $source | **Інструменти:** $tools" >> "$OUT"
    echo "- **Пошуковий запит:** $search_q" >> "$OUT"
    echo "- **Кількість товарів:** $prod_count" >> "$OUT"
    if [ "$prod_count" -gt 0 ]; then
        echo "- **Товари:**" >> "$OUT"
        echo "$products" >> "$OUT"
    fi
    echo "- **Текст відповіді:** $text" >> "$OUT"
    echo "" >> "$OUT"
    
    echo "  [$status] Test $num: \"$query\" → $prod_count products ($source)"
}

echo "=========================================="
echo "  BAVKATOYS AGE TEST SUITE"
echo "=========================================="
echo ""

# === ВІКОВІ ЗАПИТИ ===

echo "--- Вікові запити ---"
echo "" >> "$OUT"
echo "# 👶 ВІКОВІ ЗАПИТИ (за віком дитини)" >> "$OUT"
echo "" >> "$OUT"

test_query 1 "іграшки для немовляти" "МАЛЮКАМ 0-1"
sleep 2

test_query 2 "іграшки для малюка" "МАЛЮКАМ 0-1 або уточнення віку"
sleep 2

test_query 3 "іграшки для дитини до 1 року" "МАЛЮКАМ 0-1"
sleep 2

test_query 4 "іграшки для дитини 1 рік" "ТОДЛЕРАМ 1-3"
sleep 2

test_query 5 "іграшки для дитини 2 років" "ТОДЛЕРАМ 1-3"
sleep 2

test_query 6 "іграшки для дитини 3 роки" "ТОДЛЕРАМ 1-3 або ДОШКІЛЬНЯТАМ 3-7"
sleep 2

test_query 7 "іграшки для дитини 4 роки" "ДОШКІЛЬНЯТАМ 3-7"
sleep 2

test_query 8 "іграшки для дитини 5 років" "ДОШКІЛЬНЯТАМ 3-7"
sleep 2

test_query 9 "іграшки для дитини 6 років" "ДОШКІЛЬНЯТАМ 3-7"
sleep 2

test_query 10 "що подарувати дитині на 2 роки" "ТОДЛЕРАМ 1-3"
sleep 2

test_query 11 "що подарувати на 5 років" "ДОШКІЛЬНЯТАМ 3-7"
sleep 2

# === ВІКОВІ КАТЕГОРІЇ НАПРЯМУ ===

echo ""
echo "--- Вікові категорії напряму ---"
echo "" >> "$OUT"
echo "# 🏷️ ЗАПИТИ ЗА НАЗВОЮ КАТЕГОРІЇ" >> "$OUT"
echo "" >> "$OUT"

test_query 12 "покажи товари для тодлерів" "ТОДЛЕРАМ 1-3"
sleep 2

test_query 13 "що є для дошкільнят" "ДОШКІЛЬНЯТАМ 3-7"
sleep 2

test_query 14 "іграшки для малюків до року" "МАЛЮКАМ 0-1"
sleep 2

# === СЕМАНТИЧНІ / ТЕМАТИЧНІ ЗАПИТИ ===

echo ""
echo "--- Семантичні запити ---"
echo "" >> "$OUT"
echo "# 🔍 СЕМАНТИЧНІ / ТЕМАТИЧНІ ЗАПИТИ" >> "$OUT"
echo "" >> "$OUT"

test_query 15 "Монтессорі іграшки" "Монтессорі простір або різне"
sleep 2

test_query 16 "дерев'яні іграшки" "різні категорії"
sleep 2

test_query 17 "пазл" "пазли з різних категорій"
sleep 2

test_query 18 "розвиваючі іграшки" "різні"
sleep 2

test_query 19 "меблі для дитячої кімнати" "МЕБЛІ ТА ОРГАНІЗАЦІЯ"
sleep 2

test_query 20 "навчальні посібники" "НАВЧАЛЬНІ ПОСІБНИКИ"
sleep 2

test_query 21 "які категорії товарів у вас є?" "текстова відповідь зі списком категорій"
sleep 2

# === EDGE CASES ===

echo ""
echo "--- Edge cases ---"
echo "" >> "$OUT"
echo "# 🧪 EDGE CASES" >> "$OUT"
echo "" >> "$OUT"

test_query 22 "іграшка" "товари з різних категорій"
sleep 2

test_query 23 "подарунок новонародженому" "МАЛЮКАМ 0-1"
sleep 2

test_query 24 "розвиваючі іграшки для дитини 3-5 років" "ТОДЛЕРАМ 1-3 або ДОШКІЛЬНЯТАМ 3-7"
sleep 2

echo ""
echo "=========================================="
echo "  DONE! Results saved to test-bavkatoys-age-results.md"
echo "=========================================="

# Add summary
echo "" >> "$OUT"
echo "---" >> "$OUT"
echo "" >> "$OUT"
echo "# 📊 ПІДСУМОК" >> "$OUT"
echo "" >> "$OUT"
echo "Загалом тестів: 24" >> "$OUT"
echo "" >> "$OUT"
echo "Категорії bavkatoys:" >> "$OUT"
echo "- МАЛЮКАМ 0 – 1 (44 товари)" >> "$OUT"
echo "- ТОДЛЕРАМ 1 – 3 (95 товарів)" >> "$OUT"
echo "- ДОШКІЛЬНЯТАМ 3 – 7 (7 товарів)" >> "$OUT"
echo "- МЕБЛІ ТА ОРГАНІЗАЦІЯ (49 товарів)" >> "$OUT"
echo "- МОНТЕССОРІ ПРОСТІР (32 товари)" >> "$OUT"
echo "- НАВЧАЛЬНІ ПОСІБНИКИ (15 товарів)" >> "$OUT"
