#!/usr/bin/env bash
# Builds releases/ajcore-ra-<version>.zip, commits/tags/pushes, publishes a GitHub
# release, and installs the zip into the two local DDEV sites only (no other site).
set -euo pipefail

PLUGIN_SLUG="ajcore-ra"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RELEASES_DIR="$ROOT_DIR/releases"
PLUGIN_FILE="$ROOT_DIR/ajcore-ra.php"

GITHUB_REPO="ssnanda/ajcore-ra"
TAG_PREFIX="v"
SITES_ROOT="/Users/sandip/Projects/sites"
DEPLOY_SITES=(ncllc upos)   # ncllc.ddev.site, upos.ddev.site

GITHUB_RELEASE="true"
GIT_COMMIT="true"
GIT_PUSH="true"

usage() {
  cat <<'USAGE'
Usage: ./bin/build-release.sh [options]

Flow: choose version bump -> build zip -> git commit -> tag + push ->
GitHub release -> install into local sites ncllc and upos.

Options:
  --version X.Y.Z | --bump patch|minor|major | --no-bump
  --no-git-commit  --no-push  --no-github-release
  --no-deploy      (skip the local site install)
  --help
USAGE
}

get_version() {
  awk -F':' 'tolower($1) ~ /version[ \t]*$/ {gsub(/^[ \t]+|[ \t\r]+$/, "", $2); print $2; exit}' "$PLUGIN_FILE"
}

validate_version() {
  [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Error: version must be X.Y.Z" >&2; exit 1; }
}

bump_version() {
  local major minor patch
  IFS='.' read -r major minor patch <<< "$1"
  case "$2" in
    patch) patch=$((patch + 1)) ;;
    minor) minor=$((minor + 1)); patch=0 ;;
    major) major=$((major + 1)); minor=0; patch=0 ;;
    *) echo "Error: bump must be patch, minor, or major" >&2; exit 1 ;;
  esac
  echo "$major.$minor.$patch"
}

set_version() {
  validate_version "$1"
  perl -0pi -e "s/Version:\s*[0-9]+\.[0-9]+\.[0-9]+/Version:           $1/" "$PLUGIN_FILE"
  perl -0pi -e "s/define\(\s*'AJCORE_RA_VERSION'\s*,\s*'[^']+'\s*\);/define( 'AJCORE_RA_VERSION', '$1' );/" "$PLUGIN_FILE"
}

# Never delete: move to the macOS Trash via Finder so "Put Back" works.
trash() {
  [[ -e "$1" ]] || return 0
  osascript -e "tell application \"Finder\" to delete POSIX file \"$1\"" >/dev/null
}

choose_version_from_menu() {
  local current="$1" choice custom
  while true; do
    cat >&2 <<MENU
Current version: $current

Choose release type:
  1) patch  ($(bump_version "$current" patch))
  2) minor  ($(bump_version "$current" minor))
  3) major  ($(bump_version "$current" major))
  4) custom version
  5) no bump / package current version
MENU
    read -r -p "Enter choice (1-5, default=1): " choice
    case "${choice:-1}" in
      1) bump_version "$current" patch; return 0 ;;
      2) bump_version "$current" minor; return 0 ;;
      3) bump_version "$current" major; return 0 ;;
      4) read -r -p "Enter version X.Y.Z: " custom; validate_version "$custom"; echo "$custom"; return 0 ;;
      5) echo "$current"; return 0 ;;
      *) echo "Please choose 1-5." >&2 ;;
    esac
  done
}

build_zip() {
  local tmp_dir
  tmp_dir="$(mktemp -d)"
  mkdir -p "$tmp_dir/$PLUGIN_SLUG"

  rsync -a \
    --exclude='.git' --exclude='.gitignore' --exclude='.DS_Store' \
    --exclude='node_modules' --exclude='vendor' --exclude='releases' --exclude='bin' \
    --exclude='README.md' --exclude='*.zip' --exclude='.vscode' --exclude='.idea' \
    --exclude='__MACOSX' --exclude='*.swp' --exclude='*.swo' --exclude='*~' \
    "$ROOT_DIR/" "$tmp_dir/$PLUGIN_SLUG/"

  (cd "$tmp_dir" && zip -rq "$VERSIONED_ZIP" "$PLUGIN_SLUG" -x "*/.DS_Store" -x "*/__MACOSX/*")
  cp "$VERSIONED_ZIP" "$LATEST_ZIP"
  trash "$tmp_dir"
}

verify_zip() {
  [[ -f "$1" ]] || { echo "Error: zip was not created: $1" >&2; exit 1; }
  unzip -l "$1" | awk '{print $4}' | grep -q "^$PLUGIN_SLUG/$PLUGIN_SLUG.php$" \
    || { echo "Error: zip is missing $PLUGIN_SLUG/$PLUGIN_SLUG.php" >&2; exit 1; }
}

git_commit_release_files() {
  cd "$ROOT_DIR"
  git add -u
  while IFS= read -r file; do
    case "$file" in releases/*|*.zip) continue ;; *) git add "$file" ;; esac
  done < <(git ls-files --others --exclude-standard)
  if git diff --cached --quiet; then echo "Git: nothing to commit"; return 0; fi
  git commit -m "Release AJ Core RA $VERSION"
}

get_release_tag() {
  local branch
  branch="$(git -C "$ROOT_DIR" rev-parse --abbrev-ref HEAD)"
  if [[ "$branch" == "main" ]]; then
    echo "$TAG_PREFIX$VERSION"
  else
    echo "$TAG_PREFIX$VERSION-dev-$(echo "$branch" | tr '/[:space:]' '--' | tr -cd '[:alnum:]._-')"
  fi
}

git_create_tag() {
  local tag; tag="$(get_release_tag)"
  if git -C "$ROOT_DIR" rev-parse "$tag" >/dev/null 2>&1; then
    echo "Git: tag already exists: $tag"
  else
    git -C "$ROOT_DIR" tag "$tag"; echo "Git: created tag $tag"
  fi
}

git_push_release() {
  cd "$ROOT_DIR"
  git push -u origin "$(git rev-parse --abbrev-ref HEAD)"
  git push origin "$(get_release_tag)"
}

publish_github_release() {
  command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1 \
    || { echo "Error: GitHub CLI missing or not logged in (gh auth login)" >&2; exit 1; }
  local tag branch args
  tag="$(get_release_tag)"
  branch="$(git -C "$ROOT_DIR" rev-parse --abbrev-ref HEAD)"
  if gh release view "$tag" --repo "$GITHUB_REPO" >/dev/null 2>&1; then
    gh release upload "$tag" "$VERSIONED_ZIP" --repo "$GITHUB_REPO" --clobber
  else
    args=(release create "$tag" "$VERSIONED_ZIP" --repo "$GITHUB_REPO" --title "AJ Core RA $VERSION" --notes "AJ Core RA release $VERSION")
    [[ "$branch" == "main" ]] || args+=(--prerelease)
    gh "${args[@]}"
  fi
}

local_deploy_step() {
  local site plugins_dir
  echo ""
  echo "Local deploy: AJ Core RA $VERSION -> ${DEPLOY_SITES[*]}"
  for site in "${DEPLOY_SITES[@]}"; do
    plugins_dir="$SITES_ROOT/$site/wp-content/plugins"
    if [[ ! -d "$plugins_dir" ]]; then
      echo "  ✗ $site: $plugins_dir not found, skipped"
      continue
    fi
    trash "$plugins_dir/$PLUGIN_SLUG"
    unzip -q "$VERSIONED_ZIP" -d "$plugins_dir/"
    echo "  ✓ $site"
  done
}

VERSION_OVERRIDE=""; BUMP_PART=""; NO_BUMP="false"; DEPLOY="true"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --version) VERSION_OVERRIDE="${2:-}"; shift 2 ;;
    --bump) BUMP_PART="${2:-}"; shift 2 ;;
    --no-bump) NO_BUMP="true"; shift ;;
    --no-git-commit) GIT_COMMIT="false"; shift ;;
    --no-push) GIT_PUSH="false"; shift ;;
    --no-github-release) GITHUB_RELEASE="false"; shift ;;
    --no-deploy) DEPLOY="false"; shift ;;
    --help|-h) usage; exit 0 ;;
    *) echo "Error: unknown option $1" >&2; usage; exit 1 ;;
  esac
done

for cmd in zip unzip rsync git; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Error: $cmd is required" >&2; exit 1; }
done
[[ -f "$PLUGIN_FILE" ]] || { echo "Error: missing $PLUGIN_FILE" >&2; exit 1; }
git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
  || { echo "Error: $ROOT_DIR is not a git repository (git init + add origin https://github.com/$GITHUB_REPO.git)" >&2; exit 1; }

CURRENT_VERSION="$(get_version)"; validate_version "$CURRENT_VERSION"

if [[ -n "$VERSION_OVERRIDE" ]]; then NEXT_VERSION="$VERSION_OVERRIDE"
elif [[ -n "$BUMP_PART" ]]; then NEXT_VERSION="$(bump_version "$CURRENT_VERSION" "$BUMP_PART")"
elif [[ "$NO_BUMP" == "true" ]]; then NEXT_VERSION="$CURRENT_VERSION"
else NEXT_VERSION="$(choose_version_from_menu "$CURRENT_VERSION")"
fi
validate_version "$NEXT_VERSION"
[[ "$NEXT_VERSION" == "$CURRENT_VERSION" ]] || set_version "$NEXT_VERSION"

VERSION="$(get_version)"; validate_version "$VERSION"

mkdir -p "$RELEASES_DIR"
VERSIONED_ZIP="$RELEASES_DIR/$PLUGIN_SLUG-$VERSION.zip"
LATEST_ZIP="$RELEASES_DIR/$PLUGIN_SLUG.zip"

build_zip
verify_zip "$VERSIONED_ZIP"

[[ "$GIT_COMMIT" == "true" ]] && git_commit_release_files
if [[ "$GIT_PUSH" == "true" ]]; then git_create_tag; git_push_release; fi
if [[ "$GITHUB_RELEASE" == "true" ]]; then git_create_tag; publish_github_release; fi

echo "Version: $CURRENT_VERSION -> $VERSION"
echo "Built: $VERSIONED_ZIP"
echo "Built: $LATEST_ZIP"

[[ "$DEPLOY" == "true" ]] && local_deploy_step
echo ""
echo "AJ Core RA v$VERSION"
