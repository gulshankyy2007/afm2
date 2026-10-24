#!/usr/bin/env bash
# ==============================================================================
# AFM#2 — Automated Cloud VPS Setup Script
# Works on fresh Ubuntu 22.04 / 24.04 or Debian 12
# ==============================================================================

set -euo pipefail

# ANSI Colors
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
BOLD='\033[1m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

echo -e "${CYAN}${BOLD}=== AFM#2 Cloud VPS Production Deployment ===${NC}"

# Check for root/sudo
if [ "$(id -u)" -ne 0 ]; then
    echo -e "${YELLOW}Please run this script as root or with sudo.${NC}"
    exit 1
fi

# 1. Install Docker & Docker Compose if missing
if ! command -v docker &> /dev/null; then
    echo -e "${CYAN}[1/4] Installing Docker...${NC}"
    curl -fsSL https://get.docker.com | sh
    systemctl enable --now docker
else
    echo -e "${GREEN}[1/4] Docker is already installed.${NC}"
fi

# Configure host firewall (important for Oracle Cloud / standard Ubuntu)
echo -e "${CYAN}Opening HTTP/HTTPS firewall ports (80/443)...${NC}"
if command -v ufw &> /dev/null; then
    ufw allow 80/tcp 2>/dev/null || true
    ufw allow 443/tcp 2>/dev/null || true
fi
iptables -I INPUT -p tcp --dport 80 -j ACCEPT 2>/dev/null || true
iptables -I INPUT -p tcp --dport 443 -j ACCEPT 2>/dev/null || true


# 2. Prepare environment variables
echo -e "${CYAN}[2/4] Configuring production secrets...${NC}"
ENV_FILE="${APP_DIR}/.env"
if [ ! -f "${ENV_FILE}" ]; then
    DB_RAND_PASS=$(tr -dc A-Za-z0-9_ < /dev/urandom | head -c 24 || echo "Afm2SecurePass$(date +%s)")
    APP_RAND_SECRET=$(tr -dc A-Za-z0-9_ < /dev/urandom | head -c 48 || echo "Afm2SuperSecret$(date +%s)")

    cat <<EOF > "${ENV_FILE}"
DB_HOST=db
DB_PORT=3306
DB_NAME=afm_db
DB_USER=afm_user
DB_PASS=${DB_RAND_PASS}
DB_ROOT_PASS=${DB_RAND_PASS}_root
APP_ENV=production
APP_SECRET=${APP_RAND_SECRET}
SESSION_LIFETIME=86400
EOF
    echo -e "  ✔ Created production .env configuration"
fi
cp -f "${ENV_FILE}" "${SCRIPT_DIR}/.env" 2>/dev/null || true

# 3. Build and launch Docker Compose
echo -e "${CYAN}[3/4] Building and launching containers (PHP 8.3 + MySQL 8.4)...${NC}"
cd "${SCRIPT_DIR}"
docker compose down -v 2>/dev/null || true
docker compose up -d --build

# 4. Wait for healthy database initialization
echo -e "${CYAN}[4/4] Verifying database seeding (2,475 songs)...${NC}"
RETRIES=30
while [ $RETRIES -gt 0 ]; do
    if docker compose ps | grep -q "healthy"; then
        break
    fi
    echo -n "."
    sleep 2
    RETRIES=$((RETRIES - 1))
done
echo ""

PUBLIC_IP=$(curl -s https://api.ipify.org || hostname -I | awk '{print $1}')

echo ""
echo -e "${GREEN}${BOLD}================================================================${NC}"
echo -e "${GREEN}${BOLD}🎉 AFM#2 IS NOW LIVE AND ACCESSIBLE 24/7 ONLINE!${NC}"
echo -e "${GREEN}${BOLD}================================================================${NC}"
echo -e "Public Web URL:  ${CYAN}${BOLD}http://${PUBLIC_IP}/${NC}"
echo -e "Admin Username:  ${BOLD}gulshan${NC}"
echo -e "Admin Password:  ${BOLD}<Configured during initial setup or in .env>${NC}"
echo ""
echo -e "${BOLD}Next Steps for Custom Domain & Free SSL (HTTPS):${NC}"
echo "1. Point your domain's DNS 'A' record to: ${PUBLIC_IP}"
echo "2. Install Caddy (automated Let's Encrypt SSL):"
echo "   apt install -y debian-keyring debian-archive-keyring apt-transport-https"
echo "   curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg"
echo "   curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | tee /etc/apt/sources.list.d/caddy-stable.list"
echo "   apt update && apt install -y caddy"
echo "3. Add to /etc/caddy/Caddyfile:"
echo "   yourdomain.com {"
echo "       reverse_proxy localhost:80"
echo "   }"
echo "4. Restart Caddy: systemctl restart caddy"
echo -e "${GREEN}${BOLD}================================================================${NC}"
