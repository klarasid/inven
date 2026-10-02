#!/usr/bin/env bash
# Release helper for the Klaras Inven SLiMS plugin.
#
#   tools/release.sh <version>      Prepare a release locally: set the version, rebuild the frontend,
#                                   commit "Release v<version>" and tag v<version>. Push to publish:
#                                   git push origin main --follow-tags
#   tools/release.sh package        Build dist/klaras-inven-<version>.zip (+ .sha256) from HEAD.
#                                   Run by .github/workflows/release.yml on tag push; also safe locally.
#
# The version lives in one place, the "Version:" line of inventory.plugin.php, which SLiMS reads and
# the in-app update check compares against GitHub's latest release.
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT=$(pwd)
PLUGIN=inventaris-barang          # folder name SLiMS serves assets from (plugins/inventaris-barang/); kept for existing installs
ARCHIVE=klaras-inven             # release file name: klaras-inven-<version>.zip
HEADER=inventory.plugin.php

die() { echo "Galat: $*" >&2; exit 1; }
current_version() { sed -n 's/^ \* Version: *\([^ ]*\).*/\1/p' "$HEADER" | head -1; }
need() { command -v "$1" >/dev/null || die "perintah '$1' tidak ditemukan."; }

prepare() {
  local version=$1
  [[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]] || die "versi harus berformat semver, mis. 2.0.0 atau 2.1.0-rc.1"
  need git; need npm
  [[ -z $(git status --porcelain --untracked-files=no) ]] || die "ada perubahan yang belum di-commit. Commit atau simpan dulu."
  git rev-parse -q --verify "refs/tags/v$version" >/dev/null && die "tag v$version sudah ada."
  local previous; previous=$(current_version)
  echo "Versi: $previous -> $version"

  # Header (read by SLiMS) and frontend package.json stay in step.
  sed -i.bak "s/^ \* Version: .*/ * Version: $version/" "$HEADER" && rm -f "$HEADER.bak"
  (cd frontend && npm version "$version" --no-git-tag-version --allow-same-version >/dev/null)
  echo "Membangun frontend…"
  (cd frontend && npm ci --no-audit --no-fund >/dev/null && npm run build >/dev/null)

  git add "$HEADER" frontend/package.json frontend/package-lock.json assets/app assets/viewer
  git commit -q -m "Release v$version"
  git tag -a "v$version" -m "v$version"
  echo
  echo "Siap. Terbitkan dengan:"
  echo "  git push origin $(git branch --show-current) --follow-tags"
  echo "GitHub Actions akan membangun zip dan membuat rilis v$version."
}

package() {
  need git; need composer; need npm; need zip
  local version; version=$(current_version)
  [[ -n $version ]] || die "baris Version: tidak ditemukan di $HEADER."
  if [[ ${GITHUB_REF_TYPE:-} == tag && ${GITHUB_REF_NAME:-} != "v$version" ]]; then
    die "tag ${GITHUB_REF_NAME} tidak cocok dengan Version: $version di $HEADER."
  fi
  local dist=$ROOT/dist
  STAGE=$(mktemp -d)   # global: the EXIT trap runs after this function returns
  trap 'rm -rf "$STAGE"' EXIT
  local stage=$STAGE
  mkdir -p "$dist"
  echo "Mengemas v$version dari $(git rev-parse --short HEAD)…"

  # Tracked files of HEAD, minus export-ignore'd development files (.gitattributes).
  git archive --worktree-attributes --format=tar --prefix="$PLUGIN/" HEAD | tar -x -C "$stage"

  # Fresh frontend build, so the archive never ships stale committed bundles.
  (cd frontend && npm ci --no-audit --no-fund >/dev/null && npm run build >/dev/null)
  rm -rf "$stage/$PLUGIN/assets/app" "$stage/$PLUGIN/assets/viewer"
  cp -R assets/app assets/viewer "$stage/$PLUGIN/assets/"
  if ! git diff --quiet -- assets/app assets/viewer; then
    echo "Peringatan: aset hasil build berbeda dari yang di-commit; zip memakai hasil build terbaru." >&2
  fi

  # Production PHP dependencies (mPDF, QR code) inside the package.
  (cd "$stage/$PLUGIN" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet)
  # mPDF ships ~100 MB of fonts for scripts this plugin never sets (CJK, historic). Keep the DejaVu and
  # Free families it uses (font substitution is off, so no other face is ever loaded) and their licences.
  find "$stage/$PLUGIN/vendor/mpdf/mpdf/ttfonts" -type f ! -name 'DejaVu*' ! -name 'Free*' ! -name '*.txt' -delete
  find "$stage/$PLUGIN/vendor" -type d \( -name tests -o -name .github \) -prune -exec rm -rf {} +
  # Scripts in the libraries that act when a browser requests them: mPDF's legacy download helper and
  # random_compat's build tools. Nothing loads them, and nginx does not read the .htaccess that
  # denies PHP in this folder, so they are not shipped.
  rm -rf "$stage/$PLUGIN/vendor/mpdf/mpdf/data/out.php" "$stage/$PLUGIN/vendor/paragonie/random_compat/other" \
    "$stage/$PLUGIN/vendor/paragonie/random_compat/psalm-autoload.php" "$stage/$PLUGIN/vendor/paragonie/random_compat/build-phar.sh"
  find "$stage" -name .DS_Store -delete
  echo "Uji asap paket…"
  php tools/smoke.php "$stage/$PLUGIN" || die "uji asap gagal; paket tidak dibuat."

  local zipfile=$dist/$ARCHIVE-$version.zip
  rm -f "$zipfile" "$zipfile.sha256"
  (cd "$stage" && zip -q -r -X "$zipfile" "$PLUGIN")
  (cd "$dist" && shasum -a 256 "$(basename "$zipfile")" > "$(basename "$zipfile").sha256")
  echo "Selesai: dist/$(basename "$zipfile") ($(du -h "$zipfile" | cut -f1))"
}

case ${1:-} in
  package) package ;;
  ""|-h|--help) sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//' ;;
  *) prepare "$1" ;;
esac
