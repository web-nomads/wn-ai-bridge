.. include:: /Includes.rst.txt

==============
Known Problems
==============

Current Limitations
===================

Markdown Conversion
-------------------

**Complex HTML Structures**
  Some complex HTML structures may not convert perfectly to Markdown. This particularly affects:

  * Multi-column layouts
  * Nested tables with complex formatting
  * Custom HTML elements with specific styling
  * Interactive elements (JavaScript widgets, forms)

**Content Element Limitations**
  Certain TYPO3 content elements may not convert optimally:

  * File collections with custom rendering
  * Media galleries with specific layouts
  * Custom content elements without semantic HTML

**Large Page Content**
  Pages with very large amounts of content may experience:

  * Memory limitations during HTML-to-Markdown conversion
  * Slower response times for .md requests
  * Potential timeouts on resource-constrained servers

Navigation Structure
--------------------

**Deep Navigation Hierarchies**
  Sites with very deep page hierarchies (6+ levels) may experience:

  * Performance impacts during navigation building
  * Large llms.txt files that may be difficult for AI systems to process
  * Memory usage scaling with site complexity

**Hidden Page Handling**
  The extension respects TYPO3's page visibility settings, but:

  * Pages hidden in navigation are excluded from llms.txt
  * Access-restricted pages may not appear in navigation structure
  * Workspace/versioning states may affect page inclusion

Web Server Configuration
------------------------

**.well-known Directory Access**
  Some web server configurations may block access to ``.well-known`` directories:

  * Apache servers may require specific .htaccess rules
  * Nginx servers may need location block configuration
  * Some shared hosting providers block hidden directory access

**MIME Type Handling**
  Text/plain MIME type for .md and llms.txt files may not be properly configured on all servers.

Known Issues
============

HTML-to-Markdown Edge Cases
----------------------------

**Issue:** Nested blockquotes may not render correctly

  **Workaround:** Avoid deeply nested blockquote structures in content elements

**Issue:** Table formatting may be simplified or lost

  **Workaround:** Use simple table structures for content that will be converted to Markdown

**Issue:** Custom CSS classes and styling are not preserved

  **Expected Behavior:** Markdown is a semantic format - visual styling is intentionally simplified

Performance Issues
------------------

**Issue:** Large sites (1000+ pages) may experience slow llms.txt generation

  **Workaround:** Reduce the ``maxDepth`` setting in TypoScript configuration

  **Solution:** Consider implementing page-level caching for navigation structures

**Issue:** Memory usage scales with page content size

  **Workaround:** Monitor memory limits and consider splitting very large pages

  **Solution:** Implement chunked processing for extremely large content

Compatibility Issues
--------------------

**Issue:** Some third-party extensions may interfere with content rendering

  **Symptoms:** Missing content in Markdown output or errors during generation

  **Workaround:** Test with third-party extensions disabled to isolate conflicts

**Issue:** Custom TypoScript configurations may affect page type rendering

  **Symptoms:** Incorrect MIME types or additional headers in output

  **Solution:** Ensure the extension's TypoScript is loaded after custom configurations

Planned Improvements
====================

Performance Enhancements
-------------------------

* **Caching Layer:** Implementation of dedicated caching for navigation structures and frequently accessed content
* **Chunked Processing:** Support for processing very large pages in chunks to reduce memory usage
* **Selective Rendering:** Options to exclude specific content types from Markdown conversion

Feature Additions
------------------

* **Custom Content Filters:** Configuration options to exclude specific content element types
* **Enhanced Metadata:** Support for additional llms.txt specification fields as the standard evolves
* **Multi-language Support:** Better handling of multi-language sites and language-specific llms.txt files

Developer Experience
--------------------

* **PSR-14 Events:** Addition of events for custom processing hooks
* **Better Error Handling:** More detailed error messages and logging
* **Development Tools:** CLI commands for testing and debugging llms.txt generation

Workarounds
===========

Large Site Performance
----------------------

For sites with performance issues:

.. code-block:: typoscript

   llmstxt.settings {
       # Reduce navigation depth
       maxDepth = 2

       # Consider adding custom page exclusion logic
       # (requires custom extension development)
   }

Custom Content Filtering
-------------------------

To exclude specific content types from Markdown conversion, extend the controller:

.. code-block:: php

   <?php
   // Custom implementation to filter content elements
   // See Developer documentation for detailed examples

Reporting Issues
================

When reporting issues, please include:

* TYPO3 version
* PHP version
* Extension version
* Site size (approximate number of pages)
* Specific error messages or unexpected behavior
* Steps to reproduce the issue
* Server configuration details (if relevant)

Report issues on the project's issue tracker or contact the development team directly.
