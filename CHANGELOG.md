# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.14.0] - 2026-07-20

### Added
- The per-site llms.txt texts (title, description, additional info, keywords) can now also be maintained per language variation in the Site settings (on each site language), analogous to the AI search assistant texts. When the page is viewed in a foreign language, the localized values are rendered in the llms.txt. Resolution order: language value → site (default language) value

## [1.13.0] - 2026-07-19

### Changed
- The filters in the "AI Assistant Log" and "Bot Access" backend modules now apply via AJAX: the result list, statistics and pagination update in place without a full page reload. This also fixes the duplicated module header that appeared when submitting the log filter (a POST re-rendered the module doc-header). Filtering is now live (selects/checkboxes apply immediately, free-text fields debounced), the browser URL stays in sync so a reload keeps the active filter, and the forms still submit normally when JavaScript is unavailable

## [1.12.1] - 2026-07-19

### Changed
- On smartphones the chat panel no longer covers the entire page; it stays anchored bottom-right and leaves roughly 30px of the site visible on the left and top

## [1.12.0] - 2026-07-19

### Added
- Assistant temperature is now configurable in the extension configuration (`assistantTemperature`, decimal 0.0–1.0, default 0.2) and passed to the LLM; empty/invalid values fall back to 0.2 and the value is clamped to the valid range
- Global agent instructions can be configured in the extension configuration (`assistantInstructions`); they are added to the system prompt on every answer and combine with the existing per-site instructions

## [1.11.1] - 2026-07-17

### Changed
- Replaced the "New discussion" button icon with a reset/refresh icon

## [1.11.0] - 2026-07-17

### Added
- Multilingual chat widget: all UI texts (buttons, headings, status/error messages) and the visitor-facing search-only/no-results messages are now resolved per site language from bundled translations. Default translations are shipped for German, English, French, Spanish, Italian, Portuguese, Dutch, Russian, Polish, Turkish, Arabic, Chinese (Simplified), Japanese and Hindi
- The per-site assistant texts (title, welcome, placeholder, system prompt) can now also be maintained per language variation in the Site settings (on each site language). Resolution order: language value → site value → bundled translation → default

### Changed
- Widget labels and the search-only/no-results messages are no longer hardcoded in German; they fall back to the bundled default for the current language

## [1.10.2] - 2026-07-17

### Changed
- The "New discussion" confirmation is now a styled, semi-transparent overlay inside the chat panel instead of the native browser confirm dialog (click the dimmed backdrop or Escape to cancel)

## [1.10.1] - 2026-07-17

### Changed
- The "New discussion" button now asks for confirmation before discarding an existing conversation (skipped when only the greeting is shown)

## [1.10.0] - 2026-07-17

### Added
- "New discussion" button in the chat header: clears the current conversation, resets the stored transcript and starts a fresh backend thread (new conversation id), then shows the welcome message again

## [1.9.1] - 2026-07-17

### Changed
- The assistant answer no longer contains inline links. The `[n]` citation markers are stripped from the answer text and only used to list the relevant pages below the answer under the heading "Weiterführende Links zum Thema" (renamed from "Möglicherweise auch interessant"). The system prompt now instructs the model to write self-contained sentences without link phrases and to put the numbers only at the end of a sentence/answer

## [1.9.0] - 2026-07-17

### Added
- New backend module **Corrections** (own navigation entry under "AI Bridge"): approve or reject visitor corrections, and revoke previously approved ones. Moderation moved out of the AI Assistant Log into its own module
- New backend module **Bot Access Log** (own navigation entry): records when bots/crawlers access llms.txt, the Markdown (.md) versions and normal pages, with per-row detail (date, type, detected bot, AI flag, HTTP status, method, path + query, IP, user agent, referer). Filterable by date, type, bot, IP, free text and an "AI crawlers only" toggle, with a statistics overview and a clear-log action
- Opt-in extension setting `botAccessLogging` (off by default) and a bot access middleware; `BotDetectionService` can now name the crawler and flag AI bots

### Changed
- The visitor-correction moderation panel was removed from the AI Assistant Log module (it now lives in the dedicated Corrections module)

### Upgrade notes
- Run the database schema update (e.g. `typo3 extension:setup` or the Install Tool) to create the new `tx_wnaibridge_bot_access` table. Enable "Log bot accesses" in the extension configuration to start recording

## [1.8.1] - 2026-07-17

### Changed
- Learning from visitor corrections is now switched on/off per site in the Site settings ("Learn from visitor corrections", off by default) instead of the global extension configuration. The backend review panel shows pending corrections regardless, so they can always be moderated

## [1.8.0] - 2026-07-17

### Added
- Owner-moderated learning from visitor corrections (opt-in via the new `assistantLearningEnabled` extension setting). When a visitor corrects an answer, the correction is captured as "pending" and shown for review in the AI Assistant Log backend module. Only corrections an editor approves are fed back into future answers — and only when a new question thematically matches (keyword overlap) — so a visitor cannot poison the assistant for others
- New `tx_wnaibridge_assistant_learning` table, `LearningService`, `AssistantLearningRepository`, and an approve/reject panel in the backend module

### Upgrade notes
- Run the database schema update (e.g. `typo3 extension:setup` or the Install Tool) to create the new `tx_wnaibridge_assistant_learning` table. The feature is off by default; enable it in the extension configuration ("Aus Korrekturen lernen?")

## [1.7.0] - 2026-07-17

### Changed
- Search snippets are now keyword-centred: the excerpt handed to the LLM shows the passage around the matched term instead of the page's (often navigational) start, so the assistant can actually answer content questions (e.g. "Bietet Marcel auch Upgrades an?") instead of replying that it found nothing
- The plain page/content provider now builds snippets from the matching `tt_content` passage (falling back to the page description/abstract), so keyword hits in the page body surface as context
- When the same page is found by several backends, the result now keeps the snippet that actually contains the query terms instead of the first (possibly navigational) one

### Fixed
- Refined the assistant system prompt: it must point to the closest matching page (with alternatives) instead of saying it found nothing, and must never emit technical meta-commentary about the search/context (e.g. "der Kontext zeigt nur die Startseite", "die Details sind nicht sichtbar")

## [1.6.3] - 2026-07-17

### Fixed
- Refined the assistant system prompt to prevent duplicated/awkward link phrasing such as "Kontakt Kontaktseite": the model now introduces links with a short lead-in and the citation number at the end/after a colon (e.g. "... unter diesem Link: [1]") and must not place a descriptive word like "Seite"/"Bereich" next to the number, since the number is already replaced by the linked page title

## [1.6.2] - 2026-07-17

### Fixed
- OnePager section links now work even with themes that keep sections hidden until their own navigation link is clicked (e.g. BootstrapMade-style templates that toggle a `.section-show` class). For a chat link targeting the current page + fragment, the widget now replays the matching in-page navigation link so the theme reveals the section and scrolls as designed; it falls back to native hash scrolling when no such link exists

## [1.6.1] - 2026-07-17

### Fixed
- OnePager section links in the chat now scroll to the section when the visitor is already on that page. The links are absolute URLs ("https://site/#section") which did not trigger the theme's anchor scrolling; the widget now drives native hash navigation for a link to the current page + fragment (firing "hashchange" for scroll-spy), just like the site navigation. Links to another page keep navigating normally

## [1.6.0] - 2026-07-17

### Added
- The chat conversation now persists across page navigation within the site (stored in sessionStorage): the discussion — and whether the panel was open — is restored on the next page and only cleared when the visitor closes the browser/tab

### Changed
- Links in the chat (both inline citations and the suggested-pages list) now open internal links in the same tab, so navigating the site keeps the conversation; only links to another host still open in a new tab (`target="_blank"`)

## [1.5.0] - 2026-07-17

### Added
- The AI Assistant Log backend module now shows the actual suggested links: the answer's `[n]` citations are rendered as page-title links (mirroring the widget), and each turn lists the suggested pages below the answer
- The suggested pages (title + URL) are now persisted with each log entry (new `sources` column on `tx_wnaibridge_assistant_log`)

### Upgrade notes
- Run the database schema update (e.g. `typo3 extension:setup` or the Install Tool) to add the new `sources` column. Existing entries simply show no links.

## [1.4.3] - 2026-07-17

### Fixed
- OnePager section links now work: the result de-duplication no longer strips the URL anchor, so distinct sections ("/#about", "/#services") stay separate results instead of collapsing into the anchor-less homepage. Previously every OnePager hit linked to the site root
- Reinforced the assistant system prompt to cite the specific section/page rather than falling back to the generic homepage

## [1.4.2] - 2026-07-17

### Changed
- Renamed the suggestions heading from "Passende Seiten" to "Möglicherweise auch interessant"
- The suggestions block (heading included) is now reliably omitted whenever there are no linkable entries to show

## [1.4.1] - 2026-07-17

### Changed
- Inline page citations now render the page title as the linked text (e.g. a linked "alao – The love place für dein Abo") instead of linking the raw "[1]" marker. A title the model wrote in bold right before the marker is de-duplicated, and the system prompt now instructs the model to place only the number where the linked title should appear

## [1.4.0] - 2026-07-17

### Added
- Assistant answers now render the `[n]` citation markers as inline links straight to the referenced page, and basic `**bold**` formatting, instead of showing raw markers as plain text (rendered XSS-safe: text is escaped and only citation links from the trusted sources list are injected)

### Changed
- "Matching pages" are now only shown when contextually relevant: in LLM mode only the pages the answer actually cited are listed, and the block is omitted entirely when nothing was cited (search-only mode still lists all suggestions)
- Source list is now compact — bullet-free, smaller text, no snippet cards — so a few hits no longer fill the whole chat panel
- Refined the assistant system prompt to link relevant pages inline via `[n]` and to cite only genuinely matching sources

## [1.3.1] - 2026-07-17

### Fixed
- Assistant widget icons rendered broken after the isolation layer was introduced: SVG icons (and their presentation attributes for size and fill) are now excluded from the `all: revert` reset
- Assistant widget text was too large because the base stylesheet (loaded last) reverted the container typography; the reset no longer touches the `#wn-ai-assistant` container, and all widget text is now set to a consistent `1rem`

## [1.3.0] - 2026-07-17

### Added
- `UrlGeneratorService::generateUrlForPageId()` now accepts optional route arguments, so callers can generate deep links to a specific record/detail view instead of only the hosting page

### Fixed
- Indexed Search results now link directly to the matched record (e.g. a news detail view) using the route parameters indexed_search stored (`static_page_arguments`, `data_page_mp`, `data_page_type`), instead of pointing at the overview/list page that hosts the plugin. Results are now de-duplicated by resolved URL, so several records on the same page are no longer collapsed into one

## [1.2.5] - 2026-07-17

### Fixed
- `bg-info` badges in the AI Assistant Log module now use white text as well (the backend theme renders bg-info as a saturated teal, on which black text was hard to read)

## [1.2.4] - 2026-07-17

### Fixed
- Badge labels in the AI Assistant Log backend module were hard to read (dark text on coloured backgrounds); text colour is now pinned per badge variant for guaranteed contrast in both the light and dark backend themes

## [1.2.3] - 2026-07-17

### Changed
- Base/isolation stylesheet is now emitted as the last element of the page body (footerData, after the widget) instead of into the `<head>`, so it is the last stylesheet in the document and reliably wins the cascade against host CSS on equal specificity

## [1.2.2] - 2026-07-17

### Added
- Always-loaded frontend base/isolation stylesheet (`Resources/Public/Css/assistant-base.css`), included via `page.includeCSS` on every page

### Fixed
- Chat toggle button no longer rendered as an oval when the host theme injects rules like `button { min-width: 100px }`; the widget subtree is now isolated with `all: revert` (scoped to `#wn-ai-assistant`) and the button geometry is clamped, so foreign CSS can no longer bleed in

## [1.2.1] - 2026-07-17

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
- Per-site configuration (Site tab): enable toggle, title, welcome message, input placeholder, auto-open toggle with configurable delay, additional system prompt, OnePager mode and an optional search root page
- Full per-site colour theming ("AI Assistant Colors" site tab): twelve optional HEX colours — accent, background, text, and background/text/link each for user messages, assistant messages and sources; unset colours keep the stylesheet defaults (including dark-mode)
- Per-site avatar (logo/photo, `aiAssistantAvatar`): shown as a round element in the widget header and next to assistant answers; accepts a URL, an EXT: path or a public-root-relative path
- Per-site custom CSS file (`aiAssistantCustomCss`): loaded after the widget's default stylesheet so site-specific overrides win; accepts a URL, an EXT: path or a public-root-relative path
- OnePager support for result links: on sites flagged as OnePager, hits on direct children of the site root link to a homepage anchor (e.g. `/#customers`) instead of a separate sub-page URL
- Accessible, dependency-free frontend widget (keyboard/focus handling, ARIA roles, dark mode, mobile full-screen, reduced-motion support)
- Bot protection for the assistant endpoint (`assistantBotProtection`, on by default): blocks crawlers/scripts via a widget proof header, bot User-Agent detection and a same-origin check, returning `403` with a "no bots allowed" message
- Optional interaction logging (`assistantLogging`, off by default): persists every question/answer with date, IP, user agent, provider, model and token usage to `tx_wnaibridge_assistant_log`
- Backend module "AI Assistant Log": a filterable list (by date, IP, provider, mode, free text) with a statistics overview (interactions, LLM vs. search split, token totals and a per-provider breakdown) and a clear-log action
- Conversation threading: each chat session gets a conversation id; the log list is grouped into threads (one collapsible row per conversation showing the first question), which expand to reveal all follow-up questions and answers with per-turn and total token usage
- Visitor information in the collapsed thread row: IP address, reverse-DNS hostname (cached) and — opt-in via `assistantLogGeoLookup` — the country resolved via an external, cached geolocation lookup; private/reserved IPs are flagged as local
- Polished backend module styling: collapsible thread rows are now a single, vertically centered line with a custom chevron
- Estimated LLM cost in CHF: shown in the overview (total), per thread and per turn, derived from token usage and per-model pricing with a configurable USD-to-CHF rate (`assistantUsdToChfRate`)
- Token usage is captured from the LLM response (`LlmResult`) for cost tracking
- Unit tests for `SearchQuery`, `SearchService` (ranking/dedup), the `AssistantService` search-only fallback and token capture, and the `BotDetectionService`

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

[Unreleased]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.11.1...HEAD
[1.11.1]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.11.0...v1.11.1
[1.11.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.10.2...v1.11.0
[1.10.2]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.10.1...v1.10.2
[1.10.1]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.10.0...v1.10.1
[1.10.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.9.1...v1.10.0
[1.9.1]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.9.0...v1.9.1
[1.9.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.8.1...v1.9.0
[1.8.1]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.8.0...v1.8.1
[1.8.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.6.3...v1.7.0
[1.6.3]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.6.2...v1.6.3
[1.6.2]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.6.1...v1.6.2
[1.6.1]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.4.3...v1.5.0
[1.4.3]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.2.5...v1.3.0
[1.2.5]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.2.4...v1.2.5
[1.2.4]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.2.3...v1.2.4
[1.2.3]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.2.2...v1.2.3
[1.2.2]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v0.2.0...v1.0.0
[0.2.0]: https://github.com/web-nomads/wn-ai-bridge/compare/v0.1.9...v0.2.0
[0.1.9]: https://github.com/web-nomads/wn-ai-bridge/releases/tag/v0.1.9