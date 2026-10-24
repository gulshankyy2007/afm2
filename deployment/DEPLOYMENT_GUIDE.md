# AFM#2 — 24/7 Online Deployment Guide

This guide outlines the 4 best ways to deploy **AFM#2** online so it is accessible 24/7 from any device (phone, laptop, tablet).

---

## Pre-Packaged Deployment Assets
All necessary deployment assets have already been prepared for you:
- **Full Database Dump (2,475 songs + tables):** `deployment/afm2_full_backup.sql`
- **Docker Production Image:** `deployment/Dockerfile`
- **One-Command Full Stack Compose:** `deployment/docker-compose.yml`
- **Local Service Controller:** `bin/afm2-service.sh`

---

## Comparison of Deployment Options

| Option | Cost | Uptime / 24/7 | Effort | Best For |
| :--- | :--- | :--- | :--- | :--- |
| **Option 1: Cloudflare Tunnel** | **Free ($0)** | Dependent on PC power | 2 minutes | Instant public access without renting a server |
| **Option 2: Cloud VPS (Hetzner / DigitalOcean / Oracle Free Tier)** | **$4/mo or Free** | **100% 24/7** | 10 minutes | Maximum control, speed, custom domain, real production |
| **Option 3: Managed PaaS (Railway / Render)** | **Free / $5/mo** | **100% 24/7** | 5 minutes | Zero server management, push-to-deploy from GitHub |
| **Option 4: Shared Web Hosting (cPanel / Hostinger)** | **Existing plan** | **100% 24/7** | 5 minutes | If you already own web hosting with cPanel |

---

## Option 1: Instant Public Access via Cloudflare Tunnel (Zero Cost)

If your machine stays powered on (or you run a home mini-PC/Raspberry Pi), you can expose AFM#2 to the internet over a secure HTTPS URL without opening router ports.

### Quick Start:
```bash
# 1. Download official Cloudflare Tunnel CLI
curl -L --output cloudflared https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64
chmod +x cloudflared

# 2. Start a quick tunnel pointing to your active AFM#2 service (port 8080)
./cloudflared tunnel --url http://localhost:8080
```
Cloudflare will output a public HTTPS URL like:
`https://random-word-abc.trycloudflare.com`

You can open this URL on your phone or share it with friends immediately.

---

## Option 2: Cloud VPS (Hetzner, DigitalOcean, or Oracle Cloud Free Tier) — Recommended 24/7

Rent a virtual server ($4/month on Hetzner / DigitalOcean, or free forever on Oracle Cloud Always Free).

### Step 1: Upload code to the VPS
```bash
git clone <your-repo> /var/www/afm2
# OR rsync directly:
rsync -avz --exclude '.git' /mnt/data/GY/Music/AFM#2/ user@<your-vps-ip>:/var/www/afm2/
```

### Step 2: Run with Docker Compose (Easiest)
On the VPS:
```bash
cd /var/www/afm2/deployment
docker compose up -d
```
Docker will start MySQL 8.4, automatically import `afm2_full_backup.sql`, start Apache/PHP, and expose port 80!

### Step 3: Add HTTPS / SSL (with Caddy or Nginx)
If you have a domain like `music.yourdomain.com`:
```bash
sudo apt install -y caddy
```
In `/etc/caddy/Caddyfile`:
```caddy
music.yourdomain.com {
    reverse_proxy localhost:80
}
```
Caddy automatically obtains and renews a free Let's Encrypt SSL certificate.

---

## Option 3: Managed PaaS (Railway or Render)

1. Create a GitHub repository and push your project.
2. Sign in to **[Railway.app](https://railway.app)** or **[Render.com](https://render.com)**.
3. Click **New Project** -> **Database: MySQL**.
4. Import `deployment/afm2_full_backup.sql` into the newly created database.
5. Click **New Service** -> **GitHub Repo**.
6. Set the Environment Variables:
   - `DB_HOST`: `<provided-by-platform>`
   - `DB_PORT`: `3306`
   - `DB_NAME`: `afm_db`
   - `DB_USER`: `<provided-by-platform>`
   - `DB_PASS`: `<provided-by-platform>`
   - `APP_ENV`: `production`
7. Deploy! Your app will be live at `https://your-app.up.railway.app` with automatic HTTPS.

---

## Option 4: Shared Web Hosting (cPanel / Hostinger)

1. Open **cPanel -> MySQL Databases**, create database `afm_db` and user.
2. Open **phpMyAdmin**, click Import, and choose `deployment/afm2_full_backup.sql`.
3. Open **File Manager**, upload project files.
4. Set Document Root to the `public/` directory (or put `.htaccess` rewrite to `/public`).
5. Update `.env` with your hosting MySQL credentials.
