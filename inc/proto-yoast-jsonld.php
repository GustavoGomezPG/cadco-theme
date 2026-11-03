<?php
/**
 * Custom JSON-LD — a Yoast SEO extension.
 *
 * Yoast only lets you add arbitrary schema in Premium. This adds a "JSON-LD"
 * row to Yoast's own editor UI (metabox + sidebar, see
 * assets/editor/proto-yoast-jsonld.js) and merges the stored nodes into the
 * single graph Yoast prints, via the `wpseo_schema_graph` filter.
 *
 * Data contract (written by the editor UI and by the site-builder skill
 * through WP-CLI, so keep it stable):
 *
 * - Post meta `_proto_jsonld`: one string holding JSON. Invalid JSON is
 *   stored as typed (so the user can fix it) but never output.
 * - Accepted shapes: a single node object, an array of nodes, or an object
 *   with `@graph`. `@context` is removed from every node.
 * - Who may write it: users with `edit_post` on the post AND `unfiltered_html`
 *   (the same capability core requires for raw markup). The graph is printed
 *   into `<head>` by Yoast's encoder, which does not set JSON_HEX_TAG, so the
 *   meta is treated like raw HTML: authors/contributors cannot write it.
 * - Sanitised on write (`proto_jsonld_sanitize_meta`) and again on output
 *   (`proto_jsonld_parse`): capped at PROTO_JSONLD_MAX_BYTES, nesting capped
 *   at PROTO_JSONLD_MAX_DEPTH, scalars rejected, and in every string (keys
 *   included) a `<` that could open a tag or comment inside `<script>`
 *   (`</`, `<!`, `<?`, `<a`…) is removed. A bare `<` ("a < b") is kept.
 * - A node whose `@id` is the page's main schema id (or `#webpage`), or whose
 *   `@type` is WebPage or a WebPage subtype, is merged into Yoast's WebPage
 *   piece (properties array_merge'd, Yoast's `@id` kept, `@type`s unioned).
 * - Every other node is appended. `#…` ids resolve to `<canonical>#…`, a
 *   missing `@id` becomes `<canonical>#proto-<type>-<n>`, and a missing
 *   `isPartOf` points at the WebPage (except for entity types such as
 *   Organization or Person).
 *
 * Everything here is inert unless Yoast SEO is active.
 */

defined('ABSPATH') || exit;

const PROTO_JSONLD_META_KEY = '_proto_jsonld';

/** Largest JSON-LD document accepted, in bytes (64 KB). Larger is dropped. */
const PROTO_JSONLD_MAX_BYTES = 65536;

/** Deepest nesting accepted, as json_decode() counts it. Deeper is dropped. */
const PROTO_JSONLD_MAX_DEPTH = 16;

/**
 * Whether Yoast SEO is loaded on this request.
 */
function proto_jsonld_is_active(): bool
{
    return defined('WPSEO_VERSION');
}

/**
 * Public post types that use the block editor — the ones that get the field.
 *
 * Note: WordPress only exposes registered meta over REST for post types that
 * also support `custom-fields` (posts and pages do by default).
 *
 * @return string[]
 */
function proto_jsonld_post_types(): array
{
    $types = array_filter(
        get_post_types(['public' => true]),
        fn($type) => post_type_supports($type, 'editor')
    );

    return array_values((array) apply_filters('proto_jsonld_post_types', $types));
}

/**
 * Boot: registers meta, the editor script and the schema filter. Runs on a
 * late `init` so custom post types registered at the default priority exist.
 */
add_action('init', function () {
    if (!proto_jsonld_is_active()) {
        return;
    }

    foreach (proto_jsonld_post_types() as $post_type) {
        register_post_meta($post_type, PROTO_JSONLD_META_KEY, [
            'type'          => 'string',
            'single'        => true,
            'default'       => '',
            'show_in_rest'  => true,
            'description'   => __('Custom JSON-LD nodes merged into the Yoast schema graph.', 'proto-theme'),
            // Raw-markup capability, not just edit_post: this text ends up
            // inside a <script> in <head> on the public page.
            'auth_callback' => fn($allowed, $meta_key, $post_id) =>
                current_user_can('edit_post', $post_id) && current_user_can('unfiltered_html'),
            // Runs for every write path (REST, WP-CLI, update_post_meta).
            'sanitize_callback' => 'proto_jsonld_sanitize_meta',
        ]);
    }

    add_action('enqueue_block_editor_assets', 'proto_jsonld_enqueue_editor');
    add_filter('wpseo_schema_graph', 'proto_jsonld_filter_graph', 20, 2);
}, 20);

/**
 * Editor UI — only on post edit screens for a supported post type (not the
 * Site Editor / widgets screen, which have no Yoast metabox).
 */
function proto_jsonld_enqueue_editor(): void
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || $screen->base !== 'post' || !in_array($screen->post_type, proto_jsonld_post_types(), true)) {
        return;
    }

    $js = get_stylesheet_directory() . '/assets/editor/proto-yoast-jsonld.js';
    if (!file_exists($js)) {
        return;
    }

    // Yoast's public editor components (window.yoast.editorModules) live in
    // its registered-but-not-enqueued `editor-modules` script — the same one
    // Yoast Premium depends on. Depend on it only if Yoast registered it, so
    // a renamed handle in a future Yoast can't block our script from loading
    // (the JS then simply renders nothing).
    $deps = ['wp-plugins', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n', 'wp-editor'];
    if (wp_script_is('yoast-seo-editor-modules', 'registered')) {
        $deps[] = 'yoast-seo-editor-modules';
    }

    // Code editor: the CodeMirror build bundled with core (`wp.codeEditor`),
    // in JSON-LD mode with core's jsonlint gutter. Returns false when the
    // user turned syntax highlighting off in their profile; the JS then
    // falls back to a plain textarea.
    $code_editor = wp_enqueue_code_editor([
        'type'       => 'application/ld+json',
        'codemirror' => [
            'lineNumbers'       => true,
            'lineWrapping'      => true,
            'matchBrackets'     => true,
            'autoCloseBrackets' => true,
            'styleActiveLine'   => true,
            'indentUnit'        => 2,
            'tabSize'           => 2,
            'indentWithTabs'    => false,
            'viewportMargin'    => 100000, // Auto-height; documents are small.
        ],
    ]);
    if ($code_editor) {
        $deps[] = 'code-editor';
    }

    wp_enqueue_script(
        'proto-yoast-jsonld',
        get_stylesheet_directory_uri() . '/assets/editor/proto-yoast-jsonld.js',
        $deps,
        filemtime($js),
        true
    );
    // Inline JSON (not wp_localize_script) so booleans and nested settings
    // keep their types.
    wp_add_inline_script(
        'proto-yoast-jsonld',
        'window.protoYoastJsonLd = ' . wp_json_encode([
            'metaKey'    => PROTO_JSONLD_META_KEY,
            'codeEditor' => $code_editor ?: false,
        ], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . ';',
        'before'
    );

    // Editor chrome: match Yoast's input border/shadow, grow from ~14 to
    // ~18 lines (12px x 1.5 line height) then scroll, grey out the empty-
    // editor hint (core ships no placeholder CSS), and tint the line a JSON
    // parse error points at.
    wp_register_style('proto-yoast-jsonld', false, $code_editor ? ['code-editor'] : [], filemtime($js));
    wp_enqueue_style('proto-yoast-jsonld');
    wp_add_inline_style('proto-yoast-jsonld', '
        .proto-jsonld__editor .CodeMirror {
            height: auto;
            border: 1px solid rgba(0, 0, 0, .2);
            border-radius: 0;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, .1);
            font-family: Menlo, Consolas, Monaco, "Liberation Mono", monospace;
            font-size: 12px;
            line-height: 1.5;
        }
        .proto-jsonld__editor .CodeMirror-focused { box-shadow: var(--yoast-color-focus, 0 0 0 2px #5b9dd9); }
        /* +50px offsets the -50px CodeMirror scroll margin: ~14 to ~18 visible lines. */
        .proto-jsonld__editor .CodeMirror-scroll { min-height: 302px; max-height: 374px; }
        .proto-jsonld__editor .CodeMirror-gutters { background: #f6f7f7; border-right: 1px solid #dcdcde; }
        .proto-jsonld__editor .CodeMirror-placeholder { color: #757575 !important; font-style: italic; }
        .proto-jsonld__editor .proto-jsonld-error-line { background: #fcf0f1; }
        .proto-jsonld__editor .proto-jsonld-error-wrap .CodeMirror-linenumber { color: #cc1818; font-weight: 600; }
    ');
}

/**
 * Remove, from every string in a decoded JSON structure, each `<` that could
 * change the HTML tokenizer's state inside a `<script>` element.
 *
 * Inside script data only `<` followed by `!`, `/`, `?` or a letter opens a
 * tag or comment (`</script`, `<!--`, `<script`); a bare `<` ("a < b") does
 * nothing and is kept. Yoast prints the graph with wp_json_encode() without
 * JSON_HEX_TAG, so this is what stands between stored text and `</script>`.
 *
 * Applied to values and to object keys. Object members whose key is not a
 * string (PHP turns "1" into int 1) are dropped; lists keep their order and
 * are re-indexed. Anything nested deeper than PROTO_JSONLD_MAX_DEPTH is
 * replaced by an empty array.
 *
 * @param mixed $value Decoded JSON (string, number, bool, null or array).
 * @return mixed Same shape, cleansed.
 */
function proto_jsonld_strip_tag_openers($value, int $depth = 0)
{
    if (is_string($value)) {
        return (string) preg_replace('/<(?=[!\/?a-z])/i', '', $value);
    }
    if (!is_array($value)) {
        return $value; // int, float, bool, null.
    }
    if ($depth >= PROTO_JSONLD_MAX_DEPTH) {
        return [];
    }

    $is_list = proto_jsonld_is_list($value);
    $out     = [];
    foreach ($value as $key => $child) {
        if (!$is_list) {
            if (!is_string($key)) {
                continue;
            }
            $key = proto_jsonld_strip_tag_openers($key);
        }
        $out[$key] = proto_jsonld_strip_tag_openers($child, $depth + 1);
    }

    return $is_list ? array_values($out) : $out;
}

/**
 * Encode a cleansed structure back to text the editor shows: 2-space indent
 * like its Format button (JSON.stringify(…, null, 2)), slashes and unicode
 * unescaped. Leading whitespace in pretty-printed JSON is never inside a
 * string, so halving the 4-space indent is safe.
 */
function proto_jsonld_encode(array $data): string
{
    $json = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return '';
    }

    return (string) preg_replace_callback(
        '/^(?: {4})+/m',
        fn($m) => str_repeat(' ', (int) (strlen($m[0]) / 2)),
        $json
    );
}

/**
 * `sanitize_callback` for the `_proto_jsonld` meta: what gets stored.
 *
 * - Not a string, blank, over PROTO_JSONLD_MAX_BYTES or nested deeper than
 *   PROTO_JSONLD_MAX_DEPTH: stored as ''.
 * - Valid JSON that is a scalar (string, number, true/false/null): ''.
 * - Valid JSON object or list: every string is cleansed by
 *   proto_jsonld_strip_tag_openers(). If nothing had to change the text is
 *   stored exactly as typed (formatting kept); otherwise the cleansed
 *   structure is re-encoded.
 * - Invalid JSON: kept as typed so the editor can show the error and the user
 *   can fix it (it is never output), but with the same `<` cleanse applied to
 *   the raw text so the stored value can never hold `</script` or `<!--`.
 *
 * @param mixed $value Meta value as submitted.
 */
function proto_jsonld_sanitize_meta($value, $meta_key = '', $object_type = '', $object_subtype = ''): string
{
    $value = is_string($value) ? trim($value) : '';
    if ($value === '') {
        return '';
    }
    if (strlen($value) > PROTO_JSONLD_MAX_BYTES) {
        return '';
    }

    $decoded = json_decode($value, true, PROTO_JSONLD_MAX_DEPTH);
    $error   = json_last_error();

    if ($error === JSON_ERROR_DEPTH) {
        return '';
    }
    if ($error !== JSON_ERROR_NONE) {
        return proto_jsonld_strip_tag_openers($value); // Invalid: keep, cleansed.
    }
    if (!is_array($decoded)) {
        return ''; // A JSON scalar is never a node.
    }

    $clean = proto_jsonld_strip_tag_openers($decoded);
    if ($clean === $decoded) {
        return $value;
    }

    return proto_jsonld_encode($clean);
}

/**
 * Parse the stored string into a list of nodes.
 *
 * Re-applies the size, depth and `<` cleanse on every read: meta written
 * before the sanitize callback existed (or by code bypassing it) gets the
 * same treatment before it reaches Yoast's encoder.
 *
 * @return array[] Nodes (associative arrays). Empty on blank/invalid input.
 */
function proto_jsonld_parse(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > PROTO_JSONLD_MAX_BYTES) {
        return [];
    }

    $data = json_decode($raw, true, PROTO_JSONLD_MAX_DEPTH);
    if (!is_array($data)) {
        return []; // Invalid JSON, too deep, or a scalar — stored, never output.
    }

    if (proto_jsonld_is_list($data)) {
        $nodes = $data;                       // [ {...}, {...} ]
    } elseif (isset($data['@graph']) && is_array($data['@graph'])) {
        $nodes = $data['@graph'];             // { "@context": ..., "@graph": [...] }
        if (!proto_jsonld_is_list($nodes)) {
            $nodes = [$nodes];
        }
    } else {
        $nodes = [$data];                     // { "@type": ... }
    }

    $out = [];
    foreach ($nodes as $node) {
        if (!is_array($node) || $node === [] || proto_jsonld_is_list($node)) {
            continue;
        }
        unset($node['@context']);
        $node = proto_jsonld_strip_tag_openers($node); // Defence in depth; see above.
        if ($node !== []) {
            $out[] = $node;
        }
    }

    return $out;
}

/**
 * array_is_list() polyfill (theme supports PHP 8.0).
 */
function proto_jsonld_is_list(array $arr): bool
{
    if (function_exists('array_is_list')) {
        return array_is_list($arr);
    }

    return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
}

/**
 * A node's `@type` as a list of strings.
 *
 * @return string[]
 */
function proto_jsonld_types($node): array
{
    $type = is_array($node) ? ($node['@type'] ?? []) : [];

    return array_values(array_filter((array) $type, 'is_string'));
}

/**
 * Schema.org WebPage and its subtypes — nodes of these types describe the
 * page itself and are merged into Yoast's WebPage piece.
 *
 * @return string[]
 */
function proto_jsonld_webpage_types(): array
{
    return (array) apply_filters('proto_jsonld_webpage_types', [
        'WebPage', 'AboutPage', 'CheckoutPage', 'CollectionPage', 'ContactPage',
        'FAQPage', 'ItemPage', 'MedicalWebPage', 'ProfilePage', 'QAPage',
        'RealEstateListing', 'SearchResultsPage', 'MediaGallery', 'ImageGallery',
        'VideoGallery',
    ]);
}

/**
 * Types that stand on their own and never get an automatic `isPartOf`.
 *
 * @return string[]
 */
function proto_jsonld_standalone_types(): array
{
    return (array) apply_filters('proto_jsonld_standalone_types', [
        'Organization', 'Person', 'Brand', 'WebSite', 'ImageObject', 'Place',
    ]);
}

/**
 * Resolve every relative `@id` ("#q1") in a structure to "<base>#q1".
 */
function proto_jsonld_resolve_ids($value, string $base)
{
    if (!is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $child) {
        if ($key === '@id' && is_string($child) && strpos($child, '#') === 0) {
            $value[$key] = $base . $child;
        } elseif (is_array($child)) {
            $value[$key] = proto_jsonld_resolve_ids($child, $base);
        }
    }

    return $value;
}

/**
 * `wpseo_schema_graph` filter: merge/append the post's custom nodes.
 *
 * @param array  $graph   Yoast's graph pieces.
 * @param object $context Yoast Meta_Tags_Context.
 */
function proto_jsonld_filter_graph($graph, $context)
{
    if (!is_array($graph) || !is_object($context)) {
        return $graph;
    }

    $indexable = $context->indexable ?? null;
    if (!$indexable || ($indexable->object_type ?? '') !== 'post' || empty($indexable->object_id)) {
        return $graph; // Only singular post indexables.
    }

    $post_id = (int) $indexable->object_id;
    if (post_password_required($post_id)) {
        return $graph; // Yoast strips protected pages down to a bare WebPage.
    }

    $raw = get_post_meta($post_id, PROTO_JSONLD_META_KEY, true);
    $nodes = is_string($raw) ? proto_jsonld_parse($raw) : [];
    if ($nodes === []) {
        return $graph;
    }

    $main_id = (string) ($context->main_schema_id ?? '');
    $base    = (string) ($context->canonical ?? '');
    if ($base === '') {
        $base = $main_id !== '' ? $main_id : (string) get_permalink($post_id);
    }

    // Locate Yoast's WebPage piece (its @id is main_schema_id).
    $webpage_key = null;
    foreach ($graph as $key => $piece) {
        if (is_array($piece) && ($piece['@id'] ?? null) === $main_id) {
            $webpage_key = $key;
            break;
        }
    }

    $webpage_types   = proto_jsonld_webpage_types();
    $standalone      = proto_jsonld_standalone_types();
    $counters        = [];

    foreach ($nodes as $node) {
        $types = proto_jsonld_types($node);
        $id    = $node['@id'] ?? null;

        $is_webpage = ($id !== null && ($id === $main_id || $id === '#webpage'))
            || array_intersect($types, $webpage_types) !== [];

        // Merge into Yoast's WebPage piece.
        if ($is_webpage && $webpage_key !== null) {
            $piece       = $graph[$webpage_key];
            $piece_types = proto_jsonld_types($piece);
            $merged      = array_merge($piece, proto_jsonld_resolve_ids($node, $base));

            $merged['@id'] = $piece['@id'];
            $all_types     = array_values(array_unique(array_merge($piece_types, $types)));
            if ($all_types !== []) {
                $merged['@type'] = count($all_types) === 1 ? $all_types[0] : $all_types;
            }

            $graph[$webpage_key] = $merged;
            continue;
        }

        // Append as its own node.
        $node = proto_jsonld_resolve_ids($node, $base);

        if ($is_webpage && $main_id !== '') {
            $node['@id'] = $main_id; // No Yoast WebPage piece to merge into.
        }

        if (empty($node['@id']) || !is_string($node['@id'])) {
            $slug = sanitize_title($types[0] ?? 'node');
            $counters[$slug] = ($counters[$slug] ?? 0) + 1;
            $node['@id'] = $base . '#proto-' . $slug . '-' . $counters[$slug];
        }

        if (!isset($node['isPartOf']) && !$is_webpage && $main_id !== ''
            && array_intersect($types, $standalone) === []) {
            $node['isPartOf'] = ['@id' => $main_id];
        }

        $graph[] = $node;
    }

    return $graph;
}
