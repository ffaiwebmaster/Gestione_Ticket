# Task Manager - Gestionale Lavorazioni

> 🇮🇹 Versione italiana disponibile nella sezione [Wiki](https://github.com/ffaiwebmaster/Gestione_Ticket/wiki) o nel pannello Impostazioni dell'app.

A **single-file** web application (one PHP file + SQLite) for managing tasks and operational requests in any team. A setup wizard guides you through full configuration on first launch: sector, name, logo, team members, and request categories.

Works both **locally** (Windows/Mac/Linux PC) and **online** on any shared hosting with PHP.

**Stack:** PHP 8.1+ - SQLite - Zero external dependencies - 🌐 Italian / English

---

## Features

- **Setup wizard** on first launch (sector, name, logo, team, categories)
- Task tracking with type, priority, status, notes and comments
- Team assignment, filters, search, sorting
- KPI dashboard and monthly CSV report export
- **AI assistant** powered by Anthropic Claude API (optional)
- **2FA TOTP** for external network access - Google Authenticator / Authy (optional)
- Custom logo uploaded via browser, stored in the database
- Automatic daily database backup
- Full audit log
- Responsive dark-mode interface, works on mobile

**Preconfigured sectors in the wizard:** IT Department - Accountants - Law Firm - Medical Office - Barber/Salon - Agency/Studio - Workshop/Lab - Custom

---

## Requirements

- PHP 8.1 or higher
- PHP extensions: `pdo_sqlite`, `sqlite3`, `session` (enabled by default on most hosting providers)
- Modern browser (Chrome, Firefox, Safari, Edge)
- Internet connection only needed for the AI assistant (optional)

---

## Quick Start - Local (Windows)

### Step 1 - Download portable PHP

1. Go to **[windows.php.net/download](https://windows.php.net/download/)**
2. Download the **Thread Safe x64 ZIP** (e.g. `php-8.3.x-Win32-vs16-x64.zip`)
3. Extract to `C:\php\`
4. Rename `php.ini-development` to `php.ini`
5. Open `php.ini` with Notepad and make sure these lines have **no** `;` in front:

```
extension=pdo_sqlite
extension=sqlite3
extension=curl
```

> **Alternative:** If you have XAMPP installed, PHP is already at `C:\xampp\php\php.exe`.

### Step 2 - Download the app

**Option A - with Git:**

```
git clone https://github.com/ffaiwebmaster/Gestione_Ticket.git
```

**Option B - without Git:**
Click **Code -> Download ZIP** on the GitHub page, then extract to a folder of your choice (e.g. `C:\gestionale\`).

### Step 3 - Configure security credentials

Open `gestionale_lavorazioni.php` with any text editor and edit the top section:

```
define('SETTINGS_PIN',  'yourPin');          // PIN for the Settings panel
define('AUTH_PASSWORD', 'yourPassword');      // password for external network access
define('TOTP_SECRET',   'GENERATESECRET16'); // 2FA secret - see below
define('TOTP_ACCOUNT',  'team@example.com'); // email shown in Authenticator
```

**How to generate a secure TOTP secret** (open Command Prompt in the PHP folder):

```
C:\php\php.exe -r "$c='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$s='';for($i=0;$i<16;$i++)$s.=$c[random_int(0,31)];echo $s.PHP_EOL;"
```

> If you use the app **only on your local network (LAN)**, you can leave the default credentials - 2FA is only required for external connections.

### Step 4 - Start the server

**Double-click `AVVIA.bat`** - a black window appears with the server running.

If PHP is not at `C:\php\php.exe`, open the BAT file with Notepad and edit:

```
set PHP_EXE=C:\php\php.exe
```

The app will be available at:

- **Same PC:** `http://localhost:8080/gestionale_lavorazioni.php`
- **Other PCs or smartphones on the same Wi-Fi:** `http://[YOUR-PC-IP]:8080/gestionale_lavorazioni.php`

To find your IP: open Command Prompt and type `ipconfig`, look for "IPv4 Address".

### Step 5 - First launch: the wizard

The **setup wizard** starts automatically on first access (5 steps, under 2 minutes).

---

## Quick Start - Local (macOS / Linux)

```bash
# Check PHP is installed
php -v

# If not installed:
# macOS:   brew install php
# Ubuntu:  sudo apt install php php-sqlite3

# Clone the repository
git clone https://github.com/ffaiwebmaster/Gestione_Ticket.git
cd Gestione_Ticket

# Start the server
php -S 0.0.0.0:8080

# Open your browser at:
# http://localhost:8080/gestionale_lavorazioni.php
```

---

## Online Deployment - Shared Hosting (Aruba, Hostinger, SiteGround, etc.)

The simplest way to make the app accessible from the internet - no server configuration needed.

### Hosting requirements

- PHP 8.1+ with `pdo_sqlite` and `sqlite3` (supported by virtually all hosting providers)
- FTP access or File Manager in the control panel

### Steps

1. **Upload the files** via FTP or the hosting File Manager to the `public_html` folder (or a subfolder, e.g. `public_html/gestionale/`)
2. **Protect the database** - create a `.htaccess` file in the same folder:

```
# Block direct access to database and logs
<FilesMatch "\.(sqlite|log|gitkeep)$">
    Order allow,deny
    Deny from all
</FilesMatch>
```

3. **Access via browser** at the corresponding URL:

```
https://yourdomain.com/gestionale/gestionale_lavorazioni.php
```

4. The first-launch wizard starts automatically.

---

## Online Deployment - VPS / Dedicated Server

### Apache

```apache
<VirtualHost *:80>
    ServerName gestionale.yourdomain.com
    DocumentRoot /var/www/gestionale
    <Directory /var/www/gestionale>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### nginx

```nginx
server {
    listen 80;
    server_name gestionale.yourdomain.com;
    root /var/www/gestionale;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~* \.(sqlite|log)$ { deny all; }
}
```

### HTTPS (recommended)

```bash
sudo certbot --nginx -d gestionale.yourdomain.com
```

---

## Online Deployment - Cloudflare Tunnel (no port forwarding)

Expose your local server to the internet **without configuring your router** and without a hosting plan.

```bash
# Install cloudflared from:
# https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/

# Login to Cloudflare
cloudflared tunnel login

# Start a quick tunnel pointing to local port 8080
cloudflared tunnel --url http://localhost:8080
```

Cloudflare provides a temporary URL like `https://xxxx.trycloudflare.com` - works immediately. For a permanent URL with your domain, configure it in the Cloudflare Zero Trust dashboard.

> **Security tip:** pair the tunnel with a Cloudflare Access Application to restrict who can reach your instance. Authentication happens entirely on Cloudflare's servers before any request touches yours.

---

## AI Assistant (optional)

Uses the Anthropic Claude API. Costs a few cents per month for normal use.

1. Create an account at [console.anthropic.com](https://console.anthropic.com)
2. Go to **API Keys** and generate a key
3. In the app, open **Settings -> AI** and paste the key

**Alternative - environment variable:**

```powershell
# Windows PowerShell
$env:ANTHROPIC_API_KEY="sk-ant-xxxx"
# then double-click AVVIA.bat
```

```bash
# Linux/macOS
ANTHROPIC_API_KEY="sk-ant-xxxx" php -S 0.0.0.0:8080
```

---

## 2FA Authentication (optional)

Useful if accessed from the internet or untrusted networks. Automatically bypassed on local networks (192.168.x.x, 10.x.x.x).

1. Set `TOTP_SECRET`, `AUTH_PASSWORD`, `TOTP_ACCOUNT` in the PHP file
2. On first external access, the app shows a QR code
3. Scan it with **Google Authenticator** or **Authy**
4. Every external login will require password + 6-digit OTP

---

## Project Structure

```
Gestione_Ticket/
├── gestionale_lavorazioni.php    # The entire application (single-file)
├── gestionale.sqlite             # Database - created automatically on first launch
├── gestionale.log                # Audit log - created automatically
├── sqlite_backup_data/           # Automatic daily backups
│   └── .gitkeep
├── AVVIA.bat                     # Quick-start server launcher for Windows
├── .gitignore                    # Excludes database and logs from Git
└── README.md
```

> Uploaded logos are stored in the database as base64. Database, logs and backups are never committed to Git (covered by `.gitignore`).

---

## Security Checklist (before going online)

- Change `SETTINGS_PIN` to a personal PIN
- Change `AUTH_PASSWORD` to a strong password
- Generate a new `TOTP_SECRET` with the command above
- On shared hosting: create the `.htaccess` file to protect the database
- Always use HTTPS (Let's Encrypt is free)
- Never commit the `.sqlite` file to a public repository

---

## FAQ

**Will the database be lost if I update the app?**
No. The `gestionale.sqlite` file is separate from the PHP code. Updating the PHP file does not touch your data.

**Can multiple users use it simultaneously?**
Yes, from multiple devices at the same time - the database is shared server-side.

**Does it work with XAMPP?**
Yes. Put the PHP file in `htdocs/gestionale/` and access via `http://localhost/gestionale/gestionale_lavorazioni.php`.

**How do I back up my data?**
From the Settings -> Backup & Log panel. Or manually copy the `gestionale.sqlite` file.

**How do I restart the wizard from scratch?**
Delete the `gestionale.sqlite` file and reload the page. The wizard restarts (all data will be lost).

---

## License

MIT - free to use, modify and distribute.
