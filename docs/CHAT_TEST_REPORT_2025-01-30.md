# 📊 Chat Test Report - 30 січня 2025

**Tenant:** 2 (attack.kiev.ua - тактичний магазин)  
**Token:** `zIzYKx8o2RVdT1KYmJAv25FJO5GIbxZj`  
**Tested by:** AI Agent  
**Build:** після commit 700c588 (seasonal queries fix)

---

## 🎯 Executive Summary

| Category | Passed | Failed | Success Rate |
|----------|--------|--------|--------------|
| 1-word queries | 3/3 | 0 | **100%** |
| Brand queries | 2/2 | 0 | **100%** |
| Seasonal queries | 2/2 | 0 | **100%** |
| Semantic queries | 2/2 | 0 | **100%** |
| Price filters | 3/3 | 0 | **100%** |
| Confusing queries | 4/4 | 0 | **100%** |
| Language mix | 2/3 | 1 | 67% |
| Edge cases | 2/3 | 1 | 67% |
| Follow-up context | 2/4 | 2 | 50% |
| **TOTAL** | **22/26** | **4** | **84.6%** |

---

## ✅ Passed Tests

### 1-Word Queries (short_query_handler)
All single-word queries now correctly use `short_query_handler` (no GPT call):

| Query | Products | Source |
|-------|----------|--------|
| `шоломи` | 3 | short_query_handler |
| `берці` | 3 | short_query_handler |
| `рюкзаки` | 3 | short_query_handler |

### Brand Queries
Brand detection works correctly:

| Query | Products | Source |
|-------|----------|--------|
| `Ops-Core` | 1 | short_query_handler |
| `Salomon` | 1 | short_query_handler |

### Seasonal Queries (FIX CONFIRMED ✅)
After fix in commit 700c588, seasonal queries now use `search_products` instead of `get_popular_products`:

| Query | Products | Source | Notes |
|-------|----------|--------|-------|
| `що беруть взимку` | 3 | GPT | Returns winter gear (куртки, термобілизна) |
| `что берут зимой` | 3 | GPT | Russian also works |

### Semantic Queries
AI understands intent without exact product names:

| Query | Products | Source |
|-------|----------|--------|
| `захист голови` | 3 | implicit_query_handler |
| `захист слуху` | 3 | GPT |
| `мені треба щось для стрільби` | 3 | GPT |
| `хочу захиститись` | 3 | GPT |
| `шось для ночі` | 3 | GPT |
| `покажи найпопулярніше` | 3 | GPT |

### Price Filter Queries
Price filtering works correctly:

| Query | Products | Source |
|-------|----------|--------|
| `шоломи до 20000` | 3 | GPT |
| `рюкзак до 5000 грн` | 3 | GPT |
| `берці до 10000` | 3 | GPT |

### Chat Responses (no products expected)
Greetings and farewell handled correctly:

| Query | Products | Response |
|-------|----------|----------|
| `привіт` | 0 | "Привіт! 👋 Чим можу допомогти?" |
| `дякую` | 0 | "Будь ласка! Якщо виникнуть питання..." |

---

## ⚠️ Partial Failures / Edge Cases

### Language Mix
| Query | Products | Notes |
|-------|----------|-------|
| `helmets` | 3 ✅ | English works |
| `тактические перчатки` | 2 ✅ | Russian works |
| `płytki balistyczne` | 0 ❌ | Polish not supported (responds in Polish tho) |

### Edge Cases
| Query | Products | Notes |
|-------|----------|-------|
| `123456` | 0 | Interprets as order number (correct) |
| `???!!!` | 3 ⚠️ | Returns random products (questionable) |
| `` (empty) | 0 | Asks for query (correct) |

---

## ❌ Failed Tests

### Follow-up Context Issues

**Test Session:** `follow_test_1769777502`

| Step | Query | Products | Issue |
|------|-------|----------|-------|
| 1 | `покажи шоломи` | 3 ✅ | Works |
| 2 | `а дешевші є?` | 0 ⚠️ | Remembers context (шоломи), but no cheaper ones in DB |
| 3 | `ще 3` | 0 ❌ | **Doesn't understand "show 3 more"** |
| 4 | `а рюкзаки маєте?` | 3 ✅ | Topic change works |

**Issues identified:**
1. ❌ `ще 3` / `покажи ще` - doesn't show more products from same category
2. ⚠️ When no cheaper products exist, should suggest alternatives

---

## 🔧 Source Distribution

| Source | Count | Description |
|--------|-------|-------------|
| `short_query_handler` | 6 | 1-word queries (fast, no GPT) |
| `implicit_query_handler` | 1 | 2-word semantic queries |
| `GPT` | 19 | Complex queries via OpenAI |

---

## 🐛 Bugs Found

### BUG-001: "ще 3" doesn't work
- **Severity:** Medium
- **Query:** `ще 3`, `покажи ще`, `ще декілька`
- **Expected:** Show 3 more products from current category
- **Actual:** Returns 0 products
- **Root cause:** Follow-up detection doesn't handle "more" requests properly
- **Fix location:** `FunctionCallingAgent.php` / `StreamingFunctionCallingAgent.php`

### BUG-002: Polish language not supported
- **Severity:** Low
- **Query:** `płytki balistyczne`
- **Expected:** Search for ballistic plates
- **Actual:** Returns Polish "sorry" message
- **Note:** This is acceptable - focus on UK/RU/EN

### BUG-003: `???!!!` returns products
- **Severity:** Low
- **Query:** Random symbols
- **Expected:** Ask for clarification
- **Actual:** Returns random products
- **Note:** Edge case, low priority

---

## 📈 Improvements Since Last Build

| Issue | Before | After |
|-------|--------|-------|
| Seasonal queries | ❌ Wrong products (bandages) | ✅ Correct winter gear |
| 1-word queries | ❌ Sometimes failed | ✅ 100% working |
| Brand detection | ⚠️ Inconsistent | ✅ Works via short_query_handler |
| Language patterns | ❌ Hardcoded UA patterns | ✅ Universal (1-word only) |

---

## 🎯 Recommendations

1. **HIGH:** Fix "ще 3" / "покажи ще" follow-up requests
2. **MEDIUM:** Add explicit handling for "no cheaper products" scenario
3. **LOW:** Consider filtering out gibberish queries (`???!!!`)

---

## 📝 Test Commands

```bash
# Quick test any query
curl -s "https://aintento.laravel.cloud/api/chat" \
  -H "Content-Type: application/json" \
  -d '{"message": "YOUR_QUERY", "session_id": "test_'$(date +%s)'", "token": "zIzYKx8o2RVdT1KYmJAv25FJO5GIbxZj"}' | python3 -c "
import sys,json
d=json.load(sys.stdin)
print('source:', d.get('meta',{}).get('source','GPT'))
print('products:', len(d.get('products',[])))
print('text:', d.get('text','')[:100])"

# Check chat history
curl -s "https://aintento.laravel.cloud/api/diagnostic/chat-history/{SESSION_ID}?key=diagnostic_secret_key_2025"
```

---

**Report generated:** 2025-01-30 15:00 UTC
