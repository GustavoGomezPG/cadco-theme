<?php

/**
 * Run directories as a versioned history.
 *
 * Every upload — including one that fails validation — is archived under
 * wp-content/uploads/cadco-imports/{Y-m-d-His}-{userid}-{random}/ as
 * workbook.xlsx, report.csv, plan.json and (transiently, while an apply is
 * in progress) queue.bin. This class adds manifest.json — one small file per
 * run recording what the run was and whether it was ever applied — and turns
 * the directory into a retained, browsable history: create the run
 * directory, read and write its manifest, and prune it down to the newest
 * N runs.
 *
 * is_valid_run_id() is the security boundary. A run id arrives from the
 * browser (restore, label editing) in later tasks, so every method that
 * turns a run id into a path validates it with the exact same anchored
 * pattern first — never a looser check, never after the path is already
 * built.
 */

declare(strict_types=1);

final class CADCO_Import_Archive
{
    /**
     * Anchored deliberately: a timestamp, a user id, and the random suffix
     * generated in create(). Matched against the run id BEFORE it is ever
     * used to build a path, so something like "../../../etc/passwd" or a
     * trailing "/.." never reaches the filesystem.
     */
    private const RUN_ID_PATTERN = '/^\d{4}-\d{2}-\d{2}-\d{6}-\d+-[A-Za-z0-9]{12}$/';

    public static function is_valid_run_id(string $id): bool
    {
        return preg_match(self::RUN_ID_PATTERN, $id) === 1;
    }

    private static function base_dir(): string
    {
        return trailingslashit(wp_upload_dir()['basedir']) . 'cadco-imports';
    }

    /**
     * Make a new run directory and return its id and path.
     *
     * The timestamp alone is guessable; the user ID plus a random component
     * makes the directory unguessable (it sits behind guard_imports_dir()'s
     * deny-all anyway, but this is cheap and removes the timestamp collision
     * between two operators uploading in the same second as a side effect).
     *
     * @return array{run_id:string, dir:string}
     */
    public static function create(string $filename, int $user_id): array
    {
        $base_dir = self::base_dir();

        self::guard_imports_dir($base_dir);

        $run_id = gmdate('Y-m-d-His') . '-' . $user_id . '-' . wp_generate_password(12, false);
        $dir    = $base_dir . '/' . $run_id;

        wp_mkdir_p($dir);

        return ['run_id' => $run_id, 'dir' => $dir];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function write_manifest(string $dir, array $manifest): void
    {
        file_put_contents(
            $dir . '/manifest.json',
            (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Amend a run's manifest once the apply run it describes actually
     * completes.
     *
     * `applied` distinguishes a workbook that was reviewed from one that was
     * actually run. It is written false at upload and only flipped to true
     * here, called from the same place the batch loop declares completion —
     * a run abandoned midway (browser closed, batch failed) is never
     * presented as a usable restore point.
     */
    public static function mark_applied(string $dir): void
    {
        $manifest = self::read_manifest($dir . '/manifest.json');

        if ($manifest === null) {
            return;
        }

        $manifest['applied']    = true;
        $manifest['applied_at'] = gmdate('Y-m-d\TH:i:s\Z');

        self::write_manifest($dir, $manifest);
    }

    /**
     * Every run's manifest, newest first.
     *
     * Reads only manifest.json, never a workbook, so the history list stays
     * fast no matter how many runs are retained. A run whose manifest is
     * missing, unreadable or corrupt (a crash mid-write, a hand edit)
     * degrades gracefully — it is left out of the list rather than fataling
     * the whole screen for one bad file.
     *
     * Sorted by run id (its Y-m-d-His prefix) descending rather than by
     * filemtime(), which is exactly chronological for names this class
     * creates and removes a syscall that can fail.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $base_dir = self::base_dir();

        if (!is_dir($base_dir)) {
            return [];
        }

        $entries = scandir($base_dir);

        if ($entries === false) {
            return [];
        }

        $runs = [];

        foreach ($entries as $entry) {
            if (!self::is_valid_run_id($entry)) {
                continue;
            }

            $path = $base_dir . '/' . $entry;

            if (is_link($path) || !is_dir($path)) {
                continue;
            }

            $manifest = self::read_manifest($path . '/manifest.json');

            if ($manifest === null) {
                continue;
            }

            $manifest['run_id'] = $entry;
            $runs[]              = $manifest;
        }

        usort($runs, static fn (array $a, array $b): int => strcmp((string) $b['run_id'], (string) $a['run_id']));

        return $runs;
    }

    /**
     * A single run's manifest, or null if the id is invalid or nothing
     * usable is on disk for it.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $run_id): ?array
    {
        if (!self::is_valid_run_id($run_id)) {
            return null;
        }

        $path = self::base_dir() . '/' . $run_id;

        if (is_link($path) || !is_dir($path)) {
            return null;
        }

        $manifest = self::read_manifest($path . '/manifest.json');

        if ($manifest === null) {
            return null;
        }

        $manifest['run_id'] = $run_id;

        return $manifest;
    }

    /**
     * Edit a run's label in place. False for an invalid id, a run that does
     * not exist, or a manifest that cannot be read back — never a fatal.
     */
    public static function set_label(string $run_id, string $label): bool
    {
        if (!self::is_valid_run_id($run_id)) {
            return false;
        }

        $dir = self::base_dir() . '/' . $run_id;

        if (is_link($dir) || !is_dir($dir)) {
            return false;
        }

        $manifest = self::read_manifest($dir . '/manifest.json');

        if ($manifest === null) {
            return false;
        }

        $manifest['label'] = $label;

        self::write_manifest($dir, $manifest);

        return true;
    }

    /**
     * Keep the newest $keep run directories, delete the rest.
     *
     * Replaces the previous 7-day age-based collector: a restore point
     * should not disappear purely because a fortnight passed. Ordered by
     * run id descending — the Y-m-d-His prefix makes that exactly
     * chronological — keep the first $keep, delete the rest.
     *
     * Every safety property of the collector this replaces is carried over
     * verbatim: only entries whose name matches the anchored run-id pattern
     * are even considered (an unrecognised name is left alone, not touched);
     * symlinks are skipped at both the directory and file level; only plain
     * files directly inside a matching directory are ever unlinked, never a
     * recursion into an unexpected subdirectory; and every failing syscall
     * (scandir() returning false) means skip, never delete. This never
     * touches anything outside the archive base directory.
     *
     * @return list<string> the run ids that were actually deleted
     */
    public static function prune(int $keep = 20): array
    {
        $base_dir = self::base_dir();

        if (!is_dir($base_dir)) {
            return [];
        }

        $entries = scandir($base_dir);

        if ($entries === false) {
            return [];
        }

        $run_ids = [];

        foreach ($entries as $entry) {
            if (!self::is_valid_run_id($entry)) {
                continue;
            }

            $path = $base_dir . '/' . $entry;

            if (is_link($path) || !is_dir($path)) {
                continue;
            }

            $run_ids[] = $entry;
        }

        rsort($run_ids);

        $to_delete = array_slice($run_ids, max(0, $keep));
        $deleted   = [];

        foreach ($to_delete as $run_id) {
            $path = $base_dir . '/' . $run_id;

            $files = scandir($path);

            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $file_path = $path . '/' . $file;

                if (is_file($file_path) && !is_link($file_path)) {
                    unlink($file_path);
                }
            }

            // Fails harmlessly if an unexpected entry kept the directory
            // from being empty — that entry was left alone above, on
            // purpose, rather than deleted. Only report the run id as
            // deleted when the directory is actually gone.
            if (@rmdir($path)) {
                $deleted[] = $run_id;
            }
        }

        return $deleted;
    }

    /**
     * Read and decode a manifest.json, degrading to null rather than
     * throwing on anything that goes wrong: a missing file, a symlink, an
     * unreadable file, or JSON that does not decode to an array (malformed,
     * truncated, or simply not an object/array at the top level).
     *
     * @return array<string, mixed>|null
     */
    private static function read_manifest(string $path): ?array
    {
        if (is_link($path) || !is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $manifest = json_decode($raw, true);

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * Make sure the archive directory cannot be browsed or executed.
     *
     * Every run's workbook, report, plan and manifest live under
     * wp-content/uploads/cadco-imports/ — real catalogue data (SKUs, UPCs,
     * supplier descriptions, dimensions). Nothing under uploads/ on this
     * install carries an .htaccess of its own, so without this the whole
     * run archive is one guessable directory name away from being readable
     * — or, with autoindex on, listable — by anyone.
     *
     * Idempotent and cheap: called on every upload, so a guard file removed
     * by hand is simply rewritten on the next run rather than staying gone.
     */
    private static function guard_imports_dir(string $base_dir): void
    {
        wp_mkdir_p($base_dir);

        $index = $base_dir . '/index.php';

        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        $htaccess = $base_dir . '/.htaccess';

        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n");
        }
    }
}
