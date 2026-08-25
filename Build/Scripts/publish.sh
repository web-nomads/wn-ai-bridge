#!/usr/bin/env bash
#
# Publish this extension to its own repository and tag the release.
#
# The extension lives inside a larger project repository. Its public repository
# holds only this subdirectory, so every release has to be split out. Two things
# make that harder than "git subtree push":
#
#   1. The project repository once carried a compiled DI container under var/.
#      It is stripped here so it never reaches the public repository.
#   2. The published history was rewritten once (that removal, plus a rewritten
#      commit message). A fresh split therefore no longer matches what is on the
#      remote, and "git subtree push" refuses with a non-fast-forward.
#
# So instead of deriving the whole history every time, this grafts: it finds the
# commit in the fresh split whose *tree* matches the published tip — the same
# content under a different hash — and replays everything after it on top of the
# remote. That works no matter how often the published history was rewritten.
#
# Usage: Build/Scripts/publish.sh [--dry-run]
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."
EXT_DIR="$(pwd)"
PREFIX="packages/$(basename "$EXT_DIR")"
REMOTE='wn-ai-bridge'
DRY_RUN="${1:-}"

fail() { printf '\033[31m✗ %s\033[0m\n' "$1" >&2; exit 1; }
info() { printf '\033[36m→ %s\033[0m\n' "$1"; }
ok()   { printf '\033[32m✓ %s\033[0m\n' "$1"; }

cd "$(git rev-parse --show-toplevel)"
git remote get-url "$REMOTE" >/dev/null 2>&1 || fail "No git remote named \"$REMOTE\"."

# --- Version ----------------------------------------------------------------

VERSION=$(php -r '
    $_EXTKEY = basename("'"$EXT_DIR"'");
    include "'"$EXT_DIR"'/ext_emconf.php";
    echo $EM_CONF[$_EXTKEY]["version"] ?? "";
')
COMPOSER_VERSION=$(php -r '
    $c = json_decode(file_get_contents("'"$EXT_DIR"'/composer.json"), true);
    echo $c["version"] ?? "";
')
[ -n "$VERSION" ] || fail 'No version found in ext_emconf.php.'
[ "$VERSION" = "$COMPOSER_VERSION" ] \
    || fail "Version mismatch: ext_emconf.php says $VERSION, composer.json says $COMPOSER_VERSION."
TAG="v$VERSION"
ok "Version $VERSION"

# Publishing from a dirty tree would ship something that is in no commit.
[ -z "$(git status --porcelain -- "$PREFIX")" ] || fail 'The extension has uncommitted changes.'

# --- Split and clean --------------------------------------------------------

git fetch -q "$REMOTE" main 2>/dev/null || true
REMOTE_TIP=$(git rev-parse --verify -q "$REMOTE/main" || true)

info 'Splitting the extension out of the project history …'
git branch -D __publish_split -q 2>/dev/null || true
git subtree split --prefix="$PREFIX" -b __publish_split -q >/dev/null

# Same filter as the published history was built with: generated directories out,
# and no Co-Authored-By trailer — GitHub turns those into repository contributors.
FILTER_BRANCH_SQUELCH_WARNING=1 git filter-branch -f --prune-empty \
    --index-filter 'git rm -r --cached --ignore-unmatch var .Build > /dev/null 2>&1 || true' \
    --msg-filter 'grep -v "^Co-Authored-By: Claude" | sed -e :a -e "/^\n*$/{$d;N;ba" -e "}"' \
    __publish_split >/dev/null 2>&1
SPLIT_TIP=$(git rev-parse __publish_split)

# --- Find where the published history and the split describe the same content -

NEW_COMMITS=''
if [ -n "$REMOTE_TIP" ]; then
    REMOTE_TREE=$(git rev-parse "$REMOTE_TIP^{tree}")
    GRAFT=''
    for c in $(git rev-list __publish_split); do
        if [ "$(git rev-parse "$c^{tree}")" = "$REMOTE_TREE" ]; then GRAFT="$c"; break; fi
    done
    [ -n "$GRAFT" ] || fail 'No commit in the split matches the published tip. Publish by hand and check what diverged.'
    NEW_COMMITS=$(git rev-list --reverse "$GRAFT..__publish_split")
    if [ -z "$NEW_COMMITS" ]; then
        ok 'The published repository is already up to date'
    else
        info "$(echo "$NEW_COMMITS" | wc -l | tr -d ' ') new commit(s) to publish"
    fi
else
    info 'The published repository is empty — pushing the whole history'
fi

# --- Refuse to move an existing tag -----------------------------------------

EXISTING_TAG=$(git ls-remote --tags "$REMOTE" "refs/tags/$TAG" | cut -f1)
if [ -n "$EXISTING_TAG" ] && [ -z "$NEW_COMMITS" ] && [ -n "$REMOTE_TIP" ]; then
    if [ "$EXISTING_TAG" = "$REMOTE_TIP" ]; then
        ok "$TAG already published"
        git branch -D __publish_split -q
        exit 0
    fi
fi
[ -z "$EXISTING_TAG" ] || fail "$TAG already exists on the remote. Bump the version — a published tag must never move."

if [ "$DRY_RUN" = '--dry-run' ]; then
    printf '\nWould publish:\n'
    [ -n "$NEW_COMMITS" ] && git log --oneline --reverse "${GRAFT:-}..__publish_split" | sed 's/^/  /'
    printf '  tag %s\n' "$TAG"
    git branch -D __publish_split -q
    exit 0
fi

# --- Replay and push --------------------------------------------------------

WORKTREE="$(git rev-parse --show-toplevel)/../.publish-$$"
if [ -n "$REMOTE_TIP" ]; then
    git worktree add -q --detach "$WORKTREE" "$REMOTE_TIP"
    (
        cd "$WORKTREE"
        git reset --hard -q HEAD
        for c in $NEW_COMMITS; do
            git cherry-pick "$c" >/dev/null || { echo "cherry-pick of $c failed" >&2; exit 1; }
        done
        git push -q "$REMOTE" HEAD:main
        git push -q "$REMOTE" "HEAD:refs/tags/$TAG"
    )
    git worktree remove --force "$WORKTREE"
else
    git push -q "$REMOTE" "$SPLIT_TIP:main"
    git push -q "$REMOTE" "$SPLIT_TIP:refs/tags/$TAG"
fi

git branch -D __publish_split -q
ok "Published and tagged $TAG"
printf '\nPackagist picks the new version up from the tag.\n'
printf 'For the TER: Build/Scripts/release.sh, then upload the archive.\n'
