# Test catalogue content

Throwaway products, so the product archive has something real to lay out before
the client's own import exists. Everything written here carries a flag and comes
back out again with one command — see the theme README, "Test catalogue
content", for the flags and the full workflow.

## Importing

`cadco-products-50.json` is already built; it is all the import needs.

```bash
wp eval-file scripts/test-data/import-test-products.php \
    wp-content/themes/cadco-theme/scripts/test-data/cadco-products-50.json
wp eval-file scripts/test-data/assign-size-attribute.php go
```

## Removing

```bash
wp eval-file scripts/test-data/remove-test-products.php      # dry run
wp eval-file scripts/test-data/remove-test-products.php go
```

## Rebuilding the JSON

Only needed to change the selection. The three numbered scripts are the scrape,
kept so the data can be regenerated rather than hand-edited. They read
cadco-ltd.com, which has no API, so they parse its HTML and will need revisiting
if that site is rebuilt.

```bash
python3 -I 01-collect-urls.py     urls.json
python3 -I 02-scrape-products.py  urls.json products.json
python3 -I 03-select-50.py        products.json cadco-products-50.json
```

`02` fetches ~70 product pages at a 0.3s interval. `03` holds the editorial
decisions: how many to take from each type, how the live site's product types
map onto this site's four top-level categories, and how a page's model number
and descriptive line become the card's title and spec line.

The selection is weighted so Convection Ovens — the category the archive was
designed against — holds 28 products, which is the count the design shows.
