import json, re, sys, os, time, html as H, urllib.request

BASE = "https://www.cadco-ltd.com"
URLS_JSON, OUT = sys.argv[1], sys.argv[2]

# how many to take from each type page, in order
QUOTA = {
    "convection-ovens": 30,
    "bakerlux-stations": 8,
    "fast-cooking-ovens": 7,
    "foodservice-carts": 8,
    "panini-clamshell-grills": 4,
    "hot-plates": 3,
    "toasters": 3,
    "griddles": 3,
    "buffet-servers-warming-cabinets-warming-shelves": 4,
}

def get(url):
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read().decode("utf-8", "replace")

def text(s):
    s = re.sub(r"(?s)<[^>]+>", " ", s)
    s = H.unescape(s)
    return re.sub(r"\s+", " ", s).strip()

def parse(slug, raw):
    d = {"slug": slug, "url": f"{BASE}/product/{slug}"}

    m = re.search(r'(?s)<div class="row product">(.*?)<div class="col-md-4">', raw)
    main = m.group(1) if m else raw

    m = re.search(r"(?s)<h2>(.*?)</h2>", main)
    d["sku"] = text(m.group(1)) if m else slug.upper()

    # first <p> after the h2 is the descriptive subtitle
    rest = main[m.end():] if m else main
    m2 = re.search(r"(?s)<p>(.*?)</p>", rest)
    d["subtitle"] = text(m2.group(1)) if m2 else ""

    m3 = re.search(r"(?s)List Price:\s*</strong>\s*([^<]+)", main)
    d["price_text"] = text(m3.group(1)) if m3 else ""
    pm = re.search(r"([\d,]+\.\d\d)", d["price_text"])
    d["price"] = pm.group(1).replace(",", "") if pm else ""

    m4 = re.search(r'<img class="main"[^>]*src="([^"]+)"', main)
    d["image"] = (BASE + m4.group(1)) if m4 else ""

    # body: everything from the <h3>Specifications</h3> onwards, minus videos
    sm = re.search(r"(?s)(<h3>\s*Specifications.*)$", main)
    body = sm.group(1) if sm else ""
    body = re.sub(r"(?s)<figure>.*?</figure>", "", body)
    body = re.sub(r"(?s)<iframe.*?</iframe>", "", body)
    d["body_html"] = body.strip()

    # spec pairs
    specs = []
    for pm2 in re.finditer(r"(?s)<p>(.*?)</p>", body):
        chunk = pm2.group(1)
        sm2 = re.search(r"(?s)<strong>(.*?)</strong>(.*)", chunk)
        if not sm2:
            continue
        k, v = text(sm2.group(1)).rstrip(":").strip(), text(sm2.group(2))
        if k and v:
            specs.append([k, v])
    d["specs"] = specs

    # feature bullets (first <ul> in the body)
    fm = re.search(r"(?s)<h3>\s*Features\s*</h3>(.*?)(?:<h3|$)", body)
    feats = []
    if fm:
        for li in re.finditer(r"(?s)<li>(.*?)</li>", fm.group(1)):
            t = text(li.group(1))
            if t:
                feats.append(t)
    d["features"] = feats

    # product type categories, in document order (parent then child)
    cm = re.search(r"(?s)<strong>Product Type</strong>\s*<ul class=\"list-cats\">(.*?)</ul>", raw)
    cats = []
    if cm:
        for a in re.finditer(r'(?s)<li><a href="/products/types/([^"]+)">(.*?)</a></li>', cm.group(1)):
            path = a.group(1).strip("/")
            cats.append({"path": path, "name": text(a.group(2))})
    d["cats"] = cats
    return d

urls = json.load(open(URLS_JSON))
picked, seen = [], set()
for t, n in QUOTA.items():
    for s in urls.get(t, []):
        if len([p for p in picked if p[0] == t]) >= n:
            break
        if s in seen:
            continue
        seen.add(s)
        picked.append((t, s))

print("picked", len(picked), file=sys.stderr)
out = []
for i, (t, s) in enumerate(picked):
    try:
        raw = get(f"{BASE}/product/{s}")
    except Exception as e:
        print(f"FAIL {s}: {e}", file=sys.stderr)
        continue
    d = parse(s, raw)
    d["src_type"] = t
    out.append(d)
    if (i + 1) % 10 == 0:
        print(f"  {i+1}/{len(picked)}", file=sys.stderr)
    time.sleep(0.3)

with open(OUT, "w") as f:
    json.dump(out, f, indent=1)
print("wrote", len(out), "to", OUT, file=sys.stderr)
