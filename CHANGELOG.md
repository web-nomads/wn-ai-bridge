# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- Enabled PHPUnit TestDox output so every individual test and its result is shown when running the unit tests

## [1.2.0] - 2026-07-17

### Added
- On-site **AI search assistant**: a floating chat widget that helps visitors find information on the website and answers with suggestions and links to the relevant pages
- Hybrid answering: works search-only out of the box (ranked hits + links, no API key needed) and produces grounded, cited natural-language answers via an LLM when an API key is configured (retrieval-augmented generation)
- Anthropic Claude integration over the Messages REST API (via TYPO3's bundled Guzzle client, no extra Composer dependency), behind a swappable `LlmClientInterface`
- Search provider abstraction with graceful degradation and three backends: `ke_search` (fulltext on `tx_kesearch_index`), core `indexed_search` (`index_phash` + `index_fulltext`) and a dependency-free `pages`/`tt_content` fallback that works even without a search index
- `SearchService` that aggregates the available providers, merges results by rank and de-duplicates URLs
- PSR-15 `AssistantRequestMiddleware` exposing a JSON endpoint (`POST …/wn-ai-bridge/ask`); the rate limiter now also protects this endpoint
- Extension configuration for the assistant: `assistantEnabled`, `assistantProvider`, `assistantApiKey`, `assistantModel`, `assistantSearchSources`, `assistantMaxResults`, `assistantMaxTokens`
- Per-site configuration (Site tab): enable toggle, title, welcome message, input placeholder, accent color, auto-open toggle with configurable delay, additional system prompt, OnePager mode and an optional search root page
- OnePager support for result links: on sites flagged as OnePager, hits on direct children of the site root link to a homepage anchor (e.g. `/#customers`) instead of a separate sub-page URL
- Accessible, dependency-free frontend widget (keyboard/focus handling, ARIA roles, dark mode, mobile full-screen, reduced-motion support)
- Bot protection for the assistant endpoint (`assistantBotProtection`, on by default): blocks crawlers/scripts via a widget proof header, bot User-Agent detection and a same-origin check, returning `403` with a "no bots allowed" message
- Unit tests for `SearchQuery`, `SearchService` (ranking/dedup), the `AssistantService` search-only fallback and the `BotDetectionService`

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

[Unreleased]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v0.2.0...v1.0.0
[0.2.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v0.1.9...v0.2.0
[0.1.9]: https://github.com/web-nomads/wn-ai-bridge/releases/tag/v0.1.9