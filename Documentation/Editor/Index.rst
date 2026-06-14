.. include:: /Includes.rst.txt

======
Editor
======

Working with LLMS TXT Content
==============================

As an editor, you don't need to directly interact with the LLMS TXT Generator extension in most cases. The extension automatically generates content based on your existing TYPO3 pages and content.

Understanding Generated Content
===============================

The extension creates two types of content from your TYPO3 pages:

llms.txt Files
--------------

The extension automatically generates an llms.txt file that includes:

* **Site title and description** - Taken from your site's root page
* **Navigation structure** - Your page hierarchy and menu structure
* **Topics and keywords** - Configured by administrators
* **Contact information** - Configured by administrators

This file helps AI systems understand your site's structure and content.

Markdown Pages
--------------

Any page on your website can be viewed in Markdown format by adding ``.md`` to the URL. For example:

* ``/about`` becomes ``/about.md``
* ``/services/consulting`` becomes ``/services/consulting.md``

The Markdown version includes:

* **Page title** - From the page properties
* **Page description** - From the page properties
* **All content elements** - Headers, text, images, etc. converted to Markdown format

Best Practices for Content
===========================

To ensure your content works well with AI systems and the LLMS TXT Generator:

Page Properties
---------------

**Use descriptive titles**
  Clear, descriptive page titles help AI systems understand your content's purpose.

**Add page descriptions**
  The description field in page properties provides context for AI systems and appears in both llms.txt and Markdown output.

**Structure your navigation logically**
  Your site's navigation structure is included in the llms.txt file, so logical organization helps AI systems understand your content hierarchy.

Content Elements
----------------

**Use semantic headers**
  Use header elements (H1, H2, H3, etc.) to structure your content logically.

**Write clear, descriptive text**
  Well-written content is more useful for AI systems and human readers alike.

**Add alt text to images**
  Image alternative text is included in Markdown conversion and helps AI systems understand visual content.

**Organize content logically**
  Content elements are rendered in their page order, so logical organization improves the Markdown output.

Viewing Generated Content
=========================

As an editor, you can preview the generated content to understand how AI systems will see your pages:

**To view the llms.txt file:**
  Visit ``/.well-known/llms.txt`` on your website's frontend.

**To view a page in Markdown:**
  Add ``.md`` to any page URL to see the Markdown version.

**To check navigation structure:**
  The llms.txt file includes your site's navigation, which reflects your page tree structure.

Content that Works Well
=======================

The following types of content work particularly well with the LLMS TXT Generator:

* **Articles and blog posts** - Convert cleanly to Markdown with proper heading structure
* **Documentation pages** - Structured content with headers and lists
* **Service descriptions** - Clear, descriptive content about what you offer
* **About pages** - Company or organization information
* **Contact information** - Structured contact details

Content Limitations
===================

Some content types may not convert perfectly to Markdown:

* **Complex layouts** - Multi-column layouts may not preserve exact visual structure
* **Interactive elements** - Forms, JavaScript widgets, etc. may not convert meaningfully
* **Custom styling** - Visual formatting is simplified in Markdown
* **Media galleries** - Complex image arrangements may be simplified

This is normal and expected - the Markdown format is designed to be a simplified, semantic representation of your content that focuses on meaning rather than visual presentation.
