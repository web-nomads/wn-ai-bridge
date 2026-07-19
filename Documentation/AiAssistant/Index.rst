.. include:: /Includes.rst.txt

.. _ai-assistant:

===================
AI Search Assistant
===================

The AI search assistant is an on-site chat widget that helps visitors find
information on your website. Visitors ask a question in natural language and the
assistant answers with concrete suggestions and links to the matching pages.

It works in two modes:

*   **Search-only** (default, no API key): the assistant returns the best
    matching pages as ranked suggestions with links. Fast, free and privacy
    friendly.
*   **Hybrid / RAG** (with an LLM API key): the assistant additionally has an
    LLM compose a short, grounded answer that cites the source pages
    (retrieval-augmented generation). If the LLM is unavailable, it transparently
    falls back to search-only.

How it works
============

1.  The visitor's question hits the JSON endpoint
    ``POST …/wn-ai-bridge/ask`` (a PSR-15 middleware — no page or plugin needed).
2.  The :php:`SearchService` queries every available search backend and merges
    the results by rank, de-duplicating URLs.
3.  If an LLM is configured, the retrieved passages plus the question are sent to
    the model, which returns a cited answer. Otherwise the ranked hits are
    returned directly.

Search backends
===============

The assistant aggregates the following backends and degrades gracefully — a
backend that is not installed is simply skipped:

*   ``ke_search`` — fulltext search on the ``tx_kesearch_index`` table.
*   ``indexed_search`` — the TYPO3 core index (``index_phash`` /
    ``index_fulltext``).
*   ``pages`` — a dependency-free fallback that searches the ``pages`` and
    ``tt_content`` tables directly, so the assistant works even without any
    search index built yet.

Third-party extensions can add their own backend by implementing
:php:`WebNomads\WnAiBridge\Search\SearchProviderInterface` and tagging the
service with ``wn_ai_bridge.search_provider``.

Global configuration (Extension Configuration)
==============================================

.. confval:: assistantEnabled

    Master switch for the assistant. Off by default.

.. confval:: assistantProvider

    LLM provider. Currently ``anthropic``.

.. confval:: assistantApiKey

    The provider API key. Leave empty for search-only mode.

.. confval:: assistantModel

    Model id, e.g. ``claude-haiku-4-5`` (fast/cheap) or ``claude-opus-4-8``
    (highest quality).

.. confval:: assistantSearchSources

    Which backends to use: ``auto`` (all available), ``kesearch``, ``indexed``
    or ``pages``.

.. confval:: assistantMaxResults

    Number of search hits used as context / shown as suggestions.

.. confval:: assistantMaxTokens

    Maximum length of the generated answer in tokens.

.. confval:: assistantTemperature

    Sampling temperature as a decimal between ``0.0`` (deterministic/precise)
    and ``1.0`` (more creative). Default ``0.2``; invalid values fall back to
    ``0.2`` and out-of-range values are clamped.

.. confval:: assistantInstructions

    Global agent instructions (persona, tone, rules) added to the system prompt
    on every answer. Applies to all sites and combines with the per-site
    instructions (``aiAssistantSystemPrompt``).

.. confval:: assistantBotProtection

    Blocks non-human requests to the assistant endpoint (crawlers, scripts,
    known bots). On by default. Detection combines a proof header that only the
    widget sends, bot User-Agent markers and a same-origin check; blocked
    requests receive an HTTP ``403`` with a "no bots allowed" message.

Per-site configuration (Site tab "AI Search Assistant")
=======================================================

Each site has its own settings: enable/disable the widget, the window title,
the welcome message, the input placeholder, additional system-prompt
instructions (persona/tone, only used with an LLM) and an optional search root
page to restrict the search to a subtree.

**OnePager mode:** enable ``aiAssistantOnePager`` for sites whose sub-pages are
rendered as sections on the homepage. Result links to direct children of the
site root then use homepage anchors (e.g. ``/#customers``) instead of separate
sub-page URLs. Leave it off for normal multipage sites so real page URLs are
produced.

**Colors:** the widget is fully themeable per site via the *AI Assistant Colors*
tab. All twelve colours are optional HEX values — accent, background and text,
plus background/text/link for the visitor messages, the assistant messages and
the sources list. Colours you leave empty keep the built-in defaults (including
automatic dark-mode).

**Avatar (logo/photo):** set ``aiAssistantAvatar`` to a logo or photo and it is
shown as a round avatar in the widget header and next to each assistant answer
(the floating button always keeps the chat icon). The value can be a URL
(``https://…``), an extension path
(``EXT:my_ext/Resources/Public/Images/bot.png``) or a path relative to the public
root (``fileadmin/logo.png``). Since site configuration is file-based, this is a
path/URL field rather than a FAL file picker. The image is cropped to a circle
(``object-fit: cover``), so any aspect ratio looks tidy.

**Custom CSS:** for full control beyond the colour settings, set
``aiAssistantCustomCss`` to your own stylesheet (URL, ``EXT:`` path or a path
relative to the public root). It is loaded *after* the widget's default styles,
so your rules take precedence. Scope your selectors under ``#wn-ai-assistant``
(e.g. ``#wn-ai-assistant .wn-ai-panel { … }``) and, where useful, override the
CSS custom properties such as ``--wn-ai-accent`` or ``--wn-ai-radius``.

**Auto-open:** the overlay can open automatically after a configurable delay
(``aiAssistantAutoOpen`` + ``aiAssistantAutoOpenDelay`` in seconds). Once a
visitor closes the overlay it will not reopen automatically during the same
browser session (tracked via ``sessionStorage``).

Logging & backend module
=========================

Enable ``assistantLogging`` in the extension configuration to persist every
question and answer. Each entry stores the date, client IP, user agent, mode
(``llm``/``search``), provider, model and token usage in the
``tx_wnaibridge_assistant_log`` table (run the database schema update after
enabling).

The **AI Assistant Log** backend module (module group "AI Bridge") shows the
entries in a filterable list — by date range, IP address, provider, mode and
free text over question/answer — together with a statistics overview:
interactions, the LLM-vs-search split, total input/output/total tokens, the
estimated total cost and a per-provider breakdown. A "Clear log" action removes
all entries.

**Estimated cost:** the module estimates the LLM cost in CHF from the recorded
token usage and per-model pricing, shown as a total in the overview, per thread
and per turn. Model prices are quoted in USD and converted with the configurable
``assistantUsdToChfRate``. These are rough estimates (prices change over time)
for budgeting only.

Each chat session gets a conversation id (client-generated, stored per browser
session), so all turns of one conversation are grouped. The list shows **one
collapsible row per thread** (with the first question, date and visitor info);
expanding it reveals all follow-up questions and answers with their
provider/model and per-turn plus total token usage.

The collapsed row shows the visitor's IP address and reverse-DNS hostname
(cached). When ``assistantLogGeoLookup`` is enabled, the country is additionally
resolved via an external geolocation service (``ip-api.com``) and cached; this
runs only in the backend when viewing the log, never during a visitor request.
Private/reserved IPs (e.g. local testing) are flagged accordingly and are never
sent to the geolocation service.

.. warning::

   The geolocation lookup sends the visitor IP to an external third-party
   service. It is therefore off by default — enable it only if this is
   compatible with your data-protection requirements.

.. warning::

   The log stores IP addresses and the full question/answer text. Make sure this
   complies with your data-protection obligations (e.g. GDPR): inform users,
   define a retention period and clear the log regularly.

Privacy note
============

In search-only mode no data leaves your server. In hybrid mode the visitor's
question and the retrieved page excerpts are sent to the configured LLM
provider. Inform your visitors accordingly and choose the provider/model with
your data-protection requirements in mind.
