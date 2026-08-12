# Import Interface and Versioning — Design

**Date:** 2026-08-12
**Status:** Awaiting review
**Builds on:** `2026-08-11-cadco-product-import-design.md`

---

## 1. Purpose

The import system works. Its interface is one file input, a wall of tables and an
Apply button.

That is the wrong shape for what the screen actually does. Applying a plan rewrites
the entire catalogue — creating, updating, renaming and trashing products, and
rebuilding the category tree. The operator should be able to see every one of those
consequences, grouped and navigable, *before* committing. Today they see counts and
have to trust them.

This redesign has two goals:

1. **Make the operator feel in control.** Every class of change reviewable in
   advance: which categories and sub-categories appear and disappear, which products
   are created, which fields change on which products, what gets renamed, what gets
   removed.
2. **Add import versioning.** Every uploaded workbook is retained and can be
   re-applied as a restore point.

---

## 2. Decisions

| Question | Decision |
|---|---|
| Flow | Three-stage wizard: Upload → Review → Apply |
| Navigation | `Products → Import`, two tabs: Import, History |
| Review layout | Left sidebar change-navigator with counts; right pane shows the selected section |
| Visual direction | Two-level wizard modelled on the reference: stage bar across the top, sidebar beneath, data tables in the main pane |
| "Re-run" means | **Restore point** — re-apply an archived workbook, re-validated and re-planned against the catalogue as it is now |
| Trashed products on restore | **Untrashed and reused in place** (matched by SKU, then UPC), preserving post ID and attachments |
| Retention | Keep the **20** most recent runs; delete beyond that |
| Run labels | Optional free-text note per run, editable after the fact |
| Category removals | Shown, and **warned loudly** when a term would have been removed but still holds products |

---

## 3. What already exists

Most of the data this interface needs is already computed. The redesign is largely
about giving it somewhere good to live.

| Already available | From |
|---|---|
| Validation issues, grouped by tier, with fix text | `CADCO_Import_Report` |
| Create / update / rename / trash / skip lists | `CADCO_Import_Plan` |
| Per-field update diffs | `CADCO_Import_Planner::diff()` |
| Normalisation changes | `CADCO_Import_Normaliser` |
| Redirect map (225 entries) | `CADCO_Import_Admin::redirect_map()` |
| Archived workbook, report and plan per run | `uploads/cadco-imports/<run>/` |

**The one genuinely new piece of data is the category tree diff** — which terms the
workbook implies that do not exist, and which existing terms it no longer implies.

---

## 4. Architecture

`class-cadco-import-admin.php` is already 1,146 lines and does routing, request
handling, AJAX and all rendering. Adding a wizard shell, eight review sections and a
history tab to it would make it unworkable. It splits:

```
inc/import/
  class-cadco-import-admin.php     routing, request handling, AJAX   (controller)
  class-cadco-import-view.php      the shell and every review section (rendering)
  class-cadco-import-archive.php   run directories: list, read, label, restore, prune
  class-cadco-import-term-diff.php the category/tag/brand tree diff
```

Rendering moves wholesale out of the controller. The controller decides *what* state
the screen is in; the view decides how it looks. That boundary is what keeps either
file readable.

### 4.1 Screen states

The controller resolves the request to exactly one state, and the view renders it:

| State | When | Stage |
|---|---|---|
| `upload` | No run in progress | 1 |
| `invalid` | Workbook uploaded, validation failed | 2 |
| `review` | Workbook uploaded, validation passed | 2 |
| `applying` | Apply started | 3 |
| `done` | Apply finished | 3 |
| `history` | History tab | — |

---

## 5. The shell

```
 [ Import ]  [ History ]
════════════════════════════════════════════════════════════════════
 ✓ Complete          ● Current           ○ Waiting
 1. Upload           2. Review           3. Apply      [ Apply plan ]
 Product Index…xlsx  236 rows, 0 issues  nothing written yet
════════════════════════════════════════════════════════════════════
```

Each stage carries a status word, a title and a one-line subtitle reporting real
figures. The primary action sits top-right and is **disabled until Review is clean**,
so the button itself communicates whether the import can proceed.

---

## 6. Review

### 6.1 The change navigator

The sidebar is the heart of the "in control" requirement. Every class of change with
its count, always visible, so nothing hides below a scroll:

```
┌──────────────────────┬────────────────────────────────────────────┐
│ ✓ Workbook           │  Categories                                │
│   236 rows · 4 sheets│  ────────────────────────────────────────  │
│                      │  30 new · 27 removed                       │
│ ● Categories    +30  │                                            │
│   −27                │  NEW                                       │
│                      │   + Convection Ovens                       │
│ ○ Products      +236 │       + Bakerlux Classic          7 items  │
│                      │       + Bakerlux Pro             10 items  │
│ ○ Updates         0  │       + Bakerlux Station         46 items  │
│                      │                                            │
│ ○ Renames         0  │  REMOVED  (hold no products)               │
│                      │   − Caldolux Cook & Hold                   │
│ ○ Removals        0  │   − Hotplates                              │
│                      │                                            │
│ ○ Cleaned up    130  │  ⚠ STILL IN USE — will NOT be removed      │
│                      │   ! Warming Cabinets        4 products     │
│ ○ Redirects     225  │                                            │
└──────────────────────┴────────────────────────────────────────────┘
```

A section with a zero count is shown but muted and not clickable — the absence of
renames is itself information worth seeing.

### 6.2 Sections

| Section | Content |
|---|---|
| **Workbook** | Filename, size, sheet names, row counts per sheet, validation status |
| **Categories** | New terms as a tree (parent → child, with the number of products landing in each); terms to be removed; terms that would be removed but still hold products |
| **Products** | To create: Model #, name, categories, brand |
| **Updates** | Per-field diffs: Model #, field, was → now |
| **Renames** | Old → new model number, UPC, approval checkbox |
| **Removals** | To be trashed, with a note that trash is recoverable |
| **Cleaned up** | Normalisation changes made silently (sheet, row, column, was, now) |
| **Redirects** | Legacy path → new URL |

Every table is capped and states how many rows were hidden when it truncates —
a silent cap on a plan being approved would be worse than no table.

### 6.3 The invalid state

Same shell. The right pane shows the three-tier issue report; the sidebar shows
tier counts instead of change counts; the CTA is dead and labelled with why.

---

## 7. Categories: the new diff

`CADCO_Import_Term_Diff::compare(array $rows): array` returns, per taxonomy:

- **new** — implied by the workbook, absent from the site. For `product_cat`,
  nested parent → child, each carrying the number of products that will land in it.
  Existence is judged the way `ensure_term()` judges it: for categories by name
  **within a parent**, since a child name legitimately repeats under two parents;
  for tags and brands, which are flat, by name alone.
- **removed** — present on the site, not implied by the workbook, holding **zero**
  products. These are what the applier's orphan pass will delete.
- **in_use** — present on the site, not implied by the workbook, but **still holding
  products**. These will *not* be removed.

The third bucket is the one worth surfacing loudly. It means the workbook and the
site disagree about a category that is actively in use — usually a renamed `Type`
that left its old term behind with products still attached. The applier is right to
leave it alone, and the operator is the only one who can resolve it.

---

## 8. History and restore

### 8.1 The list

```
 Date              Label                          Result          Actions
 ─────────────────────────────────────────────────────────────────────────
 12 Aug 09:14      known good — pre-rename        236 created     Restore ▾
 09 Aug 16:02      —                              1 updated       Restore ▾
 07 Aug 11:40      —                              failed, 266     View
```

Each row reads its `manifest.json` (see 8.2). Labels edit inline. A failed run has
no plan to restore, so it offers only its report.

### 8.2 The manifest

Runs currently archive `workbook.xlsx`, `report.csv` and `plan.json`. A
`manifest.json` is added so the history list never has to re-parse a workbook:

```json
{
  "run_id":    "2026-08-12-091412-1-a7Kd93mQx0Lp",
  "created":   "2026-08-12T09:14:12Z",
  "user_id":   1,
  "filename":  "Product Index Spreadsheet 2026_Website_.xlsx",
  "label":     "known good — pre-rename",
  "passed":    true,
  "rows":      236,
  "issues":    0,
  "counts":    { "create": 236, "update": 0, "rename": 0, "trash": 0, "skip": 0 },
  "applied":   true,
  "applied_at":"2026-08-12T09:16:40Z"
}
```

`applied` distinguishes a workbook that was reviewed from one that was actually run —
a meaningful difference when choosing a restore point. It is written when `finalise()`
completes, so a run abandoned midway is never presented as a usable restore point.

The manifest is written at upload with `applied: false`, then amended on completion.
The history list reads only manifests, never workbooks, so it stays fast regardless of
how many runs are retained.

### 8.3 Restore

Restore loads the archived workbook back into the wizard at **Review**. It is an
ordinary import from there: re-validated, re-planned against the current catalogue,
with the full diff on screen before anything is written.

This works because the workbook is the complete source of truth. Applying an older
workbook restores the catalogue it describes: products it contains are updated back,
products it lacks are trashed, products missing from the site are recreated.

**Restore banner.** The Review stage displays which run is being restored and when it
was taken, so a restore can never be mistaken for a fresh import.

### 8.4 Untrashing

A restore that recreated previously-trashed products would give them new post IDs and
lose their media and manual edits — which would make it a re-creation, not a restore.

So the planner gains an optional **restore mode**. In restore mode only, a workbook
row that matches no live product is looked up among trashed products by SKU, then by
UPC. A match becomes an `untrash` job: the post is restored to `publish` and updated
in place.

**The planner must stay pure.** It does not query for trashed products — that would
break the property that makes the rename and zero-write cases unit-testable without a
database. The trashed candidates are *injected*, exactly as live products already are:

```php
CADCO_Import_Planner::plan(array $rows, array $current, array $trashed = []): CADCO_Import_Plan
```

`$trashed` is `[]` for a normal import and populated only by a restore, from a new
`CADCO_Import_Repository::trashed_products()`. The mode is therefore expressed by what
the caller passes, not by a flag the planner interprets — which also means the restore
path is testable by passing an array, with no WordPress involved.

Normal imports keep ignoring the trash. A product deliberately removed must stay
removed, and only an explicit restore should bring one back. This asymmetry is
deliberate and must be documented at the call site.

The ambiguity rules that apply to live products apply here too: a UPC held by more
than one trashed product cannot identify one of them, and must not produce an untrash.

### 8.5 Retention

The 7-day age-based garbage collector is replaced by a count-based one: keep the
**20** most recent runs, delete older. Age-based retention silently destroys a
restore point purely because time passed, which is exactly wrong for version history.

---

## 9. Testing

**Unit** (no WordPress): `CADCO_Import_Term_Diff::compare()` — new nesting and
counts, removed terms, in-use terms; the planner's restore mode, including that a
trashed match becomes an untrash rather than a create, and that normal mode still
ignores the trash.

**E2E**: navigating every review section; the category tree showing new parents and
children; the in-use warning appearing when a term with products is no longer
implied; the CTA disabled on an invalid workbook and enabled on a clean one; the
History tab listing a run after an import; editing a label and seeing it persist;
restoring a run and landing on Review with the restore banner; retention pruning at
21 runs; a restore untrashing a product rather than creating a duplicate.

---

## 10. Out of scope

- Media import (still phase 2).
- Editing workbook values in the browser. The workbook remains the source of truth;
  this screen reviews and applies, it does not author.
- Diffing two archived runs against each other.
- Scheduled or automatic imports.
