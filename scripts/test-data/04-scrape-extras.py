"""
Second pass over the same product pages, for everything the product card did
not need: the freight class, the spec sheet and manual, the documents linked
from the body, and the videos embedded in it.

    python3 -I 04-scrape-extras.py cadco-products-50.json cadco-extras.json

Keyed by SKU, so it lines up with the products the first pass selected.
"""
import html as H
import json
import re
import sys
import time
import urllib.request

BASE = "https://www.cadco-ltd.com"
SRC, OUT = sys.argv[1], sys.argv[2]


def get(url):
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read().decode("utf-8", "replace")


def text(s):
    s = re.sub(r"(?s)<[^>]+>", " ", s)
    return re.sub(r"\s+", " ", H.unescape(s)).strip()


def absolute(href):
    href = href.strip()
    if href.startswith("//"):
        return "https:" + href
    if href.startswith("/"):
        return BASE + href
    return href


def tidy(label):
    """The site SHOUTS most link labels; title case reads better on a card."""
    label = re.sub(r"\s+", " ", label).strip(" .;:-")
    if label.isupper() and len(label) > 4:
        label = label.title()
    # keep the brand and the acronyms the title-caser flattens
    for a, b in (("Bakerlux", "BAKERLUX"), ("Go Led", "GO LED"), ("Led", "LED"),
                 ("Pdf", "PDF"), ("Usa", "USA"), ("Hs", "HS"), ("Fs", "FS")):
        label = re.sub(r"\b" + a + r"\b", b, label)
    return label


YT = re.compile(r"(?:youtube\.com/embed/|youtu\.be/|youtube\.com/watch\?v=)([A-Za-z0-9_-]{6,})")


def parse(raw):
    out = {"freight": "", "specSheet": "", "manual": "", "documents": [], "videos": []}

    side = re.search(r'(?s)<div class="col-md-4">(.*?)</div>\s*</div>', raw)
    side = side.group(1) if side else ""

    m = re.search(r"(?s)Freight Class:\s*</strong>(.*?)</p>", side)
    if m:
        out["freight"] = text(m.group(1))

    for label, key in (("Download Spec Sheet", "specSheet"), ("Download Manual", "manual")):
        m = re.search(r'<a href="([^"]+)">\s*' + label + r"\s*</a>", side)
        if m:
            out[key] = absolute(m.group(1))

    main = re.search(r'(?s)<div class="row product">(.*?)<div class="col-md-4">', raw)
    main = main.group(1) if main else raw

    # Documents: every PDF the body links to, in the order they appear.
    seen = set()
    for m in re.finditer(r'(?s)<a [^>]*href="([^"]+\.pdf)"[^>]*>(.*?)</a>', main, re.I):
        url, label = absolute(m.group(1)), tidy(text(m.group(2)))
        if not label or url in seen:
            continue
        seen.add(url)
        out["documents"].append({"label": label, "url": url})

    # Videos: the caption is the heading that introduces the embed, so each
    # <figure> is matched together with the nearest heading before it.
    for m in re.finditer(r"(?s)<(h[2-5])>(.*?)</\1>(.*?)(?=<h[2-5]>|$)", main):
        caption, body = text(m.group(2)), m.group(3)
        for v in re.finditer(r'<iframe[^>]+src="([^"]+)"', body):
            y = YT.search(v.group(1))
            if not y:
                continue
            out["videos"].append({
                "title": caption or "Video",
                "url": "https://www.youtube.com/watch?v=" + y.group(1),
                "youtubeId": y.group(1),
            })

    # de-dup videos by id, keeping the first caption
    seen = set()
    out["videos"] = [v for v in out["videos"] if not (v["youtubeId"] in seen or seen.add(v["youtubeId"]))]
    return out


products = json.load(open(SRC))
result = {}

for i, p in enumerate(products):
    slug = p["source"].rsplit("/", 1)[-1]
    try:
        raw = get(p["source"])
    except Exception as e:
        print(f"FAIL {slug}: {e}", file=sys.stderr)
        continue
    result[p["sku"]] = parse(raw)
    if (i + 1) % 10 == 0:
        print(f"  {i+1}/{len(products)}", file=sys.stderr)
    time.sleep(0.3)

json.dump(result, open(OUT, "w"), indent=1)

docs = {d["url"] for v in result.values() for d in v["documents"]}
vids = {v["youtubeId"] for r in result.values() for v in r["videos"]}
print(f"{len(result)} products | {len(docs)} unique documents | {len(vids)} unique videos", file=sys.stderr)
print("with freight:", sum(1 for v in result.values() if v["freight"]), file=sys.stderr)
print("with spec sheet:", sum(1 for v in result.values() if v["specSheet"]), file=sys.stderr)
print("with manual:", sum(1 for v in result.values() if v["manual"]), file=sys.stderr)
