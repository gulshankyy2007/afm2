#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
OUTPUT_ZIP="${PROJECT_DIR}/deployment/afm2-infinityfree-release.zip"
ARTIFACT_ZIP="/home/gulshan-yadav/.gemini/antigravity-ide/brain/070b97cb-54b1-47d6-90ac-c6e27a2cefb2/afm2-infinityfree-release.zip"

echo "=== Building AFM#2 InfinityFree Production Release Bundle ==="

mkdir -p "${PROJECT_DIR}/deployment"
rm -f "${OUTPUT_ZIP}" "${PROJECT_DIR}/afm2-infinityfree-release.zip" "${ARTIFACT_ZIP}"

cd "${PROJECT_DIR}"
zip -q -r "${OUTPUT_ZIP}" \
    .htaccess \
    index.php \
    robots.txt \
    sitemap.xml \
    config/config.php \
    config/db.php \
    includes/ \
    migrations/ \
    public/ \
    -x "public/assets/img/preview_*" \
    -x "public/assets/img/test_*" \
    -x "*.git*" \
    -x "*.DS_Store*" \
    -x "*.env*" \
    -x "config/*.txt"

echo "  ✔ Deployment archive created: $(ls -lh "${OUTPUT_ZIP}" | awk '{print $5}') at ${OUTPUT_ZIP}"
cp -f "${OUTPUT_ZIP}" "${PROJECT_DIR}/afm2-infinityfree-release.zip"
echo "  ✔ Mirrored release archive to root: ${PROJECT_DIR}/afm2-infinityfree-release.zip"
cp -f "${OUTPUT_ZIP}" "${ARTIFACT_ZIP}"
echo "  ✔ Mirrored release archive to artifacts: ${ARTIFACT_ZIP}"
echo "=== Archive File Count & Summary ==="
unzip -l "${OUTPUT_ZIP}" | tail -n 8

