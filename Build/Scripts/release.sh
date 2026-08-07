#!/usr/bin/env bash
#
# Build a TER-ready zip of this extension.
#
# TER wants the *contents* of the extension folder, with ext_emconf.php at the
# root of the archive, and the version in the filename has to match the one in
# ext_emconf.php. Both are checked here rather than left to the eye.
#
# What this exists for: "zip -r ../ext.zip *" from the extension folder also
# packs whatever the local installation left lying around — compiled DI
# containers, debug page renderings, tool caches. Those are megabytes of
# someone's local site in a public package. The exclusions below are the point
# of the script; the checks afterwards make sure they worked.
#
# Usage: Build/Scripts/release.sh [--keep-going]
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

EXT_KEY="wn_ai_bridge"
KEEP_GOING="${1:-}"

fail() { printf '\033[31m✗ %s\033[0m\n' "$1" >&2; exit 1; }
warn() { printf '\033[33m! %s\033[0m\n' "$1" >&2; }
ok()   { printf '\033[32m✓ %s\033[0m\n' "$1"; }

command -v zip >/dev/null || fail 'zip is not installed.'
command -v php >/dev/null || fail 'php is not on the PATH.'

# --- Version -----------------------------------------------------------------

VERSION=$(php -r '
    $_EXTKEY = "'"$EXT_KEY"'";
    include "ext_emconf.php";
    echo $EM_CONF[$_EXTKEY]["version"] ?? "";
')
[ -n "$VERSION" ] || fail 'No version found in ext_emconf.php.'

COMPOSER_VERSION=$(php -r '
    $c = json_decode(file_get_contents("composer.json"), true);
    echo $c["version"] ?? "";
')

# These two drift apart the moment one of them is bumped by hand, and nothing
# downstream notices until the package is already public.
if [ "$VERSION" != "$COMPOSER_VERSION" ]; then
    fail "Version mismatch: ext_emconf.php says $VERSION, composer.json says $COMPOSER_VERSION."
fi
ok "Version $VERSION (ext_emconf.php and composer.json agree)"

ARCHIVE="../${EXT_KEY}_${VERSION}.zip"

# --- Working tree ------------------------------------------------------------

if git rev-parse --git-dir >/dev/null 2>&1; then
    if [ -n "$(git status --porcelain -- . 2>/dev/null)" ]; then
        warn 'The extension has uncommitted changes — the archive is built from the working tree.'
        if [ "$KEEP_GOING" != '--keep-going' ]; then
            fail 'Commit first, or pass --keep-going to build anyway.'
        fi
    fi
fi

# --- Build -------------------------------------------------------------------

rm -f "$ARCHIVE"

# Everything that is generated, installed or only needed while developing. Kept
# in one place; the check below fails if any of it slips through anyway.
zip -r -q "$ARCHIVE" . \
    -x '.Build/*' \
       'Build/*' \
       'Tests/*' \
       'var/*' \
       '.git/*' \
       '.github/*' \
       'composer.lock' \
       'phpunit.xml' \
       'phpstan.neon' \
       'phpstan-baseline.neon' \
       '.php-cs-fixer.cache' \
       '.php-cs-fixer.dist.php' \
       '.gitattributes' \
       '.gitignore' \
       '*/.DS_Store' \
       '.DS_Store'

[ -f "$ARCHIVE" ] || fail 'No archive was produced.'

# --- Verify ------------------------------------------------------------------

CONTENTS=$(unzip -Z1 "$ARCHIVE")

grep -qx 'ext_emconf.php' <<<"$CONTENTS" \
    || fail 'ext_emconf.php is not at the root of the archive — TER rejects that.'
ok 'ext_emconf.php is at the root'

JUNK=$(grep -E '^(\.Build|Build|Tests|var|\.git|\.github)/|^(composer\.lock|phpunit\.xml|phpstan.*|\.php-cs-fixer.*|\.gitattributes|\.gitignore)$' <<<"$CONTENTS" || true)
if [ -n "$JUNK" ]; then
    printf '%s\n' "$JUNK" >&2
    fail 'The archive contains files that must not be published.'
fi
ok 'No development or generated files in the archive'

for required in ext_emconf.php ext_localconf.php composer.json LICENSE; do
    grep -qx "$required" <<<"$CONTENTS" || fail "Missing from the archive: $required"
done
ok 'Required files present'

printf '\n%s\n' "$(cd .. && ls -lh "${EXT_KEY}_${VERSION}.zip")"
printf '\nTop level:\n'
sed 's|/.*|/|' <<<"$CONTENTS" | sort -u | sed 's/^/  /'
printf '\nUpload this file at https://extensions.typo3.org, or:\n'
printf '  tailor ter:publish %s %s --comment "..."\n' "$VERSION" "$EXT_KEY"
