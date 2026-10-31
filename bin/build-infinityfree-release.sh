#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
OUTPUT_ZIP="${PROJECT_DIR}/deployment/afm2-infinityfree-release.zip"
ARTIFACT_ZIP="/home/gulshan-yadav/.gemini/antigravity-ide/brain/070b97cb-54b1-47d6-90ac-c6e27a2cefb2/afm2-infinityfree-release.zip"

echo "=== Building AFM#2 InfinityFree Production Release Bundle ==="

mkdir -p "${PROJECT_DIR}/deployment"
rm -f "${OUTPUT_ZIP}" "${PROJECT_DIR}/afm2-infinityfree-release.zip" "${ARTIFACT_ZIP}"

STAGE_DIR="$(mktemp -d /tmp/afm2_release_staging_XXXXXX)"
trap 'rm -rf "${STAGE_DIR}"' EXIT

echo "  -> Staging release files..."
mkdir -p "${STAGE_DIR}"
cp "${PROJECT_DIR}/.htaccess" "${STAGE_DIR}/"
cp "${PROJECT_DIR}/index.php" "${STAGE_DIR}/"
cp "${PROJECT_DIR}/robots.txt" "${STAGE_DIR}/"
cp "${PROJECT_DIR}/sitemap.xml" "${STAGE_DIR}/"
mkdir -p "${STAGE_DIR}/config" "${STAGE_DIR}/includes" "${STAGE_DIR}/migrations" "${STAGE_DIR}/public"
cp -r "${PROJECT_DIR}/config/"* "${STAGE_DIR}/config/"
cp -r "${PROJECT_DIR}/includes/"* "${STAGE_DIR}/includes/"
cp -r "${PROJECT_DIR}/migrations/"* "${STAGE_DIR}/migrations/"
cp -r "${PROJECT_DIR}/public/"* "${STAGE_DIR}/public/"

# Remove excluded items
rm -f "${STAGE_DIR}/config/"*.txt "${STAGE_DIR}/config/"*.env* 2>/dev/null || true
rm -f "${STAGE_DIR}/public/assets/img/preview_"* "${STAGE_DIR}/public/assets/img/test_"* 2>/dev/null || true

# Minify CSS & JS for release if tooling is available
if command -v npx >/dev/null 2>&1; then
    echo "  -> Minifying production assets with esbuild..."
    if [ -f "${STAGE_DIR}/public/assets/css/style.css" ]; then
        npx -y esbuild "${STAGE_DIR}/public/assets/css/style.css" --minify --outfile="${STAGE_DIR}/public/assets/css/style.css" --allow-overwrite
    fi
    if [ -f "${STAGE_DIR}/public/assets/js/app.js" ]; then
        npx -y esbuild "${STAGE_DIR}/public/assets/js/app.js" --minify --outfile="${STAGE_DIR}/public/assets/js/app.js" --allow-overwrite
        node -c "${STAGE_DIR}/public/assets/js/app.js"
    fi
    if [ -f "${STAGE_DIR}/public/assets/js/auth.js" ]; then
        npx -y esbuild "${STAGE_DIR}/public/assets/js/auth.js" --minify --outfile="${STAGE_DIR}/public/assets/js/auth.js" --allow-overwrite
        node -c "${STAGE_DIR}/public/assets/js/auth.js"
    fi
    echo "  ✔ Asset minification completed and syntax-verified"
fi

cd "${STAGE_DIR}"
zip -q -r "${OUTPUT_ZIP}" . \
    -x "*.git*" \
    -x "*.DS_Store*" \
    -x "*.env*"

echo "  ✔ Deployment archive created: $(ls -lh "${OUTPUT_ZIP}" | awk '{print $5}') at ${OUTPUT_ZIP}"
cp -f "${OUTPUT_ZIP}" "${PROJECT_DIR}/afm2-infinityfree-release.zip"
echo "  ✔ Mirrored release archive to root: ${PROJECT_DIR}/afm2-infinityfree-release.zip"
cp -f "${OUTPUT_ZIP}" "${ARTIFACT_ZIP}"
echo "  ✔ Mirrored release archive to artifacts: ${ARTIFACT_ZIP}"
echo "=== Archive File Count & Summary ==="
unzip -l "${OUTPUT_ZIP}" | tail -n 8

