..  include:: /Includes.rst.txt

..  _ai-assistant:

===================
AI Search Assistant
===================

The AI search assistant is an on-site chat widget that helps visitors find
information on the website. They ask a question in natural language and the
assistant answers with concrete suggestions and links to the matching pages.

..  contents::
    :local:
    :depth: 2

..  _assistant-modes:

The two modes
=============

Search-only (default, no API key)
    The assistant returns the best matching pages as ranked suggestions with
    links. Fast, free and privacy-friendly — no data leaves the server.

Hybrid / RAG (with an LLM API key)
    A language model additionally composes a short, grounded answer that cites
    the source pages (retrieval-augmented generation). If the model is
    unavailable, the assistant transparently falls back to search-only.

..  note::

    The fallback is silent by design: the visitor gets suggestions instead of an
    error message. A missing API key, an account without credit or an unknown
    model id therefore shows up as "the assistant only ever lists pages", never
    as a visible failure. The incident is written to the TYPO3 log.

..  _assistant-how-it-works:

How it works
============

#.  The visitor's question hits the JSON endpoint ``POST …/wn-ai-bridge/ask``.
    This is a PSR-15 middleware — no page, plugin or content element is needed.
#.  :php:`SearchService` queries every available search backend, drops the hits
    the visitor may not open (see :ref:`assistant-visibility`) and merges what
    is left by rank, de-duplicating URLs.
#.  The curated answers are consulted. A close match by meaning is played back
    verbatim (log mode ``learning``); weaker matches are carried forward as
    binding hints. This lookup runs *before* the "nothing found" fallback, so a
    stored answer is given even when the site search returns no hits.
#.  If an LLM is configured, the retrieved passages plus the question are sent
    to the model, which returns a cited answer. Otherwise the ranked hits are
    returned directly.

..  _assistant-search-backends:

Search backends
===============

The assistant aggregates the following backends and degrades gracefully — a
backend that is not installed is simply skipped:

``ke_search``
    Fulltext search on the ``tx_kesearch_index`` table.

``indexed_search``
    The TYPO3 core index (``index_phash`` / ``index_fulltext``).

``pages``
    A dependency-free fallback that searches the ``pages`` and ``tt_content``
    tables directly, so the assistant works even before any search index has
    been built.

Which of them are used is controlled by :confval:`assistantSearchSources`.
Third-party extensions can add their own backend — see
:ref:`developer-search-provider`.

..  _assistant-visibility:

Which pages the assistant may show
==================================

A search index is a snapshot and outlives the page it describes: ``ke_search``
and ``indexed_search`` keep a record of a page long after it was disabled or put
behind a login, and both are queried without the frontend's own restrictions.
Every hit is therefore checked against the ``pages`` table before it reaches a
visitor — once, in :php:`SearchService`, so a search backend added later cannot
forget it.

A page is dropped when it is:

*   **disabled** (:guilabel:`Disable` on the page), or deleted,
*   **outside its publication window** — :guilabel:`Publish Date` still in the
    future or :guilabel:`Expiration Date` already passed,
*   **restricted to a frontend group** the visitor is not in.

Access is judged for the visitor who is asking, not for the site in general. A
page restricted to a frontend group therefore appears in the results of a
visitor who is logged in and holds that group, and stays out of everyone else's
— including a page that only inherits the restriction from a parent that has
:guilabel:`Extend to Subpages` set.

..  note::

    This is a *filter on results*, not a replacement for the page's own access
    protection. The page itself keeps deciding whether it may be opened; the
    assistant only stops offering links a visitor cannot follow, and stops
    leaking their titles and text passages while doing so.

..  _assistant-several-sites:

Several websites in one installation
====================================

..  versionadded:: 1.29.0

A question put to one website is only ever answered with that website's pages.
This needs saying because nothing in the search backends provides it:
``ke_search`` and ``indexed_search`` index the whole installation into one table
and know nothing about sites, and the dependency-free ``pages``/``tt_content``
fallback searched the entire page tree whenever no search root was configured —
which was the default.

Every hit is therefore checked against the site the question was asked on, in
the same place as the access check above, so a search backend added later cannot
forget it. A hit whose site cannot be established is dropped rather than kept:
"I cannot tell which website this page belongs to" must not end in showing it to
the visitors of both.

On an installation with a single site nothing is filtered and no site is even
looked up — there is no second site a hit could come from. Where there are
several, the backends are asked for more hits than are shown, so a busy
neighbouring site cannot crowd out the answers.

:confval:`aiAssistantSearchPid` still narrows the search further, to a subtree of
the site. Left empty, the site's own root page is the boundary.

Each site also has its own :confval:`aiAssistantSubscriptionKey`,
:confval:`aiAssistantTemperature` and :confval:`aiAssistantInstructions`, so two
websites can be licensed separately and address their visitors differently. The
:guilabel:`Enquiries` and :guilabel:`Answers` modules can be filtered by site —
that filter only appears where there is more than one.

..  _assistant-configuration:

Configuration
=============

The assistant needs two switches to agree: :confval:`assistantEnabled` in the
extension configuration turns the feature on at all,
:confval:`aiAssistantEnabled` in the site configuration turns it on for a given
site. Both are documented in :ref:`configuration`:

*   :ref:`configuration-assistant` — provider, model, tokens, temperature,
    instructions, bot protection, logging
*   :ref:`configuration-site-assistant` — window title, welcome message,
    placeholder, avatar, search root, OnePager mode, learning, custom CSS
*   :ref:`configuration-site-colors` — the twelve themeable colours

..  _assistant-curated-answers:

Curated answers
===============

The :guilabel:`Answers` module holds question/answer pairs that the assistant
uses as its own knowledge. An active entry replaces whatever the assistant would
have produced on its own.

Matching is by meaning rather than by wording: term overlap, prefix-tolerant so
that "delivery" also matches "delivery costs", combined with overall string
similarity. A close match is played back verbatim; a weaker one is handed to the
model as a binding hint.

Entries come from three places — written in the module, taken over from a logged
answer in :guilabel:`Enquiries`, or captured from a correction a visitor made in
the chat when :confval:`aiAssistantLearning` is enabled. Visitor corrections
arrive as :guilabel:`Pending review` and are never used until an editor approves
them.

The editorial workflow is described in :ref:`editor-answers`.

..  _assistant-logging:

Logging, statistics and cost
============================

With :confval:`assistantLogging` enabled, every question and answer is persisted
in ``tx_wnaibridge_assistant_log`` together with the date, client IP, user
agent, mode (``llm`` / ``search`` / ``learning``), provider, model and token
usage. Run ``vendor/bin/typo3 extension:setup`` after enabling, or the module
opens with a table error.

The :guilabel:`Enquiries` module shows the entries in a filterable list — by
date range, IP address, provider, mode and free text over question and answer —
with a statistics overview above it: interactions, the LLM-versus-search split,
input, output and total tokens, the estimated total cost and a per-provider
breakdown. A :guilabel:`Clear log` action removes all entries.

Conversations
-------------

Each chat session gets a conversation id, generated by the client and kept for
the browser session, so all turns of one conversation are grouped. The list
shows one collapsible row per conversation with the first question, the date and
the visitor information; expanding it reveals the follow-up questions and
answers with their provider, model and per-turn plus total token usage.

Estimated cost
--------------

The module estimates the LLM cost from the recorded token usage and per-model
pricing, shown as a total, per conversation and per turn. Model prices are quoted
in USD and converted with :confval:`assistantUsdConversionRate`; the currency the
figures are labelled with is :confval:`assistantCurrency`. Prices change over
time, so treat the figures as budgeting estimates, not accounting.

Visitor origin
--------------

The collapsed row shows the visitor's IP address and reverse-DNS hostname,
cached. With :confval:`assistantLogGeoLookup` enabled, the country is
additionally resolved via the external service ``ip-api.com`` and cached. This
runs only in the backend while the log is being viewed, never during a visitor
request. Private and reserved addresses are flagged as such and are never sent.

..  warning::

    The geolocation lookup sends the visitor IP address to a third party. It is
    off by default — enable it only if this is compatible with your
    data-protection requirements.

..  warning::

    The log stores IP addresses and the full text of every question and answer.
    Make sure this complies with your data-protection obligations: inform
    visitors in the privacy policy, define a retention period and clear the log
    on a schedule.

..  _assistant-privacy:

Privacy
=======

In search-only mode no data leaves the server. In hybrid mode the visitor's
question and the retrieved page excerpts are sent to the configured LLM
provider. Inform your visitors accordingly and choose provider and model with
your data-protection requirements in mind.

What the licence check sends — which is unrelated to visitor data — is spelled
out in :ref:`data-sent-to-the-licence-server`.

..  _assistant-abuse:

Protecting the endpoint
=======================

The ``/wn-ai-bridge/ask`` endpoint is public and, with an API key configured,
every request to it can cost money. Before going live, read
:ref:`administrator-security`.
