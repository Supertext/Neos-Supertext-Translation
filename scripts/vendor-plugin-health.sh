#!/bin/sh
# Copies the PHP part of Supertext Plugin Health (version reports, API key check result and
# ping endpoint for the Supertext Commando Center) from the Commando Center's repository into
# lib/supertext-plugin-health, with its namespace changed from Supertext\PluginHealth to
# Supertext\NeosTranslation\PluginHealth. The package ships this copy, like the Supertext SDK,
# so sites install nothing besides the package.
#
#   scripts/vendor-plugin-health.sh [commit-or-branch]   (default: main)
#   PLUGIN_HEALTH_DIR=/path/to/clone scripts/vendor-plugin-health.sh   (use a local clone as it is)
set -eu
REF="${1:-main}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

if [ -n "${PLUGIN_HEALTH_DIR:-}" ]; then
    SRC="$PLUGIN_HEALTH_DIR"
else
    git clone --quiet https://github.com/Supertext/Commando-Center-Supertext-Translation "$TMP/center"
    git -C "$TMP/center" checkout --quiet "$REF"
    SRC="$TMP/center"
fi
COMMIT="$(git -C "$SRC" rev-parse HEAD)"
VERSION="$(sed -n "s/.*PLUGIN_HEALTH_VERSION = '\([^']*\)'.*/\1/p" "$SRC/plugin-health/php/src/PluginHealth.php")"
if [ -z "$VERSION" ]; then
    echo "vendor-plugin-health: no PLUGIN_HEALTH_VERSION in $SRC/plugin-health/php/src/PluginHealth.php" >&2
    exit 1
fi

DEST="$ROOT/lib/supertext-plugin-health"
rm -rf "$DEST"
mkdir -p "$DEST"
cp -R "$SRC/plugin-health/php/src" "$DEST/src"
cp "$SRC/plugin-health/php/LICENSE" "$DEST/LICENSE"
find "$DEST/src" -name '*.php' -exec sed -i 's/Supertext\\PluginHealth\\/Supertext\\NeosTranslation\\PluginHealth\\/g; s/Supertext\\PluginHealth;/Supertext\\NeosTranslation\\PluginHealth;/g' {} +
printf '%s\n' "$COMMIT" > "$DEST/COMMIT"
printf '%s\n' "$VERSION" > "$DEST/VERSION"
cat > "$DEST/README.md" <<EOF
# Supertext Plugin Health (bundled copy)

PHP part of [Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health)
$VERSION, commit \`$COMMIT\`, namespace \`Supertext\\NeosTranslation\\PluginHealth\` instead of \`Supertext\\PluginHealth\`.

Don't edit these files: fix it in Commando-Center-Supertext-Translation/plugin-health, then run
\`scripts/vendor-plugin-health.sh\`.
EOF
if grep -rq 'Supertext\\PluginHealth' "$DEST/src"; then
    echo "vendor-plugin-health: unprefixed Supertext\\PluginHealth reference left in $DEST/src" >&2
    exit 1
fi
echo "lib/supertext-plugin-health: Supertext Plugin Health $VERSION (PHP) from commit $COMMIT"
