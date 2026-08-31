..  include:: /Includes.rst.txt

..  _changelog:

=========
Changelog
=========

The complete, per-release changelog is kept in :file:`CHANGELOG.md` at the root
of the extension, following
`Keep a Changelog <https://keepachangelog.com/en/1.0.0/>`__ and
`Semantic Versioning <https://semver.org/spec/v2.0.0.html>`__. This chapter
summarises what matters when upgrading.

..  contents::
    :local:
    :depth: 2

..  _changelog-upgrading:

Upgrading
=========

..  important::

    Run ``vendor/bin/typo3 extension:setup`` after every update. Several
    releases added tables or columns, and a missing column fails at the moment
    the feature is used, not at the moment of the update.

..  note::

    Version 1.27.0 added the page type 1702 and two suffixes to the shipped
    route enhancer. A site that imports
    :file:`EXT:wn_ai_bridge/Configuration/Routes/RouterEnhancer.yaml` by
    reference picks both up after a cache flush; a site that copied the mapping
    into its own :file:`config.yaml` has to add ``llms-full.txt: 1702`` and
    ``.well-known/llms-full.txt: 1702`` by hand. Nothing changes until
    :confval:`llmsFullTxt` is switched on.

..  _changelog-renames:

Renamed backend modules
-----------------------

Two of the three backend modules were renamed. Both kept their previous route
identifier as an alias, so **existing backend group permissions and bookmarks
keep working** and no migration is needed.

..  list-table::
    :header-rows: 1
    :widths: 12 26 26 36

    * - Version
      - Former name
      - Current name
      - Identifier (alias kept)
    * - 1.18.0
      - :guilabel:`Corrections`
      - :guilabel:`Answers`
      - ``wn_ai_bridge_answers`` (``wn_ai_bridge_corrections``)
    * - 1.21.0
      - :guilabel:`AI Assistant Log`
      - :guilabel:`Enquiries`
      - ``wn_ai_bridge_enquiries`` (``wn_ai_bridge_log``)

:guilabel:`Answers` was renamed because the module never was only about
corrections: it holds every answer the assistant gives for a question it
recognises, whoever wrote it. :guilabel:`Enquiries` was renamed to match what it
actually lists.

The subscription feature keys ``corrections`` and ``log`` still carry the old
wording. They are internal identifiers baked into already-issued licence keys
and are deliberately left alone — see :ref:`administrator-subscription`.

..  _changelog-schema:

Releases that need a schema update
----------------------------------

..  list-table::
    :header-rows: 1
    :widths: 16 84

    * - Version
      - Change
    * - 1.9.0
      - New table ``tx_wnaibridge_bot_access`` for the
        :guilabel:`Bot Access Log`
    * - 1.5.0
      - New ``sources`` column on ``tx_wnaibridge_assistant_log``. Existing
        entries simply show no links

..  _changelog-behaviour:

Behavioural changes worth checking after an update
--------------------------------------------------

1.25.0
    The setting :guilabel:`Daily status check` was removed. The check is always
    on. If you had it disabled, this installation now follows its issuing server
    again — which means a revocation takes effect, and a renewal arrives without
    anyone pasting a new key.

    Direct children of the site root are no longer rendered as home page anchors
    unless :confval:`llmsTxtOnePager` is enabled. If your site *is* a OnePager
    and you had never set the assistant's :confval:`aiAssistantOnePager`, switch
    the new setting on — otherwise the section links become separate page URLs.

1.24.0
    The system prompt of the assistant is now English, including the labels of
    the retrieved passages handed to the model. The prompt still instructs the
    model to answer in the language of the question, so German questions should
    still get German answers — but it is worth reading the first few answers
    after updating.

1.17.0
    Expiry is now decided by the signed end date from the daily status check
    when one is available, instead of solely by the date inside the key. This
    fixes renewed subscriptions switching themselves off, and it cuts both ways:
    a subscription that lapsed on the issuing server now switches off even if
    its key would still be good.

1.15.0
    Approved answers are matched by meaning rather than by wording. A close
    match is played back verbatim; weaker matches are handed to the model as
    binding hints.

..  _changelog-releases:

Release history
===============

..  _changelog-1-26-2:

1.26.2 — 2026-08-25
-------------------

*   Fixed: appending ``.md`` to the URL of a plugin's detail view returned the
    Markdown of the list view. The source URL was rebuilt from the page id,
    which every detail view on that page shares, so the arguments picking the
    record were lost. The requested URL is now used as it came in.
*   The "Web version" link on such a page pointed at the list view too, and now
    points back at the record.
*   Fixed: with :confval:`cacheMarkdown` enabled, all detail views of a page
    shared a single cache entry, so whichever was rendered first was served for
    all of them.

..  _changelog-1-26-1:

1.26.1 — 2026-08-25
-------------------

*   Fixed: on TYPO3 13.4 every backend request ended in
    ``Interface "TYPO3\CMS\Backend\Module\ModuleAccessGateInterface" not found``,
    the login screen included. Module access gates only exist on v14, and the
    module guard loaded the gate class on both.
*   The subscription-only modules are hidden on 13.4 again — from the module
    menu, since v13 has no gate to block their routes with. Reached through a
    bookmark or the live search they answer with the "subscription required"
    screen; on v14 the gate keeps blocking menu and routes alike.

..  _changelog-1-26:

1.26.0 — 2026-08-08
-------------------

*   A trial key is recognised as one: the marker travels inside the signed key,
    so the modules and ``ai-bridge:check-subscription`` say "trial" and point at
    ordering rather than at renewing.

..  _changelog-1-25-1:

1.25.1 — 2026-08-08
-------------------

*   A language pass over the manual and the README. Three corrections changed
    the meaning: the Administrator chapter announced "two of the three tables"
    above a table listing three, the Editor chapter called two backend modules
    "editorial work", and the README described ``llms.txt`` as a policy file.
*   Two README sections used Markdown definition lists, which GitHub does not
    render, so both showed a stray leading colon.

..  _changelog-1-25:

1.25.0 — 2026-08-08
-------------------

*   The setting ``subscriptionOnlineCheck`` was removed; the daily status check
    is always on.
*   Fixed: the automatic check honoured that setting while
    ``ai-bridge:check-subscription`` did not, so the command reported "confirmed
    by the server" on installations where nothing ever asked it.
*   The issuing server address falls back to the one shipped with the extension
    when neither :confval:`subscriptionServerUrl` nor the key names one.
*   Fixed: the chat panel could not be closed — the close button, the floating
    toggle and Escape all set the ``hidden`` attribute, which the panel's own
    ``display`` rule overrode.
*   Fixed: the daily status check never ran in the backend. The module guard
    resolves the status from a middleware, before the request reaches
    ``$GLOBALS``, and the service kept that empty verdict for the whole request.
*   Fixed: a corrected :confval:`subscriptionServerUrl` stayed without effect
    until the previous day-old verdict expired.
*   A failing status check is now shown as an error above the subscription state
    in every AI Bridge backend module, with the address in bold, and named by
    ``ai-bridge:check-subscription``, instead of failing silently.
*   Fixed: sites served from an entry point (``base: /camino/``) produced
    doubled URLs — ``/camino/camino/faqs.md`` — in ``llms.txt`` and in every
    internal link of the Markdown export.
*   Fixed: every site was treated as a OnePager, so the Markdown export linked
    to home page anchors that do not exist. The new site setting
    :confval:`llmsTxtOnePager` decides, and it is off by default.
*   The "Web version" link under a Markdown page is translated into the page's
    language instead of always being German.
*   Backend badges now use fixed colour pairs that meet WCAG 2.1 AA.
*   The documentation was rewritten for the renamed backend modules, with a
    broken ``toctree`` and a duplicated ChangeLog title fixed along the way.

..  _changelog-1-24:

1.24.0 — 2026-08-07
-------------------

*   English is the source language throughout. The 24 extension configuration
    labels moved from :file:`ext_conf_template.txt` into :file:`locallang.xlf`
    and are referenced with ``LLL:``, the way the TYPO3 core does it; a German
    :file:`de.locallang.xlf` carries the previous wording, so a German backend
    looks exactly as before.
*   Status messages, the output of ``ai-bridge:check-subscription`` and the
    fallback labels of the chat widget are English. Dates use ``Y-m-d``.

1.23.0 — 2026-08-07
-------------------

*   A complete :ref:`installation` chapter, from the Composer command to a
    working assistant.
*   Documentation fixes: the modules were still named "AI Assistant Log" and
    "Corrections", the Administrator chapter claimed the key is validated with
    no server call at all, and three section underlines were too short.

1.22.0 – 1.22.2 — 2026-08-07
-----------------------------

*   Attribution to web-vision, from whose ``ai-llms-txt`` this extension is
    derived, and the full GPL-2.0 text in :file:`LICENSE`.
*   :file:`phpstan.neon` (level 6) with a baseline, and
    :file:`.php-cs-fixer.dist.php` with the official TYPO3 rule set.
*   The Composer scripts pointed at a :file:`runTests.sh` that does not exist in
    this repository, so ``composer test``, ``composer stan`` and
    ``composer ci:test`` all failed. They now call the tools directly.
*   ``composer release`` builds and verifies the TER archive.
*   README rewritten; the TYPO3 requirement said 13.0 where the constraint is
    13.4.

..  _changelog-subscription-releases:

1.15.0 – 1.21.0 — 2026-08-07
-----------------------------

The subscription and the backend modules took their current shape:

*   **1.15.0** Subscription key: encrypted, Ed25519-signed, carrying the allowed
    domains, an expiry date and the enabled features. Issued by the companion
    extension ``wn_ai_bridgeserver``. The :guilabel:`Answers` module became a
    full editor for the learning source, and answers are matched by meaning.
*   **1.16.0** Daily online check with a signed, nonce-bound answer, and the
    ``ai-bridge:check-subscription`` command. The check never runs inside a
    visitor request.
*   **1.17.0** A renewed subscription no longer switches itself off; the bundled
    verification key was replaced with the real one.
*   **1.18.0** :guilabel:`Corrections` became :guilabel:`Answers`, and every
    logged answer got a :guilabel:`Define a different answer` button.
*   **1.19.0** Licence findings that cannot be an honest state are reported to
    the issuing server — subscription id, host, finding, two version numbers,
    nothing about the site or its visitors.
*   **1.20.0 / 1.20.1** The backend modules were translated into German, French,
    Italian, Portuguese and Spanish; the subscription state moved to the top of
    each module.
*   **1.21.0** :guilabel:`AI Assistant Log` became :guilabel:`Enquiries`.

1.9.0 – 1.14.1 — 2026-07/2026-08
---------------------------------

*   **1.9.0** The :guilabel:`Corrections` and :guilabel:`Bot Access Log` modules
    were split out into their own navigation entries, with the opt-in setting
    :confval:`botAccessLogging`.
*   **1.10.0 – 1.13.0** Chat widget refinements — a "New discussion" button,
    multilingual widget texts in fourteen languages, per-language site texts,
    configurable temperature and global agent instructions, AJAX filters in the
    backend modules.
*   **1.14.0** The per-site llms.txt texts can be maintained per site language.
*   **1.14.1** Security: the public ``.md`` endpoint no longer exposes internal
    exception messages, the OnePager anchor is restricted before use in an XPath
    query, and verbose LLM error bodies are truncated before logging.

1.2.0 — 2026-07-17
------------------

The AI search assistant: the chat widget, the hybrid search-only/RAG answering,
the Anthropic integration behind a swappable :php:`LlmClientInterface`, the
search provider abstraction with its three backends, the JSON endpoint, per-site
theming and avatar, bot protection, interaction logging with conversation
threading and the cost estimate.

1.1.0 — 2026-07-17
------------------

Rate limiter for the llms.txt and Markdown endpoints, with ``429`` and a
``Retry-After`` header, and its three settings.

1.0.0 — 2026-06-26
------------------

First stable release. Added the HTML parsing fallback
(:confval:`parsingFallbackHtml`), Markdown caching (:confval:`cacheMarkdown`)
and the ``ai-bridge:download-markdown`` command.

0.1.9 — 2025-10-29
------------------

Initial release: llms.txt generation according to the llmstxt.org
specification, the site navigation structure with configurable depth, and
page-to-Markdown conversion via the ``.md`` suffix.
