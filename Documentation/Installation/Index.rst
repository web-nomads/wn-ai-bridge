..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

This chapter walks through a complete setup, from the Composer command to a
working chat assistant. Follow it top to bottom; each step says what it is good
for and what breaks without it. The settings themselves are described in
:ref:`configuration`.

..  contents::
    :local:
    :depth: 2

..  _installation-requirements:

Requirements
============

..  list-table::
    :header-rows: 1
    :widths: 30 70

    * - Requirement
      - Note
    * - TYPO3 13.4 LTS or 14.x
      - Older versions are not supported
    * - PHP 8.2, 8.3 or 8.4
      - —
    * - PHP extension ``sodium``
      - Needed to verify the subscription key. Part of PHP since 7.2 and
        enabled in almost every distribution; without it the subscription
        features stay off
    * - ``ke_search`` or ``indexed_search``
      - Optional. The assistant works without either, but answers are
        noticeably better with a real search index
    * - An Anthropic API key
      - Optional. Without it the assistant runs in search-only mode
    * - A subscription key
      - Required for the chat assistant and its two backend modules. llms.txt
        and the Markdown endpoints work without one

..  _installation-install:

Step 1: Install the extension
=============================

..  code-block:: bash

    composer require web-nomads/wn-ai-bridge

Then activate it and flush the caches:

..  code-block:: bash

    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

``extension:setup`` also creates the database tables. Skipping it is the most
common cause of a backend module that opens with an error instead of a list.

..  important::

    Repeat ``extension:setup`` after every update. The extension adds tables and
    columns over time, and a missing column fails at the moment the feature is
    used, not at the moment of the update.

..  _installation-routes:

Step 2: Readable URLs
=====================

Without route enhancers the endpoints are reachable by page type only
(``?type=1699`` and ``?type=1701``). Import the shipped configuration into each
site:

..  code-block:: yaml
    :caption: config/sites/<identifier>/config.yaml

    imports:
      -
        resource: 'EXT:wn_ai_bridge/Configuration/Routes/RouterEnhancer.yaml'

..  list-table::
    :header-rows: 1

    * - Endpoint
      - Without enhancer
      - With enhancer
    * - llms.txt
      - ``/?type=1699``
      - :file:`/.well-known/llms.txt` and :file:`/llms.txt`
    * - Markdown
      - ``/?type=1701``
      - ``.md`` appended to any page URL

Flush the caches afterwards and check that ``https://example.com/llms.txt``
returns text rather than the home page. If it returns HTML, the import did not
take effect.

..  _installation-llmstxt:

Step 3: llms.txt per site
=========================

Open :guilabel:`Site Management > Sites`, pick the site and switch to the
:guilabel:`AI Bridge` tab.

..  list-table::
    :header-rows: 1
    :widths: 34 66

    * - Field
      - Purpose
    * - :confval:`llmsTxtEnabled`
      - Master switch for this site
    * - :confval:`llmsTxtOnePager`
      - Only for one-page sites, where sub-pages are sections of the home page.
        Off by default — switching it on for a normal site produces links to
        anchors that do not exist
    * - :confval:`llmsTxtTitle`
      - Name of the site as it should appear to a model
    * - :confval:`llmsTxtDescription`
      - One or two sentences on what the site is about. This is what a model
        reads first — worth writing properly
    * - :confval:`llmsTxtAdditionalInfo`
      - Free text, e.g. terms of use for the content
    * - :confval:`llmsTxtContactEmail`
      - Contact address published in the file
    * - :confval:`llmsTxtKeywords`
      - Topics, comma separated
    * - :confval:`llmsTxtMaxDepth`
      - How deep the page tree is listed. Keep it low on large sites; every
        level makes the file longer and less useful

Nothing here needs a subscription key.

..  _installation-subscription:

Step 4: Subscription key
========================

Required for the chat assistant and the :guilabel:`Enquiries` and
:guilabel:`Answers` modules. Enter it under :guilabel:`Admin Tools > Settings >
Extension Configuration > wn_ai_bridge`, tab :guilabel:`subscription`, field
:confval:`subscriptionKey`.

Then verify it:

..  code-block:: bash

    vendor/bin/typo3 ai-bridge:check-subscription

The command reports whether the key was decoded, which domains it covers, when
it expires and which features it unlocks. Use it before wondering why the widget
does not appear — it names the reason.

..  list-table:: Common outcomes
    :header-rows: 1
    :widths: 35 65

    * - Reported reason
      - What to do
    * - ``domain``
      - The key was issued for other domains. ``*.example.com`` covers the
        subdomains but **not** the bare ``example.com``, which has to be listed
        separately
    * - ``expired``
      - The term ended. A renewal reaches the installation through the daily
        status check; no new key has to be pasted
    * - ``signature`` / ``malformed``
      - The key was copied incompletely, or it was issued by a different server
        than the public key configured here expects
    * - ``revoked``
      - Withdrawn by the issuer

What the licence check sends to the issuing server, and how to switch it off, is
described in :ref:`data-sent-to-the-licence-server`.

..  _installation-assistant:

Step 5: The AI search assistant
===============================

Two switches have to agree: the extension configuration turns the feature on at
all, the site configuration turns it on for a given site.

Extension configuration
-----------------------

:guilabel:`Admin Tools > Settings > Extension Configuration > wn_ai_bridge`,
tab :guilabel:`assistant`:

..  code-block:: none

    assistantEnabled = 1

Site configuration
------------------

:guilabel:`Site Management > Sites`, tab :guilabel:`AI Search Assistant`:

..  list-table::
    :header-rows: 1
    :widths: 36 64

    * - Field
      - Purpose
    * - :confval:`aiAssistantEnabled`
      - Switch for this site
    * - :confval:`aiAssistantTitle`
      - Heading of the chat window
    * - :confval:`aiAssistantWelcome`
      - First message the visitor sees
    * - :confval:`aiAssistantPlaceholder`
      - Placeholder in the input field
    * - :confval:`aiAssistantAvatar`
      - Image for the assistant
    * - :confval:`aiAssistantAutoOpen` / :confval:`aiAssistantAutoOpenDelay`
      - Open by itself after a delay. Use sparingly — an unrequested overlay
        annoys more visitors than it helps
    * - :confval:`aiAssistantSearchPid`
      - Restrict the search to a page tree
    * - :confval:`aiAssistantOnePager`
      - For one-page sites: answers link to sections rather than pages
    * - :confval:`aiAssistantSystemPrompt`
      - Site-specific instructions, added to the global ones
    * - :confval:`aiAssistantCustomCss`
      - Own styling
    * - :confval:`aiAssistantLearning`
      - Whether a correction a visitor makes is captured for review in the
        :guilabel:`Answers` module

The widget can be themed per site on the :guilabel:`AI Assistant Colors` tab —
see :ref:`configuration-site-colors`.

At this point the assistant already works: it returns matching pages as
suggestions with links, costs nothing and sends no data anywhere. That is the
**search-only mode**, and it is a reasonable place to stop.

..  _installation-claude:

Step 6: Connecting Claude
=========================

With an API key the assistant additionally composes a short answer from the
pages it found and cites them. Everything else stays as it is — retrieval is
unchanged, the model only phrases the result.

Getting an API key
------------------

#.  Create an account at
    `console.anthropic.com <https://console.anthropic.com>`__.
#.  Buy credit under :guilabel:`Billing`. The API is prepaid; without credit
    every request fails.
#.  Create a key under :guilabel:`API Keys`. It is shown once — store it in a
    password manager right away.

Configuration
-------------

:guilabel:`Admin Tools > Settings > Extension Configuration > wn_ai_bridge`,
tab :guilabel:`assistant`:

..  code-block:: none

    assistantProvider = anthropic
    assistantApiKey   = sk-ant-...
    assistantModel    = claude-haiku-4-5

Choosing a model
----------------

..  list-table::
    :header-rows: 1
    :widths: 28 72

    * - Model
      - When
    * - ``claude-haiku-4-5``
      - The default, and the right choice here. The work is summarising three
        to five pages that were already retrieved — that is not a hard task,
        and a larger model mostly buys latency the visitor has to wait through
    * - ``claude-sonnet-5``
      - Noticeably better with tangled questions and multilingual content, at a
        higher price per answer
    * - ``claude-opus-5``
      - Hard to justify for this purpose

The model id is passed through to the API unchanged, so newer models can be
entered as soon as they are released.

Tuning the answers
------------------

..  list-table::
    :header-rows: 1
    :widths: 34 66

    * - Setting
      - Note
    * - :confval:`assistantMaxTokens` (1024)
      - Upper bound for one answer. This is the main lever on cost per answer.
        Raising it does not make answers better, only longer
    * - :confval:`assistantTemperature` (0.2)
      - Low on purpose. The assistant should reproduce what is on the site, not
        invent variations. Do not raise this above 0.5
    * - :confval:`assistantInstructions`
      - Global persona and rules, e.g. tone, language, what to refuse. Applies
        to every site; the site configuration adds to it
    * - :confval:`assistantMaxResults`
      - How many search hits go to the model as context. More context costs
        more and, past a handful, tends to dilute the answer
    * - :confval:`assistantSearchSources`
      - ``auto`` uses every index available. Pin it to ``kesearch``,
        ``indexed`` or ``pages`` if you want a specific one

..  note::

    Any failure on the LLM side — no credit, timeout, malformed response — falls
    back to search-only. The visitor gets suggestions instead of an error, and
    the incident is logged. A missing or wrong API key therefore shows up as
    "the assistant only ever lists pages", not as a visible error.

..  _installation-protection:

Step 7: Protect the endpoint
============================

**Do this before going live.** The assistant endpoint is reachable without
authentication, and every request can cost money. Without a limit, a single
script can run through a month's budget in an afternoon.

..  code-block:: none

    rateLimiterEnabled           = 1
    rateLimiterRequestsPerMinute = 10
    assistantBotProtection       = 1

The rate limiter is off by default so that it never interferes with legitimate
crawlers on the llms.txt and Markdown endpoints. Switch it on deliberately once
the assistant is exposed.

:confval:`assistantBotProtection` is a heuristic deterrent, not authentication.
Treat it as a speed bump.

As an independent second net, set a spending limit in the Anthropic console.
That one holds even if something here is misconfigured. See
:ref:`administrator-security`.

..  _installation-logging:

Step 8: Logging and cost tracking
=================================

..  code-block:: none

    assistantLogging      = 1
    assistantUsdToChfRate = 0.90

Every question, answer, provider, model and token count is then recorded and
shown in the :guilabel:`Enquiries` module, together with an estimated cost. The
estimate converts the providers' USD prices with the rate above; it is for
budgeting, not accounting.

Run ``extension:setup`` after enabling this, or the module opens with a table
error.

..  note::

    The log stores visitor questions and IP addresses. Cover it in the site's
    privacy policy and delete it on a schedule — the module has a
    :guilabel:`Clear log` action. See :ref:`administrator-privacy`.

..  _installation-answers:

Step 9: Your own answers
========================

The :guilabel:`Answers` module holds question/answer pairs the assistant uses as
its own knowledge. An entry is played back verbatim when a question matches it
in meaning; weaker matches are handed to the model as binding hints.

This is the fastest way to fix a wrong or unwanted answer: open the turn in
:guilabel:`Enquiries`, define a different answer, done. Entries also arrive from
visitors' own corrections when :confval:`aiAssistantLearning` is on — those land
as :guilabel:`Pending review` and are only used once approved. The editorial
workflow is described in :ref:`editor-answers`.

..  _installation-verify:

Step 10: Verify
===============

..  code-block:: bash

    vendor/bin/typo3 ai-bridge:check-subscription

..  list-table::
    :header-rows: 1
    :widths: 45 55

    * - Check
      - Expected
    * - ``https://example.com/llms.txt``
      - Text with the site structure
    * - Any page with ``.md`` appended
      - Markdown of that page
    * - Chat widget on the front end
      - Visible, answers a question about existing content
    * - Answer contains links
      - The retrieval works
    * - Answer is a phrased text, not a list of hits
      - The LLM connection works
    * - Backend module group :guilabel:`AI Bridge`
      - :guilabel:`Enquiries`, :guilabel:`Answers`, :guilabel:`Bot Access Log`

..  note::

    If you are looking for modules named :guilabel:`AI Assistant Log` or
    :guilabel:`Corrections`: they are called :guilabel:`Enquiries` and
    :guilabel:`Answers` since versions 1.21.0 and 1.18.0 respectively. See
    :ref:`configuration-module-access`.

..  _installation-troubleshooting:

Troubleshooting
===============

..  list-table::
    :header-rows: 1
    :widths: 38 62

    * - Symptom
      - Cause
    * - No chat widget
      - :confval:`assistantEnabled`, :confval:`aiAssistantEnabled` per site, or
        an invalid subscription key. ``ai-bridge:check-subscription`` says which
    * - Modules missing from the menu
      - Same. The modules are hidden, not disabled, without a covering key
    * - A red box above the module title naming the issuing server
      - The daily status check cannot reach it, or its answer does not verify.
        The subscription keeps working, but a renewal or a revocation cannot
        arrive — see :ref:`administrator-server-failure`
    * - Assistant only lists pages, never phrases an answer
      - No :confval:`assistantApiKey`, no credit on the account, or an unknown
        model id. The fallback is silent by design — look in the TYPO3 log
    * - Assistant finds nothing
      - No search index. Check :confval:`assistantSearchSources` and whether
        ``ke_search`` / ``indexed_search`` are indexed at all
    * - Module opens with a table error
      - ``extension:setup`` was not run after installing or updating
    * - ``llms.txt`` returns the home page
      - Route enhancer not imported, or caches not flushed
    * - Widget appears but every answer fails
      - Rate limiter set too low, or the endpoint blocked by a firewall or
        reverse proxy
