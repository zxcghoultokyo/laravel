#!/usr/bin/env python3
"""Test SSE chat for bavkatoys with UTF-8 safe queries."""
import json
import re
import sys
import time
import urllib.parse
import urllib.request

BASE = "https://aintento.laravel.cloud/api/chat/stream"
TENANT = 20

TESTS = [
    ("G1", "щось на подарунок на рік", "strict"),
    ("G2", "подарунковий набір для малюка рік", "strict"),
    ("G3", "подарунок на рочок", "strict"),
    ("G4", "що подарувати на годик", "strict"),
    ("G5", "подарунок на 1 рік хлопчику", "strict"),
    ("G6", "подарунковий сертифікат", "allow_cert"),  # user explicitly wants cert
    ("G7", "іграшки для дитини 6 місяців", "allow_newborn"),
]

BAD_PATTERNS = {
    "certificate": re.compile(r"сертифікат|gift\s*card", re.IGNORECASE),
    "newborn_0_1": re.compile(r"малюкам\s+0\s*[–\-]\s*1|рання\s+пташ|новонародж|немовлят", re.IGNORECASE),
    "non_gift": re.compile(r"фартух|нарукавник|підвіск", re.IGNORECASE),
}


def run(test_id, msg, mode):
    sid = f"test_{int(time.time())}_{test_id}"
    url = f"{BASE}?message={urllib.parse.quote(msg)}&session_id={sid}&tenant_id={TENANT}"
    req = urllib.request.Request(url, headers={"Accept": "text/event-stream"})
    text_parts = []
    products = []
    trace = None
    tools = []
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            for line in resp:
                line = line.decode("utf-8", errors="replace").strip()
                if not line.startswith("data:"):
                    continue
                payload = line[5:].strip()
                if not payload:
                    continue
                try:
                    obj = json.loads(payload)
                except Exception:
                    continue
                t = obj.get("type")
                if t == "text":
                    text_parts.append(obj.get("text", ""))
                elif t == "products":
                    products = obj.get("products", [])
                elif t == "done":
                    trace = obj.get("trace_summary")
                elif t == "tool_call":
                    tools.append(obj.get("name", ""))
    except Exception as e:
        return {"id": test_id, "msg": msg, "error": str(e)}

    text = " ".join(text_parts)
    issues = []
    for name, pat in BAD_PATTERNS.items():
        if name == "certificate" and mode == "allow_cert":
            continue
        if name == "newborn_0_1" and mode == "allow_newborn":
            continue
        hits = []
        for p in products:
            blob = (p.get("title", "") + " " + p.get("category_path", ""))
            if pat.search(blob):
                hits.append(p.get("title", "")[:50])
        if hits:
            issues.append(f"{name}: {hits}")

    category = None
    filter_str = None
    handler = None
    if trace:
        for step in trace.get("steps", []):
            if step.get("step") == "meili.category_resolved":
                category = step.get("category")
            if step.get("step") == "meili.search_execute":
                filter_str = step.get("filter")
            if "handler" in step:
                handler = step.get("handler")

    return {
        "id": test_id,
        "msg": msg,
        "mode": mode,
        "product_count": len(products),
        "products": [p.get("title", "")[:60] + " [" + p.get("category_path", "")[:30] + "]" for p in products],
        "text": text[:200],
        "category": category,
        "filter": filter_str,
        "handler": handler,
        "tools": tools,
        "issues": issues,
        "status": "PASS" if not issues else "FAIL",
    }


if __name__ == "__main__":
    results = []
    for tid, msg, mode in TESTS:
        r = run(tid, msg, mode)
        results.append(r)
        print(f"\n━━━ {r['id']}: {r['msg']}  [{r.get('status','ERR')}]")
        print(f"  category: {r.get('category')} | handler: {r.get('handler')} | products: {r.get('product_count')}")
        if r.get("filter"):
            print(f"  filter: {r['filter'][:150]}")
        if r.get("text"):
            print(f"  text: {r['text'][:150]}")
        for t in r.get("products", []):
            print(f"    - {t}")
        if r.get("issues"):
            for iss in r["issues"]:
                print(f"  ❌ {iss}")
        if r.get("error"):
            print(f"  ERROR: {r['error']}")

    passed = sum(1 for r in results if r.get("status") == "PASS")
    print(f"\n═══════════════════════════════════════")
    print(f"TOTAL: {passed}/{len(results)} passed")
