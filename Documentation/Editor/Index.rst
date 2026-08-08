..  include:: /Includes.rst.txt

..  _editor:

========
Editors
========

Most of what this extension does happens without editorial work: ``llms.txt``
and the Markdown versions are generated from the pages that already exist. Two
things do belong to editors, though — writing content that survives the
conversion, and looking after what the AI search assistant tells visitors.

..  contents::
    :local:
    :depth: 2

..  _editor-generated-content:

Generated content
=================

The extension derives two representations from the existing page tree.

llms.txt
--------

One file per site, served at :file:`/.well-known/llms.txt` and :file:`/llms.txt`.
It contains:

*   **Site title and description** — from the site configuration, falling back
    to the root page
*   **Navigation structure** — the page hierarchy down to the configured depth
*   **Topics and contact information** — maintained by administrators in the
    site configuration

Markdown pages
--------------

Any page can be requested as Markdown by appending ``.md`` to its URL:

*   :file:`/about` becomes :file:`/about.md`
*   :file:`/services/consulting` becomes :file:`/services/consulting.md`

The Markdown version contains the page title, the page description and all
content elements — headings, text, lists, links and image alternative texts —
in reading order. It ends with a link back to the rendered page, labelled in the
language of that page ("Web version", "Webversion", "Version web", …).

Previewing
----------

To check what a model sees, request the URLs directly in a browser:

..  list-table::
    :header-rows: 1
    :widths: 45 55

    * - To see
      - Open
    * - The llms.txt of a site
      - :file:`https://example.com/.well-known/llms.txt`
    * - A page as Markdown
      - the page URL with ``.md`` appended

..  _editor-content-guidelines:

Writing content that converts well
==================================

Page properties
---------------

Use descriptive titles
    Page titles carry most of the meaning in ``llms.txt``, where there is no
    layout to provide context.

Fill in the page description
    The description appears in both the ``llms.txt`` entry and the Markdown
    output, and it is what the assistant uses to judge relevance.

Keep the navigation structure logical
    The page hierarchy is reproduced in ``llms.txt`` as-is.

Content elements
----------------

Use real headings
    Heading elements (H1–H3) become Markdown headings and give the document its
    structure. Text styled to look like a heading does not.

Write self-contained text
    The assistant quotes passages out of their page context. Text that only
    makes sense next to the image beside it does not travel well.

Add alternative texts to images
    Image alt text is carried into the Markdown output; the image itself is not
    readable by a model.

Order content deliberately
    Content elements are rendered in their page order.

..  _editor-content-limits:

What does not convert
=====================

Markdown is a semantic format, so visual arrangement is deliberately dropped:

*   **Multi-column layouts** lose their arrangement and are rendered one after
    another
*   **Interactive elements** — forms, JavaScript widgets — do not convert
    meaningfully
*   **Custom styling** is simplified away
*   **Media galleries** are reduced to their images and captions

This is expected. The Markdown version is about meaning, not appearance.

..  _editor-modules:

The backend modules
===================

The module group :guilabel:`AI Bridge` holds three modules. Two of them
involve editorial work.

..  note::

    If you know these modules under different names: :guilabel:`Corrections` is
    now :guilabel:`Answers`, and :guilabel:`AI Assistant Log` is now
    :guilabel:`Enquiries`. Only the names changed; the entries are the same.

..  _editor-enquiries:

Enquiries
---------

Every question a visitor asked the assistant, with the answer it gave. Each
conversation is one collapsible row showing the first question, the date and
the visitor's IP address and hostname; expanding it reveals the follow-up
questions and answers.

The list can be filtered by date range, IP address, provider, mode
(``llm``, ``search``, ``learning``) and free text over question and answer. An
overview above it counts the interactions, splits them into LLM-backed and
search-only, totals the tokens used and estimates the cost.

Read it regularly for two reasons: it shows what visitors actually want to
know, and it is where a wrong answer becomes visible. Each answer carries a
:guilabel:`Define a different answer` button, which opens the
:guilabel:`Answers` module with that question filled in.

..  warning::

    The log stores IP addresses and the full text of every question and answer.
    Treat it as personal data: it needs a retention period, and the
    :guilabel:`Clear log` action is how that gets enforced.

..  _editor-answers:

Answers
-------

The answers the assistant gives for questions it recognises. An active entry
replaces whatever the assistant would have produced on its own and is played
back verbatim as soon as a visitor's question matches it in meaning — so the
visitor does not have to use the wording you wrote. Weaker matches are handed
to the language model as binding hints instead.

An entry has these fields:

..  list-table::
    :header-rows: 1
    :widths: 26 74

    * - Field
      - Meaning
    * - :guilabel:`Question / topic`
      - What the entry is matched against
    * - :guilabel:`Answer`
      - What the assistant says, word for word. Phrase it as a complete,
        self-contained reply
    * - :guilabel:`Keywords`
      - Optional. If left empty, they are derived from the question and the
        answer; additional keywords widen the range of questions that match
    * - :guilabel:`Site`
      - The site identifier the entry applies to. A mismatch here means the
        answer is never used
    * - :guilabel:`Language ID`
      - The site language the entry applies to (``0`` = default language)
    * - :guilabel:`Status`
      - Only :guilabel:`Active` entries are used
    * - :guilabel:`Origin`
      - :guilabel:`Editorial` for entries written in the backend,
        :guilabel:`Visitor` for corrections captured in the chat

Entries reach the module in three ways:

#.  Written directly in the module with :guilabel:`New answer`.
#.  Taken over from a logged answer via :guilabel:`Define a different answer`
    in :guilabel:`Enquiries`. This is the fastest way to fix a bad answer at
    the moment you notice it.
#.  Captured from a correction a visitor made in the chat, if
    ``aiAssistantLearning`` is enabled for that site. These arrive under
    :guilabel:`Visitor corrections awaiting review` with the status
    :guilabel:`Pending review` and are **never used until approved**.

..  tip::

    Review pending corrections before approving them. They are unverified text
    written by anonymous visitors, and approving one puts it in front of every
    future visitor who asks a similar question.

..  _editor-botaccess:

Bot Access Log
--------------

Which bots and crawlers requested ``llms.txt``, the Markdown versions and
normal pages, filterable by date, request type, bot and IP address, with an
:guilabel:`AI crawlers only` switch. It is informational: useful for seeing
whether the machine-readable content is actually being picked up, and by whom.

This module does not require a subscription, but it only records anything while
``botAccessLogging`` is enabled in the extension configuration.
