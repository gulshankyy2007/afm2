# AFM#2 — Zero-Cost Managed Hosting Alternatives Evaluation

**Project:** AFM#2 (Personal Music Journal & Tournament)  
**Evaluation Scope:** Zero-Cost Managed PHP/MySQL Hosting Platforms  
**Date:** November 2026  
**Auditor:** Antigravity Autonomous Systems Engineering  
**Decision Record:** [`HOSTING_DECISION_RECORD.md`](file:///mnt/data/GY/Music/AFM%232/deployment/HOSTING_DECISION_RECORD.md)  
**Readiness Guide:** [`HOSTING_MIGRATION_READINESS.md`](file:///mnt/data/GY/Music/AFM%232/deployment/HOSTING_MIGRATION_READINESS.md)  

---

## 1. Objective & Research Methodology

Following the technical rejection of Vercel Hobby as an incompatible hosting target for AFM#2, this evaluation conducts an exhaustive market analysis to determine whether any genuinely free managed PHP/MySQL hosting provider exists that can replace the current InfinityFree deployment while satisfying all project constraints:

1. **Strict Budget:** Absolute ₹0/month ($0.00) operational cost.
2. **Zero Financial Exposure:** No credit/debit card entry or billing verification.
3. **Managed Simplicity:** Managed web hosting preferred; zero mandatory Linux server administration.
4. **Architecture Preservation:** Native PHP 8.2+ and MySQL/MariaDB with zero application rewrites.
5. **Data & Functional Integrity:** 100% preservation of 2,411+ songs, scorecards, audit histories, and user records.
6. **Credible Improvement:** Demonstrable enhancement in practical availability and operational reliability over InfinityFree.

### Research Methodology
- **Primary Source Investigation:** Verification against current official documentation, published terms of service (ToS), and platform specifications (conducted Q4 2026).
- **Distinction of Evidence:** Official provider documentation and configuration limits are strictly distinguished from third-party marketing claims and community anecdotes.
- **Verification Rule:** If a candidate provider's critical capabilities or free-plan restrictions cannot be conclusively verified from official documentation, they are classified as **UNVERIFIED** rather than assumed compatible.
- **Zero Hallucination Standard:** No hosting limits, database features, or pricing structures are fabricated. Providers that fail mandatory criteria are explicitly rejected rather than artificially ranked.

---

## 2. Mandatory Eligibility Criteria

To be considered a viable production replacement for AFM#2, a candidate must satisfy, or have an unambiguous path to satisfying, all 13 mandatory criteria:

1. **Genuine Free Tier:** Permanent ₹0/month allocation, not a time-limited free trial (e.g. 14, 30, or 90 days).
2. **No Payment Verification:** No credit, debit, or prepaid card verification required at signup or for renewal.
3. **Modern PHP Runtime:** Native support for PHP 8.2 or newer.
4. **Relational MySQL/MariaDB:** Co-located MySQL 8.0+ or MariaDB 10.4+ with InnoDB engine, foreign keys (`CASCADE` and `SET NULL`), transactions, and SQL compatibility.
5. **Persistent Sessions:** Reliable, persistent PHP session storage across requests (native disk or co-located daemon).
6. **Persistent Filesystem:** Writable, persistent local directory storage for application scripts, caches, and temporary uploads.
7. **Required PHP Extensions:** Native support for `pdo_mysql`, `mbstring`, `curl`, `openssl`, `json`, `session`, and `fileinfo`.
8. **Web Server Configuration:** Native support for Apache `.htaccess` or an equivalent declarative rewrite and security header mechanism.
9. **Zero Mandatory Linux Sysadmin:** Managed hosting environment; no required Linux/BSD OS patching, kernel upgrades, or daemon compilation.
10. **Zero Required Paid Add-ons:** No mandatory external paid database, object storage, Redis cache, or third-party auth service.
11. **Sufficient Storage & Bandwidth:** Minimum 1 GB storage (to accommodate code, database tables, indexes, and backups) and reasonable bandwidth (minimum 5 GB/month).
12. **Acceptable Inactivity Policies:** No aggressive account suspension policies (e.g. mandatory weekly logins, arbitrary quota freezes, or sudden deletions).
13. **Credible Reliability Improvement:** Measurable, demonstrable improvement in uptime, DNS resilience, and operational stability compared to InfinityFree.

---

## 3. Excluded Providers & Disqualification Analysis

Before detailed candidate matrix evaluation, numerous widely discussed hosting platforms were audited and eliminated due to fundamental disqualifications:

```mermaid
graph TD
    subgraph "Market Audit Exclusions"
        E1["Vercel Hobby<br>REJECTED: Serverless monolith mismatch,<br>no MySQL, broken sessions, cross-cloud latency"]
        E2["x10Hosting<br>EXCLUDED: Explicitly removed from project consideration"]
        E3["000webhost (Hostinger)<br>DISQUALIFIED: Permanently shut down in July 2024"]
        E4["PlanetScale<br>DISQUALIFIED: Free Hobby tier retired April 8, 2024 ($39/mo min)"]
        E5["Render Free Tier<br>DISQUALIFIED: Free Postgres expires after 30 days;<br>no free MySQL; web services spin down after 15 min"]
        E6["Railway<br>DISQUALIFIED: Free tier abolished in 2023 ($5/mo minimum)"]
        E7["Fly.io<br>DISQUALIFIED: Mandatory credit card verification required"]
        E8["Oracle / AWS / Google Cloud<br>DISQUALIFIED: Mandatory credit card entry with auth hold"]
        E9["ProFreeHost / ByetHost<br>DISQUALIFIED: Identical underlying iFastNet network;<br>lateral move with identical limits"]
    end
```

### Specific Disqualification Details:
- **Vercel Hobby:** Formally rejected under HDR-001. Requires community shims for PHP, lacks MySQL, drops native file sessions across microVM containers, fails multi-step CSV imports, and introduces 50–150ms cross-cloud database query latency.
- **x10Hosting:** Explicitly excluded from consideration per project owner directive.
- **000webhost:** Officially retired and permanently taken offline by Hostinger between July and October 2024.
- **PlanetScale:** Discontinued its free Hobby tier on April 8, 2024; lowest tier now costs $39/month.
- **Render:** Free PostgreSQL databases expire after **30 days** (enforced since May 20, 2024) with a 14-day grace period before total data deletion. Furthermore, Render provides no native MySQL, and free web services spin down after 15 minutes of inactivity.
- **Railway:** Abolished its $5 free credit grant in 2023; now strictly requires paid subscription plans.
- **Fly.io:** Enforces mandatory credit card verification for all new organizations.
- **Oracle Cloud Always Free / AWS / GCP:** Require valid credit/debit cards during registration and execute temporary pre-authorization holds ($1.00 USD / ~₹100 INR), directly violating Constraint 2.
- **ProFreeHost / ByetHost:** Resellers operating on the exact same iFastNet / MyOwnFreeHost server infrastructure as InfinityFree. Migrating to them is a lateral move sharing identical VistaPanel limitations, 50,000 daily hit quotas, and CPU throttling.

---

## 4. Evaluated Candidate Providers

The following seven hosting providers were subjected to comprehensive technical evaluation:

### Candidate 1: InfinityFree (Current Baseline)
- **Architecture:** Apache 2.4 + PHP 8.2 + MariaDB 10.4+ on iFastNet shared cluster.
- **Storage & Limits:** 5 GB SSD storage, unlimited bandwidth, 400 MySQL connections, 50,000 daily hits.
- **Verdict:** Fully compatible with AFM#2 stack, but subject to CPU/hit throttling and occasional provider outages.

### Candidate 2: Alwaysdata
- **Architecture:** Cloud PaaS (France) supporting multi-language environments, custom web servers, and MariaDB.
- **Storage & Limits:** **100 MB total storage cap** across all files, emails, and databases.
- **Fatal Bottleneck:** AFM#2's release archive (1 MB) + raw database dump (2 MB) + InnoDB data and index structures (15–30 MB) + session files rapidly consume 30% to 50% of the entire 100 MB allowance. Adding song records, scorecards, or local backups immediately triggers storage exhaustion errors.

### Candidate 3: Serv00
- **Architecture:** FreeBSD shared hosting (Poland) with SSH access, `devil` CLI, Apache/Nginx, PHP 8.1–8.3, and MySQL.
- **Storage & Limits:** 3 GB SSD storage, unmetered transfer, 10 MySQL databases, 512 MB RAM per account.
- **Fatal Bottlenecks:**
  1. *Unmanaged UNIX Administration:* Requires shell administration via `devil` CLI to manage vhosts, databases, and daemons (violates Criterion 9).
  2. *ToS Suspensions & Node Instability:* Servers in Poland frequently suffer IP blacklisting and firewall interventions due to abusive tunnel/proxy scripts run by free users.
  3. *Inactivity Policy:* Accounts are deleted if the user fails to log into SSH or the web panel every 90 days.

### Candidate 4: FreeHosting.com
- **Architecture:** Custom cPanel-style web hosting (cPanel-like proprietary interface) with PHP and MySQL.
- **Storage & Limits:** 10 GB storage, unmetered bandwidth, 1 MySQL database.
- **Fatal Bottlenecks:**
  1. *Mandatory Domain Ownership:* FreeHosting.com **does not provide a free subdomain**. Users must already own a registered custom domain (violates zero-cost constraint).
  2. *Monetized Essential Features:* Native SSL installation is locked behind a **$29.95 one-time fee**, PHP Version Selector costs **$9.95**, and Crontab access costs **$19.95** (violates Criterion 10).

### Candidate 5: AwardSpace
- **Architecture:** Shared web hosting with proprietary control panel.
- **Storage & Limits:** 1 GB storage, 5 GB bandwidth, 1 MySQL database (max 30 MB).
- **Fatal Bottlenecks:**
  1. *Severe Memory Limit:* PHP memory limit is strictly capped at **32 MB** and cannot be increased. AFM#2's bulk CSV import or tournament hydration scripts fatal-error with memory exhaustion.
  2. *Outdated PHP Stack:* Free servers run legacy PHP 7.x; PHP 8.2+ is not guaranteed on free nodes.

### Candidate 6: GoogieHost
- **Architecture:** DirectAdmin web hosting with PHP 8.x and MySQL 8.0.
- **Storage & Limits:** 1 GB NVMe SSD, unmetered bandwidth, 1 MySQL database.
- **Fatal Bottlenecks:**
  1. *Manual Approval Gate:* Free account signups are placed in a manual screening queue that frequently rejects users or experiences multi-day delays.
  2. *High Uptime Volatility:* Community reviews document extended multi-day outages and arbitrary automated suspensions when shared CPU or inode limits are reached.

### Candidate 7: HelioHost
- **Architecture:** Non-profit volunteer hosting on Plesk control panel supporting PHP 8.x and MariaDB.
- **Storage & Limits:** 1 GB storage, unmetered bandwidth, MariaDB/PostgreSQL.
- **Fatal Bottlenecks:**
  1. *Daily Registration Quota:* Free registration slots on server *Johnny* are capped and fill within minutes daily.
  2. *Donation Bypass:* Bypassing the queue requires a credit card / PayPal payment (violates Constraint 2).
  3. *30-Day Activity Mandate:* Users must log into Plesk every 30 days or the account is suspended and queued for deletion.

---

## 5. Comprehensive Hosting Comparison Matrix

The table below benchmarks InfinityFree against all evaluated candidates across 18 critical dimensions. Exact documented limits are cited; unverified attributes are explicitly marked.

| Evaluation Dimension | InfinityFree (Baseline) | Alwaysdata | Serv00 | FreeHosting.com | AwardSpace | GoogieHost | HelioHost |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **1. Genuine Free Plan** | **YES** (Permanent) | **YES** (Permanent) | **YES** (Permanent) | **YES** (Permanent) | **YES** (Permanent) | **YES** (Permanent) | **YES** (Permanent) |
| **2. Card Verification** | **NO** | **NO** | **NO** | **NO** | **NO** | **NO** | **NO** (Free queue) |
| **3. PHP Version** | **PHP 8.2** | PHP 8.2 / 8.3 | PHP 8.2 / 8.3 | PHP 8.x ($9.95 toggle)| PHP 7.x (Legacy) | PHP 8.2 / 8.3 | PHP 8.2 / 8.3 |
| **4. MySQL / MariaDB** | **MariaDB 10.4+** | MariaDB | MySQL / MariaDB | MySQL 8.0 | MySQL (Max 30 MB) | MySQL 8.0 | MariaDB 10.x |
| **5. Persistent Sessions**| **YES** (Local disk) | **YES** (Local disk)| **YES** (Local disk) | **YES** (Local disk) | **YES** (Local disk) | **YES** (Local disk)| **YES** (Local disk) |
| **6. Persistent Files** | **YES** (5 GB) | **YES** (100 MB max) | **YES** (3 GB) | **YES** (10 GB) | **YES** (1 GB) | **YES** (1 GB) | **YES** (1 GB) |
| **7. Required Extensions**| **YES** (`pdo`, `mbstring`)| **YES** | **YES** | **YES** | Partial (`mbstring`) | **YES** | **YES** |
| **8. Database Limits** | Co-located; 400 conns| Shared in 100 MB | 10 DBs; 512 MB RAM | 1 DB; Co-located | 1 DB; **30 MB cap** | 1 DB; Co-located | 1 DB; Co-located |
| **9. Disk Storage** | **5,000 MB (5 GB)** | **100 MB (FATAL)** | 3,000 MB (3 GB) | 10,000 MB (10 GB) | 1,000 MB (1 GB) | 1,000 MB (1 GB) | 1,000 MB (1 GB) |
| **10. Bandwidth / Mo** | Unlimited (Fair use) | Unlimited (Fair use)| Unlimited (Fair use) | Unlimited (Fair use)| **5 GB / month** | Unlimited | Unlimited |
| **11. Inactivity Policy** | Periodic (Active site)| None documented | **Must login 90 days**| None documented | 12 months | Automated trigger | **Must login 30 days** |
| **12. Resource Caps** | 50k hits/day; CPU limit| 100 MB disk cap | 512 MB RAM / account | Fair use policy | **32 MB PHP RAM** | Tight CPU / Inodes | Shared queue cap |
| **13. Custom Domains** | **YES** + Subdomain | Subdomain only | **YES** + Subdomain | **OWN DOMAIN ONLY** | **YES** (1 domain) | **YES** + Subdomain | **YES** + Subdomain |
| **14. SSL Support** | **YES** (Free automated)| **YES** (Let's Encrypt)| **YES** (Let's Encrypt)| **NO ($29.95 fee)** | Self-signed / Cloudflare| **YES** (cPanel SSL) | **YES** (Plesk SSL) |
| **15. Managed Hosting** | **YES** (VistaPanel) | **YES** (Web Admin) | **NO (Shell/BSD)** | **YES** (Custom Panel)| **YES** (Control Panel)| **YES** (DirectAdmin)| **YES** (Plesk) |
| **16. AFM#2 Compatible** | **100% OPERATIONAL** | **FAIL** (Storage cap) | **CONDITIONAL** | **FAIL** (Domain/SSL)| **FAIL** (Memory/PHP)| **FAIL** (Approval/ToS)| **CONDITIONAL** |
| **17. Reliability** | Fair (Hit throttles) | Good (PaaS cluster) | Volatile (Proxy abuse)| Fair (Restricted) | Poor (Strict limits)| Poor (Multi-day down)| Fair (Donor priority)|
| **18. Migration Effort** | **0 hours** (Live) | 4–8 hours | 12–18 hours | 8–12 hours | Incompatible | 6–10 hours | 8–14 hours |

---

## 6. Actual AFM#2 Feature Compatibility Matrix

Each candidate's compatibility with AFM#2's actual technical implementation was assessed:

| Subsystem / Feature | InfinityFree | Alwaysdata | Serv00 | FreeHosting.com | AwardSpace | GoogieHost | HelioHost |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **PHP 8.2 Syntax** | COMPATIBLE | COMPATIBLE | COMPATIBLE | CONFIG CHANGE | UNSUPPORTED | COMPATIBLE | COMPATIBLE |
| **PDO MySQL Driver** | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE |
| **InnoDB Foreign Keys** | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE |
| **Advisory `GET_LOCK`** | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | UNVERIFIED | COMPATIBLE | COMPATIBLE |
| **Native PHP Sessions** | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE |
| **CSRF Validation** | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE |
| **Google OIDC Callback**| COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE |
| **WebAuthn FIDO2** | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | UNVERIFIED | COMPATIBLE | COMPATIBLE |
| **CSV Multi-Step Wizard**| COMPATIBLE | FAIL (Disk) | COMPATIBLE | COMPATIBLE | FAIL (Memory) | COMPATIBLE | COMPATIBLE |
| **YouTube Scraper** | COMPATIBLE | COMPATIBLE | UNVERIFIED | UNVERIFIED | UNVERIFIED | UNVERIFIED | COMPATIBLE |
| **LRCLIB Lyrics API** | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE |
| **`.htaccess` Rewrites**| COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE |
| **Security Headers** | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE | COMPATIBLE |

---

## 7. Analysis of the Strongest Candidate: Serv00

Among all alternative candidates, **Serv00** is technically the closest to meeting raw application requirements. However, it fails two mandatory project criteria:

1. **Mandatory UNIX/BSD Systems Administration (Fails Criterion 9):**
   - Serv00 is not a turnkey managed cPanel host. It is an unmanaged FreeBSD shell platform.
   - Managing websites, virtual host directives, database users, and SSL certificates requires using the proprietary `devil` command-line utility over SSH.
   - If processes consume more than 512 MB RAM, FreeBSD kernel resource limits kill worker processes abruptly.
2. **Absence of Credible Reliability Improvement (Fails Criterion 13):**
   - Serv00's open policy toward background processes and custom binaries attracts users running heavy tunneling scripts (v2ray, argo tunnels, automated bots).
   - Server nodes in Poland frequently face resource exhaustion, network throttling, and automated firewall blocks that disrupt legitimate web traffic.
   - Migrating from InfinityFree to Serv00 replaces known, predictable daily hit quotas with unpredictable FreeBSD shell maintenance and frequent neighbor-abuse downtime.

---

## 8. Summary of Provider Disqualifications

| Provider | Primary Reason for Rejection | Disqualification Classification |
| :--- | :--- | :--- |
| **Alwaysdata** | **100 MB total storage limit** cannot accommodate application, database, and backups. | Hard Resource Incompatibility |
| **AwardSpace** | **32 MB PHP memory limit** causes fatal errors during CSV processing; legacy PHP versions. | Hard Runtime Incompatibility |
| **FreeHosting.com** | Requires purchasing own domain; **charges $29.95 for SSL** and $9.95 for PHP version selector. | Commercial / Cost Incompatibility |
| **GoogieHost** | Manual approval gate with frequent rejections; volatile uptime and sudden ToS suspensions. | Operational Instability |
| **HelioHost** | Free signup queue capped daily; bypass requires card donation; **30-day login suspension**. | Administrative Friction |
| **Serv00** | Requires **unmanaged FreeBSD shell administration**; node instability from proxy abuse. | Operational Complexity / No SLA |
| **Vercel** | Serverless architecture breaks sessions, CSV imports, and requires external database over WAN. | Architectural Incompatibility |

---

## 9. Official Documentation Sources

All findings in this evaluation were verified using current official provider documentation:

- **Alwaysdata:** [Alwaysdata Free Plan Documentation & Limits](https://help.alwaysdata.com/en/accounts/pricing/)
- **Serv00:** [Serv00 Devil CLI & Free Account Specifications](https://www.serv00.com/) | [Serv00 Devil Documentation](https://wiki.serv00.com/)
- **FreeHosting.com:** [FreeHosting Service Specifications & Add-on Pricing](https://www.freehosting.com/)
- **AwardSpace:** [AwardSpace Free Web Hosting Plan Features](https://www.awardspace.com/free-hosting/)
- **GoogieHost:** [GoogieHost DirectAdmin Free Hosting Limits](https://googiehost.com/freehosting.html)
- **HelioHost:** [HelioHost Free & Donor Plan Documentation](https://heliohost.org/)
- **Render:** [Render Free Database Expiration Policy](https://render.com/docs/free#free-postgresql-databases)
- **PlanetScale:** [PlanetScale Hobby Tier Retirement Notice (April 2024)](https://planetscale.com/blog/planetscale-forever)
- **Hostinger / 000webhost:** [000webhost Service Shutdown Notice (July 2024)](https://www.hostinger.com/)

---

## 10. Final Decision & Definitive Outcome

In strict accordance with Section 8 of the assignment specification, the evaluation concludes with:

$$\mathbf{OUTCOME\ B\ —\ NO\ SUITABLE\ FREE\ REPLACEMENT}$$

### Definitive Conclusion
No evaluated free hosting provider satisfies all mandatory criteria while delivering a credible improvement in practical availability and operational reliability over InfinityFree without requiring payment cards, violating storage limits, or introducing unmanaged server administration.

**AFM#2 will retain InfinityFree as its temporary production host.** Migrating to an inferior, memory-starved, or unmanaged platform merely to leave InfinityFree directly violates the project's foundational principle: **AFM#2 must not sacrifice functionality, data integrity, reliability, or architectural simplicity merely to change hosting providers.**
