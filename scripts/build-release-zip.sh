#!/usr/bin/env bash
#
# Package Yishi Page Post Sync for WordPress.
# This plugin ships committed PHP/CSS/JS (no transpilation), so the build step
# is optional. Runtime files are copied (never symlinked) into the release.
#
# Usage (from repo root):
#   npm run release
#     Build + write ./build/niyi-page-post-sync-x.y.z.zip
#   npm run release:dev
#     Copy runtime files to wp-content/plugins/niyi-page-post-sync
#   bash scripts/build-release-zip.sh dev
#   bash scripts/build-release-zip.sh dev /path/to/destination
#   bash scripts/build-release-zip.sh prod
#   bash scripts/build-release-zip.sh prod /path/to/output-dir
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="niyi-page-post-sync"
MAIN_FILE="$ROOT/niyi-page-post-sync.php"

MODE="prod"
OUT_DIR="$ROOT/build"
DEV_DEST_OVERRIDE=""

if [[ $# -ge 1 ]]; then
  case "$1" in
    dev|prod)
      MODE="$1"
      if [[ $# -ge 2 ]]; then
        if [[ "$MODE" == "prod" ]]; then
          OUT_DIR="$2"
        else
          DEV_DEST_OVERRIDE="$2"
        fi
      fi
      ;;
    *)
      MODE="prod"
      OUT_DIR="$1"
      ;;
  esac
fi

if [[ ! -f "$MAIN_FILE" ]]; then
  echo "error: expected $MAIN_FILE" >&2
  exit 1
fi

# Read "Version: x.y.z" from the plugin file header (first match).
VERSION="$(
  grep -E '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*' "$MAIN_FILE" \
    | head -n1 \
    | sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//;s/[[:space:]]+$//'
)"
if [[ -z "$VERSION" ]]; then
  echo "error: could not parse Version from $MAIN_FILE" >&2
  exit 1
fi

root_realpath() {
  (cd "$ROOT" && pwd -P)
}

is_source_repo() {
  local path="$1"
  local root_real path_real

  root_real="$(root_realpath)"
  path_real="$(cd "$path" && pwd -P 2>/dev/null)" || return 1

  [[ "$path_real" == "$root_real" ]]
}

# The assets are committed; only run an npm build when the project actually
# defines one. npm is optional for producing a release.
run_build() {
  if [[ ! -f "$ROOT/package.json" ]]; then
    echo "No package.json found; using committed assets (no build step)."
    return 0
  fi

  if ! command -v npm >/dev/null 2>&1; then
    echo "warning: npm not available; using committed assets." >&2
    return 0
  fi

  if ! grep -q '"build"[[:space:]]*:' "$ROOT/package.json"; then
    echo "No build script defined; using committed assets."
    return 0
  fi

  echo "Running npm run build ..."
  (cd "$ROOT" && npm run build)
}

# Replace symlink or previous deploy with an empty directory ready for copy.
prepare_deploy_destination() {
  local dest="$1"

  # Remove symlink first (pwd -P would otherwise resolve to the source repo).
  if [[ -L "$dest" ]]; then
    echo "Removing symlink at $dest"
    rm "$dest"
  fi

  if [[ -e "$dest" ]] && is_source_repo "$dest"; then
    echo "error: deploy destination is the source repo ($dest); refusing to overwrite" >&2
    exit 1
  fi

  if [[ -e "$dest" ]]; then
    echo "Removing previous deploy at $dest"
    rm -rf "$dest"
  fi

  mkdir -p "$dest"
}

# WordPress.org readme Stable tag should match the plugin header version.
sync_readme_version() {
  local dest="$1"
  local readme="$dest/readme.txt"

  if [[ ! -f "$readme" ]]; then
    return 0
  fi

  sed -i "s/^Stable tag: .*/Stable tag: $VERSION/" "$readme"
}

copy_if_exists() {
  local dest="$1"
  local rel="$2"
  # Missing optional paths are fine; verify_runtime_files() guards the rest.
  if [[ -e "$ROOT/$rel" ]]; then
    cp -a "$ROOT/$rel" "$dest/"
  fi
}

copy_required() {
  local dest="$1"
  local rel="$2"
  if [[ ! -e "$ROOT/$rel" ]]; then
    echo "error: required release file missing: $rel" >&2
    exit 1
  fi
  cp -a "$ROOT/$rel" "$dest/"
}

verify_runtime_files() {
  local dest="$1"
  local missing=0
  local required_files=(
    "niyi-page-post-sync.php"
    "src/Settings.php"
    "src/ApiClient.php"
    "src/Synchronizer.php"
    "src/Shortcodes.php"
    "src/Plugin.php"
    "assets/js/sync.js"
    "assets/js/admin.js"
    "assets/css/admin.css"
  )

  for rel in "${required_files[@]}"; do
    if [[ ! -e "$dest/$rel" ]]; then
      echo "error: staged release missing required file: $rel" >&2
      missing=1
    fi
  done

  if [[ "$missing" -ne 0 ]]; then
    exit 1
  fi
}

stage_plugin_to() {
  local dest="$1"

  copy_required "$dest" "niyi-page-post-sync.php"
  copy_if_exists "$dest" "src"
  copy_if_exists "$dest" "assets"
  copy_if_exists "$dest" "README.md"
  # Optional, for WordPress.org distribution.
  copy_if_exists "$dest" "readme.txt"
  copy_if_exists "$dest" "license.txt"
  copy_if_exists "$dest" "LICENSE"

  sync_readme_version "$dest"

  # Production bundles only — drop source maps if present.
  find "$dest" -name '*.map' -delete 2>/dev/null || true

  # Drop dev-only paths that must not ship.
  rm -rf \
    "$dest/scripts" \
    "$dest/node_modules" \
    "$dest/.git" \
    "$dest/tasks.md" \
    "$dest/build" \
    2>/dev/null || true

  verify_runtime_files "$dest"
}

run_build

if [[ "$MODE" == "dev" ]]; then
  if [[ -n "$DEV_DEST_OVERRIDE" ]]; then
    DEV_DEST="$DEV_DEST_OVERRIDE"
  else
    PLUGINS_DIR="$(cd "$ROOT/../.." && pwd)/plugins"
    mkdir -p "$PLUGINS_DIR"
    DEV_DEST="$PLUGINS_DIR/$PLUGIN_SLUG"
  fi

  mkdir -p "$(dirname "$DEV_DEST")"
  prepare_deploy_destination "$DEV_DEST"
  stage_plugin_to "$DEV_DEST"
  echo "Deployed to $DEV_DEST (version $VERSION)"
  exit 0
fi

if ! command -v zip >/dev/null 2>&1 || ! command -v unzip >/dev/null 2>&1; then
  echo "error: zip and unzip are required to build a release archive" >&2
  exit 1
fi

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/niyi-page-post-sync-release.XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT

DEST="$STAGE/$PLUGIN_SLUG"
mkdir -p "$DEST"
stage_plugin_to "$DEST"

mkdir -p "$OUT_DIR"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="$OUT_DIR/$ZIP_NAME"

rm -f "$ZIP_PATH"
(
  cd "$STAGE"
  zip -r -q "$ZIP_PATH" "$PLUGIN_SLUG"
)

FILES_IN_ZIP="$(unzip -l "$ZIP_PATH" | awk '/ files$/ {print $(NF-1)}')"
echo "Built $ZIP_PATH ($(du -h "$ZIP_PATH" | cut -f1))"
echo "Files in archive: ${FILES_IN_ZIP}"
