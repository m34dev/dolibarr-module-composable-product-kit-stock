# Composable Product Kit Stock Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-11-17

### Added

- Automatic sending of the composable stock export by email to the contacts of a category
- Email template type "Composable kit stock export" in email templates setup
- Sending schedule: daily, weekly or monthly recurrence, sending hours, working days only, number of emails per sending task
- Test email and recipients preview in module setup
- Agenda events on contacts for each sent or failed email

### Updated

- CSV exports use Dolibarr's CSV separator setting, with a decimal comma when the separator is a semicolon
- Minimum Dolibarr version 21

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
