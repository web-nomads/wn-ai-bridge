..  include:: /Includes.rst.txt

..  _administrator:

=============
Administrator
=============

This chapter covers what has to be decided once and then looked after: the
subscription, the exposure of the public endpoint, and the personal data the
extension accumulates. Setting the extension up in the first place is
:ref:`installation`; the individual settings are listed in
:ref:`configuration`.

..  contents::
    :local:
    :depth: 2

..  _administrator-subscription:

Subscription key
================

The AI search assistant is a subscription feature. Its licence key is issued by
the companion extension ``wn_ai_bridgeserver`` and entered under
:guilabel:`Admin Tools > Settings > Extension Configuration > wn_ai_bridge` in
the field :guilabel:`Subscription key` (:confval:`subscriptionKey`).

The key is encrypted and Ed25519-signed and carries the domains it is valid
for, an expiry date and the features it unlocks. Every request validates it
locally, without a network call. Once a day the status is additionally checked
with the issuing server, so a renewal arrives and a revocation takes effect
without waiting for the expiry date — see
:ref:`data-sent-to-the-licence-server`.

What the subscription gates
---------------------------

..  list-table::
    :header-rows: 1
    :widths: 40 20 40

    * - What
      - Feature key
      - Without a valid key
    * - The chat widget and its ``/wn-ai-bridge/ask`` endpoint
      - ``chatbot``
      - The widget is not rendered
    * - The backend module :guilabel:`Enquiries`
      - ``log``
      - Hidden from the module menu
    * - The backend module :guilabel:`Answers` and the local learning source
      - ``corrections``
      - Hidden from the module menu; visitor corrections are not captured

..  note::

    The feature keys ``log`` and ``corrections`` are internal identifiers from
    before the modules were renamed. They are not module names: ``log`` gates
    :guilabel:`Enquiries` and ``corrections`` gates :guilabel:`Answers`. The
    keys are part of already-issued licence keys and are therefore kept as they
    are.

..  note::

    How thoroughly the two modules are taken away depends on the TYPO3 version.
    On v14 a module access gate blocks the module menu and the module routes
    alike. TYPO3 13.4 has no such gates, so the modules are dropped from the
    module menu only — reached through a bookmark or the live search, they
    answer with a "subscription required" screen instead of the module.

**llms.txt, the Markdown export, the** :guilabel:`Bot Access Log` **and the rate
limiter are not part of the subscription** and keep working either way.

Domains
-------

A key only validates on the domains it was issued for. ``*.example.com`` covers
every subdomain; the bare domain has to be listed separately. When the current
host cannot be resolved — on the command line, in the scheduler — the domain
check is skipped so that maintenance tasks keep running.

If the key pair of the issuing server was rolled over, enter the new public key
in :confval:`subscriptionPublicKey`; leaving it empty uses the one bundled with
the extension.

Checking the state
------------------

..  code-block:: bash

    vendor/bin/typo3 ai-bridge:check-subscription

The command reports whether the key was decoded, which domains it covers, when
it expires, which features it unlocks, and it distinguishes the date inside the
key from the authoritative one confirmed by the server. Run it before wondering
why the widget does not appear — it names the reason. The possible reasons are
listed in :ref:`installation-subscription`.

..  _administrator-online-check:

Daily online check
==================

In addition to the offline validation, the extension asks the issuing server
once every 24 hours whether the subscription is still active, so a revoked
subscription stops working without waiting for its expiry date. The server's
answer is signed and carries a nonce this installation generated, so an older
"still active" answer cannot be replayed.

The check never runs inside a visitor request: it is performed in the backend
and on the command line, and the frontend only reads the cached verdict. A slow
or unreachable licence server therefore cannot delay a page.

Only an explicitly signed "revoked" disables anything. An unreachable server, a
malformed answer or a bad signature all count as "unknown" and change nothing.

How a renewal arrives
---------------------

The check also carries the current end date, and that is how a renewal reaches
this installation: the key in the configuration keeps the date it was issued
with, so after a renewal it would be out of date. When a verified answer is
available its date is authoritative; without one, the date inside the key
stands.

An unreachable server can therefore never extend a subscription — but a
subscription that lapsed on the issuing server is switched off even if its key
would still be good for a while.

Because of this, the check may also run from a frontend request while the key
is within 30 days of its end date. Otherwise a renewal would never arrive on a
site with no scheduler that nobody logs into. Outside that window the frontend
still never makes an outgoing request, and the 24-hour cache keeps it to at
most one call per day either way.

On installations nobody logs into, schedule the check daily:

..  code-block:: bash

    vendor/bin/typo3 ai-bridge:check-subscription --host=www.example.com

The check cannot be switched off. It is what carries a renewal to the
installation and what makes a revocation take effect, so disabling it only ever
stopped an installation from following its own subscription. The server address
baked into the key can be overridden with :confval:`subscriptionServerUrl` for
staging setups; a key that carries no address of its own falls back to the
issuing server that ships with the extension.

..  _administrator-server-failure:

When the server does not answer properly
----------------------------------------

A check that fails is shown, not swallowed. Every AI Bridge backend module
carries an error above the subscription state — with the address it tried, in
bold, so a typo in :confval:`subscriptionServerUrl` is visible in the place
where the problem surfaces. ``ai-bridge:check-subscription`` names the same
reason and prints the server it talked to:

..  list-table::
    :header-rows: 1
    :widths: 34 66

    * - Reported
      - Meaning
    * - The issuing server could not be reached
      - No answer at all — connection refused, timeout, DNS or TLS. Check
        whether :confval:`subscriptionServerUrl` is correct and reachable from
        the web server
    * - The issuing server answered with an error
      - Reached, but the answer was not an HTTP 200
    * - The answer could not be verified
      - An answer arrived but is unusable: malformed, for a different
        subscription, a replayed nonce, a stale timestamp, or a signature that
        does not match the verification key

**Nothing is switched off by any of these.** The subscription keeps working
according to the date inside its key. What stops while the failure lasts is the
traffic in both directions: a renewal cannot arrive, and a revocation cannot
take effect.

The message clears only on an answer that verifies — correct signature, the
nonce this installation generated, the right subscription id and a timestamp
within the permitted clock skew. A bare HTTP ``200`` from something that happens
to sit at that address is not enough.

Two states stay silent on purpose:

*   an installation that has simply **not asked yet** — that is every fresh
    installation, and a message there would be noise;
*   a key that is **not valid** in the first place — missing, malformed,
    expired, for another domain. There is nothing to renew or revoke, and the
    state of the key is the message that matters.

Correcting :confval:`subscriptionServerUrl` takes effect immediately: the
address is part of the cache key of the verdict, so a new one is asked at once
rather than after the previous verdict expires.

What is reported
----------------

Alongside the status check, the extension reports licence findings that cannot
be an honest state: a key whose signature does not verify, a key used on a
domain it was not issued for, verification against a key pair other than the
bundled one, and altered bundled keys.

Only the following leaves the installation: the subscription id from the key,
the host, the finding, the extension version and the TYPO3 version. Nothing
about the site, its content or its visitors. The same finding is sent at most
once a day and never from a visitor request.

A missing, expired, malformed or revoked key is **not** reported — those are the
everyday states of an installation whose subscription has lapsed.

..  _administrator-security:

Security
========

Protect the assistant endpoint against abuse and cost overruns
--------------------------------------------------------------

When the assistant runs *with* an LLM API key, every request to the public
``/wn-ai-bridge/ask`` endpoint can trigger a paid call. Out of the box the
endpoint is guarded only by the heuristic bot protection
(:confval:`assistantBotProtection`), which is a deterrent, not authentication.
Before going live:

*   Enable the rate limiter (:confval:`rateLimiterEnabled`) and keep
    :confval:`rateLimiterRequestsPerMinute` low, so a single client cannot flood
    the endpoint with expensive calls. The limiter is off by default so that it
    never interferes with legitimate crawlers on the ``.md`` and ``llms.txt``
    endpoints — enable it deliberately once the assistant is exposed.
*   Configure a hard spending limit or budget alert in the LLM provider account,
    as a second and independent safety net. That one holds even if something
    here is misconfigured.
*   Keep :confval:`assistantMaxTokens` at a sensible value to bound the cost of
    a single answer.

Protected pages stay out of what is published
---------------------------------------------

``llms.txt``, ``llms-full.txt`` and the Markdown export describe the site as a
visitor without a login sees it: disabled pages, pages outside their publication
window and pages behind a frontend group are left out, together with everything
below them. That verdict does not depend on who requested the document, so an
editor fetching :file:`/llms.txt` while logged in cannot put protected pages
into the page cache for everyone.

The assistant judges the same three points per request, so a group-restricted
page appears in the results of a visitor who holds that group and in nobody
else's. See :ref:`assistant-visibility`.

Keep debug output off
---------------------

Keep :confval:`debug` at ``0`` in production. With debug enabled, the ``.md``
endpoint may return internal error details and writes rendered HTML to
:file:`var/log`; both are intended for local troubleshooting only.

..  _administrator-privacy:

Personal data
=============

All three tables the extension writes contain personal data, and all three are
off by default:

..  list-table::
    :header-rows: 1
    :widths: 32 20 48

    * - Table
      - Written when
      - Contains
    * - ``tx_wnaibridge_assistant_log``
      - :confval:`assistantLogging`
      - Question and answer text, IP address, user agent, optionally the
        resolved country
    * - ``tx_wnaibridge_assistant_learning``
      - :confval:`aiAssistantLearning`
      - Question, the answer objected to, the visitor's correction, IP address
    * - ``tx_wnaibridge_bot_access``
      - :confval:`botAccessLogging`
      - Path, user agent, IP address and referer of bot requests

Before enabling any of them:

*   cover them in the site's privacy policy, including the LLM provider as a
    recipient if an API key is configured,
*   define a retention period and actually enforce it — both log modules have a
    :guilabel:`Clear log` action,
*   decide deliberately about :confval:`assistantLogGeoLookup`, which sends
    visitor IP addresses to a third party.

..  _data-sent-to-the-licence-server:

Data sent to the licence server
===============================

Verifying the subscription key means this installation talks to the issuing
server, so it is spelled out here what leaves the site, when, and how to stop
it.

The daily status check
    Once a day — and, in the last 30 days before a key expires, also from a
    frontend request — the extension asks the issuing server whether the
    subscription is still active. It sends:

    *   the subscription id taken from the key,
    *   the hostname of this installation,
    *   a random nonce, used to detect a replayed answer.

    The server answers with the status and the end date, signed. This is what
    carries a renewal to the installation: the key in the configuration keeps
    its original date, the signed answer carries the new one.

    No visitor data is involved — no IP addresses, no questions asked of the
    assistant, no content of the site.

Reports of suspected manipulation
    A finding that cannot be an honest state — a forged signature, a key used on
    a domain it does not cover, a modified extension — is reported to the
    issuing server, which notifies the author. Such a report contains the
    subscription id, the hostname and the kind of finding. A missing or simply
    expired key is not a finding and is never reported.

Switching it off
    Leave :confval:`subscriptionKey` empty. Without a key nothing is sent, and
    the subscription features stay switched off; the llms.txt and Markdown
    endpoints work regardless. There is no setting that keeps the key but stops
    the recurring call: a subscription that does not follow its issuing server
    can neither be renewed nor revoked, which serves nobody.

When the server cannot be reached
    The check fails silently and the date inside the key decides. An unreachable
    server can never extend a subscription — only a signed answer can — and it
    never takes one away either.

..  _administrator-maintenance:

Maintenance
===========

..  important::

    Run ``vendor/bin/typo3 extension:setup`` after every update. The extension
    adds tables and columns over time, and a missing column fails at the moment
    the feature is used, not at the moment of the update.

..  list-table::
    :header-rows: 1
    :widths: 40 60

    * - Task
      - Interval
    * - ``ai-bridge:check-subscription``
      - Daily, on installations nobody logs into
    * - Clearing the enquiry log
      - According to the retention period you defined
    * - Clearing the bot access log
      - Same
    * - Reviewing pending visitor corrections
      - Whenever :confval:`aiAssistantLearning` is enabled

..  _administrator-performance:

Performance
===========

*   ``llms.txt`` generation walks the site navigation, so its cost scales with
    the size of the page tree. :confval:`llmsTxtMaxDepth` is the lever.
*   Markdown conversion renders all content elements of a page.
    :confval:`cacheMarkdown` avoids repeating that work for pages crawlers
    request regularly.
*   The assistant endpoint performs a search and, in hybrid mode, waits for the
    model. The model choice is the dominant factor in the response time the
    visitor experiences — see :ref:`installation-claude`.
