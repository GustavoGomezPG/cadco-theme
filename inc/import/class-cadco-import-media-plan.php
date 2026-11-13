<?php

/**
 * What the workbook's media columns ask for, product by product.
 *
 * Built from the normalised rows alone — no WordPress, no network — so what
 * the review screen promises and what the media stage then does come from
 * the same pure computation, and every rule here is unit-tested.
 *
 * Three outputs:
 *
 * - products: per Model #, the image sources in the order written (the first
 *   is the featured image) and the resources it cites, each as a role
 *   ('spec-sheet', 'manual', 'video') and a source key.
 * - sources: every distinct source the workbook cites, keyed by
 *   CADCO_Import_Media::resolve()'s key, so a spec sheet shared by twelve
 *   models is fetched once.
 * - notices: links that cannot be used, reported with the sheet, row, column
 *   and the change to make, like every other issue the importer raises.
 *
 * Media notices never block an import. A product with a broken image link
 * is still a product worth publishing, and the policy that every other
 * problem blocks exists to protect the catalogue's data, which a missing
 * picture does not corrupt. They are reported so CADCO can fix the link, and
 * the next import picks the file up.
 */

declare(strict_types=1);

final class CADCO_Import_Media_Plan
{
    /** Tier used for media notices. Never added to the blocking report. */
    public const TIER = 'M';

    /**
     * Cell text that means "nothing here", which is not worth a notice.
     */
    private const NOTHING = ['n/a', 'na', 'n.a.', 'none', '-', '--', '—', 'no', 'not applicable'];

    /** @var array<string, array{sheet:string,row:int,name:string,images:list<string>,resources:list<array{role:string,key:string}>,cells:array<string,bool>}> */
    private array $products = [];

    /** @var array<string, array<string, mixed>> */
    private array $sources = [];

    /** @var list<CADCO_Import_Issue> */
    private array $notices = [];

    private string $tenant = '';

    /**
     * @param list<array<string, mixed>> $rows normalised rows
     */
    public static function build(array $rows, string $tenant = ''): self
    {
        $plan = new self();

        if ($tenant === '') {
            $cells = [];

            foreach ($rows as $row) {
                foreach (array_keys(cadco_import_media_roles()) as $column) {
                    $cells[] = (string) ($row[$column] ?? '');
                }
            }

            $tenant = CADCO_Import_Media::tenant_host($cells);
        }

        $plan->tenant = $tenant;

        foreach ($rows as $row) {
            $model = trim((string) ($row['Model #'] ?? ''));

            if ($model === '') {
                continue;
            }

            $plan->add_row($model, $row);
        }

        return $plan;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function add_row(string $model, array $row): void
    {
        $entry = [
            'sheet'     => (string) ($row['__sheet'] ?? ''),
            'row'       => (int) ($row['__row'] ?? 0),
            'name'      => trim((string) ($row['Product Name'] ?? '')),
            'images'    => [],
            'resources' => [],
            // Which roles had any link at all. A role whose cell is empty is
            // one the workbook has stopped asking for; a role whose links all
            // failed is one it still wants — the media stage treats the two
            // differently (see CADCO_Import_Media_Applier::assign_images()).
            'cells'     => [],
        ];

        foreach (cadco_import_media_roles() as $column => $role) {
            $cell  = (string) ($row[$column] ?? '');
            $links = CADCO_Import_Media::links($cell);

            if ($links === []) {
                $this->notice_for_text($entry, $column, $cell);
                continue;
            }

            $entry['cells'][$role] = true;

            foreach ($links as $link) {
                $resolved = CADCO_Import_Media::resolve($link, $this->tenant);

                if (!$this->usable($entry, $column, $role, $resolved)) {
                    continue;
                }

                $key = $resolved['key'];

                if (!isset($this->sources[$key])) {
                    $this->sources[$key] = $resolved + ['models' => [], 'roles' => []];
                }

                if (!in_array($model, $this->sources[$key]['models'], true)) {
                    $this->sources[$key]['models'][] = $model;
                }

                $effective = $resolved['kind'] === CADCO_Import_Media::KIND_VIDEO ? 'video' : $role;

                if (!in_array($effective, $this->sources[$key]['roles'], true)) {
                    $this->sources[$key]['roles'][] = $effective;
                }

                if ($role === 'image') {
                    if (!in_array($key, $entry['images'], true)) {
                        $entry['images'][] = $key;
                    }

                    continue;
                }

                $ref = ['role' => $effective, 'key' => $key];

                if (!in_array($ref, $entry['resources'], true)) {
                    $entry['resources'][] = $ref;
                }
            }
        }

        // A model listed twice (on two sheets) is a Tier A error the
        // validator blocks on; merging rather than overwriting keeps this
        // class total even when it is handed rows that never went through it.
        if (isset($this->products[$model])) {
            $existing                     = $this->products[$model];
            $existing['images']           = array_values(array_unique(array_merge($existing['images'], $entry['images'])));
            $existing['resources']        = array_values(array_map('unserialize', array_unique(array_map('serialize', array_merge($existing['resources'], $entry['resources'])))));
            $existing['cells']            = $existing['cells'] + $entry['cells'];
            $this->products[$model]       = $existing;

            return;
        }

        $this->products[$model] = $entry;
    }

    /**
     * Whether a resolved link can be used in this column, raising a notice
     * when it cannot (or can, but will stop working).
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $link
     */
    private function usable(array $entry, string $column, string $role, array $link): bool
    {
        $found = (string) $link['source'];

        switch ($link['problem']) {
            case CADCO_Import_Media::PROBLEM_LOCAL_PATH:
                $this->notice($entry, $column, $found,
                    'A path on someone\'s computer, which the website cannot reach.',
                    'Replace it with the file\'s SharePoint sharing link (Share > Copy link, set to "Anyone with the link").');

                return false;

            case CADCO_Import_Media::PROBLEM_FOLDER:
                $this->notice($entry, $column, $found,
                    'A link to a folder, not to a file. The website needs the file itself.',
                    'Open the folder, share the file inside it, and paste that file\'s link instead.');

                return false;

            case CADCO_Import_Media::PROBLEM_NO_TENANT:
                $this->notice($entry, $column, $found,
                    'A relative link, and no full SharePoint link anywhere in the workbook to complete it from.',
                    'Paste the file\'s full sharing link (starting https://).');

                return false;

            case CADCO_Import_Media::PROBLEM_SHAREPOINT_PATH:
                $this->notice($entry, $column, $found,
                    'A SharePoint library address that only signed-in CADCO staff can open.',
                    'Use Share > Copy link on the file, set to "Anyone with the link", and paste that link instead.');

                return false;

            case CADCO_Import_Media::PROBLEM_BAD_VIDEO:
                $this->notice($entry, $column, $found,
                    'A YouTube or Vimeo address that does not name a single video.',
                    'Paste the video\'s own address (the one its Share button gives).');

                return false;

            case CADCO_Import_Media::PROBLEM_OLD_SITE:
                // Usable today, so it is used; reported because it will not
                // be after launch, and the next import would then lose it.
                $this->notice($entry, $column, $found,
                    'Hosted on cadco-ltd.com, which is being replaced. It imports now, but the link stops working when the new site launches.',
                    'Move the file into the SharePoint media library and link it from there.');
                break;
        }

        if ($link['kind'] === CADCO_Import_Media::KIND_UNREACHABLE) {
            $this->notice($entry, $column, $found,
                'Not a link the website can follow.',
                'Paste the file\'s full sharing link (starting https://).');

            return false;
        }

        if ($role === 'image' && $link['kind'] === CADCO_Import_Media::KIND_VIDEO) {
            $this->notice($entry, $column, $found,
                'A video link in the Images column.',
                'Move it to the Video URL column and link an image here.');

            return false;
        }

        return true;
    }

    /**
     * A cell with text but no link. 'n/a' and the like say there is nothing
     * to have, which is a fine answer; a bare file name says there is a file
     * the importer was not told how to find.
     *
     * @param array<string, mixed> $entry
     */
    private function notice_for_text(array $entry, string $column, string $cell): void
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $cell));

        if ($text === '' || in_array(mb_strtolower($text), self::NOTHING, true)) {
            return;
        }

        $this->notice($entry, $column, $text,
            'Text with no link behind it. The website cannot find a file from its name alone.',
            'Add the file\'s SharePoint sharing link to the cell (as a hyperlink on the name, or pasted in).');
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function notice(array $entry, string $column, string $found, string $message, string $fix): void
    {
        $this->notices[] = new CADCO_Import_Issue(
            self::TIER,
            (string) $entry['sheet'],
            (int) $entry['row'] > 0 ? (int) $entry['row'] : null,
            $column,
            $found,
            $message,
            $fix
        );
    }

    public function tenant(): string
    {
        return $this->tenant;
    }

    /**
     * @return array<string, array{sheet:string,row:int,name:string,images:list<string>,resources:list<array{role:string,key:string}>,cells:array<string,bool>}>
     */
    public function products(): array
    {
        return $this->products;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * Sources that are files to download (not videos).
     *
     * @return array<string, array<string, mixed>>
     */
    public function files(): array
    {
        return array_filter($this->sources, static fn (array $s): bool => $s['kind'] === CADCO_Import_Media::KIND_FILE && $s['fetch'] !== '');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function videos(): array
    {
        return array_filter($this->sources, static fn (array $s): bool => $s['kind'] === CADCO_Import_Media::KIND_VIDEO);
    }

    /**
     * Every resource the workbook implies, before content deduplication:
     * one entry per role and source, with the models that cite it.
     *
     * Two different links can still turn out to be the same file once
     * downloaded (a short and a long sharing link to one PDF); the media
     * applier merges those by content. This is the list it starts from.
     *
     * @return list<array{role:string,key:string,models:list<string>}>
     */
    public function resources(): array
    {
        $out = [];

        foreach ($this->products as $model => $product) {
            foreach ($product['resources'] as $ref) {
                $id = $ref['role'] . '|' . $ref['key'];

                if (!isset($out[$id])) {
                    $out[$id] = ['role' => $ref['role'], 'key' => $ref['key'], 'models' => []];
                }

                $out[$id]['models'][] = (string) $model;
            }
        }

        return array_values($out);
    }

    /**
     * @return list<CADCO_Import_Issue>
     */
    public function notices(): array
    {
        return $this->notices;
    }

    /**
     * @return array{products:int,images:int,files:int,videos:int,resources:int,notices:int}
     */
    public function counts(): array
    {
        $images = 0;

        foreach ($this->products as $product) {
            $images += count($product['images']);
        }

        return [
            'products'  => count(array_filter($this->products, static fn (array $p): bool => $p['images'] !== [] || $p['resources'] !== [])),
            'images'    => $images,
            'files'     => count($this->files()),
            'videos'    => count($this->videos()),
            'resources' => count($this->resources()),
            'notices'   => count($this->notices),
        ];
    }

    /**
     * Notices as CSV, in the validation report's own format and through its
     * own formula-injection defence — the found values are workbook data.
     */
    public function notices_csv(): string
    {
        $report = new CADCO_Import_Report();
        $report->add_many($this->notices);

        return $report->to_csv();
    }
}
