# Deploying AFM#2 on Oracle Cloud "Always Free" (100% Free Forever)

Oracle Cloud Infrastructure (OCI) offers an **Always Free Tier** with generous compute and networking that never expires:
- **Up to 4 Ampere ARM cores + 24 GB RAM** (or 2 AMD Micro VMs)
- **200 GB Block Storage**
- **10 TB free monthly outbound transfer**
- **Static Public IPv4 address included**

Follow these 4 simple steps to get AFM#2 running 24/7 online for free.

---

## Step 1: Create Your Free Oracle Cloud Account
1. Go to **[signup.cloud.oracle.com](https://signup.cloud.oracle.com/)**.
2. Select your country and enter your name/email.
3. Choose your **Home Region** (pick the location closest to you, e.g. Mumbai, Frankfurt, US East, etc.).
4. Add a payment card for identity verification (*Oracle makes a temporary ~$1 hold that is immediately reversed. As long as you stay within the "Always Free Eligible" resources, you are never charged*).

---

## Step 2: Create an Always Free Ubuntu Instance
1. In the OCI Console dashboard, click **"Create a VM instance"** (or open the menu: **Compute** $\rightarrow$ **Instances** $\rightarrow$ **Create Instance**).
2. **Name:** `afm2-server`
3. **Image and Shape:**
   - Click **Edit**.
   - **Image:** Click *Change Image*, select **Canonical Ubuntu** (Ubuntu 24.04 or 22.04 LTS).
   - **Shape:** Click *Change Shape*, choose:
     - **Ampere (ARM)** $\rightarrow$ `VM.Standard.A1.Flex` $\rightarrow$ Select **2 OCPUs, 12 GB RAM** *(Notice the green "Always Free Eligible" tag)*.
     - *(Alternative)*: **AMD** $\rightarrow$ `VM.Standard.E2.1.Micro` (1 OCPU, 1 GB RAM).
4. **Networking:**
   - Leave default Virtual Cloud Network (VCN).
   - Ensure **"Assign a public IPv4 address"** is selected.
5. **Save SSH Keys (Important!):**
   - Under *Add SSH keys*, click **"Save private key"** (downloads a file like `ssh-key-2026-xx-xx.key` to your computer).
6. Click **Create** at the bottom.
   - Wait 1–2 minutes until the instance status changes from yellow *PROVISIONING* to green *RUNNING*.
   - Copy your **Public IP Address** (e.g. `140.238.xx.xx`).

---

## Step 3: Open HTTP/HTTPS Ports in Oracle Cloud Firewall
By default, Oracle Cloud blocks web traffic on port 80/443. To open it:

1. On your instance page, click on your **Virtual Cloud Network (VCN)** link (under *Instance Details* $\rightarrow$ *Primary VNIC*).
2. Click on **Security Lists** (left sidebar) $\rightarrow$ click **Default Security List for...**.
3. Click **Add Ingress Rules**:
   - **Source CIDR:** `0.0.0.0/0`
   - **IP Protocol:** `TCP`
   - **Destination Port Range:** `80,443`
   - **Description:** `Allow HTTP and HTTPS`
4. Click **Add Ingress Rules**.

---

## Step 4: Deploy AFM#2 to Your Server (3 Commands)

Open your local terminal (where you have your downloaded Oracle SSH private key and AFM#2 project):

### 1. Fix SSH Key Permissions:
```bash
chmod 400 ~/Downloads/ssh-key-*.key
```

### 2. Copy the AFM#2 Deployment Bundle to your Oracle Server:
*(Replace `~/Downloads/ssh-key-*.key` with your key file path and `<ORACLE_IP>` with your public IP)*
```bash
scp -i ~/Downloads/ssh-key-*.key /mnt/data/GY/Music/AFM#2/afm2-deploy.tar.gz ubuntu@<ORACLE_IP>:~
```

### 3. SSH into your Oracle Server and Run Setup:
```bash
ssh -i ~/Downloads/ssh-key-*.key ubuntu@<ORACLE_IP>
```
Once inside your Oracle server, paste this single line:
```bash
mkdir -p /var/www/afm2 && tar -xzf ~/afm2-deploy.tar.gz -C /var/www/afm2 && cd /var/www/afm2/deployment && sudo bash vps-setup.sh
```

---

## 🎉 That's It!
The script will automatically:
- Install Docker & Docker Compose
- Start the Apache/PHP web container and MySQL container
- Seed all **2,475 songs**, criteria schemas, and competition stages
- Open the local firewall ports

Your music journal will be live 24/7 at:
```text
http://<ORACLE_IP>/
```

### Initial Credentials:
- **Username:** `gulshan`
- **Password:** `<Configured in .env / setup.php>`
