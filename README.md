# CADCO theme

A batteries-included WordPress block-theme starter built for [Proto-Blocks](https://github.com/GustavoGomez092/Proto-Blocks) development. It ships with a clean, brand-free foundation: neutral Tailwind tokens, vendored animation libraries (GSAP, SplitText, Lottie, Lenis), a builder-canvas page editor, and CI deploy workflows for WP Engine.

Proto-theme is designed to be forked once per project. Swap the colors in `tailwind-theme.css`, drop your blocks into `proto-blocks/`, replace the intro animation and favicon, wire up the deploy workflows, and you have a production-ready block theme.

---

## Requirements

- WordPress 6.9+
- PHP 8.0+
- Proto-Blocks plugin (auto-prompted on activation via TGMPA)

---

## Activation and Required Plugins

When you activate Proto-theme, WordPress will display a **"Install Required Plugins"** notice at the top of the dashboard (powered by TGM Plugin Activation). Install all flagged plugins before building:

| Plugin | Source | Required |
|---|---|---|
| **Safe SVG** | WordPress.org | Yes |
| **Yoast SEO** | WordPress.org | Yes |
| **Proto-Blocks** | GitHub (latest release) | Yes |
| **Wordfence Security** | WordPress.org | Recommended |

Click "Begin installing plugins", check all items, and choose "Install". After installation the notice will resolve automatically.

> **Note on Wordfence:** It is marked recommended rather than required. You can safely skip it during local development and install it only on staging/production environments.

---

## The Builder Canvas (Default Page Editor)

Proto-theme configures all **Pages** to use the builder canvas by default (`page.html`). This means:

- The default WordPress post-title input is **hidden from the editing canvas** so blocks fill the full viewport edge-to-edge.
- The page title is not gone — it is moved to the **"Page Title" panel** in the document sidebar (right side, under the "Summary" section). Edit it there; it continues to power the URL slug, navigation menus, browser tab, SEO title, and breadcrumbs.
- All other post types (posts, custom post types) are unaffected and render normally with a visible title.

If you want to disable the builder canvas for a specific page, open the page, go to the document sidebar, and switch its template to a different one.

---

## Page Transitions (Taxi.js)

Proto-theme ships [Taxi.js](https://taxi.js.org/) 1.9.1 (with its `@unseenco/e`
2.5.0 event-emitter dependency), vendored as UMD builds so the theme stays
build-free. Same-origin links swap the page's `<main>` in place; the header,
footer, Lenis scroll instance and intro overlay persist. The default
transition is a GSAP fade, and `prefers-reduced-motion: reduce` makes it an
instant swap.

### Markup requirement

Every front-end template must wrap its `<main>` group:

```html
<div data-taxi>
  <div data-taxi-view>
    <!-- wp:group {"tagName":"main", …} --> … <!-- /wp:group -->
  </div>
</div>
```

Header and footer template parts stay **outside** the wrapper. `templates/index.html`,
`templates/page.html` and `templates/single.html` already do this. If the
wrapper is missing, transitions are disabled and a warning is logged to the
console.

### Writing blocks that survive a swap

A block's `view.js` is re-executed automatically on every navigation — the
theme stamps `data-taxi-reload` onto any enqueued `<script>` tag whose handle
starts with `proto-blocks-` (Proto-Blocks' per-block script prefix), and
Taxi's default `reloadJsFilter` re-runs any script tagged that way. Plain
IIFE blocks need no changes.

Two cases need the lifecycle event instead:

- Blocks declaring `viewScriptModule` in `block.json`. These are registered
  through WordPress's Script Modules API rather than `wp_enqueue_script()`, so
  they're printed outside the `script_loader_tag` filter entirely — the theme
  never gets a chance to tag them — and even if it could, an ES module only
  evaluates once per resolved URL, so re-appending the tag would not re-run
  it anyway. **This is not just a Proto-Blocks concern**: it's a general
  WordPress limitation that also affects *core* blocks. WordPress's own
  Interactivity API (`@wordpress/block-library/navigation/view-js-module`,
  and plugins that hook into it — e.g. WooCommerce's `customer-account.js`)
  ships as `type="module"` and hydrates `data-wp-interactive` regions once
  at load, with no observer for DOM inserted later by a Taxi swap. Core
  blocks that depend on it — the image lightbox, the query loop's enhanced
  pagination, the file block — go inert inside `<main>` after a client-side
  navigation. There is no `data-taxi-reload`-style fix for these; if a page
  needs one of them, either add it to `proto_taxi_ignore_urls` (full page
  loads that route) or accept the degradation.
- Code that must react to a navigation without owning a block script.

```js
document.addEventListener('proto:page-ready', (e) => {
  // fires on initial load AND after every navigation
  init(e.detail.container) // the [data-taxi-view] element
  // e.detail.url is also available (the new page's URL)
})

document.addEventListener('proto:page-leave', (e) => {
  teardown(e.detail.container) // only { container } is provided here
})
```

### Custom transitions

```js
window.protoTaxi.addTransition('slide', class extends window.protoTaxi.Transition {
  onLeave({ from, done }) { /* animate out, then */ done() }
  onEnter({ to, done })   { /* animate in, then */  done() }
})
```

```html
<a href="/about" data-transition="slide">About</a>
```

`window.protoTaxi` exposes:

| Property | What it is |
|---|---|
| `core` | The Taxi `Core` instance — `navigateTo()`, `preload()`, `addRoute()`, cache control |
| `Transition` | Alias for `window.taxi.Transition`, the base class custom transitions extend |
| `addTransition(name, TransitionClass)` | Registers a transition usable via `<a data-transition="name">` |

### Which links are intercepted

Same-origin links, excluding: links inside the admin bar (`#wpadminbar`), links
to `/wp-admin` or `wp-login`, `mailto:` and `tel:` links, `[download]` links,
hash-only links (`href="#…"`), `[target]` links, `[data-taxi-ignore]` links,
and the two WooCommerce add-to-cart triggers (`.add_to_cart_button` and
`.wc-block-components-product-button a`). Links to the WooCommerce cart,
checkout and my-account pages are marked `data-taxi-ignore` server-side.
Forms always submit with a full page load — Taxi only intercepts `<a>` clicks.

### Routes this integration doesn't cover

The `[data-taxi]` / `[data-taxi-view]` wrapper only exists because this
theme's own templates (`templates/index.html`, `templates/page.html`,
`templates/single.html`) put it there. Any route rendered by a **plugin-
supplied** block template never gets it — WooCommerce, for example, ships
its own `archive-product`, `single-product` and `taxonomy-product_cat`
templates, so `/shop/` and every product page have zero `[data-taxi-view]`
elements. Clicking into one still works — Taxi's `createCacheEntry` throws
on the missing wrapper, the `.catch` falls through to a real
`window.location` navigation — but it wastes a full WordPress render on
every click, and, because `enablePrefetch` defaults to `true`, on every
hover/focus too (`preload()`'s catch logs a console warning each time).

If a fork adds a plugin whose templates aren't wrapped, either:

- add the wrapper to that plugin's templates (override them in the theme), or
- add the route's URL(s) to `proto_taxi_ignore_urls` so links to it stay full
  page loads and skip the wasted prefetch/click round-trip.

The WooCommerce shop page is handled in code already — `wc_get_page_id('shop')`
is included in the theme's `proto_taxi_ignore_urls` default alongside cart,
checkout and my-account, since it's the one plugin-supplied route linked from
the default navigation. Other WooCommerce routes (individual products,
product categories) are not — add them via the filter if needed.

### Known behaviour: Back/Forward during a transition

The fade transition takes ~0.9s (0.4s out, 0.5s in). If the user presses
Back or Forward **while one is still running**, Taxi does not queue or
interrupt it — `allowInterruption` is `false` — it silently restores the
previous history entry, logs `A transition is currently in progress` to the
console, and the popstate navigation is dropped. This is stock Taxi
behaviour, not a bug in this integration.

To shrink the window, shorten the tween durations in the `ProtoFade`
transition in `scripts/proto-taxi.js` — 0.25s out and 0.3s in roughly halves
it and reads as snappier anyway.

**Do not reach for Taxi's `allowInterruption: true` to solve this.** The
synchronisation layer in `scripts/proto-taxi.js` assumes one navigation at a
time. With interruption enabled two navigations overlap, each emitting its own
`NAVIGATE_IN`, and the order is decided by which fetch finishes first rather
than which link was clicked first. The visible content comes from whichever
renderer updated last; the head tags come from whichever `NAVIGATE_IN` emitted
last. Those can disagree, leaving the document advertising one page's
`canonical` and `og:url` while displaying another — wrong for crawlers, share
sheets and analytics, and with nothing visibly broken on screen to reveal it.
The announcer would also read the wrong title. Making the option safe requires
threading a per-navigation token through `syncBodyClass`, `syncHead`,
`syncNavState` and `syncAdminBar` so each can check whether it still belongs to
the current navigation before touching the document.

### PHP filters

| Filter | Purpose |
|---|---|
| `proto_taxi_enabled` | Master switch. `add_filter('proto_taxi_enabled', '__return_false');`. Always `false` in `wp-admin` and on JSON requests, regardless of the filter. |
| `proto_taxi_reload_handles` | Extra script handles (beyond the `proto-blocks-` prefix) to mark `data-taxi-reload`. Empty by default. |
| `proto_taxi_denied_handles` | Handles that must never be re-run. Defaults to the theme's own animation/runtime scripts (`proto-gsap`, `proto-split-text`, `proto-scroll-trigger`, `proto-lottie`, `proto-lenis`, `proto-taxi-e`, `proto-taxi`, `proto-taxi-init`, `proto-init`, `proto-intro`) — re-running any of these would create a second Lenis instance, RAF loop or Taxi Core. The deny list wins over the `proto-blocks-` prefix. |
| `proto_taxi_ignore_urls` | Extra URLs whose links get `data-taxi-ignore`. Defaults to the WooCommerce cart/checkout/my-account/shop permalinks when WooCommerce is active, otherwise empty. See "Routes this integration doesn't cover" above for why `shop` is included. |

### Upgrading Taxi

`taxi.umd.js` externalizes its dependency and reads `window.E` at runtime,
which `e.umd.js` provides — the two must always be upgraded together. Run
this from a scratch directory (not inside the theme):

```bash
npm pack @unseenco/taxi@<version> @unseenco/e@<version>

mkdir -p taxi-upgrade e-upgrade
tar -xzf unseenco-taxi-<version>.tgz -C taxi-upgrade --strip-components=1
tar -xzf unseenco-e-<version>.tgz -C e-upgrade --strip-components=1

# copy only the UMD builds — never the .map files, which reference sources
# the theme doesn't ship and would leave broken sourcemap references
cp taxi-upgrade/dist/taxi.umd.js e-upgrade/dist/e.umd.js /path/to/proto-theme/scripts/

rm -rf unseenco-taxi-<version>.tgz unseenco-e-<version>.tgz taxi-upgrade e-upgrade
```

`npm pack` only writes `.tgz` tarballs to the current directory — it does not
extract them, so the `tar` step above is required before there is a `dist/`
to copy from. Vendored files must stay byte-identical to the npm package's
`dist/` originals; do not hand-edit them.

Then bump the two `version` values in the `$libs` map in `functions.php`
(the `taxi-e` and `taxi` entries) to match, and keep the `'taxi' => [...,
'deps' => ['proto-taxi-e']]` dependency in place so `e.umd.js` always loads
first.

### Running the E2E suite

**Prerequisite:** the site must use pretty permalinks (Settings → Permalinks
→ "Post name", i.e. `/%postname%/`), not "Plain". Under plain permalinks
every page resolves to a `/?page_id=…` (or `/?p=…`) URL whose path is just
`/`, and `proto_taxi_mark_ignored_links()` in `inc/proto-taxi.php`
deliberately skips a bare `/` (it would otherwise substring-match every
internal href on the site) — so the ignore-URL marking it tests never fires.
Several assertions in `tests/e2e/php-integration.spec.js` depend on real
paths being present.

```bash
npm install && npx playwright install chromium
./tests/fixtures/setup.sh          # creates /taxi-test-a/ and /taxi-test-b/
npm test
```

Point at another site with `PROTO_BASE_URL=https://example.test npm test`.
The `tests/`, `playwright.config.js`, `package.json` and `package-lock.json`
paths are all `export-ignore`d — none of this ships in the release zip built
by `git archive`.

---

## WooCommerce: catalogue only

WooCommerce is installed for its product post type, its admin editing
experience and its product URL structure. The site does not sell anything.

`inc/cadco-woocommerce.php` turns commerce off. WooCommerce core has no setting
for this — verified against 10.9.4, and the official Cart and Checkout FAQ
documents no supported way to disable purchasing — so it is done with hooks:
`woocommerce_is_purchasable` returns false, the add-to-cart template actions are
removed, `woocommerce_payment_gateways` returns an empty array, the
cart-fragments script is dequeued, and cart / checkout / my-account redirect to
the catalogue.

Everything is behind one filter, so selling can be switched back on without
unpicking the file:

```php
add_filter('cadco_commerce_disabled', '__return_false');
```

Products deliberately stay **public**. Making the post type non-public would
destroy the product URLs below.

### Required site configuration

These are database settings, not code — a fresh environment needs them set or
product URLs will differ from production:

| Setting | Value | Where |
|---|---|---|
| Shop page slug | `products` | Pages → the WooCommerce "Shop" page |
| Product permalink | Custom base `/products/%product_cat%/` | Settings → Permalinks → Product permalinks |
| Site visibility | Live (not "Coming soon") | WooCommerce → Settings → Site visibility |

That yields:

```
/products/                                   catalogue archive
/products/widgets/test-widget/               product in a top-level category
/products/widgets/gadgets/nested-widget/     product in a sub-category
```

Category nesting is handled by WooCommerce itself: it walks the full ancestor
chain of the deepest assigned category, so arbitrarily deep hierarchies work.
`woocommerce_product_post_type_link_parent_category_only` flattens it to the
top-level parent if that is ever wanted.

**Do not set the product base to a bare `/%product_cat%/`.** It reads like the
tidier URL, but a product base starting at the site root is a greedy catch-all
that swallows every page and post — the entire site 404s except the front page.
WooCommerce only enables the verbose page rules that could disambiguate it when
the base contains the shop slug (`class-wc-admin-permalink-settings.php:205`),
which is exactly why the `/products/` prefix is used here.

### Admin cleanup

The commerce-only admin surfaces are removed too, so the WooCommerce menu shows
only what this site can actually manage.

| Removed | How |
|---|---|
| Analytics, Marketing, Home, Coupons, store setup flows, payment/shipping upsells | `woocommerce_admin_features` filter |
| Settings tabs: Payments, Shipping, Tax, Accounts & Privacy, Emails, Point of Sale | `woocommerce_get_settings_pages` filter |
| Orders, Reports, Extensions | `remove_submenu_page()` |

What remains: **Products** with all its screens (All Products, Add new, Brands,
Categories, Tags, Attributes, Reviews), and **WooCommerce → Settings / Status**.
Settings keeps General, Products, Site visibility, Advanced and any Integration
tabs.

### Blocked, not just hidden

Removing a menu item is cosmetic — WordPress still serves the screen to anyone
who types the URL. An `admin_init` guard refuses the commerce screens outright:

| Request | Result |
|---|---|
| `admin.php?page=wc-orders`, `wc-reports`, `wc-addons` | 403 |
| `admin.php?page=wc-admin&path=/analytics\|/extensions\|/marketing\|/customers` | 403 |
| `edit.php?post_type=shop_coupon` | 403 |
| `admin.php?page=wc-settings&tab=<removed tab>` | 302 → settings root |

Removed tabs redirect rather than dying: the tab is a query arg on a page that
is legitimately available, so bouncing a stale bookmark to General beats an
error page.

This is deliberately a list of explicit matches rather than a capability change.
Stripping `manage_woocommerce` would be the blunt route and would take
WooCommerce → Settings and Status with it. `wc-admin` paths are matched by
**prefix**, never by blocking the `wc-admin` page wholesale, because that page
is the root of the WooCommerce Admin app.

Three things worth knowing:

- `launch-your-store` is deliberately left enabled. WooCommerce only registers
  the Site visibility settings tab when that feature is on
  (`class-wc-admin-settings.php:62`), so disabling it as "store setup cruft"
  silently removes a legitimate setting.
- Settings tabs must be removed via `woocommerce_settings_tabs_array`, not
  `woocommerce_get_settings_pages`. Each settings page registers its own tab
  from its constructor, so filtering the pages array afterwards leaves the tab
  in the nav. `WC_Admin_Settings::get_settings_pages()` also memoises into a
  static and may build its list before this theme loads.
- **Known limitation:** with this layer active, `admin.php?page=wc-admin`
  returns 403 — disabling the WooCommerce Admin features unregisters the app
  root. Nothing the site uses depends on it today: "Add new product" uses the
  classic `post-new.php?post_type=product` editor, which works in full
  (General, Inventory, Shipping, Linked Products, Attributes, Variations,
  Advanced, gallery, categories). But WooCommerce's newer **block-based product
  editor** is served from `wc-admin&path=/add-product`, so it would not load
  while this is on. If that editor is ever wanted, re-enable the relevant
  features:

  ```php
  add_filter('cadco_disabled_wc_admin_features', function ($features) {
      return array_diff($features, ['analytics']); // find the one that re-registers wc-admin
  });
  ```

Both lists are filterable — `cadco_disabled_wc_admin_features` and
`cadco_disabled_wc_settings_tabs` — and everything is behind
`cadco_commerce_disabled`.

### Product templates

`templates/single-product.html` and `templates/archive-product.html` override
WooCommerce's blockified templates. They are WooCommerce's markup wrapped in the
`[data-taxi]` / `[data-taxi-view]` structure, so product pages transition like
every other route instead of falling back to full page loads, with the
add-to-cart form and loop button removed.

Because those templates carry the wrapper, `inc/cadco-woocommerce.php` also
removes the shop page from `proto_taxi_ignore_urls()` — the upstream theme
excludes it precisely because stock WooCommerce templates have no wrapper, and
that reasoning does not apply here.

**If WooCommerce's templates change upstream, these copies do not.** Re-diff
them against `plugins/woocommerce/templates/templates/blockified/` after a major
WooCommerce upgrade.

---

## Scaffolding a Block

All custom blocks live in `proto-blocks/`. Each block is a folder containing at minimum a `block.json` and a `template.php`. Proto-Blocks discovers all folders in this directory automatically — no registration code needed.

**The fastest way to scaffold:**

```bash
wp proto-blocks create my-block --title="My Block" --fields="heading:text,body:wysiwyg"
```

This creates `proto-blocks/my-block/block.json` and `proto-blocks/my-block/template.php` with the declared fields pre-wired.

**Manual scaffold — minimum viable block:**

1. Create `proto-blocks/my-block/block.json` with a `protoBlocks` key defining fields and controls.
2. Create `proto-blocks/my-block/template.php` with PHP/HTML markup. Mark editable elements with `data-proto-field="fieldName"` attributes.
3. Save. The block appears in the "Proto Blocks" inserter category immediately (no build step).

If the block does not appear after saving, clear the Proto-Blocks template cache:

```bash
wp proto-blocks cache clear
```

For a complete field/control reference see the Proto-Blocks plugin documentation.

---

## Customizing Design Tokens

All Tailwind utility tokens are declared in `tailwind-theme.css` under a single `@theme {}` block. This file is read by Proto-Blocks' server-side Tailwind compiler — any token you define here becomes a Tailwind utility class available in every block.

The starter ships with a neutral grayscale ramp and one placeholder accent:

```css
--color-accent: #2563eb; /* placeholder accent — change me */
```

Replace these values with your brand colors. Token names become utility class suffixes: `--color-accent` → `bg-accent`, `text-accent`; `--font-display` → `font-display`; `--text-h1` → `text-h1`; and so on.

After editing `tailwind-theme.css`, reload any open editor tabs to pick up the new classes in block previews.

---

## Animation Globals

Proto-theme enqueues all animation libraries as self-hosted scripts and exposes them as window globals so blocks and inline scripts can use them without bundling:

| Global | Library | Version |
|---|---|---|
| `window.gsap` | GSAP Core | 3.15.0 |
| `window.SplitText` | GSAP SplitText | 3.15.0 |
| `window.ScrollTrigger` | GSAP ScrollTrigger | 3.15.0 |
| `window.lottie` | lottie-web (light) | 5.13.0 |
| `window.Lenis` | Lenis smooth scroll | 1.1.13 |

Lenis is initialized automatically by `scripts/proto-init.js` and exposed as `window.__protoLenis`. To pause smooth scroll during a transition (e.g., while a modal is open), call `window.__protoLenis.stop()` and `window.__protoLenis.start()`.

All library files live in `scripts/` and are versioned by their `filemtime`, so browsers bust the cache on update automatically.

---

## Intro Animation and Favicon

### Intro overlay

The intro overlay plays once per browser session and fades out before the page is visible. It is powered by `scripts/proto-intro.js` and reads a Lottie JSON file pointed to by the `data-lottie-url` attribute.

To swap the animation, replace `assets/lottie/intro.json` with your own Lottie file. The overlay dimensions are controlled by `.proto-intro__lottie` in `style.css`, currently `clamp(260px, 80vw, 420px)` with `aspect-ratio: 520 / 200` to match the shipped composition. If your replacement has a different aspect ratio, update that `aspect-ratio` to match its `w`/`h` or the animation will letterbox inside the container.

The shipped intro is the Cadco wordmark (520×200, 110f @60fps, 1.83s): the **C** outline draws on with a trim path while its fill cross-fades in underneath, and `a`,`d`,`c`,`o` stagger in to complete the word. The stroke layer is clipped by a track matte (`td`/`tt`) of the same glyph — Lottie strokes are centre-aligned, so without the matte the stroke straddles the outline and the C visibly deflates when the stroke fades out.

`proto-intro.js` ends the overlay on the Lottie `complete` event, so the overlay's lifetime is driven by the animation's `op` — a longer animation delays the site becoming interactive (capped by the 8 s safety timeout).

To disable the intro entirely, remove the `wp_body_open` hook and the `wp_head` script in `functions.php` (the two blocks near the bottom of the file).

### Favicon

The theme registers its own site icons from `assets/img/`, bypassing the WordPress Customizer setting. Every URL includes a `filemtime` cache-buster so browsers pick up changes immediately.

| File | Emitted as | Purpose |
| --- | --- | --- |
| `favicon.svg` | `rel="icon"` (`image/svg+xml`) | Primary icon; scales to any size |
| `favicon-32.png` | `rel="icon"` `32x32` | Raster fallback |
| `favicon-16.png` | `rel="icon"` `16x16` | Raster fallback |
| `apple-touch-icon.png` | `rel="apple-touch-icon"` `180x180` | iOS home screen (opaque; iOS masks its own corners) |

Each file is emitted only if it exists, so you can drop any of them without touching `functions.php`. Replace the set to rebrand.

`favicon.ico` (16/32/48) and `apple-touch-icon.png` also live at the **web root**, because browsers and iOS request those paths directly without reading the markup. Keep them in sync with the theme copies when you rebrand — `assets/img/favicon.ico` is the source.

---

## Updating Proto-Blocks

Proto-theme automatically resolves the **latest Proto-Blocks release** at install time. The function `proto_protoblocks_zip_url()` in `inc/proto-required-plugins.php` calls the GitHub Releases API (`/releases/latest`) to fetch the current release's `.zip` download URL, then caches it in a 12-hour WordPress transient.

**How it works:**

1. On first load (or after the transient expires), the theme queries `https://api.github.com/repos/GustavoGomez092/Proto-Blocks/releases/latest`.
2. It extracts the first `.zip` asset URL from the response and stores it as the `proto_protoblocks_zip_url` transient (TTL: 12 hours).
3. TGMPA reads this URL as the plugin `source` when offering installation.
4. If the API is unreachable (rate limit, no network), the function returns a **pinned fallback URL** hard-coded in the file.

**Refreshing the cached URL** — if you need the latest release URL to resolve immediately (e.g., after a new Proto-Blocks release), delete the transient from the WordPress admin under Tools → Scheduled Tasks, or run:

```bash
wp transient delete proto_protoblocks_zip_url
```

The next page load will re-query the API and cache the new URL.

**Updating the pinned fallback** — open `inc/proto-required-plugins.php` and update the `$fallback` variable to the latest release zip URL:

```php
$fallback = 'https://github.com/GustavoGomez092/Proto-Blocks/releases/download/vX.Y.Z/proto-blocks-X.Y.Z.zip';
```

**Pinning a specific version** — to freeze the theme to a particular Proto-Blocks release (useful for stability on production), replace the `source` value in `proto_register_required_plugins()` with a direct, version-pinned URL:

```php
'source' => 'https://github.com/GustavoGomez092/Proto-Blocks/releases/download/v2.3.1/proto-blocks-2.3.1.zip',
```

With a hard-coded URL the `proto_protoblocks_zip_url()` resolver is bypassed entirely for that entry. Remove this pinning and restore `proto_protoblocks_zip_url()` to resume auto-resolution.

---

## Deploy Workflows (WP Engine)

Proto-theme ships two GitHub Actions workflows in `.github/workflows/`:

- `deploy-wpengine-development.yml` — triggers on every push to the `development` branch.
- `deploy-wpengine-production.yml` — **manual only** (`workflow_dispatch`); never auto-deploys to production.

Both workflows strip development files (`docs/`, `README.md`, `.gitignore`, `.git/`, `.github/`) before the rsync transfer, so the server receives only theme files.

### Setup steps

1. **Add the SSH key secret.** In your GitHub repository go to Settings → Secrets and variables → Actions and add a secret named `WPE_SSHG_KEY_PRIVATE` containing your WP Engine SSH gateway private key.

2. **Set the install names.** In each workflow file, replace the placeholder values:

   In `deploy-wpengine-development.yml`:
   ```yaml
   WPE_ENV: YOUR_DEV_INSTALL_NAME
   ```

   In `deploy-wpengine-production.yml`:
   ```yaml
   WPE_ENV: YOUR_PROD_INSTALL_NAME
   ```

   The install name is the short identifier for your WP Engine environment (the subdomain part of `*.wpengine.com`).

3. **Set the trigger branch.** The development workflow triggers on pushes to `development`. If your branch is named differently (e.g., `staging`), update the `branches:` value in the workflow file.

4. **Trigger a deployment.** Push to `development` to deploy to the dev environment. To deploy to production, go to Actions → "Deploy to WP Engine Production" → "Run workflow".

---

## Product import

The catalogue is driven by a single Excel workbook. `inc/import/` parses it,
reports every inconsistency, previews what it would change, and applies nothing
until the workbook is clean.

Run it from **Products → Import**.

### The pipeline

```
Reader → Normaliser → Validator → Planner → Applier
```

The dry run is not a separate mode — it is the pipeline stopping after the
Planner, so the preview and the applied result come from one code path and
cannot drift apart. The first four units are pure PHP and are unit-tested with
no WordPress loaded (`composer test`).

### Rules worth knowing

- **Columns are read by header name, never by position.** Two sheets in the
  same workbook disagree about column order, and the previous revision had
  different sheets entirely. Reordering columns is safe; renaming them is not.
- **Sheets beginning with `_` are ignored,** so the workbook can carry its own
  correction log. Any other unrecognised sheet is an error.
- **Validation is all-or-nothing.** Any problem in any tier blocks the whole
  import. This is deliberate: the catalogue is only as trustworthy as the sheet.
- **`Model #` is the key; `UPC#` detects renames.** A row whose model number is
  new but whose UPC matches an existing product is offered as a rename, which
  preserves the post ID, URL and images. Renames are never applied without
  being ticked.
- **Re-running an unchanged workbook writes nothing.** Each product stores a
  hash of its source row.
- **Categories are fully derived** from the sheet name and the `Type` column.
  A comma-separated `Type` places a product in several sub-categories.
- **Removed products are trashed, never deleted.**

### Rewrite rules

`inc/cadco-woocommerce.php` registers one literal rewrite rule per category
term and flushes on term changes. The applier therefore does all taxonomy work
first and flushes **once** at the end of a run, rather than 30+ times.

### Out of scope

Images, spec sheets and manuals are not imported — most links in the workbook
point to SharePoint locations requiring a CADCO login. The URLs are stored as
`_cadco_*` meta so a later phase can consume them.

### Running the tests

```bash
composer test        # PHP units: reader, normaliser, validator, planner
npm run test:e2e      # Browser: upload, report, dry run, apply, product page
```

`composer test` covers the four pure units with no WordPress loaded. It cannot
see the parts of the system that only exist inside a real HTTP request — file
uploads, nonces travelling through a real cookie session, the capability gate,
whether the admin screen's JS actually gets enqueued, or whether the browser
escapes what it renders. `npm run test:e2e` drives the real admin screen at
`https://cadco.local/wp-admin/edit.php?post_type=product&page=cadco-import`
with Playwright to cover exactly that gap, including:

- a real multipart upload through `handle_upload()`, not a call to `read()`
  with a path already on disk;
- the capability gate's *refusal* path — a logged-in administrator who has
  just had `manage_woocommerce` removed is denied by both the page and the
  AJAX handler, not only the "allowed" path every other test exercises;
- that `admin_enqueue_scripts` actually matches this screen's hook suffix and
  localizes `window.cadcoImport` — if the `str_contains($hook, 'cadco-import')`
  check ever stops matching, the page still renders and the Apply button still
  appears, but clicking it silently does nothing;
- the batching JS end to end against a real 236-product apply, plus the
  failure-list rendering and network-error branches (provoked deterministically
  by intercepting the one AJAX call the Apply button makes, since forcing a
  real WooCommerce write failure or a dropped connection inside the big apply
  would be slow and hard to aim reliably);
- rename approval specifically — the checkbox's `value` is the UPC, and the
  server matches renames to approve by that UPC string, not by array index;
- that a product name containing a `<script>` tag is escaped on the public
  product page rather than executed.

The suite resets the catalogue before and after running (`resetCatalogue()` in
`tests/e2e/helpers.js`), and deletes every run archive it leaves under
`wp-content/uploads/cadco-imports/` when it finishes. Do not point it at
anything but a development site — it deletes all products and terms.

Install once, then run:

```bash
npm install && npx playwright install chromium
npm run test:e2e
```

The suite runs serially (`workers: 1`) — every test shares one WordPress
database, and the apply test in particular takes a few minutes because it
imports 236 real products through the same batching the browser uses. Override
the target site with `CADCO_BASE_URL=https://example.test npm run test:e2e`.

`package.json`, `package-lock.json`, `playwright.config.js`, `node_modules/`
and `tests/` are all `export-ignore`d — none of this ships in the release zip
built by `git archive`.
