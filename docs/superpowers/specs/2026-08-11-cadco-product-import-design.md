# CADCO Product Import — Design

**Date:** 2026-08-11
**Status:** Approved
**Source of truth:** `Product Index Spreadsheet 2026_Website_.xlsx`

---

## 1. Purpose

CADCO maintains its entire product catalogue in a single Excel workbook. That
workbook is the authority: when it changes, the website must change to match.

This system parses that workbook, decides which products to add, update, rename
and remove, reports every inconsistency it finds, and applies the result only
once the data is clean.

The site is a **catalogue, not a shop**. There is no cart, checkout or payment —
see `inc/cadco-woocommerce.php`. WooCommerce supplies the product post type,
taxonomies, admin screens and URL structure; nothing here assumes selling.

---

## 2. Context established before design

Findings that shaped the decisions below. Each was verified against the data,
not assumed.

### 2.1 The workbook is structurally unstable

Column *positions* differ between sheets in the same file:

| Sheet | Leading `Notes` column | Trailing `Other` column |
|---|---|---|
| CONVECTION OVENS | yes | no |
| FAST COOKING OVENS | no | yes |
| COUNTERTOP EQUIPMENT | no | no |
| FOODSERVICE CARTS | yes | no |

Comparing against the previous revision (`V09052025-02.xlsx`) shows the shape
changes between versions too: that file had **10 differently-named sheets** and
a `Third Category` column that no longer exists, while `Type`, `Parent Product`
and `Notes` are new.

**Therefore: columns are read by header name, never by index. Sheet names are
matched case-insensitively and an unrecognised sheet is an error, not a
silent skip.**

### 2.2 Products get renamed, and only UPC reveals it

Keyed on `Model #` alone, the previous-to-current revision looks like 25
deletions and 17 additions. Matching on UPC shows at least 8 are renames:

```
OV-003   → BLC-003    UPC 654796-53250-6 unchanged
OV-013   → BLC-013    UPC 654796-53350-3 unchanged
OV-023   → BLC-023    UPC 654796-53400-5 unchanged
XAF-113  → BLC-113    UPC 654796-52113-5 unchanged
XAF-133  → BLC-133    UPC 654796-52133-3 unchanged
XAFS-113 → BLS-113    UPC 654796-56106-1 unchanged
XAFS-193-2 → BLS-193-2  UPC 654796-57102-1 unchanged
```

This is also why 47 rows carry a `Website URL` whose slug disagrees with
`Model #` — the URL preserves the *legacy* model number.

Treating these as delete-plus-create would destroy 8 products' post IDs, slugs,
media attachments and inbound links.

**Therefore: rename detection is required, but never automatic — see §6.**

### 2.3 UPC is not trustworthy enough to be the primary key

Four UPCs are duplicated in the current workbook, and they are not all the same
kind of problem:

```
654796-54513-7   OP-4        both sheets — same physical product, cross-listed
654796-54513-8   OP-8        both sheets — same physical product, cross-listed
654796-55145-3   BLS-4HLD-2 / PGW-10   unrelated products — data-entry error
654796-99414-4   SS-32 / SS-54         different sizes — data-entry error
```

One row (`MTD-1418-2D`) has no UPC at all.

**Therefore: `Model #` is the primary key and UPC is a corroborating signal.**

### 2.4 The existing category tree is unused scaffolding

26 `product_cat` terms exist, hand-built, all with **0 products**. The site has
**0 products** in total. Nothing is at risk from rebuilding the tree, so it is
derived from the workbook rather than maintained by hand.

### 2.5 Category signal lives in the sheet name and `Type`

`Primary Category` has only 2 distinct values across 4 sheets ('Food Service',
'Convection Ovens') and contradicts the sheet it appears in. It carries no
usable information.

The real signal is the **sheet name** (top level) and **`Type`** (29 values,
sub-level). One `Type` cell is multi-valued:
`'Buffet Server & Warming Shelf , ACCESSORIES for Demo / Sampling Carts'`.

### 2.6 Media is not reachable

Of 233 `Images URL` values, 198 are SharePoint links requiring CADCO tenant
authentication and 35 are bare filenames (`CAP-F.jpeg`) with no matching file
anywhere on disk. The uploads directory contains zero product images. The same
applies to Spec Sheet, Manual, Diagram and Warranty URLs.

**Therefore: media is out of scope for this phase. URLs are stored so phase 2
can consume them.**

### 2.7 Attribute cardinality

29 candidate spec columns would produce 506 attribute terms. 396 of those come
from six numeric columns nobody filters by (Package Weight alone: 114 terms),
and 8 columns are constants — every `... Unit of Measure` column contains only
`Inches` or `Pounds` across all 238 rows.

---

## 3. Architecture

Six units. Each has one purpose, a defined interface, and no knowledge of the
next. The first three are pure functions requiring no WordPress.

```
Reader      XLSX → array of raw row dicts keyed by header name
    ↓
Normaliser  Trim, collapse whitespace, strip bullets and stray quotes,
            title-case, split multi-values. Records every change made.
    ↓
Validator   Applies the three tiers. Produces a Report. Returns pass/fail.
    ↓           ✗ fail → STOP. Nothing is written.
Planner     Diffs clean rows against current DB state.
            Emits a Plan: create / update / rename / trash / taxonomy ops.
    ↓
Applier     Executes a Plan in batches. Only ever runs on a passing Plan.
    ↓
Reporter    Renders Report and Plan to the admin screen; archives each run.
```

**The dry run is not a separate mode.** It is the pipeline stopping after
Planner. The preview and the applied result come from the same code path, so
the preview cannot drift from what actually happens.

### 3.1 File layout

```
inc/import/
  class-cadco-import-reader.php        XLSX → rows
  class-cadco-import-normaliser.php    cleanup + multi-value splitting
  class-cadco-import-validator.php     three-tier rules
  class-cadco-import-planner.php       diff against DB
  class-cadco-import-applier.php       batched writes
  class-cadco-import-report.php        report/plan value objects
  class-cadco-import-admin.php         Products → Import screen + AJAX
  class-cadco-product-meta-box.php     CADCO Specifications metabox
  field-map.php                        column → destination table
```

Loaded from `functions.php` alongside the existing `inc/` includes.

### 3.2 Dependency

XLSX parsing requires a reader. Use **PhpSpreadsheet**, vendored via Composer
into the theme and committed, so deployment needs no build step on the server.
Only the Reader touches it; every other unit works on plain arrays, so the
dependency can be swapped without touching business logic.

---

## 4. Field mapping

### 4.1 Native WooCommerce fields

| Column | Destination |
|---|---|
| `Model #` | SKU, and `post_name` (slug) |
| `Product Name` | `post_title` |
| `Primary Description` | `post_excerpt` (short description) |
| `Supplier Specifications - Bullet Points` | `post_content`, `•` lines → `<ul><li>` |
| `Secondary Description (Optional)` | appended to `post_content` as a second `<ul>` under a heading |
| `Height` / `Width` / `Depth` | product dimensions |
| `Weight` | product weight |
| `Brand Name` | `product_brand` (native, WooCommerce 11.0.0) |
| sheet name + `Type` | `product_cat` |
| `Specialties` | `product_tag` |

### 4.2 Product attributes

Eleven taxonomies, roughly 90 terms — chosen because each is genuinely
categorical and worth filtering by:

```
Material   Color   Voltage   Plug Type   Certifications (comma-split)
Lead Time  Country Of Origin  Freight Class  Shipping Method
Size       Capacity
```

### 4.3 Post meta

Prefixed `_cadco_`, surfaced through the CADCO Specifications metabox (§8.2):

```
wattage  amps  package_height  package_width  package_length  package_weight
upc  prop65_affected  prop65_warning  footnote  disclaimer
warranty_info  warranty_url  second_category  legacy_url
image_url  spec_sheet_url  manual_url  diagram_url  video_url
notes  parent_model  source_sheet  source_row  import_hash
```

### 4.4 Deliberately dropped

| Column | Reason |
|---|---|
| 8 × `... Unit of Measure` | Constants (`Inches` / `Pounds`). Woo's global unit setting covers native dimensions. |
| `Primary Category` | 2 values across 4 sheets; contradicts the sheet it appears in. |
| `Other` | Empty on all 238 rows. |

`Notes` is imported to meta but never rendered publicly — it holds internal
remarks like *"Phil facelifting spec sheets for BL pro stations"*.

---

## 5. Taxonomies

### 5.1 Categories are fully derived

All 26 existing terms are deleted. The tree is rebuilt from the workbook:

- **Top level** = sheet name, title-cased (`FOODSERVICE CARTS` → `Foodservice Carts`)
- **Child** = `Type`, title-cased
- Multi-valued `Type` (comma-separated) assigns the product to **several**
  categories
- `®` and `™` are stripped for slugs, retained in display names

Normalisation is limited to trim, whitespace collapse, title-case and comma
splitting. There is no per-value override file: the workbook is the single
source of truth, so `'Hot Plate'` stays singular and produces `/hot-plate/`.

### 5.2 Rewrite flushing

`inc/cadco-woocommerce.php` registers **one literal rewrite rule per term** and
sets `cadco_flush_category_rules` on `created_product_cat`,
`edited_product_cat` and `delete_product_cat`.

An import touching 30+ terms must therefore not flush per term. The Applier
completes **all** taxonomy work first, then triggers a **single** flush at the
end of the run.

### 5.3 Tags

From `Specialties`, split on newline, leading `•` stripped.

Normalisation is aggressive: case-insensitive matching, whitespace collapsed,
punctuation spacing normalised, stray quotes and stray inline bullets removed.
Variants collapse to the **most frequent** spelling in the workbook.

Every merge is reported so CADCO can correct the source — e.g.
`Steam Table/ Chafer Supplies` + `Steam Tables/Chafer Supplies` +
`Steam table / chafer supplies` → `Steam Table / Chafer Supplies`.

Note that normalisation *reports* these; under the validation policy (§7) they
also **block**, so in practice the sheet must be corrected before an import
runs. The normaliser exists so that near-miss variants are detected and named
precisely rather than silently creating duplicate tags.

### 5.4 Brands

Native `product_brand`, flat, six terms, imported as written.

### 5.5 Orphans

Categories, tags and brands holding zero products after a run are reported and
removed.

---

## 6. Identity and change detection

Every product stores its SKU and `_cadco_upc`. Each run:

1. Index DB products by SKU and by UPC.
2. For each row, match on **SKU** first; if none, try **UPC**.
3. A UPC match with a *different* SKU is a **rename candidate**. It is never
   applied silently: the plan lists it as
   `OV-003 → BLC-003 (UPC 654796-53250-6)` with a per-item approval checkbox.
   Approving updates the existing product's SKU and slug in place and records a
   redirect.
4. DB products matched by nothing → **Trash**.
5. Rows matched by nothing → **Create**.

### 6.1 Skipping unchanged rows

Each product stores `_cadco_import_hash`, a hash of its normalised source row.
A row whose hash is unchanged is skipped entirely. Re-running against an
unmodified workbook therefore performs **zero writes** — this is an explicit
test case.

### 6.2 Slugs and redirects

Slugs derive from `Model #`: `BLC-113` → `/products/bakerlux-classic/blc-113/`.

The 47 rows whose `Website URL` preserves a legacy model number produce a
redirect map (`legacy path → new path`), exported with the run for feeding into
Yoast's redirect manager. Renames approved in step 3 append to the same map.

### 6.3 Parent Product

`Parent Product` references another product's `Model #`. Both products stay
**simple**; the relationship is expressed as a related-products link.

Stored as `_cadco_parent_model` on the child. After all products are imported,
references are resolved to post IDs and the parent's related list gains its
children, via a `woocommerce_related_products` filter.
`templates/single-product.html` already renders the
`woocommerce-blocks/related-products` pattern, so no template change is needed.

Links are recomputed from scratch each run — never appended to — so a removed
reference disappears rather than lingering.

A `Parent Product` naming a `Model #` that does not exist in the workbook is a
Tier A error.

---

## 7. Validation

**All three tiers block the entire import.** Nothing is written until the
workbook is completely clean. The validation report is consequently the primary
deliverable: a worklist CADCO iterates against.

### Tier A — integrity

- blank or duplicate `Model #`
- blank or duplicate `UPC#`
- the same `Model #` appearing on more than one sheet (cross-listings must be
  deduplicated in the workbook; multi-category membership is expressed by a
  comma-separated `Type`)
- `Parent Product` referencing a `Model #` not present
- unrecognised sheet name or missing required header

### Tier B — consistency

Variant spellings within a column, detected case- and punctuation-insensitively:
`Specialties`, `Color`, `Material`, `Certifications`, `Country Of Origin`
(`IT` vs `Italy`), `Plug Type`, `Lead Time`, `Type`.

### Tier C — completeness

**Hard-required, must be non-blank:**
`UPC#`, `Model #`, `Product Name`, `Type`, `Primary Description`,
`Supplier Specifications - Bullet Points`, `Height`, `Width`, `Depth`,
`Weight`, `Brand Name`, `Lead Time`.

**`n/a` acceptable, blank is not** — the value must be stated, so that
"not applicable" is distinguishable from "not yet filled in":
`Certifications`, `Wattage`, `Voltage`, `Amps`, `Plug Type`, `Freight Class`,
`(UPS/FedEx or LTL?)`, `Package Height`, `Package Width`, `Package Length`,
`Package Weight`, `Country Of Origin`, `Material`, `Color`,
`Affected by Prop 65 Yes or No`.

**Optional**, may be blank: `Notes`, `Footnote`, `Description Disclaimer`,
`Secondary Description (Optional)`, `Capacity`, `Size`, `Cubic Feet`,
`Video URL`, `Diagram URL`, `Parent Product`, `Second Category`, `Approvals`.

### Report format

Every issue names **sheet, row, column, current value, and required change**,
so it can be acted on without interpretation.

---

## 8. Admin interface

### 8.1 Products → Import

Capability `manage_woocommerce`. Five steps:

1. **Upload** `.xlsx` — parsed and validated immediately
2. **Validation report** — grouped by tier and sheet, exportable as CSV
3. **Plan preview** (only if validation passes) — counts plus full tables for
   Create / Update (with per-field diffs) / Rename (with approval checkboxes) /
   Trash / Taxonomy changes
4. **Apply** — AJAX batches of ~25 rows with a progress bar, keeping each
   request well inside PHP's timeout
5. **Archive** — workbook, report and plan written to
   `uploads/cadco-imports/<timestamp>/`

Nonce-protected, capability-checked, and the uploaded file is validated for MIME
type and extension before parsing.

### 8.2 CADCO Specifications metabox

A labelled metabox on the product edit screen showing the `_cadco_*` meta in
grouped, typed inputs (Electrical / Packaging / Compliance / Source).

Because the workbook is the source of truth, values edited here are overwritten
on the next import. The metabox carries a notice saying so — its purpose is
inspection and spotting bad data, not authoring.

---

## 9. Testing

Reader, Normaliser and Validator are pure functions tested without WordPress.
Planner is tested against a fixtured DB state. Fixture workbooks cover:

- column-order variance between sheets (leading `Notes`, trailing `Other`)
- the `OP-4` / `OP-8` cross-sheet duplicate → Tier A failure
- the `OV-003` → `BLC-003` rename → detected, requires approval, preserves post ID
- the four duplicate UPCs and the blank UPC → Tier A failure
- tag variant merging → correct canonical form, merge reported
- a clean workbook → passes, produces the expected plan
- **re-running an unchanged workbook → zero writes**
- a workbook missing a required header → rejected with a named column
- `Parent Product` resolution, and an unresolvable reference → Tier A failure

A corrected workbook (all inconsistencies resolved) is maintained alongside the
fixtures as the canonical passing case and as the reference CADCO compares
against.

---

## 10. Out of scope

- **Media import** — images, spec sheets, manuals. Deferred to phase 2; URLs
  are stored as meta so that phase can consume them.
- **Variable products** — no workbook column drives variation attributes.
- **Pricing** — the site does not sell; the previous workbook's `LIST PRICING`
  sheet is not present in the current one and is not imported.
- **Scheduled/automatic import** — runs are operator-initiated.
