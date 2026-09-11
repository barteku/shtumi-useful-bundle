# Changelog

## [1.6.2] - 2026-09-11

### Fixed

- **DependentFilteredSelect2Type**: pass `multiple` to Select2 as a real boolean. The previous quoted `'{{ multiple }}'` turned the default `0` into JS `'0'` (truthy), so single-value fields rendered as multi-select. Empty placeholder options are no longer pre-selected.

## [1.5.6] - 2025-03-03

### Fixed

- **AjaxMediaType upload**: Ensure uploaded files are stored with the correct extension based on actual content (magic bytes). Prevents PDF invoices from being saved as `.png` and vice versa when MIME detection misidentifies the format. Fixes both regular and chunked upload paths.
