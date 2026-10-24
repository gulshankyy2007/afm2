#!/usr/bin/env bash
# ==============================================================================
# AFM#2 - Production Deployment Bundle Generator
# Packages the entire application, migrations, and database dump into a 
# single compressed archive for transfer to any Cloud VPS.
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
OUTPUT_ARCHIVE="${PROJECT_DIR}/afm2-deploy.tar.gz"

echo "=== Generating AFM#2 Production Deployment Bundle ==="

# 1. Ensure latest database dump is up-to-date
echo "[1/3] Generating fresh MySQL database dump (2,411 songs + tables)..."
mkdir -p "${PROJECT_DIR}/deployment"
DB_PASS_ARG=""
if [ -f "${PROJECT_DIR}/.env" ]; then
    ENV_PASS=$(grep -E '^DB_PASS=' "${PROJECT_DIR}/.env" | cut -d'=' -f2- | tr -d '"' | tr -d "'")
    if [ -n "${ENV_PASS}" ]; then
        DB_PASS_ARG="-p${ENV_PASS}"
    fi
fi
mysqldump -u gulshan ${DB_PASS_ARG} --add-drop-table --routines --triggers afm_db > "${PROJECT_DIR}/deployment/afm2_full_backup.sql"
echo "  ✔ Database dump saved: $(ls -lh "${PROJECT_DIR}/deployment/afm2_full_backup.sql" | awk '{print $5}')"

# 2. Package codebase into tar.gz
echo "[2/3] Compressing codebase into ${OUTPUT_ARCHIVE}..."
cd "${PROJECT_DIR}"
tar -czf "${OUTPUT_ARCHIVE}" \
    --exclude="afm2-deploy.tar.gz" \
    --exclude="afm2-infinityfree.zip" \
    --exclude="*.log" \
    --exclude=".git" \
    --exclude="scratch" \
    --exclude="backups" \
    --exclude="config/rotated_credentials_readme.txt" \
    --exclude=".env" \
    config/ \
    includes/ \
    public/ \
    migrations/ \
    deployment/ \
    bin/ \
    tests/ \
    README.md \
    .env.example

echo "  ✔ Archive created: $(ls -lh "${OUTPUT_ARCHIVE}" | awk '{print $5}')"

# 3. Output instructions
echo "[3/3] Deployment bundle ready!"
echo ""
echo "=============================================================================="
echo "HOW TO TRANSFER & DEPLOY TO YOUR CLOUD VPS:"
echo "=============================================================================="
echo "1. Transfer the bundle to your VPS (replace with your VPS IP):"
echo "   scp afm2-deploy.tar.gz root@<YOUR_VPS_IP>:~"
echo ""
echo "2. SSH into your VPS:"
echo "   ssh root@<YOUR_VPS_IP>"
echo ""
echo "3. Extract and run automated setup:"
echo "   mkdir -p /var/www/afm2 && tar -xzf ~/afm2-deploy.tar.gz -C /var/www/afm2"
echo "   cd /var/www/afm2/deployment"
echo "   bash vps-setup.sh"
echo "=============================================================================="
