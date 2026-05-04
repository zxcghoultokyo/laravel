#!/bin/bash
# SSE Use Case Tests for AIntento Chat
# Tests streaming endpoint: GET /api/chat/stream

TOKEN="<WIDGET_TOKEN>"
BASE="https://aintento.laravel.cloud/api/chat/stream"
PASS=0
FAIL=0
TOTAL=0

sse_test() {
    local name="$1"
    local message="$2"
    local session="$3"
    local expect_products="$4"
    local expect_text_contains="$5"
    
    TOTAL=$((TOTAL + 1))
    
    local encoded_msg=$(python3 -c "import urllib.parse; print(urllib.parse.quote('''$message'''))")
    local url="${BASE}?message=${encoded_msg}&session_id=${session}&token=${TOKEN}"
    
    local raw=$(curl -s -N --max-time 30 "$url" 2>/dev/null)
    
    local chunks=""
    local products_info=""
    local text_content=""
    local has_products="no"
    local has_text="no"
    local has_error="no"
    local has_chunk="no"
    local error_msg=""
    local event_list=""
    local product_count=0
    
    while IFS= read -r line; do
        if [[ "$line" == data:* ]]; then
            local data="${line#data: }"
            [[ -z "$data" ]] && continue
            
            local etype=$(echo "$data" | python3 -c "import sys,json
try:
    d=json.load(sys.stdin)
    print(d.get('type','unknown'))
except: print('parse_error')" 2>/dev/null)
            
            event_list="${event_list}${etype},"
            
            if [[ "$etype" == "chunk" ]]; then
                has_chunk="yes"
                local c=$(echo "$data" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('content',''),end='')" 2>/dev/null)
                chunks="${chunks}${c}"
            elif [[ "$etype" == "products" ]]; then
                has_products="yes"
                product_count=$(echo "$data" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('products',[])))" 2>/dev/null)
                products_info=$(echo "$data" | python3 -c "
import sys,json
d=json.load(sys.stdin)
for p in d.get('products',[])[:3]:
    t=p.get('title','')[:55]
    pr=p.get('price','?')
    imgs=len(p.get('images',[]))
    print(f'    {t} | {pr} грн | imgs:{imgs}')
" 2>/dev/null)
            elif [[ "$etype" == "text" ]]; then
                has_text="yes"
                text_content=$(echo "$data" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('content',d.get('text',''))[:400])" 2>/dev/null)
            elif [[ "$etype" == "error" ]]; then
                has_error="yes"
                error_msg=$(echo "$data" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('message',d.get('error',''))[:200])" 2>/dev/null)
            fi
        fi
    done <<< "$raw"
    
    local all_text="${text_content}${chunks}"
    
    local status="PASS"
    local reason=""
    
    if [[ "$has_error" == "yes" ]]; then
        status="FAIL"
        reason="ERROR: $error_msg"
    elif [[ -z "$event_list" ]]; then
        status="FAIL"
        reason="No SSE events received"
    elif [[ "$expect_products" == "yes" && "$has_products" != "yes" ]]; then
        status="FAIL"
        reason="Expected products but got none"
    fi
    
    if [[ -n "$expect_text_contains" && "$status" == "PASS" ]]; then
        if ! echo "$all_text" | grep -qi "$expect_text_contains" 2>/dev/null; then
            status="WARN"
            reason="Text doesn't contain expected: '$expect_text_contains'"
        fi
    fi
    
    if [[ "$status" == "PASS" ]]; then
        echo "✅ $name"
        PASS=$((PASS + 1))
    elif [[ "$status" == "WARN" ]]; then
        echo "⚠️  $name — $reason"
        PASS=$((PASS + 1))
    else
        echo "❌ $name — $reason"
        FAIL=$((FAIL + 1))
    fi
    
    local unique_events=$(echo "$event_list" | tr ',' '\n' | grep -v '^$' | sort | uniq -c | sort -rn | xargs)
    echo "   Events: $unique_events"
    
    if [[ "$has_products" == "yes" ]]; then
        echo "   Products ($product_count):"
        echo "$products_info" | head -3
    fi
    
    if [[ -n "$all_text" ]]; then
        local display="${all_text:0:250}"
        echo "   Text: $display"
    fi
    echo ""
}

echo "=============================================="
echo "  SSE USE CASE TESTS — $(date '+%Y-%m-%d %H:%M')"
echo "=============================================="
echo ""

# GROUP 1: Basic product searches
echo "━━━ 1. BASIC PRODUCT SEARCHES ━━━"
echo ""
sse_test "1.1 шоломи" "шоломи" "sse1_$(date +%s)" "yes" ""
sleep 2
sse_test "1.2 берці" "берці" "sse2_$(date +%s)" "yes" ""
sleep 2
sse_test "1.3 підсумки" "підсумки" "sse3_$(date +%s)" "yes" ""
sleep 2
sse_test "1.4 бронежилети" "бронежилети" "sse4_$(date +%s)" "yes" ""
sleep 2

# GROUP 2: Multi-word GPT queries
echo "━━━ 2. MULTI-WORD GPT QUERIES ━━━"
echo ""
sse_test "2.1 покажи тактичні рукавиці" "покажи тактичні рукавиці" "sse5_$(date +%s)" "yes" ""
sleep 3
sse_test "2.2 захист голови" "що є для захисту голови" "sse6_$(date +%s)" "yes" ""
sleep 3
sse_test "2.3 куртка до 5000 грн" "потрібна куртка до 5000 грн" "sse7_$(date +%s)" "yes" ""
sleep 3

# GROUP 3: Brand queries
echo "━━━ 3. BRAND QUERIES ━━━"
echo ""
sse_test "3.1 Ops-Core шоломи" "шоломи Ops-Core" "sse8_$(date +%s)" "yes" ""
sleep 3
sse_test "3.2 опс кор (транслітерація UA)" "опс кор" "sse9_$(date +%s)" "yes" ""
sleep 3
sse_test "3.3 берці salomon (латиниця)" "берці salomon" "sse10_$(date +%s)" "yes" ""
sleep 3

# GROUP 4: Slang
echo "━━━ 4. SLANG & MILITARY ━━━"
echo ""
sse_test "4.1 термуха (=термобілизна)" "термуха" "sse11_$(date +%s)" "yes" ""
sleep 3
sse_test "4.2 плітнік (=плитоноска)" "плітнік" "sse12_$(date +%s)" "yes" ""
sleep 3

# GROUP 5: Gender/attribute
echo "━━━ 5. GENDER/ATTRIBUTE QUERIES ━━━"
echo ""
sse_test "5.1 жіноча термобілизна (немає→чесна відповідь)" "жіноча термобілизна" "sse14_$(date +%s)" "any" ""
sleep 3
sse_test "5.2 зимова куртка" "зимова куртка" "sse15_$(date +%s)" "any" ""
sleep 3

# GROUP 6: Service queries
echo "━━━ 6. SERVICE/INFO QUERIES ━━━"
echo ""
sse_test "6.1 про магазин" "Розкажи про магазин" "sse16_$(date +%s)" "no" ""
sleep 3
sse_test "6.2 контакти" "контакти магазину" "sse17_$(date +%s)" "no" ""
sleep 3
sse_test "6.3 доставка" "які умови доставки" "sse18_$(date +%s)" "no" ""
sleep 3

# GROUP 7: Follow-up (same session)
echo "━━━ 7. FOLLOW-UP CONVERSATION ━━━"
echo ""
FU_SESSION="sse_fu_$(date +%s)"
sse_test "7.1 [FU] шоломи (перший запит)" "шоломи" "$FU_SESSION" "yes" ""
sleep 4
sse_test "7.2 [FU] покажи ще" "покажи ще" "$FU_SESSION" "any" ""
sleep 4
sse_test "7.3 [FU] а дешевше є?" "а дешевше є?" "$FU_SESSION" "any" ""
sleep 4

# GROUP 8: Edge cases
echo "━━━ 8. EDGE CASES ━━━"
echo ""
sse_test "8.1 привіт (greeting)" "привіт" "sse19_$(date +%s)" "no" ""
sleep 3
sse_test "8.2 довгий складний запит" "мені потрібна якісна тактична куртка для зимових умов з мембраною та капюшоном розмір L олива до 8000 грн" "sse20_$(date +%s)" "yes" ""
sleep 3
sse_test "8.3 tactical gloves (English)" "tactical gloves" "sse21_$(date +%s)" "yes" ""
sleep 3
sse_test "8.4 покажи plate carrier (mix)" "покажи plate carrier" "sse22_$(date +%s)" "yes" ""
sleep 3

# GROUP 9: Semantic/complex
echo "━━━ 9. SEMANTIC & COMPLEX ━━━"
echo ""
sse_test "9.1 що беруть на фронт" "що зазвичай беруть на фронт" "sse23_$(date +%s)" "any" ""
sleep 3
sse_test "9.2 екіпіровка для снайпера" "екіпіровка для снайпера" "sse24_$(date +%s)" "any" ""
sleep 3
sse_test "9.3 що потрібно новобранцю" "що потрібно новобранцю" "sse25_$(date +%s)" "any" ""
sleep 3

echo "=============================================="
echo "  RESULTS: $PASS passed, $FAIL failed / $TOTAL total"
echo "=============================================="
