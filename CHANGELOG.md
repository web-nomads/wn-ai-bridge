# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.29.0] - 2026-09-04

### Fixed
- **The assistant did not work on a TYPO3 serving two websites, even with both
  domains on the licence.** The domains a subscription covers were frozen inside
  the key at the moment it was issued. Adding the second one to the licence
  changed nothing here — the site kept being told the key was for another domain,
  and only a re-issued key pasted into the extension configuration would have
  helped. The domain list is now published with the daily status check, so a
  domain added to a licence takes effect within a day and no new key is needed
- The list is signed separately, over a canonical string of its own. The
  signature the answer always carried is untouched, so an installation still
  running 1.28 or earlier verifies the new answers exactly as before — and this
  version verifies the answers of a server that publishes no domains at all,
  where the key stays the only word on it
- A list that does not verify is discarded rather than trusted, and an
  unreachable server leaves the key's own list standing. A licence can therefore
  only ever be extended by a signed answer, never by silence or by a manipulated
  one
- **The assistant answered with pages of the other website.** `ke_search` and
  `indexed_search` index the whole installation into one table and know nothing
  about sites, and the dependency-free `pages`/`tt_content` fallback searched the
  entire page tree whenever no search root was configured — which was the
  default. Every hit is now checked against the site the question was asked on.
  The check is made once, where the results are merged, so a search provider
  added later cannot forget it
- On an installation with a single site nothing is filtered and no site is even
  looked up: there is no second site a hit could come from. Where there are
  several, the providers are asked for more hits than are shown, so a busy
  neighbouring site cannot crowd out the answers
- "Search Root Page" left empty now means this site's root page instead of
  "no restriction". The subtree walk behind it was widened accordingly, so a deep
  or large page tree is not cut short

### Changed
- **The LLM API key, the temperature and the agent instructions moved to the site
  configuration.** All three were installation-wide, which was wrong in the one
  place it matters: a TYPO3 serving several websites billed them all to one API
  key and had them all speak with one voice. They are now on the **AI Assistant**
  tab of each site. The temperature is a dropdown from 0.0 to 1.0 rather than a
  free text field that nothing validated, and the instructions are a proper
  textarea
- Run the upgrade wizard *"AI Bridge: move the assistant's API key, temperature
  and instructions into the site configuration"* (Admin Tools → Upgrade). It
  copies the current values into every site that does not set them itself and
  then removes them from the extension configuration. A site that has already
  been given a value of its own keeps it, and the extension configuration is only
  cleared once every site has been written — a failure halfway through leaves the
  installation exactly as it was. **Until the wizard has run, the old values keep
  being used**, so nothing stops working at the moment of updating
- **`assistantUsdToChfRate` is now `assistantUsdConversionRate`, and the currency
  is a setting of its own.** The rate was named after one country's money and the
  log module printed "CHF" whatever it had been set to. Run the upgrade wizard
  *"AI Bridge: rename the USD conversion rate and record the currency it converts
  to"*, which carries the rate over and sets the currency to CHF — what the
  figures said before. Correct it afterwards if that is not what you bill in. The
  former setting is still read until the wizard has run

### Added
- **The "Enquiries" and "Answers" modules can be filtered by site.** On an
  installation serving several websites both were one list of everything, and the
  question "what are people asking on this site" could not be answered from it.
  The filter only appears where there is more than one site, and the chosen site
  survives pagination, the AJAX reload and — in "Answers" — approving or deleting
  an entry
- The site an enquiry was asked on is shown on its thread, again only where there
  is more than one

## [1.28.1] - 2026-09-02

### Fixed
- **`llms-full.txt` answered with a 404 on sites that had copied the route
  enhancer mapping.** `Configuration/Routes/RouterEnhancer.yaml` is meant to be
  imported by reference, and a site that does so picked up the new endpoint of
  1.27.0 by itself. Copying its content into `config.yaml` is just as common,
  and that copy is a snapshot: it maps `llms.txt` and `.md`, knows nothing of
  `llms-full.txt`, and the request never reaches the page type — with nothing in
  the backend to suggest why
- A suffix the extension serves but the site does not map is now added to the
  site's `PageType` enhancer while the configuration is read. Suffixes the site
  already maps are left exactly as they are, whatever they point at, and a site
  without a `PageType` enhancer is left alone entirely — introducing one changes
  how every URL on that site is built. Flush the caches after updating: the
  completed configuration is what gets cached

## [1.28.0] - 2026-09-02

### Added
- **The Bot Access Log counts `llms-full.txt` separately.** Accesses to the full
  document were recorded under the same type as `llms.txt`, so the module could
  not say how often it was actually fetched — which is the number that matters
  for a document that renders every page of the site in one request. It has its
  own tile, its own filter option and its own badge in the list now
- The tile and the filter option are shown while `llmsFullTxt` is switched on: a
  site that does not serve the document has nothing to count. Accesses are
  recorded either way, so switching it on later does not start from zero

### Note
- Accesses recorded before this version stay filed under `llms.txt`; the path
  column tells them apart. Only `llms-full.txt` was affected, and it has been
  available since 1.27.0

## [1.27.0] - 2026-08-31

### Added
- **An `llms-full.txt` can be served alongside `llms.txt`.** Where `llms.txt` is
  a list of links, the full document carries the readable content of every page
  of a site in one file — the form a model can consume without following a
  single link. It is served at `/llms-full.txt` and
  `/.well-known/llms-full.txt` (page type 1702) once the route enhancer is
  imported
- The new extension configuration option `llmsFullTxt` switches it on. **Off by
  default**: one request renders every page of the site at once, which is
  expensive on a large site, so it is a deliberate decision rather than
  something that starts happening after an update
- The document follows the layout a full document is validated against: one H1
  with the site title, a blockquote summary, then one section per page. A page's
  own headings are nested directly below its section heading, so the outline
  never skips a level and the document keeps exactly one H1
- Each section carries the page description as a blockquote and the URL it was
  taken from. Page depth is the one already configured per site for `llms.txt`;
  at most 500 pages go into one document, and a document cut short says so
- While the option is on, `llms.txt` points at the full document in an
  `## Optional` section, so it is discoverable from the file crawlers ask for
  first
- The rate limiter and the bot access log treat the new endpoint like the
  existing ones

### Fixed
- **The search assistant offered pages that are switched off.** A search index
  is a snapshot and outlives the page it describes: ke_search and
  indexed_search keep a row for a page long after it was disabled, and both
  were queried with the frontend's restrictions removed. Disabled pages, pages
  whose publish date has not arrived and pages whose expiry has passed are now
  dropped from the results — together with their title and the text passage
  that would otherwise have been quoted back
- **Pages behind a login were offered to visitors who cannot open them.** A
  page restricted to a frontend group now appears only in the results of a
  visitor who is logged in and holds that group, and a restriction a parent
  extends to its subpages counts as well. The check is made once, in
  `SearchService`, so a search backend added later cannot forget it. For this
  the assistant endpoint now runs after the frontend authentication, which is
  what makes the visitor's groups known at all
- **`llms.txt` listed pages that are switched off.** The page tree walk relied
  on TYPO3's frontend restrictions, which depend on the context a request
  happens to carry. Disabled pages, pages outside their publication window and
  pages behind a frontend group are now left out explicitly, together with
  everything below them — a page a visitor cannot reach has no place in a
  public document. This also applies to `llms-full.txt` and to
  `ai-bridge:download-markdown`
- The verdict is deliberately the anonymous one for those documents, so an
  editor fetching `/llms.txt` while logged in cannot write protected pages into
  the page cache for everyone

### Changed
- Page rendering moved out of `LlmsTxtController` into a `PageContentRenderer`,
  which both the `.md` endpoint and the full document use. The `.md` output is
  unchanged
- `SearchService` takes the new `PageAccessService` as a third constructor
  argument. Relevant only for code constructing it by hand

## [1.26.2] - 2026-08-25

### Fixed
- **A detail view asked for as Markdown answered with the list view.** Appending
  `.md` to the URL of a plugin's detail page — an Extbase record, a news article,
  a project — returned the Markdown of the page that hosts the plugin instead of
  the record. The source URL was rebuilt from the page id, which is the same for
  every detail view on that page, so the route arguments that pick the record
  were dropped before the HTML was fetched. The requested URL is now used as it
  came in, with only the `.md` suffix taken off
- **The "Web version" link at the end of such a page pointed at the list view**
  for the same reason, and now points back at the record it came from
- **With `cacheMarkdown` enabled, every detail view on a page shared one cache
  entry.** The identifier was built from the page id and the language alone, so
  whichever record was rendered first was served for all of them. Detail views
  now get an identifier of their own; a plain page keeps the one it had
- A OnePager section still resolves through its page, since its HTML lives behind
  an anchor on the home page and it has no URL of its own to request

## [1.26.1] - 2026-08-25

### Fixed
- **The backend was unreachable on TYPO3 13.4.** Every request ended in
  `Interface "TYPO3\CMS\Backend\Module\ModuleAccessGateInterface" not found`,
  including the login screen — the module guard read a constant off the access
  gate that hides the subscription-only modules, and loading that class means
  loading the v14-only interface it implements. The identifier now lives on the
  guard, and the gate is kept out of the service container on v13, where
  reflecting on it would have failed just the same
- **The subscription-only modules are hidden on TYPO3 13.4 again.** Module access
  gates arrived with v14; 13.4 decides module access from the plain `access`
  string, and a value it does not know still lets every admin through. On v13
  the guard therefore drops "Enquiries" and "Answers" from the module menu
  instead. Their routes stay reachable through a bookmark or the
  live search, where the modules answer with the "subscription required" screen
  they always had — on v14 the gate keeps blocking both at once

## [1.26.0] - 2026-08-08

### Added
- **A trial key is shown as a trial.** Keys issued through the trial form of the
  AI Bridge Server carry a marker inside their signed payload, and the
  subscription state now reads it: the modules say "Trial subscription active …
  it does not renew; order a subscription to keep the AI Bridge running
  afterwards", and `ai-bridge:check-subscription` reports the type alongside the
  subscription number. An expired trial points at ordering rather than at
  renewing — there is nothing to renew
- The marker is read from the key itself rather than from the daily status check,
  so it holds even while the issuing server cannot be reached. A key without it
  is an ordinary subscription, which is what every key issued before trials
  existed, and every paid one since, looks like

## [1.25.1] - 2026-08-08

### Fixed
- **Language pass over the documentation and the README.** Corrections that
  changed the meaning, not just the wording:
  - The Administrator chapter announced "two of the three tables ... and both are
    off by default" above a table listing **three** of them. All three hold
    personal data and all three are off by default
  - The Editor chapter called two backend modules "editorial work". A module is
    where the work happens, not the work itself
  - The README described `llms.txt` as a "policy file" that "states how the site
    would like to be used". It describes content, it does not restrict access —
    the shared idea with `robots.txt` is the well-known location, not the purpose
- **Two blocks in the README never rendered on GitHub.** The search-only and
  hybrid modes used Markdown definition lists, which GitHub does not support, so
  both paragraphs appeared with a stray leading colon
- Grammar and wording: "expecting to ask instead of browse", "renamed in version
  1.18.0 and 1.21.0" for two versions, "entries reach the module three ways",
  "left empty they are derived", "privacy friendly" unhyphenated, "which served
  nobody" in a sentence about the present, and a circular "visible where it is
  noticed"
- The example for prefix-tolerant matching used German words ("Versand" /
  "Versandkosten") in the English manual
- `licence` / `license` are used consistently again, British spelling in prose

## [1.25.0] - 2026-08-08

### Removed
- **The setting "Daily status check" (`subscriptionOnlineCheck`) is gone.** The
  check is now always on and cannot be disabled. It is what carries a renewal to
  the installation and what makes a revocation take effect, so an installation
  with it switched off silently stopped following its own subscription — the one
  state nobody wants and nothing reports

### Fixed
- **The daily check never ran in the backend at all.** The module guard listens
  on `BeforeModuleCreationEvent`, which fires while the module list is built —
  inside a middleware, before `Backend\Http\RequestHandler` puts the request into
  `$GLOBALS`. The check refuses to make an outgoing request without one, so it
  did nothing; and because the service caches its answer per request, that empty
  verdict was what every module then displayed. This is the deeper reason behind
  "the daily status check does not work": on an installation nobody used the CLI
  on, the check only ever ran from the CLI. The status is now resolved a second
  time once the request context allows it — once per request, not per call
- **The chat panel could not be closed.** `.wn-ai-panel` carries `display: flex`
  and nothing handled the `hidden` attribute, so the author style beat the
  browser's own `[hidden] { display: none }`. The close button, the floating
  toggle and Escape all set the attribute correctly and all appeared to do
  nothing
- **A wrong issuing server stayed invisible for a day.** The cached verdict was
  keyed by subscription id alone, so correcting the address in the extension
  configuration changed nothing until the previous day-old verdict expired. The
  address is part of the cache key now, and a change takes effect immediately
- **The daily check could be off while `ai-bridge:check-subscription` reported it
  working.** The automatic path (`verdict()`) honoured the setting, the CLI path
  (`refreshNow()`) never did. With the setting off, the command still printed
  "active — confirmed by the server" while no backend request ever asked it. That
  is exactly the symptom "the daily check does not work, but the command says it
  does", and it made the one diagnostic tool available useless for the fault it
  was meant to find. With the setting removed, both paths are the same code
- **Sites served from an entry point produced doubled URLs.** With a site base
  of `/camino/`, the router returns a path that already carries the entry point
  (`/camino/faqs`), and the site URL — which also carries it — was prefixed on
  top, yielding `https://example.com/camino/camino/faqs.md`. Every link in
  `llms.txt` and every internal link inside the Markdown export was broken.
  Router results are now made absolute with scheme and host only
- **Every site was treated as a OnePager.** The anchor form (`/#packing-list`)
  was applied to any direct child of the site root, without asking whether the
  site actually is a OnePager — that check existed only on the chat assistant's
  path. On a normal multipage site the Markdown export therefore linked to
  anchors that do not exist

### Added
- **A failing issuing server is now reported instead of passing silently.** The
  daily check distinguishes three failures — unreachable, an HTTP error, and an
  answer that cannot be verified (malformed, wrong subscription, replayed nonce,
  stale timestamp, bad signature) — and carries the reason through the cache.
  It is shown as an error next to the subscription state at the top of every AI
  Bridge backend module, with the address in bold so the typo is visible, and
  named by `ai-bridge:check-subscription`, which also prints the server it
  actually talked to. Nothing is switched off by it: the wording says so, because
  while it lasts neither a renewal nor a revocation can arrive. It clears only on
  an answer that verifies — signature, nonce, subscription id and timestamp — not
  on a bare HTTP 200. Two states deliberately stay silent: "nobody has asked yet",
  which is every fresh installation, and any key that is not valid in the first
  place, where the key itself is the message that matters
- **A default issuing server.** The address is resolved as configuration →
  address inside the key → the issuing server that ships with the extension.
  Previously a key carrying no address of its own, with `subscriptionServerUrl`
  left empty, left the check with no one to ask, so it silently did nothing. The
  same resolution now also applies to the tamper reports, which had the opposite
  precedence
- Site setting **`llmsTxtOnePager`** (AI Bridge tab, right below "Enable
  LLMS.TXT Generation"), off by default. Only with it enabled do direct children
  of the site root become anchors on the home page. While it has never been
  saved on a site, the assistant's `aiAssistantOnePager` is used instead, so
  existing OnePager installations keep their behaviour
- The "Web version" link under a Markdown page is translated into the language
  of the page — all 14 shipped languages, resolved per site language like the
  chat widget's texts. It was hardcoded German ("Webversion") on every site

### Changed
- **Backend badges meet WCAG 2.1 AA.** Only the text colour was pinned before,
  while the background still came from the backend theme, so the pair could drift
  out of contrast — black on green or red measured 3.2-4.2:1. Both halves are now
  fixed pairs: primary 5.84, secondary 6.09, success 6.45, danger 6.50, info
  5.06, warning 9.69, light 14.99, dark 15.43
- The documentation was rewritten for the renamed backend modules (Corrections →
  **Answers**, AI Assistant Log → **Enquiries**), which older chapters still used
  under their former names. Fixed along the way: a broken `toctree` in
  `Index.rst` whose entries were indented one space short of its options, a
  duplicated document title in the ChangeLog chapter, TypoScript examples that
  did not match the shipped setup, and a `maxDepth` workaround pointing at
  TypoScript where the setting is `llmsTxtMaxDepth` in the site configuration
- `Documentation/guides.xml` carries the real release instead of "main
  (development)", and the project title is the extension's name

### Added (tests)
- `SubscriptionOnlineCheckTest` (7): a cached verdict is reused instead of asking
  again, an unreachable server yields "unknown" rather than "revoked", an answer
  for another subscription is discarded, a key without an issuing server is never
  checked, the configured server URL overrides the one inside the key, and a
  stale `subscriptionOnlineCheck = 0` in `settings.php` suppresses nothing
- `EntryPointUrlTest` (7): entry point not repeated, external links untouched,
  existing extensions preserved, links outside the entry point get no `.md`
- `OnePagerSettingTest` (8): the switch, its fallback, and that the assistant
  keeps its own
- `WebVersionLabelTest` (16): every shipped language translates the label

### Note
- The stale `subscriptionOnlineCheck` entry stays in `config/system/settings.php`
  until the extension configuration is saved once. It is inert — nothing reads it
- A PHPStan baseline entry became obsolete because `generateHtmlUrl()` now has a
  typed `@param`, and was removed rather than regenerated
- `generateHtmlUrl()` gained an optional `?bool $onePager` argument; null keeps
  the previous behaviour of asking the site
- `SubscriptionToken::__construct` and `UrlGeneratorService::generateUrlForPageId()`
  each carried two docblocks, the first an outdated copy of the second

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