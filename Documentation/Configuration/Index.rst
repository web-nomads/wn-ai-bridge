..  include:: /Includes.rst.txt

..  _configuration:

=============
Configuration
=============

The extension is configured in three places: the extension configuration for
everything that is installation-wide, the site configuration for everything
that differs per site, and the route enhancers for readable URLs. This chapter
is the reference; :ref:`installation` walks through the same settings in the
order you need them.

..  contents::
    :local:
    :depth: 2

..  _configuration-extension:

Extension configuration
=======================

:guilabel:`Admin Tools > Settings > Extension Configuration > wn_ai_bridge`.
The values are stored in :file:`config/system/settings.php` under
``EXTENSIONS/wn_ai_bridge`` and can be deployed from there.

..  _configuration-basic:

Tab "basic"
-----------

..  confval:: debug
    :type: boolean
    :Default: 0

    Writes rendered HTML to :file:`var/log` and returns internal error details
    on the ``.md`` endpoint. For local troubleshooting only — keep it off in
    production.

..  confval:: cacheMarkdown
    :type: boolean
    :Default: 0

    Caches the generated Markdown of a page. Worth enabling on content-heavy
    pages that are requested by crawlers regularly.

..  confval:: parsingFallbackHtml
    :type: boolean
    :Default: 0

    Falls back to raw HTML when a page cannot be parsed into Markdown, instead
    of returning an error.

..  confval:: botAccessLogging
    :type: boolean
    :Default: 0

    Records bot and crawler accesses to ``llms.txt``, the Markdown versions and
    normal pages into ``tx_wnaibridge_bot_access``, shown in the
    :guilabel:`Bot Access Log` module. Run the database schema update after
    enabling.

..  _configuration-ratelimiter:

Tab "rateLimiter"
-----------------

..  confval:: rateLimiterEnabled
    :type: boolean
    :Default: 0

    Master switch for the rate limiter. Off by default so it never interferes
    with legitimate crawlers on the ``llms.txt`` and ``.md`` endpoints — switch
    it on deliberately once the assistant is exposed. See
    :ref:`administrator-security`.

..  confval:: rateLimiterRequestsPerMinute
    :type: int
    :Default: 60

    Requests per minute a single client may make. For a site with an LLM API
    key configured, a much lower value such as ``10`` is appropriate.

..  confval:: rateLimiterPerKeyRequestsPerMinute
    :type: int
    :Default: 120

    Upper bound across all clients per limiter key.

..  _configuration-assistant:

Tab "assistant"
---------------

..  confval:: assistantEnabled
    :type: boolean
    :Default: 0

    Master switch for the AI search assistant. The widget additionally has to be
    enabled per site with :confval:`aiAssistantEnabled`.

..  confval:: assistantProvider
    :type: options
    :Default: anthropic

    LLM provider. Currently ``anthropic``.

..  confval:: assistantApiKey
    :type: string
    :Default: (empty)

    The provider API key. Leave it empty to run the assistant in search-only
    mode.

..  confval:: assistantModel
    :type: string
    :Default: claude-haiku-4-5

    Model id. It is passed to the API unchanged, so a newly released model can
    be entered as soon as it exists. See :ref:`installation-claude` for how to
    choose one.

..  confval:: assistantSearchSources
    :type: options
    :Default: auto

    Which search backends to use: ``auto`` (every one available), ``kesearch``,
    ``indexed`` or ``pages``.

..  confval:: assistantMaxResults
    :type: int
    :Default: 5

    How many search hits are used as context for the model and shown as
    suggestions. More context costs more and, past a handful, dilutes the
    answer.

..  confval:: assistantMaxTokens
    :type: int
    :Default: 1024

    Upper bound on the length of one generated answer. This is the main lever on
    the cost per answer; raising it makes answers longer, not better.

..  confval:: assistantTemperature
    :type: string
    :Default: 0.2

    Sampling temperature between ``0.0`` (deterministic) and ``1.0`` (more
    creative). Low on purpose: the assistant should reproduce what is on the
    site, not invent variations. Invalid values fall back to ``0.2``,
    out-of-range values are clamped. Do not raise it above ``0.5``.

..  confval:: assistantInstructions
    :type: text
    :Default: (empty)

    Global agent instructions — persona, tone, what to refuse — added to the
    system prompt on every answer. Applies to all sites and combines with the
    per-site :confval:`aiAssistantSystemPrompt`.

..  confval:: assistantBotProtection
    :type: boolean
    :Default: 1

    Blocks non-human requests to the assistant endpoint. Detection combines a
    proof header only the widget sends, bot User-Agent markers and a same-origin
    check; blocked requests get an HTTP ``403``. It is a deterrent, not
    authentication — see :ref:`administrator-security`.

..  confval:: assistantLogging
    :type: boolean
    :Default: 0

    Persists every question and answer into ``tx_wnaibridge_assistant_log`` for
    the :guilabel:`Enquiries` module. Run the database schema update after
    enabling.

..  confval:: assistantLogGeoLookup
    :type: boolean
    :Default: 0

    Resolves the visitor country via the external service ``ip-api.com`` when
    the log is viewed in the backend, and caches the result. Never runs during a
    visitor request. Private and reserved IP addresses are never sent.

    ..  warning::

        This sends visitor IP addresses to a third party. It is off by default;
        enable it only if that is compatible with your data-protection
        requirements.

..  confval:: assistantUsdToChfRate
    :type: string
    :Default: 0.90

    Conversion rate used to express the estimated LLM cost in CHF. Model prices
    are quoted in USD. The result is a rough estimate for budgeting, not
    accounting.

..  _configuration-subscription:

Tab "subscription"
------------------

..  confval:: subscriptionKey
    :type: string
    :Default: (empty)

    The encrypted and signed licence key issued by the companion extension
    ``wn_ai_bridgeserver``. It carries the domains it is valid for, an expiry
    date and the features it unlocks. Without it the chat widget and the
    :guilabel:`Enquiries` and :guilabel:`Answers` modules stay hidden. See
    :ref:`administrator-subscription`.

..  confval:: subscriptionPublicKey
    :type: string
    :Default: (empty)

    Optional. An alternative public verification key, for the case that the
    issuing server rolled over its key pair. Empty means the key bundled with
    the extension is used.

..  confval:: subscriptionServerUrl
    :type: string
    :Default: (empty)

    Optional override of the issuing server address, for staging setups. The
    address is resolved in this order:

    #.  this setting, when it holds an ``http://`` or ``https://`` URL,
    #.  the address baked into the subscription key,
    #.  the address of the issuing server that ships with the extension.

    The last step is what keeps a key working that carries no address of its
    own — without it there is no one to ask, and the daily status check does
    nothing at all.

    A change here takes effect immediately: the address is part of the cache key
    of the stored verdict. If the server does not answer properly, the backend
    modules say so — see :ref:`administrator-server-failure`.

..  _configuration-site:

Site configuration
==================

:guilabel:`Site Management > Sites`. The settings are written to
:file:`config/sites/<identifier>/config.yaml` and belong in version control.

The text fields of both tabs can additionally be maintained per site language.
Resolution order is: value on the site language → value on the site.

..  _configuration-site-llmstxt:

Tab "AI Bridge"
---------------

..  confval:: llmsTxtEnabled
    :type: boolean
    :Default: 0

    Master switch for ``llms.txt`` on this site.

..  confval:: llmsTxtOnePager
    :type: boolean
    :Default: 0

    Enable **only** if this site is a OnePager, i.e. its sub-pages are rendered
    as sections on the home page. Links to direct children of the site root then
    use anchors (``/#packing-list``) instead of their own page URL.

    Leave it off for normal multipage sites, otherwise ``llms.txt`` and the
    Markdown export point at anchors that do not exist.

    While this setting has never been saved on a site, the assistant's
    :confval:`aiAssistantOnePager` is used instead, so sites configured before it
    existed keep their behaviour. Once saved, this one decides.

..  confval:: llmsTxtTitle
    :type: string

    The name of the site as it should appear to a model. Falls back to the root
    page title.

..  confval:: llmsTxtDescription
    :type: text

    One or two sentences on what the site is about. This is the first thing a
    model reads — worth writing properly.

..  confval:: llmsTxtAdditionalInfo
    :type: text

    Free text, for example terms of use for the content.

..  confval:: llmsTxtContactEmail
    :type: string

    Contact address published in the file.

..  confval:: llmsTxtKeywords
    :type: string

    Topics, comma separated.

..  confval:: llmsTxtMaxDepth
    :type: int

    How deep the page tree is listed. Keep it low on large sites: every level
    makes the file longer and less useful.

..  _configuration-site-assistant:

Tab "AI Search Assistant"
-------------------------

..  confval:: aiAssistantEnabled
    :type: boolean
    :Default: 0

    Shows the chat widget on this site. Requires :confval:`assistantEnabled` and
    a subscription covering the ``chatbot`` feature.

..  confval:: aiAssistantTitle
    :type: string

    Heading of the chat window.

..  confval:: aiAssistantWelcome
    :type: text

    The first message the visitor sees.

..  confval:: aiAssistantPlaceholder
    :type: string

    Placeholder text in the input field.

..  confval:: aiAssistantSystemPrompt
    :type: text

    Site-specific instructions for the model, added to the global
    :confval:`assistantInstructions`. Only has an effect with an API key
    configured.

..  confval:: aiAssistantSearchPid
    :type: int

    Restricts the search to a page subtree.

..  confval:: aiAssistantOnePager
    :type: boolean
    :Default: 0

    For sites whose sub-pages are rendered as sections of the home page. Result
    links to direct children of the site root then use home page anchors
    (``/#customers``) instead of separate URLs. Leave it off for normal
    multipage sites.

..  confval:: aiAssistantLearning
    :type: boolean
    :Default: 0

    Captures the corrections visitors make in the chat. They arrive in the
    :guilabel:`Answers` module as :guilabel:`Pending review` and are only used
    once an editor approves them. Requires a subscription covering the
    ``corrections`` feature.

..  confval:: aiAssistantAvatar
    :type: string

    A logo or photo shown as a round avatar in the widget header and next to
    each assistant answer; the floating button keeps the chat icon. Accepts a
    URL, an extension path
    (:file:`EXT:my_ext/Resources/Public/Images/bot.png`) or a path relative to
    the public root (:file:`fileadmin/logo.png`). Since the site configuration
    is file-based this is a path field, not a FAL file picker. The image is
    cropped to a circle, so any aspect ratio works.

..  confval:: aiAssistantAutoOpen
    :type: boolean
    :Default: 0

    Opens the overlay by itself after :confval:`aiAssistantAutoOpenDelay`. Once
    a visitor closes it, it does not reopen during the same browser session.
    Use sparingly — an unrequested overlay annoys more visitors than it helps.

..  confval:: aiAssistantAutoOpenDelay
    :type: int

    Delay in seconds before the overlay opens by itself.

..  confval:: aiAssistantCustomCss
    :type: string

    Your own stylesheet (URL, ``EXT:`` path or a path relative to the public
    root), loaded *after* the widget's default styles so your rules take
    precedence. Scope your selectors under ``#wn-ai-assistant``.

..  _configuration-site-colors:

Tab "AI Assistant Colors"
-------------------------

Twelve optional HEX values that theme the widget per site. Any value left empty
keeps the built-in default, including the automatic dark mode.

..  list-table::
    :header-rows: 1
    :widths: 40 60

    * - Setting
      - Applies to
    * - ``aiAssistantAccentColor``
      - Accent colour of the widget
    * - ``aiAssistantBgColor`` / ``aiAssistantTextColor``
      - Panel background and text
    * - ``aiAssistantUserBgColor`` / ``aiAssistantUserTextColor`` /
        ``aiAssistantUserLinkColor``
      - Visitor messages
    * - ``aiAssistantAssistantBgColor`` / ``aiAssistantAssistantTextColor`` /
        ``aiAssistantAssistantLinkColor``
      - Assistant messages
    * - ``aiAssistantSourcesBgColor`` / ``aiAssistantSourcesTextColor`` /
        ``aiAssistantSourcesLinkColor``
      - The sources list below an answer

For anything the colours cannot express, use :confval:`aiAssistantCustomCss` and
override the CSS custom properties such as ``--wn-ai-accent`` or
``--wn-ai-radius``.

..  _configuration-routes:

Route enhancers
===============

Without route enhancers the endpoints are reachable by page type only
(``?type=1699`` and ``?type=1701``). Import the shipped configuration into every
site:

..  code-block:: yaml
    :caption: config/sites/<identifier>/config.yaml

    imports:
      -
        resource: 'EXT:wn_ai_bridge/Configuration/Routes/RouterEnhancer.yaml'

It maps:

..  code-block:: yaml
    :caption: EXT:wn_ai_bridge/Configuration/Routes/RouterEnhancer.yaml

    routeEnhancers:
      PageTypeSuffix:
        type: PageType
        map:
          llms.txt: 1699
          .well-known/llms.txt: 1699
          .md: 1701

Flush the caches afterwards.

..  note::

    The suffix works on the detail views of a plugin as well, as long as their
    own route enhancer is configured for the site: append ``.md`` to the detail
    URL and the Markdown is made from that record, not from the list view that
    shares the page with it. Detail views reached through a query parameter
    instead of an enhancer work the same way.

..  _configuration-pagetypes:

Page types
==========

The extension ships the two page types via its static TypoScript; nothing has
to be added by hand. They are documented here for reference and for the rare
case that a distribution needs to override them.

..  code-block:: typoscript
    :caption: EXT:wn_ai_bridge/Configuration/TypoScript/setup.typoscript

    llmstxt = PAGE
    llmstxt {
        typeNum = 1699

        config {
            disableAllHeaderCode = 1
            additionalHeaders.10 {
                header = Content-Type: text/plain; charset=utf-8
                replace = 1
            }
            xhtml_cleaning = 0
            admPanel = 0
            debug = 0
            no_cache = 0
        }

        10 = USER
        10 {
            userFunc = WebNomads\WnAiBridge\Controller\LlmsTxtController->generateAction
        }
    }

..  code-block:: typoscript
    :caption: EXT:wn_ai_bridge/Configuration/TypoScript/markdown.typoscript

    markdown_page = PAGE
    markdown_page {
        typeNum = 1701

        config {
            disableAllHeaderCode = 1
            additionalHeaders.10 {
                header = Content-Type: text/plain; charset=utf-8
                replace = 1
            }
            xhtml_cleaning = 0
            admPanel = 0
            debug = 0
            no_cache = 0
            disableCharsetHeader = 1
            forceAbsoluteUrls = 1
        }

        10 = USER
        10 {
            userFunc = WebNomads\WnAiBridge\Controller\LlmsTxtController->renderPageAsMarkdown
        }
    }

The setup also adds an ``alternate`` link to the page head pointing at
:file:`/llms.txt`.

..  _configuration-excluding-pages:

Excluding pages
===============

The navigation in ``llms.txt`` follows TYPO3's own visibility rules, so the
standard page properties are the way to keep a page out of it:

*   :guilabel:`Hide in menus` excludes the page from the ``llms.txt``
    navigation
*   :guilabel:`Access` restrictions keep it out for anonymous requests
*   Hidden pages are excluded entirely
*   A ``noindex`` robots meta tag on the page excludes it

The same applies to content elements in the Markdown output: hidden elements
and elements outside the standard column positions are not rendered.

..  _configuration-module-access:

Backend module access
=====================

The modules are registered with ``access: user``, so backend groups control who
sees them. Use these identifiers when configuring
:guilabel:`Module permissions` on a backend user group:

..  list-table::
    :header-rows: 1
    :widths: 30 34 36

    * - Module
      - Identifier
      - Alias (former identifier)
    * - :guilabel:`AI Bridge` (group)
      - ``wn_ai_bridge``
      - —
    * - :guilabel:`Enquiries`
      - ``wn_ai_bridge_enquiries``
      - ``wn_ai_bridge_log``
    * - :guilabel:`Answers`
      - ``wn_ai_bridge_answers``
      - ``wn_ai_bridge_corrections``
    * - :guilabel:`Bot Access Log`
      - ``wn_ai_bridge_botaccess``
      - —

..  note::

    :guilabel:`Enquiries` was named :guilabel:`AI Assistant Log` until version
    1.21.0, and :guilabel:`Answers` was named :guilabel:`Corrections` until
    1.18.0. Both kept their old route identifier as an alias, so backend group
    permissions and bookmarks created under the former names keep working
    without migration.

Access alone is not sufficient for the first two: without a subscription
covering the corresponding feature they are hidden from the module menu
regardless of permissions — see :ref:`administrator-subscription`.
