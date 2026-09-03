# AI Bridge for TYPO3

[![TYPO3 13 & 14](https://img.shields.io/badge/TYPO3-13%20%7C%2014-orange.svg)](https://get.typo3.org/)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-blue.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

Makes a TYPO3 site readable for AI systems, and gives its visitors a search
assistant that answers from the site's own content.

Two halves that work independently:

- **Machine-readable content** — an `llms.txt` file following the
  [llmstxt.org specification](https://llmstxt.org/), an optional `llms-full.txt`
  carrying the content of every page in one document, and a Markdown
  representation of every page. Free, no key needed.
- **AI search assistant** — a chat widget that answers visitor questions from
  your search index, with links to the pages it used. Requires a subscription
  key.

## Requirements

| | |
|---|---|
| TYPO3 | 13.4 LTS or 14.x |
| PHP | 8.2, 8.3 or 8.4 |
| PHP extensions | `sodium` (subscription key verification) |
| Optional | `ke_search` or `indexed_search` as the assistant's index |

## Installation

```bash
composer require web-nomads/wn-ai-bridge
```

Then activate the extension and flush caches.

### Nice URLs

Without route enhancers the endpoints are reachable by page type only. To get
readable URLs, import the shipped route configuration:

```yaml
# config/sites/<identifier>/config.yaml
imports:
  -
    resource: 'EXT:wn_ai_bridge/Configuration/Routes/RouterEnhancer.yaml'
```

| | Without enhancer | With enhancer |
|---|---|---|
| llms.txt | `/?type=1699` | `/.well-known/llms.txt` and `/llms.txt` |
| llms-full.txt | `/?type=1702` | `/.well-known/llms-full.txt` and `/llms-full.txt` |
| Markdown | `/?type=1701` | append `.md` to any page URL |

`llms-full.txt` is off by default; switch on `llmsFullTxt` in the extension
configuration to serve it.

So `https://example.com/about` also exists as `https://example.com/about.md`.

## What llms.txt is for

`llms.txt` sits at a well-known location and tells language models what a site
is about: a short description, its structure, and links to machine-readable
versions of the content. The place is the same idea as `robots.txt`, the purpose
is not — it describes content rather than restricting access. This extension
generates it from your actual page tree, so it stays correct without anyone
maintaining it by hand.

Configure the metadata (topics, contact, description) per site on the
**AI Bridge** tab of the site configuration.

## AI search assistant

A floating chat widget. Switch it on in the extension configuration
(`assistantEnabled`) and per site in the site configuration.

**Search-only** (no API key) returns ranked matching pages as suggestions with
links. Fast, free, and nothing leaves your server.

**Hybrid** (with an LLM API key) additionally lets the model compose a short
answer from the retrieved pages and cite them. Any failure — quota, timeout,
malformed response — falls back to search-only rather than showing an error.

The API key, the temperature and the agent instructions are set **per site**, on
the **AI Assistant** tab of the site configuration: they are answers a website
gives, not an installation, so two sites in one TYPO3 can bill to different
accounts and address their visitors differently. Installations upgrading from
1.28 or earlier find them in the extension configuration; the upgrade wizard
*"AI Bridge: move the assistant's API key, temperature and instructions into the
site configuration"* copies them into every site. Until it has run, the old
values keep being used.

The assistant reads `ke_search` and `indexed_search` when they are installed,
and always keeps a dependency-free `pages`/`tt_content` fallback so it returns
something even without a search index. It only ever answers with pages of the
site the question was asked on — the search indexes hold the whole installation
and know nothing about sites, so the boundary is drawn on the results.

### Backend modules

| Module | What it is for |
|---|---|
| **Enquiries** | Every question asked, the answer given, the provider, token usage and cost. Filterable |
| **Answers** | Question/answer pairs the assistant uses as its own knowledge |
| **Bot Access Log** | Which AI crawlers requested `llms.txt` and the Markdown endpoints |

**Answers** is the local learning source. An entry is played back verbatim when
a new question matches it in meaning — term overlap plus string similarity, not
exact wording. Weaker matches are handed to the model as binding hints. Entries
come from three places: written by an editor, taken over from a logged answer in
**Enquiries**, or captured from a correction a visitor made in the chat, which
arrives as "pending" and is only used once approved.

## Subscription

The assistant and its two modules are subscription features. Enter the key in
the **Subscription key** field of the extension configuration. The key is
encrypted and signed; it carries the domains it is valid for, an expiry date and
the enabled features.

| Functions | Needs a key |
|---|---|
| Chat widget, Enquiries, Answers | yes |
| llms.txt, Markdown endpoints, Bot Access Log | no |

**Important:**  
Without a valid key the widget stays hidden and the two modules disappear.
Everything else keeps working.

Order your subscription key:  
DE: [https://www.marcelmarty.ch/#extensions](https://www.marcelmarty.ch/#extensions)  
EN: [https://www.marcelmarty.ch/en/#extensions](https://www.marcelmarty.ch/en/#extensions)  

Order your **14 days free trial key** here:  
DE: [https://www.marcelmarty.ch/ai-bridge-trial](https://www.marcelmarty.ch/ai-bridge-trial)  
EN: [https://www.marcelmarty.ch/en/ai-bridge-trial](https://www.marcelmarty.ch/en/ai-bridge-trial)  

Once the trial key has expired, nothing will be automatically renewed or charged

### What the licence check sends

Once a day the extension asks the issuing server whether the subscription is
still active, sending the subscription id, this installation's hostname and a
random nonce. No visitor data is involved — no IP addresses, no questions, no
page content. The signed answer is what carries a renewal to the installation,
so a renewed subscription takes effect without anyone pasting a new key, and a
revoked one stops working without waiting for its expiry date.

The same answer carries the domains the subscription currently covers. A licence
can therefore grow: ask the issuer to add a domain, and the site running on it
works within a day — the key in your configuration keeps the list it was issued
with and does not have to be replaced. This is what makes a second website in
the same TYPO3 possible without a second key.

An unreachable server changes nothing: the date and the domains inside the key
decide, and only an explicitly signed "revoked" switches the features off. A
server that stays silent can therefore never extend a licence either.

Leave `subscriptionKey` empty and nothing is ever sent.

See [Documentation/Administrator](Documentation/Administrator/Index.rst) for the
full description, including what is reported when an installation looks
manipulated.

## Configuration

Extension configuration (Admin Tools → Settings → Extension Configuration)
covers the assistant, the LLM provider, rate limiting and the subscription key.
Per-site settings live on the **AI Bridge** and **AI Search Assistant** tabs of
the site configuration.

Two things worth setting before going live with the assistant:

- Enable the rate limiter (`rateLimiterEnabled`). The assistant endpoint is
  reachable without authentication and every request can cost money.
- Set a spending limit in your LLM provider account as an independent second
  net.

## Documentation

The full manual is rendered at
[docs.typo3.org](https://docs.typo3.org/p/web-nomads/wn-ai-bridge/main/en-us/),
and its source lives in [`Documentation/`](Documentation/).

## Development

```bash
composer install

composer test        # unit tests
composer stan        # PHPStan level 6
composer cs:check    # coding standards, --dry-run
composer cs:fix      # apply them
composer ci          # all of the above

composer release     # build the TER archive
```

`composer release` writes `wn_ai_bridge_<version>.zip` next to the extension
folder and refuses to build if the version in `ext_emconf.php` and
`composer.json` disagree, if `ext_emconf.php` would not land at the archive
root, or if anything generated would be packed.

## Contributing

Issues and pull requests are welcome at
[github.com/web-nomads/wn-ai-bridge](https://github.com/web-nomads/wn-ai-bridge/issues).

For a pull request: follow the TYPO3 coding standards (`composer cs:fix`), add
tests for behaviour you change, and keep `composer ci` green.

## Credits

This extension started as a fork of
[web-vision/ai-llms-txt](https://github.com/web-vision/ai-llms-txt) by
web-vision, which provides the llms.txt generation according to the
[llmstxt.org](https://llmstxt.org/) specification. The AI search assistant, the
Markdown endpoints and the subscription handling were added here.

## Licence

GPL-2.0-or-later, the same licence as the original — see [LICENSE](LICENSE).
