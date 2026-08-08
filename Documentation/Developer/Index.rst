..  include:: /Includes.rst.txt

..  _developer:

=========
Developer
=========

..  contents::
    :local:
    :depth: 2

..  _developer-architecture:

Architecture
============

The extension is service-oriented and uses constructor injection throughout;
:file:`Configuration/Services.yaml` autowires everything under
:php:`WebNomads\WnAiBridge\`. Value objects are excluded from the container on
purpose — they are constructed from data, never injected.

..  list-table::
    :header-rows: 1
    :widths: 26 74

    * - Namespace
      - Responsibility
    * - :php:`Controller`
      - Entry points. :php:`LlmsTxtController` serves the two page types via
        TypoScript ``USER`` objects, :php:`AssistantWidgetController` renders
        the chat widget, :php:`Controller\Backend\*` are the three backend
        modules
    * - :php:`Middleware`
      - :php:`AssistantRequestMiddleware` answers ``/wn-ai-bridge/ask`` with
        JSON, :php:`RateLimiterMiddleware` throttles, and
        :php:`BotAccessLogMiddleware` records crawler accesses
    * - :php:`Service`
      - The application services: llms.txt generation, Markdown conversion,
        HTML cleanup, the assistant, the learning source, cost calculation,
        configuration access
    * - :php:`Search`
      - The search backends behind :php:`SearchProviderInterface` and the
        :php:`SearchService` that merges their results
    * - :php:`Llm`
      - The provider abstraction :php:`LlmClientInterface` and its Anthropic
        implementation
    * - :php:`Subscription`
      - Key decoding, signature verification, the daily online check and tamper
        detection
    * - :php:`Domain\Model` / :php:`Domain\Repository`
      - The log, learning and bot access records and their database access
    * - :php:`Dto`
      - :php:`SearchResultItem` and :php:`AssistantResponse`

..  _developer-request-flow:

Request flow
------------

The frontend middlewares are registered in
:file:`Configuration/RequestMiddlewares.php` and ordered deliberately:

#.  ``bot-access-log`` runs after site resolution and wraps the rest of the
    stack, so it can read the final response status. It never blocks.
#.  ``rate-limiter`` runs after site resolution — so the normalised parameters
    and the resolved reverse-proxy IP are available — but before the page
    resolver, so a throttled request is rejected as cheaply as possible.
#.  ``assistant`` runs right after the rate limiter, so its requests are
    throttled too, and before the page resolver, since it answers with JSON and
    must not go through page rendering.

..  _developer-database:

Database tables
===============

..  list-table::
    :header-rows: 1
    :widths: 38 62

    * - Table
      - Contents
    * - ``tx_wnaibridge_assistant_log``
      - One row per turn: question, answer, mode, provider, model, token usage,
        conversation id, visitor information
    * - ``tx_wnaibridge_assistant_learning``
      - The curated answers: topic, the answer objected to, the correction,
        keywords, status, origin, site identifier and language
    * - ``tx_wnaibridge_bot_access``
      - Bot requests: type, method, path, status, bot name, user agent, IP,
        referer

The tables have no TCA. The backend modules are plain controllers reading and
writing through their repositories, not Extbase or list module views.

..  _developer-extending:

Extension points
================

..  _developer-search-provider:

Adding a search backend
-----------------------

Implement :php:`WebNomads\WnAiBridge\Search\SearchProviderInterface` and tag the
service with ``wn_ai_bridge.search_provider``. The :php:`SearchService` receives
all providers as a tagged iterator and merges their results by rank,
de-duplicating URLs.

..  code-block:: php
    :caption: EXT:my_ext/Classes/Search/SolrSearchProvider.php

    <?php

    declare(strict_types=1);

    namespace MyVendor\MyExt\Search;

    use WebNomads\WnAiBridge\Dto\SearchResultItem;
    use WebNomads\WnAiBridge\Search\SearchProviderInterface;

    final class SolrSearchProvider implements SearchProviderInterface
    {
        public function getKey(): string
        {
            return 'solr';
        }

        public function isAvailable(): bool
        {
            // Report false when the backend is not installed; the provider is
            // then skipped instead of failing the whole search.
            return class_exists(\ApacheSolrForTypo3\Solr\Search::class);
        }

        /**
         * @return list<SearchResultItem>
         */
        public function search(
            string $query,
            int $limit,
            int $languageId,
            int $rootPageId = 0,
        ): array {
            // …
            return [];
        }
    }

..  code-block:: yaml
    :caption: EXT:my_ext/Configuration/Services.yaml

    services:
      MyVendor\MyExt\Search\SolrSearchProvider:
        tags:
          - { name: 'wn_ai_bridge.search_provider', priority: 40 }

A higher ``priority`` means the provider is consulted earlier and its hits win
when the same URL is found twice. The shipped providers use ``30``
(``ke_search``), ``20`` (``indexed_search``) and ``10`` (the page content
fallback).

..  important::

    A provider must degrade gracefully. :php:`isAvailable()` is the contract for
    that: return ``false`` when the underlying backend is missing rather than
    letting :php:`search()` throw.

Adding an LLM provider
----------------------

:php:`LlmClientInterface` is bound to :php:`AnthropicClient` by an alias in
:file:`Configuration/Services.yaml`. Implement the interface and override the
alias to swap the provider without touching the assistant service:

..  code-block:: yaml
    :caption: EXT:my_ext/Configuration/Services.yaml

    services:
      WebNomads\WnAiBridge\Llm\LlmClientInterface:
        alias: MyVendor\MyExt\Llm\MyProviderClient

Replacing a service
-------------------

Every other service can be replaced the same way, through the service
container. There are currently no PSR-14 events; see :ref:`known-problems` for
what is planned.

..  _developer-api:

API reference
=============

..  _developer-api-controller:

LlmsTxtController
-----------------

..  php:namespace:: WebNomads\WnAiBridge\Controller

..  php:class:: LlmsTxtController

    The entry points for the two page types. Both are called as TypoScript
    ``USER`` objects, so they follow the ``userFunc`` signature.

    ..  php:method:: generateAction(string $content = '', array $conf = [])

        Generates the llms.txt content for page type 1699.

        :param string $content: Content passed from TypoScript, usually empty
        :param array $conf: Configuration array from TypoScript
        :returns: The generated llms.txt content
        :returntype: string

    ..  php:method:: renderPageAsMarkdown(string $content = '', array $conf = [])

        Renders the current page as Markdown for page type 1701, using TYPO3's
        own frontend rendering and converting the result.

        :param string $content: Content passed from TypoScript, usually empty
        :param array $conf: Configuration array from TypoScript
        :returns: The page content converted to Markdown
        :returntype: string

..  _developer-api-configuration:

ConfigurationService
--------------------

..  php:namespace:: WebNomads\WnAiBridge\Service

..  php:class:: ConfigurationService

    Typed access to the site configuration and the extension configuration.
    It follows the request injection pattern the TYPO3 core uses in
    :php:`ContentObjectRenderer`: the request is set once and all other methods
    read it internally, with a fallback to ``$GLOBALS['TYPO3_REQUEST']``.

    ..  php:method:: setRequest(ServerRequestInterface $request)

        Must be called before the other methods.

        :param ServerRequestInterface $request: The current PSR-7 request

    The remaining methods are accessors for the site settings documented in
    :ref:`configuration-site` — :php:`isEnabled()`, :php:`getMaxDepth()`,
    :php:`getTitleOverride()`, :php:`getDescriptionOverride()`,
    :php:`getKeywords()`, :php:`getContactEmail()`, :php:`getAdditionalInfo()`
    and :php:`getSiteUrl()` — plus :php:`getCurrentPageId()`, which resolves the
    page id from the TSFE on TYPO3 v13 and from the
    ``frontend.page.information`` request attribute on v14.

..  _developer-commands:

Console commands
================

..  list-table::
    :header-rows: 1
    :widths: 38 62

    * - Command
      - Purpose
    * - ``ai-bridge:check-subscription``
      - Verifies the subscription key and refreshes its status with the issuing
        server. Accepts ``--host=`` to check against a specific domain
    * - ``ai-bridge:download-markdown``
      - Downloads pages as Markdown files

..  _developer-testing:

Testing
=======

The test suite is PHPUnit-based and lives in :file:`Tests/Unit`. It covers the
rate limiter, the search query parsing and result merging, bot detection, the
Markdown conversion and HTML cleanup, the assistant service, the learning score
and the whole subscription chain — key codec, token, protocol, effective
validity and tamper detection.

..  code-block:: bash

    composer install
    composer test        # PHPUnit, unit test suite
    composer stan        # PHPStan, level 6
    composer cs:check    # php-cs-fixer, TYPO3 rule set, dry run
    composer cs:fix      # php-cs-fixer, applying the changes
    composer ci          # all three, in the order a pipeline needs them

..  note::

    There is no functional test suite in this repository, and no
    :file:`runTests.sh` — the Composer scripts call the tools directly.

..  _developer-contributing:

Contributing
============

#.  **Follow the TYPO3 coding standards.** The repository ships
    :file:`.php-cs-fixer.dist.php` with the official ``typo3/coding-standards``
    rule set; ``composer cs:fix`` applies it.
#.  **Keep PHPStan clean.** Level 6, with a baseline holding the pre-existing
    findings so that anything new stands out. Do not add to the baseline.
#.  **Write tests** for new behaviour, especially anything touching the
    subscription chain or the search merging.
#.  **Use dependency injection**, constructor injection over service location.
#.  **Type everything** — ``declare(strict_types=1)``, parameter, return and
    property types.
#.  **Document changes.** New settings belong in :ref:`configuration`, and
    every release gets an entry in :file:`CHANGELOG.md`.
