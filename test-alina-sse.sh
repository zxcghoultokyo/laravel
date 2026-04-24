#!/bin/bash
# =====================================================================
# Alina's feedback regression test — bavkatoys (tenant 20) via SSE
# Based on voice transcript: gift-for-1-year-old scenarios
# Problem products flagged by tester:
#   - подвязки/підвіски для новонародженого (0-3 mo)
#   - фартух (not a gift)
#   - коробочка постійності (0-12 mo, not relevant at 1y)
#   - електронний зошит (digital, not a physical gift)
# =====================================================================

BASE="https://aintento.laravel.cloud/api/chat/stream"
TENANT=20
OUT="/workspaces/laravel/test-alina-sse-results.md"
TS=$(date +%s)

# Problem-phrase regex (case-insensitive, Ukrainian)
BAD_REGEX='підвіск|підвяз|подвяз|фартух|електронн.*зошит|pdf|цифров|коробоч.*постійн'

echo "# Alina Gift-Regression SSE Test — bavkatoys (tenant 20)" > "$OUT"
echo "" >> "$OUT"
echo "**Дата:** $(date '+%Y-%m-%d %H:%M:%S')" >> "$OUT"
echo "**Endpoint:** \`$BASE\`" >> "$OUT"
echo "" >> "$OUT"
echo "Базовий сценарій з голосового Аліни: запит \"щось на подарунок на рік\"." >> "$OUT"
echo "Проблемні товари, які НЕ мають з'являтись у gift-контексті для 1 року:" >> "$OUT"
echo "\`підвіски/підвязки для новонародженого\`, \`фартух\`, \`коробочка постійності\` (0-12м), \`електронний зошит\` (PDF)." >> "$OUT"
echo "" >> "$OUT"

PASS=0
FAIL=0
WARN=0
TOTAL=0

# =====================================================================
# Runs a single SSE query. Same session_id = conversation continuity.
# Args: test_num, description, expected_note, session_id, message, forbid_flag
#   forbid_flag: "strict" = any BAD product/text → FAIL
#                "any"    = BAD product/text → WARN only
#                "none"   = ignore bad list (for direct searches)
# =====================================================================
sse_run() {
    local num="$1"
    local desc="$2"
    local expect="$3"
    local sid="$4"
    local msg="$5"
    local forbid="${6:-strict}"

    TOTAL=$((TOTAL + 1))

    local enc
    enc=$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))" "$msg")
    local url="${BASE}?message=${enc}&session_id=${sid}&tenant_id=${TENANT}"

    local tmpfile
    tmpfile=$(mktemp)
    curl -s -N --max-time 60 "$url" > "$tmpfile" 2>/dev/null

    local parsed
    parsed=$(python3 /workspaces/laravel/test-alina-sse-parser.py < "$tmpfile")
    rm -f "$tmpfile"

    local text=$(echo "$parsed" | python3 -c "import sys,json; d=json.loads(sys.stdin.read()); print(d.get('text',''))")
    local prod_count=$(echo "$parsed" | python3 -c "import sys,json; d=json.loads(sys.stdin.read()); print(len(d.get('products',[])))")
    local source=$(echo "$parsed" | python3 -c "import sys,json; d=json.loads(sys.stdin.read()); print(d.get('source') or '?')")
    local err=$(echo "$parsed" | python3 -c "import sys,json; d=json.loads(sys.stdin.read()); print(d.get('error',''))")
    local tools=$(echo "$parsed" | python3 -c "import sys,json; d=json.loads(sys.stdin.read()); print(','.join(d.get('tools',[])))")

    # Check for BAD products / phrases
    local bad_hits
    bad_hits=$(echo "$parsed" | python3 -c "
import sys, json, re
d = json.loads(sys.stdin.read())
bad_re = re.compile(r'''$BAD_REGEX''', re.IGNORECASE)
hits = []
for p in d.get('products', []):
    blob = (p.get('title','') + ' ' + p.get('category_path','')).lower()
    if bad_re.search(blob):
        hits.append('PROD: ' + p.get('title','')[:60])
if bad_re.search(d.get('text','').lower()):
    hits.append('TEXT mention')
print('|'.join(hits))
")

    local status="PASS"
    local reason=""

    if [[ -n "$err" ]]; then
        status="FAIL"
        reason="SSE error: $err"
    elif [[ "$forbid" == "strict" && -n "$bad_hits" ]]; then
        status="FAIL"
        reason="FORBIDDEN items in gift context: $bad_hits"
    elif [[ "$forbid" == "any" && -n "$bad_hits" ]]; then
        status="WARN"
        reason="Suspect items: $bad_hits"
    fi

    # Render to console
    local icon="✅"
    [[ "$status" == "FAIL" ]] && icon="❌"
    [[ "$status" == "WARN" ]] && icon="⚠️ "
    echo "$icon  $num. $desc  [products=$prod_count, src=$source]"
    [[ -n "$reason" ]] && echo "     → $reason"

    # Write markdown block
    {
        echo "---"
        echo ""
        echo "### $num. $desc"
        echo ""
        echo "- **Session:** \`$sid\`"
        echo "- **Запит:** \`$msg\`"
        echo "- **Очікування:** $expect"
        echo "- **Режим заборонених:** $forbid"
        echo "- **Статус:** $icon **$status**"
        [[ -n "$reason" ]] && echo "- **Причина:** $reason"
        echo "- **Джерело:** $source | **Інструменти:** \`$tools\`"
        echo "- **Товарів:** $prod_count"
        if [[ "$prod_count" -gt 0 ]]; then
            echo "- **Товари:**"
            echo "$parsed" | python3 -c "
import sys, json
d = json.loads(sys.stdin.read())
for p in d.get('products', []):
    age = ''
    if p.get('age_min') is not None or p.get('age_max') is not None:
        age = f\" [вік: {p.get('age_min','?')}-{p.get('age_max','?')}м]\"
    print(f\"  - {p.get('title','')} | {p.get('category_path','')} | {p.get('price','?')} грн{age}\")
"
        fi
        echo "- **Текст:**"
        echo ""
        echo "\`\`\`"
        echo "$text" | head -c 1200
        echo ""
        echo "\`\`\`"
        echo ""
    } >> "$OUT"

    case "$status" in
        PASS) PASS=$((PASS + 1)) ;;
        WARN) WARN=$((WARN + 1)) ;;
        FAIL) FAIL=$((FAIL + 1)) ;;
    esac
}

echo "=================================================="
echo "  ALINA GIFT REGRESSION — SSE (tenant 20)"
echo "=================================================="
echo ""

# ======================================================================
# A. EXACT REPLAY OF ALINA'S DIALOG (multi-turn, SAME session)
# ======================================================================
SID_A="alina_replay_${TS}"
echo "--- A. Replay діалогу Аліни (same session: $SID_A) ---"
echo "" >> "$OUT"
echo "## A. Replay діалогу Аліни (multi-turn, same session)" >> "$OUT"
echo "" >> "$OUT"

sse_run "A1" \
    "Стартовий запит Аліни: подарунок на рік" \
    "Іграшки/набори для 12+ міс. НЕ: підвіски 0-3м, фартух, PDF, коробочка 0-12м" \
    "$SID_A" \
    "мені потрібно щось на подарунок на рік" \
    "strict"
sleep 3

sse_run "A2" \
    "Рефрейм: відхиляє підвіски, просить популярне" \
    "Має врахувати контекст попереднього повідомлення, НЕ пропонувати 0-3м" \
    "$SID_A" \
    "підвязки - це запізно вже. мені потрібно щось на подарунок на рік і популярне" \
    "strict"
sleep 3

sse_run "A3" \
    "Прямий запит на набір/комплект" \
    "Має запропонувати готовий подарунковий набір, а не одиночні товари" \
    "$SID_A" \
    "а може комплект якийсь готовий є? щоб гарно запакований" \
    "strict"
sleep 3

sse_run "A4" \
    "Уточнення: НЕ фартух" \
    "Має виключити фартух з пропозицій, альтернативи" \
    "$SID_A" \
    "фартух не підходить, це не дарують. що ще є?" \
    "strict"
sleep 3

# ======================================================================
# B. SINGLE-TURN GIFT QUERIES (fresh sessions)
# ======================================================================
echo ""
echo "--- B. Одноразові gift-запити ---"
echo "" >> "$OUT"
echo "## B. Одноразові gift-запити (fresh session)" >> "$OUT"
echo "" >> "$OUT"

sse_run "B1" "Подарунок дитині 1 рік" "Товари для 12+ міс, без заборонених" "b1_${TS}" "подарунок дитині на 1 рік" "strict"
sleep 3
sse_run "B2" "Подарунок дівчинці 1 рік" "Товари для дівчинки 12м+" "b2_${TS}" "що подарувати дівчинці 1 рік" "strict"
sleep 3
sse_run "B3" "Подарунок хлопчику 1 рік" "Товари для хлопчика 12м+" "b3_${TS}" "що подарувати хлопчику 1 рік" "strict"
sleep 3
sse_run "B4" "Подарунок на рочок" "12 міс+" "b4_${TS}" "що подарувати на рочок" "strict"
sleep 3
sse_run "B5" "Подарунок на день народження 1 рік" "12 міс+" "b5_${TS}" "подарунок на день народження 1 рік" "strict"
sleep 3
sse_run "B6" "Готовий набір іграшок" "Комплекти/набори, не одиночні" "b6_${TS}" "готовий набір іграшок на подарунок" "strict"
sleep 3
sse_run "B7" "Комплект як подарунок" "Комплекти" "b7_${TS}" "комплект іграшок як подарунок" "strict"
sleep 3
sse_run "B8" "Подарунковий набір для малюка" "Набір для 1р+" "b8_${TS}" "подарунковий набір для малюка рік" "strict"
sleep 3
sse_run "B9" "Популярний подарунок на рік" "Топ-товари без заборонених" "b9_${TS}" "що найчастіше беруть на подарунок дитині рік" "strict"
sleep 3
sse_run "B10" "Топ подарунків" "Популярні gift items" "b10_${TS}" "топ подарунків на рік" "strict"
sleep 3

# ======================================================================
# C. INFANT (0-12мо) — тут підвіски/коробочка ОК
# ======================================================================
echo ""
echo "--- C. Infant queries (0-12мо) — там заборонені ОК ---"
echo "" >> "$OUT"
echo "## C. Немовлята 0-12 міс (тут підвіски/коробочка доречні)" >> "$OUT"
echo "" >> "$OUT"

sse_run "C1" "Іграшки для немовляти 0-3м" "Підвіски/мобілі доречні" "c1_${TS}" "іграшки для немовляти 0-3 місяці" "none"
sleep 3
sse_run "C2" "Подарунок новонародженому" "Підвіски/коробочка ОК" "c2_${TS}" "подарунок новонародженому" "none"
sleep 3
sse_run "C3" "Іграшки 6 місяців" "6-12м товари" "c3_${TS}" "іграшки для 6 місяців" "none"
sleep 3
sse_run "C4" "Іграшки 9 місяців" "6-12м" "c4_${TS}" "іграшки 9 місяців" "none"
sleep 3

# ======================================================================
# D. 2-3+ роки
# ======================================================================
echo ""
echo "--- D. Діти 2-3+ років ---"
echo "" >> "$OUT"
echo "## D. Старші діти (2-5 років)" >> "$OUT"
echo "" >> "$OUT"

sse_run "D1" "Подарунок 2 роки" "24м+, заборонені виключені" "d1_${TS}" "подарунок на 2 роки" "strict"
sleep 3
sse_run "D2" "Подарунок 3 роки" "36м+" "d2_${TS}" "подарунок дитині 3 роки" "strict"
sleep 3
sse_run "D3" "Іграшки 4 роки" "48м+" "d3_${TS}" "іграшки для 4 років" "strict"
sleep 3
sse_run "D4" "Подарунок 5 років" "60м+" "d4_${TS}" "що подарувати 5 років" "strict"
sleep 3

# ======================================================================
# E. Прямі пошуки заборонених товарів (там вони ОК - це каталог)
# ======================================================================
echo ""
echo "--- E. Прямі пошуки \"заборонених\" (не gift контекст) ---"
echo "" >> "$OUT"
echo "## E. Прямі пошуки заборонених товарів (каталог, не gift)" >> "$OUT"
echo "" >> "$OUT"

sse_run "E1" "Прямий пошук: підвіски" "Мають знайтись (це каталог)" "e1_${TS}" "підвіски на ліжечко" "none"
sleep 3
sse_run "E2" "Прямий пошук: фартух" "Може знайтись (це не gift запит)" "e2_${TS}" "фартух для малювання" "none"
sleep 3
sse_run "E3" "Прямий пошук: коробочка постійності" "Знайдеться, 0-12м" "e3_${TS}" "коробочка постійності" "none"
sleep 3
sse_run "E4" "Прямий пошук: електронний зошит" "Може бути PDF-продукт" "e4_${TS}" "електронний зошит монтессорі" "none"
sleep 3

# ======================================================================
# F. Follow-up з заміною товару (multi-turn)
# ======================================================================
echo ""
echo "--- F. Follow-up виключення (multi-turn) ---"
echo "" >> "$OUT"
echo "## F. Виключення небажаного товару в діалозі" >> "$OUT"
echo "" >> "$OUT"

SID_F="alina_exclude_${TS}"
sse_run "F1" "Старт: подарунок рік" "Набір товарів для 1р" "$SID_F" "подарунок на рік" "strict"
sleep 3
sse_run "F2" "Виключи PDF / електронне" "Не пропонувати цифрові" "$SID_F" "тільки фізичні товари, не електронні" "strict"
sleep 3
sse_run "F3" "Покажи ще" "Нові товари, без заборонених" "$SID_F" "покажи ще варіанти" "strict"
sleep 3

# ======================================================================
# SUMMARY
# ======================================================================
{
    echo ""
    echo "---"
    echo ""
    echo "## Підсумок"
    echo ""
    echo "- **Усього тестів:** $TOTAL"
    echo "- **PASS:** $PASS ✅"
    echo "- **WARN:** $WARN ⚠️"
    echo "- **FAIL:** $FAIL ❌"
    echo ""
} >> "$OUT"

echo ""
echo "=================================================="
echo "  TOTAL: $TOTAL | PASS: $PASS | WARN: $WARN | FAIL: $FAIL"
echo "  Report: $OUT"
echo "=================================================="
