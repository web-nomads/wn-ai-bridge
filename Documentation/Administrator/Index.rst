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

   composer require web-nomads/wn-ai-bridge


Subscription key
================

The AI search assistant is a subscription feature. Its licence key is issued by
the companion extension ``wn_ai_bridgeserver`` and entered under
:guilabel:`Admin Tools > Settings > Extension Configuration > wn_ai_bridge` in
the field :guilabel:`Subscription-Key`.

The key is encrypted and signed and carries the domains it is valid for, an
expiry date and the features it unlocks. Every request validates it locally,
without a network call. Once a day the status is additionally checked with the
issuing server, so a renewal arrives and a revocation takes effect without
waiting for the expiry date — see :ref:`data-sent-to-the-licence-server`.

What the subscription gates:

* the chat widget on the website and its ``/wn-ai-bridge/ask`` endpoint
  (feature ``chatbot``)
* the backend module :guilabel:`Enquiries` (feature ``log``)
* the backend module :guilabel:`Answers` and the local learning source
  (feature ``corrections``)

Without a valid key those modules disappear from the module menu and the widget
is not rendered. **llms.txt, the Markdown export, the bot access log and the rate
limiter are not part of the subscription** and keep working either way.

A key only validates on the domains it was issued for. ``*.example.com`` covers
every subdomain; the bare domain has to be listed separately. When the current
host cannot be resolved — on the command line, in the scheduler — the domain
check is skipped so maintenance tasks keep running.

If the key pair of the issuing server was rolled over, enter the new public key
in the optional field :guilabel:`Öffentlicher Prüfschlüssel`; leaving it empty
uses the one bundled with the extension.

Daily online check
------------------

In addition to the offline validation, the extension asks the issuing server once
every 24 hours whether the subscription is still active, so a revoked
subscription stops working without waiting for its expiry date. The server's
answer is signed and carries a nonce this installation generated, so an older
"still active" answer cannot be replayed.

The check never runs inside a visitor request: it is performed in the backend and
on the command line, and the frontend only reads the cached verdict. A slow or
unreachable licence server therefore cannot delay a page.

Only an explicitly signed "revoked" disables anything. An unreachable server, a
malformed answer or a bad signature all count as "unknown" and change nothing.

The check also carries the current end date, and that is how a renewal reaches
this installation: the key in the configuration keeps the date it was issued
with, so after a renewal it would be out of date. When a verified answer is
available its date is authoritative; without one, the date inside the key stands.
An unreachable server can therefore never extend a subscription — but a
subscription that lapsed on the issuing server is switched off even if its key
would still be good for a while.

Because of this, the check may also run from a frontend request while the key is
within 30 days of its end date — otherwise a renewal would never arrive on a site
with no scheduler that nobody logs into. Outside that window the frontend still
never makes an outgoing request, and the 24-hour cache keeps it to at most one
call per day either way.

On installations nobody logs into, schedule the check daily:

.. code-block:: bash

   vendor/bin/typo3 ai-bridge:check-subscription --host=www.example.com

The check can be switched off with :guilabel:`Täglicher Online-Check`, and the
server address baked into the key can be overridden with
:guilabel:`Ausstellungsserver` for staging setups.

What is reported
----------------

Alongside the status check, this extension reports licence findings that cannot
be an honest state: a key whose signature does not verify, a key used on a domain
it was not issued for, verification against a key pair other than the bundled
one, and altered bundled keys.

Only the following leaves the installation: the subscription id from the key, the
host, the finding, the extension version and the TYPO3 version. Nothing about the
site, its content or its visitors. The same finding is sent at most once a day
and never from a visitor request.

A missing, expired, malformed or revoked key is **not** reported — those are the
everyday states of an installation whose subscription has lapsed.

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
       resource: 'EXT:wn_ai_bridge/Configuration/Routes/RouterEnhancer.yaml'

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

Security Considerations
=======================

**Protect the AI search assistant against abuse and cost overruns**
  When you enable the AI search assistant *with* an LLM API key
  (``assistantApiKey``), every request to the public ``/wn-ai-bridge/ask``
  endpoint can trigger a paid LLM call. Out of the box the endpoint is guarded
  only by the heuristic bot protection (``assistantBotProtection``), which is a
  deterrent, not authentication. Before going live you should therefore:

  * Enable the rate limiter (``rateLimiterEnabled = 1``) and keep a low
    ``rateLimiterRequestsPerMinute`` so a single client cannot flood the
    endpoint with expensive calls. The rate limiter is off by default so that
    it never interferes with legitimate crawlers on the ``.md`` / ``llms.txt``
    endpoints — enable it deliberately once the assistant is exposed.
  * Configure a hard spending limit / budget alert in your LLM provider account
    as a second, independent safety net.
  * Keep ``assistantMaxTokens`` at a sensible value to bound the cost of a
    single answer.

**Debug output is disabled by default**
  Keep ``debug = 0`` in production. With debug enabled, the ``.md`` endpoint may
  return internal error details and write rendered HTML to ``var/log``; both are
  intended for local troubleshooting only.

Performance Considerations
==========================

* The extension uses TYPO3's caching mechanisms where possible
* llms.txt generation processes the entire site navigation, so performance depends on site size
* Markdown conversion processes all content elements on a page
* For large sites, consider implementing additional caching strategies if needed

.. _data-sent-to-the-licence-server:

Data Sent to the Licence Server
===============================

The subscription features (chat widget, "Enquiries" and "Answers" modules) are
unlocked by the ``subscriptionKey`` in the extension configuration. Verifying
that key means this installation talks to the issuing server, so it is spelled
out here what leaves the site, when, and how to stop it.

**The daily status check**
  Once a day — and, in the last 30 days before a key expires, also from a
  frontend request — the extension asks the issuing server whether the
  subscription is still active. It sends:

  * the subscription id taken from the key,
  * the hostname of this installation,
  * a random nonce, used to detect a replayed answer.

  The server answers with the status and the end date, signed. This is what
  carries a renewal to the installation: the key in the configuration keeps its
  original date, the signed answer carries the new one.

  No visitor data is involved — no IP addresses, no questions asked of the
  assistant, no content of the site.

**Reports of suspected manipulation**
  A finding that cannot be an honest state — a forged signature, a key used on a
  domain it does not cover, a modified extension — is reported to the issuing
  server, which notifies the author. Such a report contains the subscription id,
  the hostname and the kind of finding. A missing or simply expired key is not a
  finding and is never reported.

**Switching it off**
  Leave ``subscriptionKey`` empty. Without a key nothing is sent, and the
  subscription features stay switched off; the llms.txt and Markdown endpoints
  work regardless.

**When the server cannot be reached**
  The check fails silently and the date inside the key decides. An unreachable
  server can never extend a subscription — only a signed answer can — and it
  never takes one away either.
