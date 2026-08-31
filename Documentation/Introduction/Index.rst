..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

..  _what-it-does:

What does it do?
================

AI Bridge covers two things a TYPO3 site needs once language models start
reading it and visitors start expecting to ask rather than browse:

**Machine-readable content**
    An ``llms.txt`` file per site according to the
    `llmstxt.org specification <https://llmstxt.org/>`__, plus a Markdown
    representation of every page under a ``.md`` suffix. Both are generated from
    the existing page tree and content elements — nothing has to be maintained
    twice.

**An on-site AI search assistant**
    A chat widget that answers visitors' questions from the site's own content.
    It runs on the site's search index and, optionally, has a language model
    phrase the result. Everything it says is grounded in pages that exist.

Neither half depends on the other. A site can publish ``llms.txt`` without ever
enabling the assistant.

..  _features:

Feature overview
================

..  list-table::
    :header-rows: 1
    :widths: 32 68

    * - Feature
      - Description
    * - llms.txt generation
      - Served at :file:`/llms.txt` and :file:`/.well-known/llms.txt`, with the
        site title, description, topics, contact address and the navigation
        structure down to a configurable depth
    * - llms-full.txt generation
      - Optional companion document at :file:`/llms-full.txt`: the readable
        content of every page in one file rather than a list of links. Off by
        default
    * - Markdown export
      - Any page URL with ``.md`` appended returns the page as Markdown,
        rendered through TYPO3's own content pipeline
    * - AI search assistant
      - Chat widget with search-only or LLM-backed answers, themeable per site
    * - Search backend aggregation
      - Uses ``ke_search``, ``indexed_search`` and a dependency-free fallback on
        the ``pages`` / ``tt_content`` tables; missing backends are skipped
    * - Curated answers
      - Question/answer pairs the assistant plays back verbatim, maintained in
        the backend
    * - Enquiry log and cost tracking
      - Every question and answer with provider, model, token usage and an
        estimated cost
    * - Bot access log
      - Which crawlers requested ``llms.txt``, the Markdown versions and normal
        pages
    * - Rate limiting and bot protection
      - Guards for the public assistant endpoint
    * - Multi-language
      - Per-language llms.txt texts and assistant texts on each site language;
        backend modules translated into German, French, Italian, Portuguese and
        Spanish

..  _backend-modules:

Backend modules
===============

The extension registers a module group :guilabel:`AI Bridge` with three
submodules:

..  list-table::
    :header-rows: 1
    :widths: 26 46 28

    * - Module
      - Purpose
      - Requires a subscription
    * - :guilabel:`Enquiries`
      - The questions visitors asked and the answers they got, grouped per
        conversation, with statistics and estimated cost
      - Yes
    * - :guilabel:`Answers`
      - The answers the assistant gives for questions it recognises, including
        the corrections visitors made
      - Yes
    * - :guilabel:`Bot Access Log`
      - Accesses by bots and crawlers
      - No

..  note::

    Two of these modules were renamed, in versions 1.18.0 and 1.21.0:
    :guilabel:`Corrections` became :guilabel:`Answers`, and
    :guilabel:`AI Assistant Log` became :guilabel:`Enquiries`. Older
    documentation, screenshots and blog posts may still use the former names.
    Both modules kept their previous route identifiers as aliases, so existing
    backend group permissions and bookmarks continue to work — see
    :ref:`configuration-module-access`.

..  _what-is-llmstxt:

What is llms.txt?
=================

``llms.txt`` is an emerging convention for telling language models what a
website is about and where its content lives, in the same spirit as
:file:`robots.txt` for search engine crawlers. It is a plain Markdown file that
carries:

*   a title and a short description of the site,
*   topics and contact information,
*   a curated list of links into the site's content,
*   pointers to machine-readable versions of that content.

Because it is both human-readable and trivially parseable, it gives a model a
reliable entry point instead of leaving it to guess from rendered HTML.

..  _use-cases:

Use cases
=========

Educational institutions
    Structured access to course catalogues, faculty information and academic
    content for AI-powered educational tools.

Content publishers
    Clear guidance for AI systems accessing articles, documentation and media.

Business websites
    Company information, services and contact details in a form that AI-powered
    business discovery tools can use — and an assistant that answers the
    questions the contact form would otherwise collect.

Documentation sites
    Both halves apply: models reference the documentation more accurately, and
    readers get an assistant that searches it.

..  _requirements:

Requirements
============

..  list-table::
    :header-rows: 1
    :widths: 34 66

    * - Requirement
      - Note
    * - TYPO3 13.4 LTS or 14.x
      - Older versions are not supported
    * - PHP 8.2, 8.3 or 8.4
      - —
    * - PHP extension ``sodium``
      - Needed to verify the subscription key. Part of PHP since 7.2 and
        enabled in almost every distribution
    * - ``league/html-to-markdown`` ^5.1
      - Installed automatically by Composer
    * - ``ke_search`` or ``indexed_search``
      - Optional. The assistant works without either, but answers are
        noticeably better with a real search index
    * - An LLM API key
      - Optional. Without it the assistant runs in search-only mode
    * - A subscription key
      - Required for the chat assistant and the :guilabel:`Enquiries` and
        :guilabel:`Answers` modules. llms.txt, the Markdown export and the
        :guilabel:`Bot Access Log` work without one

Continue with :ref:`installation`.

..  _credits:

Credits
=======

This extension is derived from
`web-vision/ai-llms-txt <https://github.com/web-vision/ai-llms-txt>`__ by
web-vision, and uses
`league/html-to-markdown <https://github.com/thephpleague/html-to-markdown>`__
for the HTML-to-Markdown conversion.
