# Changelog

## [1.5.6] - 2025-03-03

### Fixed

- **AjaxMediaType upload**: Ensure uploaded files are stored with the correct extension based on actual content (magic bytes). Prevents PDF invoices from being saved as `.png` and vice versa when MIME detection misidentifies the format. Fixes both regular and chunked upload paths.
