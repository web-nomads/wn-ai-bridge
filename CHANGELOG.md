# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-07-17

### Added
- Rate limiter for AI-Bridge requests (llms.txt and Markdown endpoints) to protect the server from bot/crawler overload
- Extension configuration `rateLimiterEnabled` to switch the rate limiter globally on/off
- Extension configuration `rateLimiterRequestsPerMinute` for a per-IP request limit
- Extension configuration `rateLimiterPerKeyRequestsPerMinute` for a per API-key / bot-ID request limit
- PSR-15 middleware returning `429 Too Many Requests` with a `Retry-After` header when a limit is exceeded
- Dedicated `wn_ai_bridge_ratelimit` cache for fixed-window request counters
- Unit tests for the RateLimiterService

### Fixed
- Aligned HtmlCleanerService unit tests with the service's actual behaviour (structural tags are preserved, whitespace collapsed, dangerous tags stripped)

## [1.0.0] - 2026-06-26

### Added
- HTML parsing fallback (`parsingFallbackHtml`) as an alternative Markdown source when native rendering is insufficient
- Markdown caching toggle (`cacheMarkdown`) to enable or disable caching of generated page Markdown
- Fallback configuration options in `ext_conf_template.txt`
- `DownloadMarkdownCommand` CLI command for exporting TYPO3 pages as Markdown files

### Changed
- First stable release of the AI Bridge extension

## [0.2.0] - 2026-01-22

### Added
- Unit tests for ConfigurationService with full coverage
- TYPO3 v14 LTS support in test runner scripts
- Comprehensive test suite runnable across TYPO3 12, 13, and 14

### Changed
- Refactored ConfigurationService to use TYPO3 core request injection pattern
- Request is now injected once via `setRequest()` instead of passing to each method
- Added fallback to `$GLOBALS['TYPO3_REQUEST']` for backward compatibility
- Improved code architecture following TYPO3 ContentObjectRenderer pattern

### WIP
- Started implementation of DownloadMarkdownCommand for CLI access to Markdown export

## [0.1.9] - 2025-10-29

### Added
- Initial release of LLMS TXT Generator extension
- Complete llms.txt generation according to llmstxt.org specification
- Automatic site navigation structure inclusion with configurable depth
- Page-to-Markdown conversion for any TYPO3 page via .md suffix
- Integration with TYPO3's native content rendering pipeline
- TypoScript-based configuration for all settings
- Route enhancers for user-friendly URLs
- Comprehensive documentation following TYPO3 standards
- Support for TYPO3 v12, v13, and v14 LTS

[Unreleased]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v0.2.0...v1.0.0
[0.2.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v0.1.9...v0.2.0
[0.1.9]: https://github.com/web-nomads/wn-ai-bridge/releases/tag/v0.1.9