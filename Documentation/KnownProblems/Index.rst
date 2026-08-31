..  include:: /Includes.rst.txt

..  _known-problems:

==============
Known problems
==============

..  contents::
    :local:
    :depth: 2

..  _known-problems-markdown:

Markdown conversion
===================

Markdown is a semantic format, so anything that only exists visually is
deliberately lost. The following are limitations, not defects:

*   **Multi-column layouts** are flattened; the columns are rendered one after
    another.
*   **Interactive elements** — forms, JavaScript widgets, sliders — do not
    convert meaningfully.
*   **Custom CSS classes and styling** are not preserved.
*   **Media galleries** are reduced to their images and captions.
*   **Custom content elements without semantic HTML** convert poorly, because
    there is nothing in the markup to map onto.

Genuine rough edges:

..  list-table::
    :header-rows: 1
    :widths: 45 55

    * - Issue
      - Workaround
    * - Nested blockquotes may not render correctly
      - Avoid deeply nested blockquote structures
    * - Complex table formatting is simplified or lost
      - Use simple table structures in content that will be converted
    * - Very large pages can hit the memory limit during conversion
      - Split the page, or raise ``memory_limit`` for the ``.md`` page type

..  _known-problems-navigation:

Navigation structure
====================

*   Sites with very deep hierarchies (six levels and more) produce long
    ``llms.txt`` files that are less useful to a model, not more. Lower
    :confval:`llmsTxtMaxDepth`.
*   Navigation building scales with the size of the page tree, so generation
    time and memory use grow with it.
*   Pages hidden in menus, disabled pages, pages outside their publication
    window and access-restricted pages are excluded, subpages included — see
    :ref:`configuration-excluding-pages`. This is intended, but it does surprise
    people who expect ``llms.txt`` to list everything. A ``noindex`` robots tag
    is not evaluated.

..  _known-problems-server:

Web server configuration
========================

*   Some web server configurations block :file:`.well-known` directories.
    Apache may need an :file:`.htaccess` rule, Nginx a ``location`` block, and
    some shared hosting blocks hidden directories outright. :file:`/llms.txt`
    works in those cases and is served by the same page type.
*   The ``Content-Type: text/plain`` header set by the page types can be
    overwritten by a reverse proxy or a misconfigured server.

..  _known-problems-compatibility:

Compatibility
=============

..  list-table::
    :header-rows: 1
    :widths: 45 55

    * - Issue
      - What to do
    * - A third-party extension interferes with content rendering, so content is
        missing from the Markdown output
      - Test with the extension disabled to isolate the conflict
    * - Custom TypoScript affects the page type rendering, producing wrong MIME
        types or extra headers
      - Make sure the extension's TypoScript is included after your own

..  _known-problems-performance:

Performance
===========

..  list-table::
    :header-rows: 1
    :widths: 45 55

    * - Issue
      - What to do
    * - Slow ``llms.txt`` generation on large sites (1000+ pages)
      - Lower :confval:`llmsTxtMaxDepth`
    * - Repeated Markdown conversion of the same crawled pages
      - Enable :confval:`cacheMarkdown`
    * - Memory use scales with page content size
      - Split very large pages

..  _known-problems-planned:

Planned improvements
====================

*   **PSR-14 events** so that the generation and conversion can be extended
    without replacing services.
*   **Selective rendering** — configuration to exclude specific content element
    types from the Markdown conversion.
*   **Chunked processing** for very large pages, to bound memory use.
*   **Caching layer** for navigation structures.
*   **Additional llms.txt fields** as the specification evolves.

..  _known-problems-reporting:

Reporting an issue
==================

Issues belong in the
`issue tracker <https://github.com/web-nomads/wn-ai-bridge/issues>`__. Please
include:

*   TYPO3 version, PHP version and extension version
*   the approximate number of pages, if the problem is about performance
*   the exact error message, and the relevant entry from the TYPO3 log
*   the steps to reproduce
*   relevant server configuration — reverse proxy, CDN, blocked directories

For problems with the assistant, the output of
``vendor/bin/typo3 ai-bridge:check-subscription`` is usually the first useful
piece of information.
