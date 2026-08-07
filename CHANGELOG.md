# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.24.0] - 2026-08-07

### Changed
- **English is now the source language throughout.** The extension is published
  internationally, but its settings screen, its status messages and the prompt
  sent to the model were German, with no way for anyone else to change that.
- The 24 extension configuration labels moved out of `ext_conf_template.txt`
  into `locallang.xlf` and are referenced with `LLL:` — the same way the TYPO3
  core does it. A new `de.locallang.xlf` carries the German wording, so a German
  backend looks exactly as before while the source is English
- Status messages (`SubscriptionStatus`), the output of
  `ai-bridge:check-subscription`, and the fallback labels of the chat widget are
  English. The widget fallbacks were dead defaults anyway — every key already
  exists in the XLF files, German included
- Dates in status messages and the CLI use `Y-m-d` instead of `d.m.Y`

### Note
- **The system prompt of the assistant is now English**, including the labels of
  the retrieved passages handed to the model. This is a behavioural change, not
  a translation of comments: the prompt still instructs the model to answer in
  the language of the question, so German questions should still get German
  answers — but it is worth a look at the first few answers after updating
- Two labels also stopped naming modules that were renamed four versions ago
- The bilingual DE/EN stop word list in `SearchQuery` stays as it is. Those are
  functional search data, not text

## [1.23.0] - 2026-08-07

### Added
- A complete **Installation** chapter in the documentation, from the Composer
  command to a working assistant: requirements, route enhancers, llms.txt per
  site, subscription key with a table of what each rejection reason means,
  the assistant's two switches, connecting Claude (getting a key, choosing a
  model, the settings that matter and why the temperature stays low), protecting
  the endpoint before going live, logging and cost tracking, a verification
  checklist and a troubleshooting table

### Fixed
- The documentation still named the backend modules "AI Assistant Log" and
  "Corrections"; they are **Enquiries** and **Answers** since 1.20
- The Administrator chapter claimed the subscription key is validated locally
  "with no call to any server" — the daily status check has existed for several
  versions and is described two sections further down. Corrected, with a
  cross-reference
- Three section underlines were shorter than their titles, which makes the
  renderer emit warnings and can silently drop a heading

### Note
- The Installation chapter states `claude-haiku-4-5` as the recommended model.
  The work here is summarising a handful of already-retrieved pages; a larger
  model mostly buys latency the visitor waits through

## [1.22.2] - 2026-08-07

### Changed
- README rewritten as the manual the repository page needs. It still described
  the backend modules as "Corrections" and "AI Assistant Log", renamed two
  versions ago to **Answers** and **Enquiries**; **Bot Access Log** was missing
  entirely. Also corrected: the TYPO3 requirement said 13.0 where the constraint
  is 13.4, the badge claimed v13 only, and the link to the subscription server
  was a relative path that resolves to nothing outside a local checkout
- Added what the licence check sends, which endpoints work without a key, the
  `composer` commands, and a link to the rendered documentation

## [1.22.1] - 2026-08-07

### Added
- `Build/Scripts/release.sh` (also `composer release`) builds the TER archive.
  It exists because the obvious `zip -r ../ext.zip *` also packs whatever the
  local installation left behind — a first attempt here produced a 456 KB
  archive holding two compiled DI containers and three debug page renderings of
  the developer's own site. The script excludes those and then *checks* the
  result: `ext_emconf.php` at the archive root, the version in `ext_emconf.php`
  matching `composer.json`, no development or generated files, required files
  present. Both guards were verified by breaking them on purpose — a version
  drift and a removed exclusion each fail the build and name what is wrong

## [1.22.0] - 2026-08-07

### Added
- Attribution to **web-vision**, the author of
  [web-vision/ai-llms-txt](https://github.com/web-vision/ai-llms-txt), from which
  this extension is derived — in `composer.json` under `authors` and as a
  "Credits" section in the README. Required by GPL-2.0 and previously missing
- Documentation of what the licence check sends to the issuing server: the daily
  status check (subscription id, hostname, nonce — no visitor data), the reports
  of suspected manipulation, and how to switch it all off by leaving
  `subscriptionKey` empty
- `phpstan.neon` (level 6) with a baseline, and `.php-cs-fixer.dist.php` using
  the official TYPO3 rule set
- `.gitattributes` so `Tests/`, `var/`, `composer.lock` and the tooling
  configuration stay out of the distribution archive

### Fixed
- `LICENSE` held only the first paragraphs of the GPL-2.0 preamble followed by a
  link. GPL-2.0 §1 requires the full text to be shipped, which it now is
- Every `composer` script pointed at `Build/Scripts/runTests.sh`, which does not
  exist in this repository — `composer test`, `composer stan` and `composer
  ci:test` all failed for anyone who installed the extension. The scripts now
  call the tools directly, and the ones that promised a functional test suite or
  documentation rendering are gone, because neither exists here
- Coding standards applied to 13 files
- The TYPO3 constraint in `ext_emconf.php` capped at 14.3.99 while
  `composer.json` allowed `^14.1`; both now describe the same range

### Note
- The PHPStan baseline holds 32 pre-existing findings — 31 missing array value
  types and one redundant ternary. They are parked, not fixed, so that anything
  new stands out

## [1.21.0] - 2026-08-07

### Changed
- The backend module "AI Assistant Log" is now called "Enquiries" ("Anfragen"), matching what it actually lists. Its route identifier changed from `wn_ai_bridge_log` to `wn_ai_bridge_enquiries`; the old identifier is kept as an alias, so existing backend group permissions and bookmarked links keep working
- "Enquiries" now shows the subscription status above the module title, the same way "Answers" and "Bot access" do

## [1.20.1] - 2026-08-07

### Changed
- The subscription state now sits at the very top of the "Answers" module body, above the module title and separated from it, instead of below the title where it was easy to miss
- The "Bot Access Log" module shows it too. That module is not part of the subscription and keeps working without one, so the state is informational there — but a lapsed licence is worth knowing wherever one happens to be looking
- Both render the same partial, so the two cannot drift apart. An invalid subscription is shown as a warning with its reason rather than being hidden, which is what makes it useful in the module that is not gated

## [1.20.0] - 2026-08-07

### Added
- The backend modules "AI Assistant Log", "Answers" and "Bot Access Log" are now translated into German, French, Italian, Portuguese and Spanish. English remains the source language, so any label without a translation still falls back to it

### Fixed
- The status option "Pending review" in the Answers form carried the long heading of the corrections list, left over from renaming the module
- The subscription feature list named the "Corrections" module, which no longer exists under that name

### Removed
- `locallang_mod.xlf`, the last remnant of the file-based llms.txt module that was removed earlier — nothing referenced it

## [1.19.0] - 2026-08-07

### Added
- Licence findings that cannot be an honest state are reported to the issuing server: a key whose signature does not verify, a key used on a domain it was not issued for, verification against a key pair that is not the bundled one, and altered bundled keys. The issuing server notifies the extension's author
- The bundled verification and cipher keys carry a fingerprint, so an edit to them is noticed

### Changed
- What is sent is deliberately minimal: the subscription id from the key, the host, the finding and the two version numbers that make it actionable. Nothing about the site, its content or its visitors. The same finding is sent at most once a day, never from a visitor request, and a failure to send is silent
- A missing, expired, malformed or revoked key is **not** reported. Those are the everyday states of an installation whose customer has not renewed yet, and reporting them would accuse honest people and bury the real findings

### Security
- This detection is not tamper-proof and does not pretend to be: anyone willing to edit the extension can remove it. The detection that matters runs on the issuing server, on the status check every installation performs itself — see the `wn_ai_bridgeserver` changelog

## [1.18.0] - 2026-08-07

### Changed
- The "Corrections" backend module is now called **"Answers"**. It never was only about corrections: it holds the answers the assistant gives for questions it recognises, whoever wrote them. The module identifier changed to `wn_ai_bridge_answers` and keeps the old one as an alias, so existing backend group permissions and bookmarks keep working

### Added
- Every answer in the "AI Assistant Log" module now carries a "Define a different answer" button. It opens the Answers module with the logged question filled in and the given answer shown as what is being replaced, so a bad answer can be corrected where it was noticed. Only the log id travels in the link — question and answer are read back from the log, so nothing is cut off by URL length limits

### Fixed
- A pre-existing null-coalesce on a source URL that can never be null, reported by static analysis

## [1.17.0] - 2026-08-07

### Fixed
- **A renewed subscription switched itself off.** Expiry was decided solely by the date inside the key, and that key does not change when the issuing server renews the term — so the chat widget and both backend modules went dark on the original date although the customer had paid, unless someone manually pasted the new key from the renewal e-mail. The signed end date from the daily status check is now authoritative when it is available, so a renewal reaches the installation on its own. It cuts both ways: a subscription that lapsed on the server is reported with a date in the past and switches off even if its key would still be good

### Changed
- The status check now runs before the expiry decision instead of after it, and the domain is checked first so a key belonging to someone else never causes a request to the issuing server
- While a key is within 30 days of its end date, the status check may also run from a frontend request. Outside that window the frontend still never makes an outgoing request. Together with the 24-hour cache this is at most one call per day, and it is what keeps a renewal working on a site with no scheduler and no backend use
- `ai-bridge:check-subscription` reports the date in the key and the authoritative one separately, so it is visible whether the server confirmed a renewal

### Security
- An unreachable or unverifiable server can still only take access away, never extend a subscription: without a verified answer the date inside the key stands

### Fixed
- The bundled public verification key belonged to a placeholder key pair, so every key issued by the real server was rejected as "signature invalid" — which switched off the chat widget and removed both backend modules. It now holds the public key of the WebNomads issuing server

### Added
- A unit test pins the bundled verification and cipher keys to a well-formed 64-character hex value, so a truncated or mistyped constant fails in the test suite instead of rejecting every key in the field

## [1.16.0] - 2026-08-07

### Added
- Daily online check: the subscription is verified with the issuing server once every 24 hours, so a revoked subscription stops working without waiting for its expiry date. The server's answer is Ed25519-signed and echoes a client-generated nonce, so an older "still active" answer cannot be replayed after a revocation
- New CLI command `ai-bridge:check-subscription` reports the subscription state and refreshes it; schedule it daily on installations nobody logs into
- New settings "Daily status check" (on by default) and "Issuing server" (optional override of the address baked into the key)

### Changed
- The check never runs inside a visitor request: it is performed in the backend and on the command line, and the frontend only reads the cached verdict, so a slow or unreachable licence server can never delay a page

### Security
- Only an explicitly signed "revoked" disables anything. An unreachable server, a malformed answer, a bad signature or a stale timestamp all count as "unknown" and leave the offline check in the key authoritative — the online check can take access away, never grant it

## [1.15.0] - 2026-08-07

### Added
- Subscription key: the new "Subscription-Key" setting in the extension configuration holds an encrypted and Ed25519-signed licence key that carries the allowed domains, an expiry date and the enabled features. It is issued by the new companion extension `wn_ai_bridgeserver`. Without a valid key the chat bot stays hidden on the website and the backend modules "AI Assistant Log" and "Corrections" disappear from the module menu — llms.txt, the Markdown export and the bot access log are unaffected and keep working
- The validity check runs against the current host, so a key only works on the domains it was issued for; wildcard patterns (`*.example.com`) are supported. Without a resolvable host (CLI, scheduler) the domain check is skipped so maintenance tasks keep running
- The "Corrections" module is now a full editor for the assistant's local learning source: entries can be edited, deactivated and deleted, and editors can create their own question/answer pairs from scratch
- Optional "Verification key" setting to verify keys against a rolled-over key pair without updating the extension

### Changed
- Approved answers are now matched against a new question by meaning rather than by wording: term overlap (prefix-tolerant, so "Versand" also matches "Versandkosten") combined with overall string similarity. A close match is played back verbatim as the answer (new log mode "learning"); weaker matches are still handed to the LLM as binding hints
- The learning lookup runs before the "nothing found" fallback, so a stored answer is given even when the site search returns no hits
- Corrections are only captured and used while the subscription covers them

### Security
- Subscription keys are verified with a bundled Ed25519 public key; the private signing key never leaves the issuing server, so a key cannot be forged by editing or re-encrypting it

## [1.14.1] - 2026-07-27

### Security
- The public `.md` endpoint no longer exposes internal exception messages on a rendering error; a generic message is returned instead, while the full detail is still shown when `debug` is enabled
- The page anchor extracted from a OnePager URL is now restricted to id/slug characters before it is used in an XPath query, preventing malformed-slug query errors
- Verbose LLM provider error bodies are truncated before they are written to the log when the assistant falls back to search-only

### Changed
- Internal refactoring of the llms.txt generation, HTML cleanup and Markdown export command for clearer structure; output and behaviour are unchanged (verified by the unit test suite)

### Removed
- Removed unused backend templates and layout of the former file-based llms.txt module (never rendered by the current modules)

### Fixed
- Corrected the Composer package name and route enhancer import path in the administrator documentation

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
- The assistant answer no longer contains inline links. The `[n]` citation markers are stripped from the answer text and only used to list the relevant pages below the answer under the heading "Related links" (renamed from "You might also be interested in"). The system prompt now instructs the model to write self-contained sentences without link phrases and to put the numbers only at the end of a sentence/answer

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
- Renamed the suggestions heading from "Matching pages" to "You might also be interested in"
- The suggestions block (heading included) is now reliably omitted whenever there are no linkable entries to show

## [1.4.1] - 2026-07-17

### Changed
- Inline page citations now render the page title as the linked text (e.g. the page title rendered as the link text) instead of linking the raw "[1]" marker. A title the model wrote in bold right before the marker is de-duplicated, and the system prompt now instructs the model to place only the number where the linked title should appear

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
- Initial release of the AI Bridge extension
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