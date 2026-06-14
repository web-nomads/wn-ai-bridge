.. include:: /Includes.rst.txt

=============
Configuration
=============


Page Type Configuration
=======================

The extension defines two page types for serving content:

llms.txt Page Type
------------------

.. code-block:: typoscript

   llmstxt = PAGE
   llmstxt {
       typeNum = 1699

       config {
           disableAllHeaderCode = 1
           additionalHeaders.10.header = Content-Type: text/plain; charset=utf-8
           additionalHeaders.10.replace = 1
           xhtml_cleaning = 0
           admPanel = 0
           debug = 0
           no_cache = 1
       }

       10 = USER
       10 {
           userFunc = WebNomads\WnAiBridge\Controller\LlmsTxtController->generateAction
       }
   }

Markdown Page Type
------------------

.. code-block:: typoscript

   markdown_page = PAGE
   markdown_page {
       typeNum = 1701

       config {
           disableAllHeaderCode = 1
           additionalHeaders.10.header = Content-Type: text/plain; charset=utf-8
           additionalHeaders.10.replace = 1
           xhtml_cleaning = 0
           admPanel = 0
           debug = 0
           no_cache = 1
           forceAbsoluteUrls = 1
       }

       10 = USER
       10 {
           userFunc = WebNomads\WnAiBridge\Controller\LlmsTxtController->renderPageAsMarkdown
       }
   }

Route Configuration
===================

The extension includes route enhancers to create user-friendly URLs:

.. code-block:: yaml

   # EXT:wn_ai_bridge/Configuration/Routes/RouterEnhancer.yaml
   routeEnhancers:
     PageTypeSuffix:
       type: PageType
       map:
         .md: 1701
         llms.txt: 1699

To use these routes, include them in your site configuration:

.. code-block:: yaml

   # config/sites/main/config.yaml
   imports:
     -
       resource: 'EXT:wn_ai_bridge/Configuration/Routes/RouterEnhancer.yaml'

Advanced Configuration
======================

Custom Navigation Filtering
----------------------------

If you need to exclude certain pages from the llms.txt navigation structure, you can extend the NavigationBuilder service or use standard TYPO3 page properties:

* Set "Hide in navigation" to exclude pages from llms.txt
* Use "Access" settings to control visibility
* Set pages to hidden to exclude them entirely
* Set no index meta tag on pages to prevent inclusion in llms.txt

Custom Content Processing
--------------------------

The extension uses TYPO3's standard content rendering. To customize how content appears in Markdown:

* Use standard TYPO3 content element configuration
* Customize TypoScript rendering for specific content types
* The extension respects all standard TYPO3 content visibility settings
