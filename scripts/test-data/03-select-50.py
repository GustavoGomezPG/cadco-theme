import json, sys, re, collections

SRC, OUT = sys.argv[1], sys.argv[2]
data = json.load(open(SRC))

# live-site top-level type  ->  local top-level product_cat slug
TOP = {
    "convection-ovens": "convection-cook-hold-ovens",
    "bakerlux-stations": "convection-cook-hold-ovens",
    "fast-cooking-ovens": "fast-cooking-ovens",
    "fast-cooking": "fast-cooking-ovens",
    "foodservice-carts": "food-service-carts",
    "griddles": "countertop-equipment",
    "hot-plates": "countertop-equipment",
    "toasters": "countertop-equipment",
    "panini-clamshell-grills": "countertop-equipment",
    "buffet-servers-warming-cabinets-warming-shelves": "countertop-equipment",
}
# a live top-level type that becomes a CHILD term locally
AS_CHILD = {
    "bakerlux-stations": ("bakerlux-stations", "Bakerlux Stations"),
    "griddles": ("griddles", "Griddles"),
    "hot-plates": ("hot-plates", "Hot Plates"),
    "toasters": ("toasters", "Toasters"),
    "panini-clamshell-grills": ("panini-clamshell-grills", "Panini & Clamshell Grills"),
    "buffet-servers-warming-cabinets-warming-shelves": ("buffet-servers-warming-shelves", "Buffet Servers & Warming Shelves"),
}
QUOTA = {
    "convection-ovens": 22, "bakerlux-stations": 6, "fast-cooking-ovens": 6,
    "foodservice-carts": 6, "panini-clamshell-grills": 3, "hot-plates": 2,
    "toasters": 2, "griddles": 1,
    "buffet-servers-warming-cabinets-warming-shelves": 2,
}

SKU_RE = re.compile(r"^[A-Z0-9][A-Z0-9+/().\u2019'-]*$")

def normalise(p):
    """Some pages put the product NAME in the <h2> and 'Model: SKU' in the line under it."""
    sku, sub = p["sku"].strip(), p["subtitle"].strip()
    if " " in sku and not SKU_RE.match(sku):
        m = re.match(r"^\s*Model\s*:\s*(\S+)\s*$", sub, re.I)
        if m:
            p = dict(p, sku=m.group(1).strip(), subtitle=sku)
        elif SKU_RE.match(sub) and " " not in sub:
            p = dict(p, sku=sub, subtitle=sku)
        else:
            m2 = re.match(r"^(\S+)[\s,:]+(.+)$", sub)
            if m2 and SKU_RE.match(m2.group(1)):
                p = dict(p, sku=m2.group(1), subtitle=sku + ", " + m2.group(2))
    return p

_SMALL = {"w/", "for", "and", "with", "the", "of", "in", "to", "a"}

def _case(word):
    """Title-case a SHOUTED word; leave mixed case, numbers and short acronyms alone."""
    if not word.isupper():
        return word
    core = re.sub(r"[^A-Za-z]", "", word)
    if len(core) <= 3 or any(ch.isdigit() for ch in word):
        return word                      # LED, GO, IR, 220V, XAF-113
    return "-".join(w.capitalize() for w in word.split("-"))

def _clean(seg):
    seg = re.sub(r"\s+", " ", seg).strip(" .;:-")
    return " ".join(_case(w) for w in seg.split(" "))

def _split(sub):
    """Split on commas that are not inside parentheses."""
    out, depth, cur = [], 0, ""
    for ch in sub:
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth = max(0, depth - 1)
        if ch == "," and depth == 0:
            out.append(cur)
            cur = ""
        else:
            cur += ch
    out.append(cur)
    return [x.strip() for x in out if x.strip()]

def _strip_sku(sub, sku):
    """Drop a leading 'Model: XX-YY' or bare SKU from the descriptive line."""
    s = re.sub(r"^\s*Model\s*:\s*", "", sub, flags=re.I)
    s = re.sub(r"^\s*" + re.escape(sku) + r"\b[\s,:-]*", "", s, flags=re.I)
    return s.strip() or sub.strip()

def title_for(p):
    """Design card title = descriptive name + SKU, e.g. 'Bakerlux Half Size XAFT-03HS-GD'."""
    parts = _split(_strip_sku(p["subtitle"], p["sku"]))
    head = _clean(parts[0]) if parts else ""
    if re.fullmatch(r"\d+\s*v", head, re.I) or len(head) < 5:
        head = ""
    if not head or len(head) > 52:
        head = _clean(p["cats"][-1]["name"]) if p["cats"] else ""
    return f"{head} {p['sku']}".strip()

def short_for(p):
    """Design card description = the spec line under the title."""
    parts = _split(_strip_sku(p["subtitle"], p["sku"]))
    head = _clean(parts[0]) if parts else ""
    # a voltage-only first segment belongs in the description, not the title
    keep_head = bool(re.fullmatch(r"\d+\s*v", head, re.I) or (head and len(head) < 5))
    rest = [_clean(x) for x in (parts if keep_head else parts[1:])]
    if rest:
        # Comma-separated, not space-joined: the card renders these as separate
        # lines and the product hero as bullets, so the segment boundaries have
        # to survive into the stored short description.
        return ", ".join(rest)
    # single-segment subtitle: fall back to the product's own headline specs
    keep = [v for k, v in p["specs"] if k.lower() in ("size", "shelves", "volts", "watts", "capacity")]
    if keep:
        pairs = [f"{k}: {v}" for k, v in p["specs"]
                 if k.lower() in ("size", "shelves", "volts", "capacity")][:3]
        return " \u00b7 ".join(pairs)
    if p["features"]:
        return _clean(p["features"][0])
    if p["specs"]:
        return " \u00b7 ".join(f"{k}: {v}" for k, v in p["specs"][:2])
    return ""

picked, used = [], set()
by_type = collections.defaultdict(list)
for p in data:
    by_type[p["src_type"]].append(p)

for t, n in QUOTA.items():
    taken = 0
    for p in by_type.get(t, []):
        if taken >= n or p["slug"] in used:
            continue
        used.add(p["slug"])
        taken += 1
        p = normalise(p)

        top_slug = TOP[t]
        terms = [{"slug": top_slug, "name": None, "parent": None}]
        if t in AS_CHILD:
            s, nm = AS_CHILD[t]
            terms.append({"slug": s, "name": nm, "parent": top_slug})
        # real sub-series from the live site's own Product Type list
        for c in p["cats"]:
            path = c["path"]
            if "/" not in path:
                continue
            head, tail = path.split("/", 1)
            if head not in TOP:
                continue
            child_slug = tail.split("/")[-1]
            terms.append({"slug": child_slug, "name": c["name"].title() if c["name"].isupper() else c["name"],
                          "parent": AS_CHILD[head][0] if head in AS_CHILD else TOP[head]})
        # de-dup, keep order
        seen, out_terms = set(), []
        for x in terms:
            if x["slug"] in seen:
                continue
            seen.add(x["slug"])
            out_terms.append(x)

        picked.append({
            "sku": p["sku"],
            "title": title_for(p),
            "short": short_for(p),
            "subtitle": p["subtitle"],
            "price": p["price"],
            "image": p["image"],
            "specs": p["specs"],
            "features": p["features"],
            "terms": out_terms,
            "source": p["url"],
        })

json.dump(picked, open(OUT, "w"), indent=1)
print("selected", len(picked))
cc = collections.Counter(t["slug"] for p in picked for t in p["terms"])
for k, v in cc.most_common():
    print(f"  {v:3d}  {k}")
print()
for p in picked[:4]:
    print(f"{p['title']!r:60s} | {p['short']!r} | ${p['price']}")
