#!/usr/bin/env bash
# ==============================================================================
# AFM#2 Web Service Management Script
# Annual Musical Finale 2026 Production Service Controller
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
SERVICE_NAME="afm2.service"
USER_SYSTEMD_DIR="${HOME}/.config/systemd/user"
UNIT_SOURCE="${PROJECT_DIR}/deployment/afm2.service"
UNIT_TARGET="${USER_SYSTEMD_DIR}/${SERVICE_NAME}"
PORT="8080"

# ANSI Colors
CYAN='\033[0;36m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BOLD='\033[1m'
NC='\033[0m'

get_lan_ip() {
    ip -4 addr show scope global | grep -oP '(?<=inet\s)\d+(\.\d+){3}' | head -n1 || echo "127.0.0.1"
}

ensure_installed() {
    mkdir -p "${USER_SYSTEMD_DIR}"
    if [ ! -f "${UNIT_TARGET}" ] || [ "${UNIT_SOURCE}" -nt "${UNIT_TARGET}" ]; then
        cp -f "${UNIT_SOURCE}" "${UNIT_TARGET}"
        systemctl --user daemon-reload
    fi
}

cmd_start() {
    ensure_installed
    echo -e "${CYAN}Starting AFM#2 service...${NC}"
    systemctl --user start "${SERVICE_NAME}"
    sleep 1
    cmd_status
}

cmd_stop() {
    echo -e "${YELLOW}Stopping AFM#2 service...${NC}"
    systemctl --user stop "${SERVICE_NAME}" 2>/dev/null || true
    echo -e "${GREEN}Service stopped.${NC}"
}

cmd_restart() {
    ensure_installed
    echo -e "${CYAN}Restarting AFM#2 service...${NC}"
    systemctl --user restart "${SERVICE_NAME}"
    sleep 1
    cmd_status
}

cmd_status() {
    ensure_installed
    echo -e "${BOLD}=== AFM#2 Service Status ===${NC}"
    if systemctl --user is-active --quiet "${SERVICE_NAME}"; then
        LAN_IP=$(get_lan_ip)
        echo -e "Status:   ${GREEN}${BOLD}● ACTIVE & RUNNING${NC}"
        echo -e "Local:    ${CYAN}http://localhost:${PORT}/${NC}"
        echo -e "Loopback: ${CYAN}http://127.0.0.1:${PORT}/${NC}"
        echo -e "Network:  ${CYAN}http://${LAN_IP}:${PORT}/${NC}"
        echo ""
        systemctl --user status "${SERVICE_NAME}" --no-pager --lines=5
    else
        echo -e "Status:   ${RED}${BOLD}○ INACTIVE (STOPPED)${NC}"
    fi
}

cmd_enable() {
    ensure_installed
    echo -e "${CYAN}Enabling AFM#2 service to auto-start on user session...${NC}"
    systemctl --user enable "${SERVICE_NAME}"
    echo -e "${GREEN}Service enabled.${NC}"
}

cmd_disable() {
    echo -e "${YELLOW}Disabling AFM#2 auto-start...${NC}"
    systemctl --user disable "${SERVICE_NAME}" 2>/dev/null || true
    echo -e "${GREEN}Service disabled.${NC}"
}

cmd_logs() {
    echo -e "${CYAN}Tailing AFM#2 service journal logs (Ctrl+C to exit)...${NC}"
    journalctl --user -u "${SERVICE_NAME}" -f --no-hostname
}

cmd_url() {
    LAN_IP=$(get_lan_ip)
    echo -e "${BOLD}AFM#2 Access Endpoints:${NC}"
    echo -e "  - Local URL:   http://localhost:${PORT}/"
    echo -e "  - Network URL: http://${LAN_IP}:${PORT}/"
}

case "${1:-status}" in
    start)
        cmd_start
        ;;
    stop)
        cmd_stop
        ;;
    restart)
        cmd_restart
        ;;
    status)
        cmd_status
        ;;
    enable)
        cmd_enable
        ;;
    disable)
        cmd_disable
        ;;
    logs)
        cmd_logs
        ;;
    url)
        cmd_url
        ;;
    *)
        echo "Usage: $0 {start|stop|restart|status|enable|disable|logs|url}"
        exit 1
        ;;
esac
