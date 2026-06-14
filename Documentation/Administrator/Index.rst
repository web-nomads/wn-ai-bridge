.. include:: /Includes.rst.txt

=============
Administrator
=============

Installation
============

The extension can be installed using Composer (recommended). Legacy mode is not tested.

Composer Installation
---------------------

.. code-block:: bash

   composer require web-nomads/t3-ai-bridge


Configuration
=============

After installation, the extension works out of the box with sensible defaults. However, you can customize its behavior through TypoScript configuration.

Basic TypoScript Setup
----------------------

The extension automatically includes its TypoScript configuration. No manual setup is required for basic functionality.

Site Configuration
------------------

The extension automatically uses your site's configuration from ``config/sites/[site]/config.yaml``. If you want to customize the routing, you can add custom route enhancers:

.. code-block:: yaml

   imports:
     -
       resource: 'EXT:t3_ai_bridge/Configuration/Routes/RouterEnhancer.yaml'

This import adds the following route enhancers:

* ``llms.txt`` for the llms.txt specification (typeNum 1699)
* ``.md`` suffix for Markdown content (typeNum 1701)

Accessing Generated Content
===========================

After installation, the following URLs become available:

llms.txt Files
--------------

* **https://yoursite.com/?type=1699** - Default access via typeNum
* **https://yoursite.com/llms.txt** - Alternative direct access (if route enhancer is configured)


Markdown Content
----------------

* **https://yoursite.com/any-page.md** - Markdown version of any TYPO3 page

Testing the Installation
========================

1. **Test llms.txt generation:**
   Visit ``https://yoursite.com/llms.txt`` to verify the llms.txt is accessible.

   or

   Visit ``https://yoursite.com/.well-known/llms.txt``

2. **Test Markdown conversion:**

   Visit any page on your site with ``.md`` appended (e.g., ``https://yoursite.com/about.md``) to see the Markdown version.

3. **Check content structure:**

   The llms.txt file should include your site's title, description, and navigation structure.

Troubleshooting
===============

**llms.txt file not accessible**
  Ensure your web server is configured to serve files from the ``.well-known`` directory. Some servers block access to hidden directories by default.

**Markdown conversion fails**
  Check that the ``league/html-to-markdown`` package is properly installed via Composer.

**Navigation structure missing**
  Verify that your pages are not hidden and have proper navigation settings in the page properties.

**Content not rendering**
  Ensure content elements are not hidden and are in standard column positions (colPos).

Performance Considerations
=========================

* The extension uses TYPO3's caching mechanisms where possible
* llms.txt generation processes the entire site navigation, so performance depends on site size
* Markdown conversion processes all content elements on a page
* For large sites, consider implementing additional caching strategies if needed
