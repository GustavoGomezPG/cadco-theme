import json, re, sys, urllib.request, time, os

BASE = "https://www.cadco-ltd.com"
OUT = sys.argv[1]
TYPES = [
    "convection-ovens", "bakerlux-stations", "fast-cooking-ovens",
    "foodservice-carts", "griddles", "hot-plates", "toasters",
    "panini-clamshell-grills",
    "buffet-servers-warming-cabinets-warming-shelves",
]

def get(url):
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read().decode("utf-8", "replace")

result = {}
for t in TYPES:
    url = f"{BASE}/products/types/{t}/"
    try:
        html = get(url)
    except Exception as e:
        print(f"FAIL {t}: {e}", file=sys.stderr)
        continue
    slugs = []
    seen = set()
    for m in re.finditer(r'href="/product/([a-z0-9][a-z0-9\-\._]*)"', html, re.I):
        s = m.group(1)
        if s not in seen:
            seen.add(s)
            slugs.append(s)
    result[t] = slugs
    print(f"{t}: {len(slugs)}")
    time.sleep(0.4)

os.makedirs(os.path.dirname(OUT), exist_ok=True)
with open(OUT, "w") as f:
    json.dump(result, f, indent=1)
print("total unique:", len({s for v in result.values() for s in v}))
