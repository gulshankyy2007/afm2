# Deploying AFM#2 on InfinityFree (100% Free — No Credit Card Required)

InfinityFree is the perfect zero-cost solution for hosting **AFM#2**:
- **100% Free Forever** (No credit/debit card required at any point)
- **Runs 24/7 in the Cloud** (Your laptop can be turned off completely)
- **Apache + PHP 8.2 + MySQL Included**
- **Free Subdomain Provided** (e.g. `yourname.infinityfreeapp.com`) or use your own custom domain
- **Full phpMyAdmin Access** for one-click database management

---

## Pre-Packaged Assets in Your Project
Everything has been updated and packaged for you:
1. **Deployment Archive:** `afm2-infinityfree.zip` (2.3 MB) — pre-structured with the root `.htaccess` rewrite rule to `public/`.
2. **Complete Database Dump:** `deployment/afm2_full_backup.sql` (273 KB) — contains all 2,411 songs, tournament stages, criteria, and YouTube matching tables.
3. **Environment Template:** `.env.infinityfree` — pre-formatted for InfinityFree MySQL settings.

---

## Step 1: Create Your InfinityFree Account & Domain
*(If you already have an InfinityFree account and domain, skip to Step 2)*

1. Go to **[infinityfree.com](https://www.infinityfree.com/)** and click **Register Now** (only email & password required).
2. Verify your email and log in to the **Client Area**.
3. Click **Create Account**.
4. Choose a Domain Type:
   - **Subdomain (Free):** Enter your preferred name (e.g. `afm2-music`) and select an extension (e.g. `.infinityfreeapp.com` or `.great-site.net`).
   - *(Or Custom Domain if you own one)*.
5. Click **Check Availability** $\rightarrow$ Click **Create Account**.
6. Wait 1–2 minutes while the hosting account is provisioned.

---

## Step 2: Create Your MySQL Database
1. In your InfinityFree account dashboard, click **Manage** on your account $\rightarrow$ click **Control Panel** (or VistaPanel).
2. In the Control Panel, find the **Databases** section and click **MySQL Databases**.
3. Under **Create New Database**:
   - Enter `afm_db` in the database name field.
   - Click **Create Database**.
4. You will see a table displaying your database connection details. **Copy these down**:
   - **MySQL Hostname:** (e.g., `sql200.infinityfree.com` or `sql305.infinityfree.com`)
   - **MySQL Database Name:** (e.g., `if0_12345678_afm_db`)
   - **MySQL Username:** (e.g., `if0_12345678`)
   - **MySQL Password:** (Your InfinityFree account password — find it in the Client Area under *Account Details* $\rightarrow$ *Show Password*).
   - **MySQL Port:** `3306`

---

## Step 3: Import the Database (All 2,411 Songs)
1. Go back to the Control Panel and click **phpMyAdmin**.
2. Click **Connect** next to your newly created database (`if0_12345678_afm_db`).
3. Inside phpMyAdmin:
   - Click your database name in the left sidebar to select it.
   - Click the **Import** tab in the top navigation bar.
   - Click **Choose File** (or Browse) and select `afm2_full_backup.sql` (located in `/mnt/data/GY/Music/AFM#2/deployment/afm2_full_backup.sql`).
   - Scroll to the bottom and click **Import** (or **Go**).
4. After 3–5 seconds, you will see a green success banner: *"Import has been successfully finished"*.
   - All tables (`songs`, `song_media`, `song_youtube_candidates`, `ratings`, `tournament_rounds`, `app_users`, etc.) are now live!

---

## Step 4: Upload Project Files to `htdocs/`

You can upload using either the **Web File Manager** or **FileZilla (FTP)**:

### Option A: Via Web File Manager (Easiest — No Extra Software)
1. In the Control Panel or Client Area, click **File Manager** (Monsta FTP opens in your browser).
2. Double-click the **`htdocs`** folder to open it.
   *(Delete the default `index2.html` placeholder file if present)*.
3. Click the **Upload** button $\rightarrow$ select **Upload Zip Archive**.
4. Choose `afm2-infinityfree.zip` from `/mnt/data/GY/Music/AFM#2/`.
5. Check the box to **Extract Archive** automatically upon upload.
6. Once uploaded and extracted, your `htdocs/` folder should contain:
   - `.htaccess`
   - `config/`
   - `includes/`
   - `public/`
   - `migrations/`
   - `.env.infinityfree`

### Option B: Via FileZilla FTP (Recommended for Speed & Resumes)
1. Download [FileZilla](https://filezilla-project.org/) (free).
2. In InfinityFree Client Area, copy your **FTP Details**:
   - **FTP Host:** `ftpupload.net`
   - **FTP Username:** (e.g. `if0_12345678`)
   - **FTP Password:** (Your hosting account password)
   - **Port:** `21`
3. Connect and navigate into `/htdocs/`.
4. Extract `afm2-infinityfree.zip` on your computer, then drag all extracted folders and `.htaccess` into `/htdocs/`.

---

## Step 5: Configure `.env`
1. In the **File Manager** inside `htdocs/`:
   - Rename `.env.infinityfree` to `.env` (or click **New File** $\rightarrow$ name it `.env`).
2. Edit the `.env` file and paste your credentials from Step 2:
   ```ini
   DB_HOST=sqlxxx.infinityfree.com
   DB_PORT=3306
   DB_NAME=if0_xxxxxxxx_afm_db
   DB_USER=if0_xxxxxxxx
   DB_PASS=YourHostingPasswordHere
   APP_ENV=production
   APP_SECRET=your_secure_generated_64_character_hex_secret
   SESSION_LIFETIME=86400
   ```
3. Click **Save** (disk icon).

---

## 🎉 That's It! Your App is Live 24/7!

Open your InfinityFree subdomain in any web browser:
```text
https://yourname.infinityfreeapp.com/
```

- **Admin Login:** `gulshan`
- **Admin Password:** Your secure administrator password (configured during setup or in `.env`)

### What Works Online:
- Complete 2,411-song catalogue with real YouTube Music metadata
- YouTube Recording Discovery, Candidate Inspection, Score Breakdown, and Manual Link Entry
- Audition ratings, Criteria scoring, and Progress bars
- Full Tournament Stages & Bracket Engine
- Advanced Audio Player with YouTube embed fallback
- Works 24/7 from phone, tablet, or desktop with 0 dependency on your laptop
- **Cost: $0.00 forever**
