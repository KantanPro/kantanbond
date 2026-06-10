#!/usr/bin/env bash
set -euo pipefail

# KantanBond リリースZIP作成スクリプト
# 出力先: /Users/kantanpro/Desktop/KantanBond_TEST_UP
# 解凍後フォルダ名: KantanBond

PLUGIN_SLUG="KantanBond"
OUTPUT_DIR="/Users/kantanpro/Desktop/KantanBond_TEST_UP"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="${SCRIPT_DIR}"

if [[ ! -f "${PLUGIN_DIR}/kantanbond.php" ]]; then
	echo "Error: ${PLUGIN_DIR}/kantanbond.php が見つかりません。" >&2
	exit 1
fi

mkdir -p "${OUTPUT_DIR}"

VERSION="$(sed -n 's/^ \* Version: \(.*\)$/\1/p' "${PLUGIN_DIR}/kantanbond.php" | head -n 1 | tr -d '\r')"
if [[ -z "${VERSION}" ]]; then
	VERSION="dev"
fi

TODAY="$(date +%Y%m%d)"
ZIP_NAME="${PLUGIN_SLUG}_${VERSION}_${TODAY}.zip"
ZIP_PATH="${OUTPUT_DIR}/${ZIP_NAME}"

if [[ -f "${ZIP_PATH}" ]]; then
	rm -f "${ZIP_PATH}"
fi

cd "${PLUGIN_DIR}/.."

zip -r "${ZIP_PATH}" "${PLUGIN_SLUG}" \
	-x "${PLUGIN_SLUG}/.git/*" \
	-x "${PLUGIN_SLUG}/.git" \
	-x "${PLUGIN_SLUG}/.DS_Store" \
	-x "${PLUGIN_SLUG}/**/.DS_Store" \
	-x "${PLUGIN_SLUG}/*.zip" \
	-x "${PLUGIN_SLUG}/create_release_zip.sh"

echo "Release ZIP created:"
echo "${ZIP_PATH}"
echo "Extracted folder name: ${PLUGIN_SLUG}"
