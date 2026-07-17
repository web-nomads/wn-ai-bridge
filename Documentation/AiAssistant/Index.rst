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

**Accent color:** the button, header and links use a per-site accent color
(``aiAssistantAccentColor``, hex) so the widget can match your design and stay
visible on any background.

**Auto-open:** the overlay can open automatically after a configurable delay
(``aiAssistantAutoOpen`` + ``aiAssistantAutoOpenDelay`` in seconds). Once a
visitor closes the overlay it will not reopen automatically during the same
browser session (tracked via ``sessionStorage``).

Privacy note
============

In search-only mode no data leaves your server. In hybrid mode the visitor's
question and the retrieved page excerpts are sent to the configured LLM
provider. Inform your visitors accordingly and choose the provider/model with
your data-protection requirements in mind.
