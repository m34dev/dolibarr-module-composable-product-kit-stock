# Composable Product Kit Stock Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-11-20

### Added

- Automatic sending of the composable stock export by email to the contacts of a category, with an hourly scheduled job
- Emailed export: products of a category and its sub-categories, on sale only, with the selected fields
- Email template type "Composable kit stock export" in email templates setup
- Sending schedule: daily, weekly or monthly recurrence, sending hours, working days only, number of emails per sending task
- Test email, recipients preview and configuration warnings in module setup
- Agenda events on contacts for each sent or failed email
- Physical stock column in the export
- Setup option to combine physical and composable stock in a single export stock column

### Updated

- CSV exports use Dolibarr's CSV separator setting, with a decimal comma when the separator is a semicolon
- Minimum Dolibarr version 21
- Requires the Categories, Agenda and Scheduled jobs modules
- Export stock column: composable stock for kits, physical stock for other products (was physical + composable)
- Composable stock and exported physical stock ignore closed warehouses
- Composable stock calculation documented in README
- Faster composable stock on product card, product list, composition tab and export: subproducts stock read once, total and per warehouse together
- CSV export includes the products of the sub-categories of the selected category, like the export sent by email
- API: HTTP 400 for a service (was 500), clearer 404 message for a product that is not a kit

### Fixed

- "Show composable stock per warehouse" set back to Yes when the module is re-activated
- Warehouse ref not escaped in the composable stock per warehouse
- Composable stock per warehouse missing the internal warehouses
- Composable stock when a subproduct has a negative stock (was shown as N/A or "No subproduct", or hidden)
- Error when a subproduct has a quantity of 0 in the kit
- Composable stock rounding with decimal subproduct quantities
- Checkbox extrafields exported as 0/1 instead of their values

## [1.2.2] - 2026-09-08

### Updated

- Improved API error handling

## [1.2.1] - 2026-06-11

### Added

- Product public URL to export

## [1.2.0] - 2026-06-09

### Added

- Composable stock export
- Composable stock column selector

## [1.1.4] - 2026-05-27

### Fixed

- Update URL

## [1.1.3] - 2025-12-02

### Added

- PHP 7.4 compatibility
- Dolibarr v18 compatibility

## [1.1.2] - 2025-11-25

### Fixed

- Composable stock per warehouse when missing some subproducts

## [1.1.1] - 2025-11-24

### Fixed

- Product creation card error

## [1.1.0] - 2025-11-24

### Added

- Composable stock per warehouse detail option

## [1.0.0] - 2025-11-20

Initial version
