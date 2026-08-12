<?php

/**
 * Loads the import system.
 *
 * The pure units are plain includes with no side effects, so that the test
 * suite can require them individually without WordPress. Only the admin and
 * meta box register hooks, and only inside wp-admin.
 */

declare(strict_types=1);

/**
 * PhpSpreadsheet ships inside the theme — the WP Engine deploy has no build
 * step, so there is nothing to run composer install on the server.
 */
if (is_readable(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

/**
 * Files are loaded if present rather than unconditionally, so that this file
 * can be committed before every unit exists and the site never fatals on a
 * half-built branch. By the end of the plan all of them are here.
 */
foreach ([
    'field-map.php',
    'class-cadco-import-reader.php',
    'class-cadco-import-normaliser.php',
    'class-cadco-import-issue.php',
    'class-cadco-import-report.php',
    'class-cadco-import-validator.php',
    'class-cadco-import-plan.php',
    'class-cadco-import-planner.php',
    'class-cadco-import-repository.php',
    'class-cadco-import-applier.php',
    'class-cadco-import-archive.php',
] as $cadco_import_file) {
    if (is_readable(__DIR__ . '/' . $cadco_import_file)) {
        require_once __DIR__ . '/' . $cadco_import_file;
    }
}
unset($cadco_import_file);

if (is_admin()) {
    foreach (['class-cadco-import-admin.php', 'class-cadco-product-meta-box.php'] as $cadco_admin_file) {
        if (is_readable(__DIR__ . '/' . $cadco_admin_file)) {
            require_once __DIR__ . '/' . $cadco_admin_file;
        }
    }
    unset($cadco_admin_file);

    if (class_exists('CADCO_Import_Admin')) {
        CADCO_Import_Admin::init();
    }

    if (class_exists('CADCO_Product_Meta_Box')) {
        CADCO_Product_Meta_Box::init();
    }
}
