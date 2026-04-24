#!/usr/bin/env python3
"""Parse SSE stream from stdin, emit compact JSON summary."""
import sys, json

chunks = []
products = []
status_events = []
tools = []
source = ""
err = ""
trace_path = ""

for line in sys.stdin:
    line = line.rstrip("\n")
    if not line.startswith("data:"):
        continue
    data = line[5:].strip()
    if not data:
        continue
    try:
        d = json.loads(data)
    except Exception:
        continue
    t = d.get("type", "")
    if t == "chunk":
        chunks.append(d.get("text", d.get("content", "")))
    elif t == "products":
        for p in d.get("products", []):
            products.append({
                "title": (p.get("title") or "")[:80],
                "article": p.get("article", ""),
                "price": p.get("price"),
                "category_path": (p.get("category_path") or "")[:60],
                "age_min": p.get("age_min_months"),
                "age_max": p.get("age_max_months"),
            })
    elif t == "status":
        status_events.append(d.get("phase", d.get("text", "")))
    elif t == "text":
        chunks.append(d.get("text", d.get("content", "")))
    elif t == "error":
        err = (d.get("message") or d.get("error") or "")[:200]
    elif t == "done":
        ts = d.get("trace_summary") or {}
        steps = ts.get("path", []) or []
        if steps:
            trace_path = ",".join(steps[-6:])
        meta = d.get("meta", {}) or {}
        source = meta.get("source") or meta.get("agent") or ""
        tools = meta.get("tools_called", []) or []

if not source and trace_path:
    source = trace_path

text = "".join(chunks).strip()
out = {
    "text": text,
    "products": products,
    "source": source,
    "tools": tools,
    "error": err,
}
print(json.dumps(out, ensure_ascii=False))
