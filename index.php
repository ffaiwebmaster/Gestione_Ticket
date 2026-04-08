<?php
// Serve manifest PWA evitando il filtro antibot di InfinityFree
if (isset($_GET['manifest'])) {
    header('Content-Type: application/manifest+json');
    header('Cache-Control: no-cache');
    echo json_encode([
        'name'             => 'Gestione Ticket',
        'short_name'       => 'Gestione Ticket',
        'description'      => 'Task manager for any team — PHP + SQLite, self-hosted.',
        'start_url'        => '/',
        'scope'            => '/',
        'display'          => 'standalone',
        'background_color' => '#1a2535',
        'theme_color'      => '#1a2535',
        'orientation'      => 'portrait-primary',
        'lang'             => 'it',
        'icons'            => [
            ['src'=>'assets/icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],
            ['src'=>'assets/icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'maskable'],
            ['src'=>'assets/icon-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any'],
            ['src'=>'assets/icon-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'maskable'],
        ],
        'screenshots' => [
            ['src'=>'assets/icon-512.png','sizes'=>'512x512','type'=>'image/png','form_factor'=>'narrow'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ================================================================
// Gestione Ticket — Task Manager  
// Single-file PHP + SQLite  |  AI Assistant  |  2FA TOTP
// ================================================================
// CONFIGURAZIONE - modifica solo questi valori
// ----------------------------------------------------------------
define('DB_FILE',       __DIR__ . '/gestione_ticket.sqlite');
define('BACKUP_DIR',    __DIR__ . '/sqlite_backup_data');
define('UPLOAD_DIR',    __DIR__ . '/allegati');
define('LOG_FILE',      __DIR__ . '/gestione_ticket.log');
define('APP_VERSION',   '4.6');
define('ANTHROPIC_KEY', getenv('ANTHROPIC_API_KEY') ?: '');

// Carica configurazioni esterne se presenti
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Valori di fallback se non definiti in config.php
if (!defined('SETTINGS_PIN'))  define('SETTINGS_PIN',  '1234');
if (!defined('AUTH_PASSWORD')) define('AUTH_PASSWORD', 'CAMBIA_QUESTA_PASSWORD');
if (!defined('TOTP_SECRET'))   define('TOTP_SECRET',   'GENERA_UN_NUOVO_SEGRETO');
if (!defined('SMTP_HOST'))     define('SMTP_HOST', 'smtp-relay.brevo.com');
if (!defined('SMTP_PORT'))     define('SMTP_PORT', 587);
if (!defined('SMTP_USER'))     define('SMTP_USER', '');
if (!defined('SMTP_PASS'))     define('SMTP_PASS', '');
if (!defined('SMTP_FROM'))     define('SMTP_FROM', 'noreply@esempio.it');

// Nome che appare in Google Authenticator
define('TOTP_ISSUER',   'Gestione Ticket');
define('TOTP_ACCOUNT',  'team@esempio.it');
// Subnet interne - su questi IP NON viene richiesta autenticazione
define('INTERNAL_SUBNETS', ['10.0.', '192.168.', '172.16.', '172.17.',
    '172.18.', '172.19.', '172.20.', '172.21.', '172.22.', '172.23.',
    '172.24.', '172.25.', '172.26.', '172.27.', '172.28.', '172.29.',
    '172.30.', '172.31.', '127.', '::1']);
// Durata sessione autenticata in secondi (default 8 ore)
define('SESSION_TTL', 43200);
// ================================================================

// ── GESTIONE ERRORI GLOBALE ────────────────────────────────────
set_exception_handler(function(Throwable $e) {
    $msg = date('Y-m-d H:i:s').' FATAL: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine();
    if (defined('LOG_FILE')) @file_put_contents(LOG_FILE, $msg.PHP_EOL, FILE_APPEND);
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8">
    <title>Gestione Ticket — Errore</title>
    <style>body{font-family:system-ui;background:#0b1929;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
    .box{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:40px;max-width:500px;text-align:center}
    .icon{font-size:3rem;margin-bottom:16px}.title{font-size:1.2rem;font-weight:700;margin-bottom:8px}
    .sub{font-size:.85rem;color:#94a3b8;line-height:1.6}.btn{margin-top:24px;display:inline-block;
    background:#2d7dd2;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:600}</style>
    </head><body><div class="box">
    <div class="icon">⚠️</div>
    <div class="title">Si è verificato un errore</div>
    <div class="sub">Il server ha incontrato un problema.<br>Riavvia <b>AVVIA.bat</b> e riprova.<br><small style="opacity:.5">'.htmlspecialchars($e->getMessage()).'</small></div>
    <a href="javascript:location.reload()" class="btn">↻ Riprova</a>
    </div></body></html>';
    exit;
});

// ── BACKUP AUTOMATICO SQLITE ───────────────────────────────────
function eseguiBackup(): void {
    if (!file_exists(DB_FILE)) return;
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
    
    // Backup rotativo basato sul giorno della settimana (es. backup_Mon.sqlite)
    $dest = BACKUP_DIR . '/backup_' . date('D') . '.sqlite';
    
    @copy(DB_FILE, $dest);
    $size = file_exists($dest) ? round(filesize($dest)/1024, 1).'KB' : '?';
    audit_log('BACKUP', 'sistema', "Backup eseguito → $dest ($size)");
}

// Esegui backup automatico una volta al giorno (controlla timestamp)
function backupSeNecessario(): void {
    $flagFile = BACKUP_DIR . '/.last_backup';
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0755, true);
    $ultimoBackup = file_exists($flagFile) ? (int)file_get_contents($flagFile) : 0;
    if ((time() - $ultimoBackup) > 86400) { // ogni 24 ore
        eseguiBackup();
        file_put_contents($flagFile, time());
    }
}

// ── AUDIT LOG ─────────────────────────────────────────────────
function audit_log(string $azione, string $utente, string $dettaglio = ''): void {
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '-';
    $ts   = date('Y-m-d H:i:s');
    $riga = "[$ts] [$ip] [$utente] $azione" . ($dettaglio ? " - $dettaglio" : '') . PHP_EOL;
    @file_put_contents(LOG_FILE, $riga, FILE_APPEND | LOCK_EX);
    // Ruota il log se supera 5MB
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > 5 * 1024 * 1024) {
        @rename(LOG_FILE, LOG_FILE.'.old');
    }
}

// ── SESSIONE ───────────────────────────────────────────────────
session_name('gestione_ticket');
session_start();

// Backup automatico giornaliero (zero impatto su UX - eseguito in background)
backupSeNecessario();

// Helper audit nel DB (oltre al file di log)
function audit_db(string $azione, string $utente, int $entitaId = 0, string $dettaglio = ''): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
        db()->prepare("INSERT INTO audit_trail(utente,ip,azione,entita_id,dettaglio) VALUES(?,?,?,?,?)")
            ->execute([$utente, $ip, $azione, $entitaId ?: null, $dettaglio]);
    } catch(Exception $e) { /* audit non blocca mai l'operazione */ }
}

// ── NOTIFICHE PUSH (OneSignal) ────────────────────────────────
define('ONESIGNAL_APP_ID',  'd94f94d6-95ff-4862-90f2-d73817a09130');
define('ONESIGNAL_API_KEY', 'os_v2_app_3fhzjvuv75egfehs244bpiergbbhzxkhp4def2esujnq6gnosws4nyxvydtbptvfhqedmi5ahla4g6itgoxyf3la7jwmhsqe3kake5q');

/**
 * Invia una notifica push a tutti i dispositivi iscritti via OneSignal.
 * Chiamata in background (non blocca la risposta JSON).
 */
function invia_notifica(string $titolo, string $messaggio, ?string $url = null): void {
    if (!function_exists('curl_init')) return;
    $payload = array_filter([
        'app_id'            => ONESIGNAL_APP_ID,
        'headings'          => ['en' => $titolo, 'it' => $titolo],
        'contents'          => ['en' => $messaggio, 'it' => $messaggio],
        'included_segments' => ['All'],
        'url'               => $url,
    ], fn($v) => $v !== null);
    // Chiude la connessione HTTP prima di chiamare OneSignal
    // così il client non aspetta il timeout della chiamata esterna
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    $ch = curl_init('https://onesignal.com/api/v1/notifications');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . ONESIGNAL_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Rilevamento IP interno ─────────────────────────────────────
function is_internal_ip(): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // Supporto proxy / Cloudflare Tunnel (header standard)
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        // Richiesta arriva da Cloudflare → sicuramente esterna
        return false;
    }
    foreach (INTERNAL_SUBNETS as $subnet) {
        if (str_starts_with($ip, $subnet)) return true;
    }
    return false;
}

// ── TOTP puro PHP (RFC 6238) - zero dipendenze ────────────────
function totp_verify(string $secret, string $code, int $window = 1): bool {
    $secret  = totp_base32_decode($secret);
    $code    = preg_replace('/\s/', '', $code);
    $time    = (int) floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        $t   = pack('N*', 0) . pack('N*', $time + $i);
        $hmac = hash_hmac('sha1', $t, $secret, true);
        $off  = ord($hmac[19]) & 0x0F;
        $otp  = (
            ((ord($hmac[$off])   & 0x7F) << 24) |
            ((ord($hmac[$off+1]) & 0xFF) << 16) |
            ((ord($hmac[$off+2]) & 0xFF) <<  8) |
             (ord($hmac[$off+3]) & 0xFF)
        ) % 1000000;
        if (str_pad((string)$otp, 6, '0', STR_PAD_LEFT) === str_pad($code, 6, '0', STR_PAD_LEFT)) {
            return true;
        }
    }
    return false;
}

function totp_base32_decode(string $b32): string {
    $b32     = strtoupper(preg_replace('/\s/', '', $b32));
    $charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits    = '';
    foreach (str_split($b32) as $c) {
        $pos = strpos($charset, $c);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
}

function totp_qr_url(): string {
    $otpauth = 'otpauth://totp/'.rawurlencode(TOTP_ISSUER.':'.TOTP_ACCOUNT)
              .'?secret='.TOTP_SECRET.'&issuer='.rawurlencode(TOTP_ISSUER).'&algorithm=SHA1&digits=6&period=30';
    return 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='.rawurlencode($otpauth);
}

// ── Autenticazione: solo rete interna per ora ─────────────────
// Il 2FA esterno verrà riabilitato su richiesta
$is_internal  = is_internal_ip();
$is_auth      = (defined('INTERNAL_ONLY') && INTERNAL_ONLY && $is_internal)
    || (isset($_SESSION['ales_auth']) && $_SESSION['ales_auth'] === true
        && isset($_SESSION['ales_ts'])  && (time() - $_SESSION['ales_ts']) < SESSION_TTL);

// ── Rate limiting: blocco IP dopo 2 tentativi falliti ─────────
function rl_db(): PDO {
    static $rl = null;
    if ($rl) return $rl;
    $rl = new PDO('sqlite:' . __DIR__ . '/login_attempts.sqlite');
    $rl->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $rl->exec("CREATE TABLE IF NOT EXISTS attempts (
        ip        TEXT PRIMARY KEY,
        count     INTEGER DEFAULT 0,
        blocked   INTEGER DEFAULT 0,
        last_try  INTEGER DEFAULT 0
    )");
    return $rl;
}

function rl_is_blocked(string $ip): bool {
    $db = rl_db();
    $row = $db->prepare("SELECT blocked, count FROM attempts WHERE ip=?");
    $row->execute([$ip]);
    $r = $row->fetch(PDO::FETCH_ASSOC);
    return $r && ($r['blocked'] || $r['count'] >= 2);
}

function rl_fail(string $ip): void {
    $db = rl_db();
    $db->prepare("INSERT INTO attempts (ip, count, blocked, last_try)
        VALUES (?, 1, 0, ?)
        ON CONFLICT(ip) DO UPDATE SET
            count    = count + 1,
            blocked  = CASE WHEN count + 1 >= 2 THEN 1 ELSE 0 END,
            last_try = excluded.last_try")
       ->execute([$ip, time()]);
}

function rl_reset(string $ip): void {
    rl_db()->prepare("DELETE FROM attempts WHERE ip=?")->execute([$ip]);
}

// Gestione login da rete esterna (POST con password + OTP)
if (!$is_auth && isset($_POST['_login'])) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $pwd = $_POST['pwd'] ?? '';
    $otp = $_POST['otp'] ?? '';

    if (rl_is_blocked($ip)) {
        $errLogin = 'Accesso bloccato per troppi tentativi falliti. Contatta l\'amministratore IT.';
    } elseif ($pwd === AUTH_PASSWORD && totp_verify(TOTP_SECRET, $otp)) {
        rl_reset($ip);
        $_SESSION['ales_auth'] = true;
        $_SESSION['ales_ts']   = time();
        audit_log('LOGIN', 'esterno');
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    } else {
        rl_fail($ip);
        $row = rl_db()->prepare("SELECT count FROM attempts WHERE ip=?");
        $row->execute([$ip]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        $rimasti = max(0, 2 - (int)($r['count'] ?? 0));
        if ($rimasti === 0) {
            audit_log('LOGIN_BLOCCATO', $ip, 'IP bloccato dopo 2 tentativi');
            $errLogin = 'Accesso bloccato per troppi tentativi falliti. Contatta l\'amministratore IT.';
        } else {
            $errLogin = "Password o codice OTP errati. Tentativo rimanente: $rimasti.";
        }
    }
}


// Logout (reset utente)
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Cambio utente
if (isset($_GET['cambia_utente'])) {
    unset($_SESSION['ales_user'], $_SESSION['ales_user_nome'], $_SESSION['ales_readonly']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Selezione utente
if (isset($_POST['_scegli_utente'])) {
    $sigla = trim($_POST['sigla'] ?? '');
    $nome  = trim($_POST['nome']  ?? '');
    // Admin (FF/FP): richiede PIN
    if (in_array($sigla, ['FF','FP'], true)) {
        $pin = trim($_POST['pin'] ?? '');
        if ($pin !== SETTINGS_PIN) {
            header('Location: ?pin_err=1&sigla=' . urlencode($sigla));
            exit;
        }
        $_SESSION['ales_user']      = $sigla;
        $_SESSION['ales_user_nome'] = $nome;
        $_SESSION['ales_readonly']  = false;
    } elseif ($sigla === 'OSPITE' && $nome) {
        // Utente ospite: sola lettura, nessun PIN
        $_SESSION['ales_user']      = 'OSPITE';
        $_SESSION['ales_user_nome'] = $nome;
        $_SESSION['ales_readonly']  = true;
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$utente_sigla = $_SESSION['ales_user']      ?? '';
$utente_nome  = $_SESSION['ales_user_nome'] ?? '';
$is_readonly  = $_SESSION['ales_readonly']   ?? false;

// Se non autenticato → mostra pagina login
// ── Invio QR via email ───────────────────────────────────────────
if (isset($_POST['_richiedi_qr'])) {
    $email = strtolower(trim($_POST['email_qr'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header('Location: ?qr_err='.urlencode('Indirizzo email non valido.'));
        exit;
    }
    $qrUrl  = totp_qr_url();
    $secret = TOTP_SECRET;

    // Invio via Brevo API HTTP (porta 443, sempre disponibile)
    function smtp_send(string $to, string $subject, string $msgBody): bool {
        $apiKey = SMTP_PASS; // riuso SMTP_PASS per la API key Brevo
        $payload = json_encode([
            'sender'     => ['name' => 'Gestione Ticket', 'email' => SMTP_FROM],
            'to'         => [['email' => $to]],
            'subject'    => $subject,
            'textContent'=> $msgBody,
        ]);
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", [
                'Content-Type: application/json',
                'api-key: ' . $apiKey,
                'Content-Length: ' . strlen($payload),
            ]),
            'content' => $payload,
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $ctx);
        $code = 0;
        if (isset($http_response_header)) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
            $code = (int)($m[1] ?? 0);
        }
        return $code >= 200 && $code < 300;
    }

    $corpo = "Ciao,\r\n\r\n"
           . "Hai richiesto la configurazione accesso sicuro al gestionale.\r\n\r\n"
           . "Apri Google Authenticator e scansiona il QR code al link:\r\n"
           . $qrUrl . "\r\n\r\n"
           . "Oppure inserisci manualmente il codice segreto:\r\n"
           . $secret . "\r\n\r\n"
           . "Accedi su: https://alesit.free.nf\r\n\r\n"
           . "--- Messaggio automatico Gestione Ticket ---";

    $sent = smtp_send($email, 'Gestione Ticket - Configurazione Google Authenticator', $corpo);
    audit_log('QR_EMAIL', $email, $sent ? 'inviata via Brevo SMTP' : 'errore SMTP');
    if ($sent) {
        header('Location: ?qr_inviato=1');
    } else {
        header('Location: ?qr_err='.urlencode('Errore invio email. Contatta l\'amministratore.'));
    }
    exit;
}

if (!$is_auth) {
    $errMsg = $errLogin ?? '';
    ?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestione Ticket — Accesso</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700&family=IBM+Plex+Mono&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'IBM Plex Sans',system-ui,sans-serif;background:#0b1929;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{background:#fff;border-radius:14px;width:100%;max-width:400px;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.4)}
.card-head{background:#152f52;padding:28px 28px 24px;text-align:center}
.card-head .logo{width:52px;height:52px;background:#2d7dd2;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:12px}
.card-head h1{color:#fff;font-size:1.1rem;font-weight:700}
.card-head p{color:rgba(255,255,255,.5);font-size:.78rem;margin-top:4px}
.card-body{padding:28px}
.field{margin-bottom:16px}
.field label{display:block;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:5px}
.field input{width:100%;border:1.5px solid #e2e8f0;border-radius:7px;padding:10px 13px;font-size:14px;font-family:inherit;color:#1a2535;transition:border-color .15s}
.field input:focus{outline:none;border-color:#2d7dd2;box-shadow:0 0 0 3px rgba(45,125,210,.13)}
.field.mono input{font-family:'IBM Plex Mono',monospace;letter-spacing:.15em;font-size:1.1rem;text-align:center}
.btn{width:100%;background:#1d4f88;color:#fff;border:none;border-radius:7px;padding:12px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:background .15s;margin-top:4px}
.btn:hover{background:#152f52}
.err{background:#fee2e2;color:#991b1b;border-radius:7px;padding:10px 13px;font-size:.82rem;margin-bottom:16px;border-left:3px solid #dc2626}
.hint{font-size:.72rem;color:#94a3b8;text-align:center;margin-top:16px;line-height:1.6}
.qr-wrap{text-align:center;padding:16px 0 8px}
.qr-wrap img{border-radius:8px;border:1px solid #e2e8f0}
.qr-label{font-size:.72rem;color:#64748b;margin-top:8px}
.divider{height:1px;background:#f1f5f9;margin:20px 0}
</style>
</head>
<body>
<div class="card">
    <div class="card-head">
        <img src="assets/logo.png" alt="Logo" style="height:64px;width:auto;margin-bottom:12px;filter:brightness(0) invert(1)">
        <h1>Gestione Ticket</h1>
        <p>Accesso da rete esterna - autenticazione richiesta</p>
    </div>
    <div class="card-body">
        <?php if($errMsg): ?>
        <div class="err">⚠ <?= htmlspecialchars($errMsg) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="_login" value="1">
            <div class="field">
                <label>Password</label>
                <input type="password" name="pwd" placeholder="••••••••" autofocus required>
            </div>
            <div class="divider"></div>
            <div class="field mono">
                <label>Codice Google Authenticator (6 cifre)</label>
                <input type="text" name="otp" placeholder="000000" maxlength="6"
                    inputmode="numeric" autocomplete="one-time-code" required>
            </div>
            <button class="btn" type="submit">🔐 Accedi</button>
        </form>

        <div class="hint">
            Prima volta? Richiedi il QR di configurazione via email aziendale.
        </div>

        <?php if(!empty($_GET['qr_inviato'])): ?>
        <div style="background:#dcfce7;color:#166534;border-radius:7px;padding:10px 13px;font-size:.82rem;margin-top:14px;border-left:3px solid #16a34a;text-align:center">
            ✅ QR inviato! Controlla la tua casella email
        </div>
        <?php elseif(!empty($_GET['qr_err'])): ?>
        <div class="err" style="margin-top:14px">
            ⚠ <?= htmlspecialchars($_GET['qr_err']) ?>
        </div>
        <?php endif; ?>

        <details style="margin-top:14px" <?= !empty($_GET['qr_err']) ? 'open' : '' ?>>
            <summary style="font-size:.75rem;color:#94a3b8;cursor:pointer;text-align:center;list-style:none">
                ▼ Richiedi QR di configurazione
            </summary>
            <form method="POST" style="margin-top:14px">
                <input type="hidden" name="_richiedi_qr" value="1">
                <div class="field">
                    <label>Email aziendale</label>
                    <input type="email" name="email_qr"
                        placeholder="tuo@email.it"
                        
                        title="Inserisci il tuo indirizzo email"
                        required>
                </div>
                <button class="btn" type="submit" style="background:#166534">
                    📧 Invia QR alla mia email
                </button>
            </form>
        </details>
    </div>
</div>
</body>
</html>
<?php
    exit;
}
// ── Fine blocco autenticazione - da qui in poi l'utente è autenticato ─

// ── Selezione utente - se autenticato ma senza utente scelto ──
if ($is_auth && empty($utente_sigla)) {
    // Legge gli assegnati configurati dal DB (se disponibile) oppure defaults
    $assegnatiRaw = null;
    try {
        $assegnatiRaw = json_decode(cfg('assegnati', '[]'), true);
    } catch (Exception $e) { $assegnatiRaw = null; }
    if (empty($assegnatiRaw)) {
        $assegnatiRaw = [
            ['sigla'=>'FF','nome'=>'Francesco Fodri'],
            ['sigla'=>'FP','nome'=>'Francesco Pagano'],
        ];
    }
    $avatarEmoji = ['FF'=>'👨‍💻','FP'=>'🧑‍💼','GI'=>'👤','NC'=>'📢','EC'=>'🔒'];
    ?><!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestione Ticket — Chi sei?</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{max-width:100%;overflow-x:hidden}
body{
    font-family:'IBM Plex Sans',system-ui,sans-serif;
    min-height:100vh;display:flex;align-items:flex-start;justify-content:center;
    background:
        radial-gradient(ellipse 80% 60% at 20% 10%, rgba(45,125,210,.22) 0%, transparent 60%),
        radial-gradient(ellipse 60% 80% at 80% 80%, rgba(124,58,237,.16) 0%, transparent 60%),
        #eef2f7;
    padding:24px 16px 40px;
}
@media(max-width:480px){body{align-items:flex-start;padding-top:32px}}
.wrap{text-align:center;max-width:480px;width:100%;margin:0 auto}
.logo-box{margin-bottom:16px;display:flex;justify-content:center;align-items:center;width:100%;overflow:hidden}
.logo-box img{height:80px;max-width:100%;width:auto;object-fit:contain;filter:drop-shadow(0 2px 8px rgba(0,0,0,.15))}
h1{font-size:1.3rem;font-weight:700;color:#1a2535;margin-bottom:4px}
p{font-size:.85rem;color:#64748b;margin-bottom:24px}
.user-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px}
@media(max-width:380px){.user-grid{grid-template-columns:1fr}}
.user-card{
    background:rgba(255,255,255,.75);
    backdrop-filter:blur(20px) saturate(180%);
    -webkit-backdrop-filter:blur(20px) saturate(180%);
    border:1.5px solid rgba(255,255,255,.8);
    border-radius:16px;
    padding:28px 20px 24px;
    cursor:pointer;
    transition:all .2s;
    box-shadow:0 4px 24px rgba(0,0,0,.08),inset 0 1px 0 rgba(255,255,255,.9);
    text-decoration:none;display:block;
}
.user-card:hover{
    transform:translateY(-4px);
    box-shadow:0 12px 40px rgba(45,125,210,.2),inset 0 1px 0 rgba(255,255,255,.9);
    border-color:rgba(45,125,210,.4);
}
.user-avatar{
    width:72px;height:72px;border-radius:50%;
    background:linear-gradient(135deg,var(--c1),var(--c2));
    display:flex;align-items:center;justify-content:center;
    font-size:2rem;margin:0 auto 14px;
    box-shadow:0 4px 16px rgba(0,0,0,.15);
}
.user-sigla{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#64748b;margin-bottom:6px}
.user-nome{font-size:1rem;font-weight:700;color:#1a2535;line-height:1.3}
.user-sub{font-size:.75rem;color:#94a3b8;margin-top:4px}
.logout-link{font-size:.78rem;color:#94a3b8;text-decoration:none}
.logout-link:hover{color:#64748b}
/* Colori avatar per sigla */
.c-FF{--c1:#2d7dd2;--c2:#1d4f88}
.c-FP{--c1:#7c3aed;--c2:#4c1d95}
.c-GI{--c1:#16a34a;--c2:#166534}
.c-NC{--c1:#d97706;--c2:#92400e}
.c-EC{--c1:#dc2626;--c2:#991b1b}
.c-default{--c1:#64748b;--c2:#475569}
</style>
</head>
<body>
<div class="wrap">
    <div class="logo-box">
        <img src="assets/logo.png" alt="Logo" onerror="this.style.display='none'"
            style="height:80px;width:auto;max-width:240px;object-fit:contain">
    </div>
    <h1>Chi sei?</h1>
    <p>Seleziona il tuo profilo per accedere a <b>Gestione Lavorazioni IT</b></p>

    <?php if (!empty($_GET['pin_err'])): ?>
    <div style="background:#fee2e2;color:#991b1b;border-radius:8px;padding:10px 16px;
        font-size:.82rem;margin-bottom:16px;border-left:3px solid #dc2626">
        ⚠ PIN errato. Riprova.
    </div>
    <?php endif; ?>

    <!-- Overlay PIN admin -->
    <div id="pin-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
        z-index:100;align-items:center;justify-content:center;padding:16px">
        <div style="background:#fff;border-radius:14px;padding:28px 24px;max-width:340px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto">
            <div id="pin-avatar" style="font-size:2.5rem;margin-bottom:8px"></div>
            <div id="pin-nome-label" style="font-weight:700;color:#1a2535;margin-bottom:4px;font-size:1rem"></div>
            <div style="font-size:.78rem;color:#64748b;margin-bottom:20px">Inserisci il PIN per accedere</div>
            <form method="POST" id="pin-form">
                <input type="hidden" name="_scegli_utente" value="1">
                <input type="hidden" name="sigla" id="pin-sigla">
                <input type="hidden" name="nome"  id="pin-nome">
                <input type="password" name="pin" id="pin-input"
                    maxlength="6" placeholder="• • • •"
                    style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;
                        padding:12px;font-size:1.2rem;text-align:center;letter-spacing:.2em;
                        font-family:inherit;margin-bottom:12px"
                    autofocus>
                <button type="submit"
                    style="width:100%;background:#1d4f88;color:#fff;border:none;
                        border-radius:8px;padding:12px;font-size:.9rem;font-weight:700;
                        font-family:inherit;cursor:pointer">
                    🔓 Accedi
                </button>
            </form>
            <button onclick="document.getElementById('pin-overlay').style.display='none'"
                style="margin-top:12px;background:none;border:none;color:#94a3b8;
                    font-size:.78rem;cursor:pointer;font-family:inherit">
                Annulla
            </button>
        </div>
    </div>

    <!-- Overlay nuovo utente -->
    <div id="ospite-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);
        z-index:100;align-items:center;justify-content:center;padding:16px">
        <div style="background:#fff;border-radius:14px;padding:28px 24px;max-width:340px;width:100%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto">
            <div style="font-size:2.5rem;margin-bottom:8px">👁</div>
            <div style="font-weight:700;color:#1a2535;margin-bottom:4px;font-size:1rem">Accesso Visualizzazione</div>
            <div style="font-size:.78rem;color:#64748b;margin-bottom:20px">Potrai vedere tutto ma non modificare nulla</div>
            <form method="POST">
                <input type="hidden" name="_scegli_utente" value="1">
                <input type="hidden" name="sigla" value="OSPITE">
                <input type="text" name="nome" placeholder="Il tuo nome"
                    style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;
                        padding:12px;font-size:1rem;font-family:inherit;margin-bottom:12px"
                    required autofocus>
                <button type="submit"
                    style="width:100%;background:#166534;color:#fff;border:none;
                        border-radius:8px;padding:12px;font-size:.9rem;font-weight:700;
                        font-family:inherit;cursor:pointer">
                    👁 Entra in visualizzazione
                </button>
            </form>
            <button onclick="document.getElementById('ospite-overlay').style.display='none'"
                style="margin-top:12px;background:none;border:none;color:#94a3b8;
                    font-size:.78rem;cursor:pointer;font-family:inherit">
                Annulla
            </button>
        </div>
    </div>

    <div class="user-grid">
        <?php
        $adminSigle = ['FF','FP'];
        foreach ($assegnatiRaw as $a):
            if (!in_array($a['sigla'], $adminSigle, true)) continue;
            $sigla = htmlspecialchars($a['sigla']);
            $nome  = htmlspecialchars($a['nome'] ?? $a['sigla']);
            $emoji = $avatarEmoji[$a['sigla']] ?? '👤';
            $colorClass = array_key_exists($a['sigla'], $avatarEmoji) ? 'c-'.$a['sigla'] : 'c-default';
            $ruoli = ['FF'=>'Tecnico IT','FP'=>'Dirigente IT'];
            $ruolo = htmlspecialchars($ruoli[$a['sigla']] ?? 'Admin');
        ?>
        <button type="button" class="user-card"
            style="font-family:inherit;border:1.5px solid rgba(255,255,255,.8);background:none;width:100%"
            onclick="mostraPin('<?= $sigla ?>','<?= $nome ?>','<?= $emoji ?>')">
            <div class="user-avatar <?= $colorClass ?>"><?= $emoji ?></div>
            <div class="user-sigla"><?= $sigla ?></div>
            <div class="user-nome"><?= $nome ?></div>
            <div class="user-sub"><?= $ruolo ?> · 🔑 PIN richiesto</div>
        </button>
        <?php endforeach; ?>
    </div>

    <div style="margin-top:8px">
        <button type="button" onclick="document.getElementById('ospite-overlay').style.display='flex'"
            style="background:rgba(255,255,255,.6);border:1.5px dashed #94a3b8;border-radius:12px;
                padding:16px 32px;font-family:inherit;font-size:.9rem;color:#64748b;
                cursor:pointer;width:100%;transition:all .2s"
            onmouseover="this.style.borderColor='#2d7dd2';this.style.color='#2d7dd2'"
            onmouseout="this.style.borderColor='#94a3b8';this.style.color='#64748b'">
            + Accedi come visualizzatore (senza PIN)
        </button>
    </div>

    <script>
    function mostraPin(sigla, nome, emoji) {
        document.getElementById('pin-sigla').value = sigla;
        document.getElementById('pin-nome').value  = nome;
        document.getElementById('pin-avatar').textContent = emoji;
        document.getElementById('pin-nome-label').textContent = nome;
        document.getElementById('pin-input').value = '';
        const ol = document.getElementById('pin-overlay');
        ol.style.display = 'flex';
        setTimeout(() => document.getElementById('pin-input').focus(), 100);
        <?php if (!empty($_GET['pin_err']) && !empty($_GET['sigla'])): ?>
        document.getElementById('pin-sigla').value = '<?= htmlspecialchars($_GET['sigla']) ?>';
        <?php endif; ?>
    }
    <?php if (!empty($_GET['pin_err'])): ?>
    window.onload = () => {
        const sigla = '<?= htmlspecialchars($_GET['sigla'] ?? '') ?>';
        const nomi  = <?= json_encode(array_column($assegnatiRaw, 'nome', 'sigla')) ?>;
        const emoj  = {'FF':'👨‍💻','FP':'🧑‍💼'};
        mostraPin(sigla, nomi[sigla]||sigla, emoj[sigla]||'👤');
    };
    <?php endif; ?>
    </script>

</div>
</body>
</html>
<?php
    exit;
}
// ── Da qui: utente autenticato e identificato ($utente_sigla, $utente_nome) ──

// ── Connessione DB + schema ────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA journal_mode=WAL");

    // Crea cartella allegati se non esiste
    if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0755, true);

    // Tabella lavorazioni principale
    $pdo->exec("CREATE TABLE IF NOT EXISTS lavorazioni (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        tipo            TEXT    NOT NULL,
        dettaglio       TEXT    DEFAULT '',
        data_richiesta  TEXT    NOT NULL,
        richiedente     TEXT    DEFAULT '',
        assegnato_a     TEXT    NOT NULL,
        descrizione     TEXT    NOT NULL,
        ticket_aperto   INTEGER DEFAULT 0,
        numero_ticket   TEXT    DEFAULT '',
        priorita        TEXT    DEFAULT 'normale',
        stato           TEXT    DEFAULT 'aperto',
        data_chiusura   TEXT    DEFAULT NULL,
        note            TEXT    DEFAULT '',
        created_at      TEXT    DEFAULT (datetime('now','localtime'))
    )");

    // Migrazione v2.3: nessuna modifica schema necessaria, i nuovi stati
    // sono solo stringhe diverse nel campo TEXT già esistente.

    // v2.9: tabella commenti per thread per lavorazione
    $pdo->exec("CREATE TABLE IF NOT EXISTS commenti (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        lavorazione_id  INTEGER NOT NULL REFERENCES lavorazioni(id) ON DELETE CASCADE,
        autore          TEXT    NOT NULL DEFAULT '',
        testo           TEXT    NOT NULL,
        created_at      TEXT    DEFAULT (datetime('now','localtime'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_commenti_lav ON commenti(lavorazione_id)");

    // v3.0: tabella audit log operazioni
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_trail (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        ts         TEXT    DEFAULT (datetime('now','localtime')),
        utente     TEXT    NOT NULL DEFAULT '',
        ip         TEXT    DEFAULT '',
        azione     TEXT    NOT NULL,
        entita_id  INTEGER DEFAULT NULL,
        dettaglio  TEXT    DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_ts ON audit_trail(ts DESC)");

    // Tabella configurazioni dinamiche (tipi, assegnati, api key)
    $pdo->exec("CREATE TABLE IF NOT EXISTS config (
        chiave  TEXT PRIMARY KEY,
        valore  TEXT NOT NULL
    )");

    // Valori predefiniti se la config è vuota
    $defaults = [
        'tipi_richiesta' => json_encode([
            'Attivazione PC','Attivazione Smartphone',
            'Risoluzione Problema','Richiesta Firma RUP',
            'Licenze','Acquisto Hardware','Assistenza Utente','Altro'
        ]),
        'tipi_licenza' => json_encode(['Adobe','Microsoft','AutoCAD','Antivirus','Altra']),
        'assegnati'    => json_encode([
            ['sigla'=>'FF','nome'=>'Francesco'],
            ['sigla'=>'FP','nome'=>'Dirigente IT'],
        ]),
        'anthropic_key' => ANTHROPIC_KEY,
        'ai_system_prompt' => "Sei un assistente operativo per la gestione delle richieste del team. Aiuta a tracciare e organizzare le lavorazioni quotidiane usando questo gestionale PHP+SQLite. Rispondi in italiano, sii pratico e diretto.",
    ];
    foreach ($defaults as $k => $v) {
        $pdo->prepare("INSERT OR IGNORE INTO config (chiave, valore) VALUES (?,?)")
            ->execute([$k, $v]);
    }

    // (storico chat AI mantenuto solo lato client in sessione JS - nessuna tabella necessaria)

    return $pdo;
}

// Cache globale per cfg() - evita query ripetute sullo stesso key
function cfg(string $k, $default = null) {
    global $_cfg_cache;
    if (!is_array($_cfg_cache)) $_cfg_cache = [];
    if (!array_key_exists($k, $_cfg_cache)) {
        $stmt = db()->prepare("SELECT valore FROM config WHERE chiave=?");
        $stmt->execute([$k]);
        $r = $stmt->fetch();
        $_cfg_cache[$k] = $r ? $r['valore'] : $default;
    }
    return $_cfg_cache[$k];
}
function cfg_write(PDO $pdo, string $k, string $v): void {
    global $_cfg_cache;
    $pdo->prepare("INSERT OR REPLACE INTO config(chiave,valore) VALUES(?,?)")->execute([$k, $v]);
    $_cfg_cache[$k] = $v; // aggiorna cache senza rileggere
}

// ── API JSON ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_action'])) {
    // FIX B4: header JSON non inviato qui - lo imposta ogni branch tranne export_csv
    $pdo = db();
    $act = $_POST['_action'];

    // Blocca scrittura per utenti readonly
    $writeActions = ['inserisci','modifica','elimina','modifica_stato','assegna',
                     'duplica','commento_add','commento_del','backup_manuale',
                     'save_config','sblocca_ip'];
    if (($is_readonly ?? false) && in_array($act, $writeActions, true)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'err'=>'Accesso in sola lettura. Operazione non consentita.']);
        exit;
    }


    // ── LAVORAZIONI ──────────────────────────────────────────
    if ($act === 'upload_file') {
        header('Content-Type: application/json; charset=utf-8');
        if (empty($_FILES['file'])) { echo json_encode(['ok'=>false,'err'=>'Nessun file']); exit; }
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK) { echo json_encode(['ok'=>false,'err'=>'Errore upload']); exit; }
        
        $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
        $newName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = UPLOAD_DIR . '/' . $newName;
        
        if (move_uploaded_file($f['tmp_name'], $dest)) {
            echo json_encode(['ok'=>true, 'filename'=>$newName, 'original'=>$f['name']]);
        } else {
            echo json_encode(['ok'=>false,'err'=>'Impossibile salvare il file']);
        }
        exit;
    }

    if ($act === 'inserisci') {
        header('Content-Type: application/json; charset=utf-8');
        // FIX B11 - validazione server-side priorita e data
        $prioriteValide = ['normale','alta','urgente'];
        $priorita = in_array($_POST['priorita']??'', $prioriteValide, true) ? $_POST['priorita'] : 'normale';
        $dataRich = $_POST['data_richiesta'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataRich)) {
            echo json_encode(['ok'=>false,'err'=>'Data non valida']); exit;
        }
        $tipo     = trim($_POST['tipo']??'');
        if (preg_match('/licenz/i', $tipo)) {
            $sottoLic = trim($_POST['sotto_licenza']??'');
            $nomeLic  = trim($_POST['nome_licenza']??'');
            if (empty($nomeLic)) $nomeLic = trim($_POST['dettaglio']??'');
            $dettaglio = $sottoLic && $nomeLic ? "$sottoLic - $nomeLic" : ($sottoLic ?: $nomeLic);
            // Auto-aggiunge il nome licenza alla lista se non esiste già
            if (!empty($nomeLic)) {
                $licenze = json_decode(cfg('tipi_licenza','[]'), true) ?: [];
                if (!in_array($nomeLic, $licenze)) {
                    $licenze[] = $nomeLic;
                    cfg_write($pdo, 'tipi_licenza', json_encode($licenze, JSON_UNESCAPED_UNICODE));
                }
            }
        } else {
            $dettaglio = '';
        }
        $s = $pdo->prepare("INSERT INTO lavorazioni
            (tipo,dettaglio,data_richiesta,richiedente,assegnato_a,
             descrizione,ticket_aperto,numero_ticket,priorita,note,allegati)
            VALUES(?,?,?,?,?,?,?,?,?,?,?)");
        $s->execute([
            $tipo,
            $dettaglio,
            $dataRich,
            trim($_POST['richiedente']??''),
            trim($_POST['assegnato_a']??'FF'),
            trim($_POST['descrizione']??''),
            empty($_POST['ticket_aperto'])?0:1,
            trim($_POST['numero_ticket']??''),
            $priorita,
            trim($_POST['note']??''),
            $_POST['allegati'] ?? '[]'
        ]);
        $newId = $pdo->lastInsertId();
        $utenteLog = $_SESSION['ales_user'] ?? '?';
        audit_db('INSERISCI', $utenteLog, (int)$newId, $tipo);
        audit_log('INSERISCI', $utenteLog, "#$newId $tipo");
        // Notifica push: nuova lavorazione
        $assegnatoNew = trim($_POST['assegnato_a']??'FF');
        $descNew      = trim($_POST['descrizione']??'');
        invia_notifica(
            '📋 Nuova lavorazione #' . $newId,
            "$tipo – $descNew (→ $assegnatoNew)",
            (defined('TUNNEL_URL') ? TUNNEL_URL : '') . '/?id=' . $newId
        );
        echo json_encode(['ok'=>true,'id'=>$newId]);
        exit;
    }

    if ($act === 'chiudi') {
        header('Content-Type: application/json; charset=utf-8');
        $idChiudi = (int)$_POST['id'];
        $pdo->prepare("UPDATE lavorazioni SET stato='chiuso',
            data_chiusura=datetime('now','localtime') WHERE id=? AND stato!='chiuso'")
            ->execute([$idChiudi]);
        audit_db('CHIUDI', $_SESSION['ales_user']??'?', $idChiudi);
        audit_log('CHIUDI', $_SESSION['ales_user']??'?', "#$idChiudi");
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($act === 'riapri') {
        header('Content-Type: application/json; charset=utf-8');
        $pdo->prepare("UPDATE lavorazioni SET stato='aperto',data_chiusura=NULL WHERE id=?")
            ->execute([(int)$_POST['id']]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($act === 'elimina') {
        header('Content-Type: application/json; charset=utf-8');
        $idDel = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM lavorazioni WHERE id=?")->execute([$idDel]);
        audit_db('ELIMINA', $_SESSION['ales_user']??'?', $idDel);
        audit_log('ELIMINA', $_SESSION['ales_user']??'?', "#$idDel");
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($act === 'modifica_stato') {
        header('Content-Type: application/json; charset=utf-8');
        $id = (int)($_POST['id'] ?? 0);
        $statiValidi = ['aperto','presa in carico','attesa','chiuso'];
        $stato = in_array($_POST['stato']??'', $statiValidi, true) ? $_POST['stato'] : null;
        if (!$id || !$stato) { echo json_encode(['ok'=>false,'err'=>'Dati non validi']); exit; }
        $dataChiusura = $stato === 'chiuso' ? date('Y-m-d H:i:s') : null;
        // Se era già chiuso mantieni data originale; se non è chiuso, azzera
        if ($stato === 'chiuso') {
            $orig = $pdo->prepare("SELECT data_chiusura FROM lavorazioni WHERE id=?");
            $orig->execute([$id]);
            $row = $orig->fetch();
            if ($row && $row['data_chiusura']) $dataChiusura = $row['data_chiusura'];
        }
        // U10: se stato != chiuso, data_chiusura = NULL (già impostata sopra)
        $pdo->prepare("UPDATE lavorazioni SET stato=?, data_chiusura=? WHERE id=?")
            ->execute([$stato, $dataChiusura, $id]);
        echo json_encode(['ok'=>true]);
        exit;
    }
if ($act === 'modifica_assegnato') {
    header('Content-Type: application/json; charset=utf-8');
    $id  = (int)($_POST['id'] ?? 0);
    $ass = trim($_POST['assegnato_a'] ?? '');
    if (!$id || empty($ass)) { echo json_encode(['ok'=>false,'err'=>'Dati mancanti']); exit; }
    $pdo->prepare("UPDATE lavorazioni SET assegnato_a=? WHERE id=?")
        ->execute([$ass, $id]);
    audit_db('MODIFICA_ASSEGNATO', $_SESSION['ales_user']??'?', $id, $ass);
    // Notifica push: riassegnazione ticket
    $rowAss = $pdo->prepare("SELECT descrizione, tipo FROM lavorazioni WHERE id=?");
    $rowAss->execute([$id]);
    $lavAss = $rowAss->fetch();
    if ($lavAss) {
        invia_notifica(
            '👤 Ticket #' . $id . ' riassegnato',
            ($lavAss['tipo'] ?? '') . ' → ' . $ass . ': ' . mb_strimwidth($lavAss['descrizione'] ?? '', 0, 60, '…'),
            (defined('TUNNEL_URL') ? TUNNEL_URL : '') . '/?id=' . $id
        );
    }
    echo json_encode(['ok'=>true]);
    exit;
}

    if ($act === 'get_record') {
        header('Content-Type: application/json; charset=utf-8');
        $stmt = $pdo->prepare("SELECT * FROM lavorazioni WHERE id=?");
        $stmt->execute([(int)$_POST['id']]);
        $r = $stmt->fetch();
        echo json_encode($r ?: ['ok'=>false,'err'=>'Record non trovato']);
        exit;
    }

    if ($act === 'modifica') {
        header('Content-Type: application/json; charset=utf-8');
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'err'=>'ID mancante']); exit; }
        $prioriteValide = ['normale','alta','urgente'];
        $statiValidi    = ['aperto','presa in carico','attesa','chiuso'];
        $priorita = in_array($_POST['priorita']??'', $prioriteValide, true) ? $_POST['priorita'] : 'normale';
        $stato    = in_array($_POST['stato']??'', $statiValidi, true) ? $_POST['stato'] : 'aperto';
        $tipo     = trim($_POST['tipo']??'');
        if (preg_match('/licenz/i', $tipo)) {
            $sottoLic = trim($_POST['sotto_licenza']??'');
            $nomeLic  = trim($_POST['nome_licenza']??'');
            if (empty($nomeLic)) $nomeLic = trim($_POST['dettaglio']??'');
            $dettaglio = $sottoLic && $nomeLic ? "$sottoLic - $nomeLic" : ($sottoLic ?: $nomeLic);
            // Auto-aggiunge il nome licenza alla lista se non esiste già
            if (!empty($nomeLic)) {
                $licenze = json_decode(cfg('tipi_licenza','[]'), true) ?: [];
                if (!in_array($nomeLic, $licenze)) {
                    $licenze[] = $nomeLic;
                    cfg_write($pdo, 'tipi_licenza', json_encode($licenze, JSON_UNESCAPED_UNICODE));
                }
            }
        } else {
            $dettaglio = '';
        }
        $dataRich  = $_POST['data_richiesta'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataRich)) {
            echo json_encode(['ok'=>false,'err'=>'Data non valida']); exit;
        }
        // Gestisce chiusura/riapertura automatica con timestamp
        $dataChiusura = null;
        if ($stato === 'chiuso') {
            $orig = $pdo->prepare("SELECT stato, data_chiusura FROM lavorazioni WHERE id=?");
            $orig->execute([$id]);
            $row = $orig->fetch();
            $dataChiusura = ($row && $row['stato'] === 'chiuso' && $row['data_chiusura'])
                ? $row['data_chiusura']
                : date('Y-m-d H:i:s');
        }
        // Se non chiuso, data_chiusura resta NULL (già impostata sopra)
        $pdo->prepare("UPDATE lavorazioni SET
            tipo=?, dettaglio=?, data_richiesta=?, richiedente=?, assegnato_a=?,
            descrizione=?, ticket_aperto=?, numero_ticket=?, priorita=?,
            stato=?, data_chiusura=?, note=?, allegati=?
            WHERE id=?")->execute([
            $tipo, $dettaglio, $dataRich,
            trim($_POST['richiedente']??''),
            trim($_POST['assegnato_a']??''),
            trim($_POST['descrizione']??''),
            empty($_POST['ticket_aperto']) ? 0 : 1,
            trim($_POST['numero_ticket']??''),
            $priorita, $stato, $dataChiusura,
            trim($_POST['note']??''),
            $_POST['allegati'] ?? '[]',
            $id
        ]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($act === 'lista') {
        header('Content-Type: application/json; charset=utf-8');
        $w=[]; $p=[];
        $stato=($_POST['stato']??'tutti');
        $ass=($_POST['assegnato']??'tutti');
        $tipo=($_POST['tipo']??'tutti');
        $prio=($_POST['priorita']??'tutti');
        $cerca=trim($_POST['cerca']??''); // M1: ricerca testuale
        $w[]='1=1';
        if($stato!=='tutti'){$w[]="stato=?";$p[]=$stato;}
        if($ass  !=='tutti'){$w[]="assegnato_a=?";$p[]=$ass;}
        if($tipo !=='tutti'){$w[]="tipo=?";$p[]=$tipo;}
        if($prio !=='tutti'){$w[]="priorita=?";$p[]=$prio;}
        if($cerca!==''){
            $w[]="(descrizione LIKE ? OR richiedente LIKE ? OR note LIKE ? OR numero_ticket LIKE ?)";
            $like="%$cerca%"; $p=array_merge($p,[$like,$like,$like,$like]);
        }
        // Ordinamento personalizzato
        $sortCols = ['id','tipo','data_richiesta','richiedente','priorita','stato','data_chiusura'];
        $sortCol  = in_array($_POST['sort_col']??'', $sortCols, true) ? $_POST['sort_col'] : '';
        $sortDir  = ($_POST['sort_dir']??'') === 'asc' ? 'ASC' : 'DESC';
        if ($sortCol) {
            $orderBy = "$sortCol $sortDir";
        } else {
            $orderBy = "CASE priorita WHEN 'urgente' THEN 1 WHEN 'alta' THEN 2 WHEN 'normale' THEN 3 ELSE 4 END,
                data_richiesta DESC, id DESC";
        }
        $stmt=$pdo->prepare("SELECT * FROM lavorazioni WHERE ".implode(' AND ',$w)." ORDER BY $orderBy");
        $stmt->execute($p);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($act === 'stats') {
        header('Content-Type: application/json; charset=utf-8');
        $r=$pdo->query("SELECT
            COUNT(*) tot,
            SUM(stato='aperto') aperti,
            SUM(stato='chiuso') chiusi,
            SUM(stato='presa in carico') in_carico,
            SUM(stato='attesa') in_attesa,
            SUM(priorita='urgente' AND stato!='chiuso') urgenti,
            SUM(ticket_aperto=1 AND stato!='chiuso') tick_ap,
            SUM(strftime('%Y-%m-%d',data_richiesta)=strftime('%Y-%m-%d','now','localtime')) oggi
            FROM lavorazioni")->fetch();
        // Per assegnati dinamici
        $assegnati = json_decode(cfg('assegnati','[]'), true);
        $r['per_assegnato'] = [];
        foreach ($assegnati as $a) {
            $c=$pdo->prepare("SELECT COUNT(*) n FROM lavorazioni WHERE assegnato_a=? AND stato!='chiuso'");
            $c->execute([$a['sigla']]);
            $r['per_assegnato'][$a['sigla']] = $c->fetchColumn();
        }
        echo json_encode($r);
        exit;
    }

    // B3: lista_completa = lista + stats in una sola chiamata
    if ($act === 'lista_completa') {
        header('Content-Type: application/json; charset=utf-8');
        $w=[]; $p=[];
        $stato = $_POST['stato']??'tutti';
        $ass   = $_POST['assegnato']??'tutti';
        $tipo  = $_POST['tipo']??'tutti';
        $prio  = $_POST['priorita']??'tutti';
        $cerca = trim($_POST['cerca']??'');
        $page  = max(1,(int)($_POST['page']??1));
        $perPage = 20;
        $w[]='1=1';
        if($stato!=='tutti'){$w[]="stato=?";$p[]=$stato;}
        if($ass  !=='tutti'){$w[]="assegnato_a=?";$p[]=$ass;}
        if($tipo !=='tutti'){$w[]="tipo=?";$p[]=$tipo;}
        if($prio !=='tutti'){$w[]="priorita=?";$p[]=$prio;}
        if($cerca!==''){
            $w[]="(descrizione LIKE ? OR richiedente LIKE ? OR note LIKE ? OR numero_ticket LIKE ? OR dettaglio LIKE ? OR allegati LIKE ?)";
            $like="%$cerca%"; $p=array_merge($p,[$like,$like,$like,$like,$like]);
        }
        $where = implode(' AND ',$w);
        $sortCols = ['id','tipo','data_richiesta','richiedente','priorita','stato','data_chiusura'];
        $sortCol  = in_array($_POST['sort_col']??'', $sortCols, true) ? $_POST['sort_col'] : '';
        $sortDir  = ($_POST['sort_dir']??'') === 'asc' ? 'ASC' : 'DESC';
        $orderBy  = $sortCol ? "$sortCol $sortDir"
            : "CASE priorita WHEN 'urgente' THEN 1 WHEN 'alta' THEN 2 WHEN 'normale' THEN 3 ELSE 4 END, data_richiesta DESC, id DESC";

        // Totale filtrato per paginazione
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM lavorazioni WHERE $where");
        $cntStmt->execute($p);
        $totFiltrato = (int)$cntStmt->fetchColumn();

        // Righe paginate con conteggio commenti
        $offset = ($page-1)*$perPage;
        $rows = $pdo->prepare("SELECT l.*, (SELECT COUNT(*) FROM commenti c WHERE c.lavorazione_id=l.id) AS num_commenti FROM lavorazioni l WHERE $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
        $rows->execute($p);

        // Stats globali (su tutto il DB, non filtrate)
        $s = $pdo->query("SELECT
            COUNT(*) tot,
            SUM(stato='aperto') aperti,
            SUM(stato='chiuso') chiusi,
            SUM(stato='presa in carico') in_carico,
            SUM(stato='attesa') in_attesa,
            SUM(priorita='urgente' AND stato!='chiuso') urgenti,
            SUM(ticket_aperto=1 AND stato!='chiuso') tick_ap,
            SUM(strftime('%Y-%m-%d',data_richiesta)=strftime('%Y-%m-%d','now','localtime')) oggi
            FROM lavorazioni")->fetch();
        $assegnati = json_decode(cfg('assegnati','[]'), true);
        $perAss = [];
        // Una sola query GROUP BY invece di N query nel loop
        $grp = $pdo->query("SELECT assegnato_a, COUNT(*) n FROM lavorazioni WHERE stato!='chiuso' GROUP BY assegnato_a")->fetchAll();
        foreach($grp as $g) $perAss[$g['assegnato_a']] = $g['n'];

        echo json_encode([
            'righe'       => $rows->fetchAll(),
            'tot_filtrato'=> $totFiltrato,
            'pagina'      => $page,
            'per_pagina'  => $perPage,
            'stats'       => array_merge($s, ['per_assegnato'=>$perAss]),
        ]);
        exit;
    }

    // F5: duplica lavorazione
    if ($act === 'duplica') {
        header('Content-Type: application/json; charset=utf-8');
        $id = (int)($_POST['id']??0);
        $orig = $pdo->prepare("SELECT * FROM lavorazioni WHERE id=?");
        $orig->execute([$id]);
        $r = $orig->fetch();
        if (!$r) { echo json_encode(['ok'=>false,'err'=>'Record non trovato']); exit; }
        $pdo->prepare("INSERT INTO lavorazioni
            (tipo,dettaglio,data_richiesta,richiedente,assegnato_a,descrizione,
             ticket_aperto,numero_ticket,priorita,stato,note,allegati)
            VALUES(?,?,?,?,?,?,?,?,?,'aperto',?,?)")
            ->execute([
                $r['tipo'], $r['dettaglio'], date('Y-m-d'),
                $r['richiedente'], $r['assegnato_a'], $r['descrizione'],
                $r['ticket_aperto'], $r['numero_ticket'], $r['priorita'], $r['note'], $r['allegati']
            ]);
        echo json_encode(['ok'=>true,'id'=>$pdo->lastInsertId()]);
        exit;
    }

    // F7: get commenti per lavorazione
    if ($act === 'get_commenti') {
        header('Content-Type: application/json; charset=utf-8');
        $id = (int)($_POST['id']??0);
        $rows = $pdo->prepare("SELECT * FROM commenti WHERE lavorazione_id=? ORDER BY created_at ASC");
        $rows->execute([$id]);
        echo json_encode($rows->fetchAll());
        exit;
    }

    // F7: aggiungi commento
    if ($act === 'aggiungi_commento') {
        header('Content-Type: application/json; charset=utf-8');
        $id     = (int)($_POST['id']??0);
        $testo  = trim($_POST['testo']??'');
        $autore = trim($_POST['autore']??'');
        if (!$id || empty($testo)) { echo json_encode(['ok'=>false,'err'=>'Dati mancanti']); exit; }
        $pdo->prepare("INSERT INTO commenti (lavorazione_id,autore,testo) VALUES(?,?,?)")
            ->execute([$id, $autore, $testo]);
        $commentoId = $pdo->lastInsertId();
        // Notifica push: nuovo commento
        $rowComm = $pdo->prepare("SELECT descrizione, tipo FROM lavorazioni WHERE id=?");
        $rowComm->execute([$id]);
        $lavComm = $rowComm->fetch();
        if ($lavComm) {
            invia_notifica(
                '💬 Nuovo commento su #' . $id,
                ($autore ?: 'Anonimo') . ': ' . mb_strimwidth($testo, 0, 80, '…'),
                (defined('TUNNEL_URL') ? TUNNEL_URL : '') . '/?id=' . $id
            );
        }
        echo json_encode(['ok'=>true,'id'=>$commentoId]);
        exit;
    }

    // F7: elimina commento
    if ($act === 'elimina_commento') {
        header('Content-Type: application/json; charset=utf-8');
        $pdo->prepare("DELETE FROM commenti WHERE id=?")->execute([(int)($_POST['cid']??0)]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // U6: richiedenti usati per autocomplete
    if ($act === 'richiedenti') {
        header('Content-Type: application/json; charset=utf-8');
        $rows = $pdo->query("SELECT DISTINCT richiedente FROM lavorazioni
            WHERE richiedente!='' ORDER BY richiedente ASC")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($rows);
        exit;
    }

    // ── DASHBOARD KPI ────────────────────────────────────────
    if ($act === 'dashboard') {
        header('Content-Type: application/json; charset=utf-8');
        // Distribuzione per tipo
        $perTipo = $pdo->query("SELECT tipo, COUNT(*) n FROM lavorazioni GROUP BY tipo ORDER BY n DESC")->fetchAll();
        // Distribuzione per stato
        $perStato = $pdo->query("SELECT stato, COUNT(*) n FROM lavorazioni GROUP BY stato")->fetchAll();
        // Distribuzione per assegnato
        $perAss = $pdo->query("SELECT assegnato_a, COUNT(*) n FROM lavorazioni GROUP BY assegnato_a ORDER BY n DESC")->fetchAll();
        // Tempo medio chiusura (giorni) per tipo
        $tempiMedi = $pdo->query("SELECT tipo,
            ROUND(AVG(julianday(data_chiusura) - julianday(data_richiesta)), 1) AS giorni_medi,
            COUNT(*) n
            FROM lavorazioni WHERE stato='chiuso' AND data_chiusura IS NOT NULL
            GROUP BY tipo ORDER BY giorni_medi ASC")->fetchAll();
        // Lavorazioni per mese (ultimi 6 mesi)
        $perMese = $pdo->query("SELECT strftime('%Y-%m', data_richiesta) mese,
            COUNT(*) inserite,
            SUM(stato='chiuso') chiuse
            FROM lavorazioni
            WHERE data_richiesta >= date('now','-6 months')
            GROUP BY mese ORDER BY mese ASC")->fetchAll();
        // Urgenti non risolte da più di 3 giorni
        $urgentiScadute = $pdo->query("SELECT COUNT(*) n FROM lavorazioni
            WHERE priorita='urgente' AND stato!='chiuso'
            AND julianday('now') - julianday(data_richiesta) > 3")->fetchColumn();
        // Tasso chiusura globale
        $tot   = $pdo->query("SELECT COUNT(*) FROM lavorazioni")->fetchColumn();
        $chiuse = $pdo->query("SELECT COUNT(*) FROM lavorazioni WHERE stato='chiuso'")->fetchColumn();
        echo json_encode([
            'per_tipo'        => $perTipo,
            'per_stato'       => $perStato,
            'per_assegnato'   => $perAss,
            'tempi_medi'      => $tempiMedi,
            'per_mese'        => $perMese,
            'urgenti_scadute' => (int)$urgentiScadute,
            'tasso_chiusura'  => $tot > 0 ? round($chiuse/$tot*100, 1) : 0,
            'totale'          => (int)$tot,
        ]);
        exit;
    }

    // ── REPORT MENSILE CSV ───────────────────────────────────
    if ($act === 'report_mensile') {
        $mese = $_POST['mese'] ?? date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $mese)) { exit; }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ales_report_'.$mese.'.csv"');
        $rows = $pdo->prepare("SELECT id,tipo,dettaglio,data_richiesta,richiedente,
            assegnato_a,descrizione,ticket_aperto,numero_ticket,priorita,
            stato,data_chiusura,note,created_at
            FROM lavorazioni
            WHERE strftime('%Y-%m',data_richiesta)=?
            ORDER BY data_richiesta ASC, id ASC");
        $rows->execute([$mese]);
        $out = fopen('php://output','w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['ID','Tipo','Dettaglio','Data Rich.','Richiedente',
            'Assegnato','Descrizione','Ticket','N.Ticket','Priorità',
            'Stato','Data Chiusura','Note','Creato il'], ';');
        foreach ($rows->fetchAll() as $r) fputcsv($out, array_values($r), ';');
        // Riepilogo in fondo
        fputcsv($out, [], ';');
        fputcsv($out, ['--- RIEPILOGO MESE '.$mese.' ---'], ';');
        $summary = $pdo->prepare("SELECT
            COUNT(*) tot, SUM(stato='chiuso') chiuse,
            SUM(priorita='urgente') urgenti,
            SUM(ticket_aperto=1) con_ticket
            FROM lavorazioni WHERE strftime('%Y-%m',data_richiesta)=?");
        $summary->execute([$mese]);
        $s = $summary->fetch();
        fputcsv($out, ['Totale','Chiuse','Urgenti','Con Ticket'], ';');
        fputcsv($out, [$s['tot'],$s['chiuse'],$s['urgenti'],$s['con_ticket']], ';');
        fclose($out);
        audit_log('REPORT_MENSILE', $_SESSION['ales_user']??'?', "Mese $mese");
        exit;
    }

    // ── AUDIT LOG VIEWER ─────────────────────────────────────
    if ($act === 'audit_log') {
        header('Content-Type: application/json; charset=utf-8');
        $pin = $_POST['pin'] ?? '';
        if ($pin !== SETTINGS_PIN) { echo json_encode(['ok'=>false,'err'=>'PIN richiesto']); exit; }
        $rows = $pdo->query("SELECT * FROM audit_trail ORDER BY ts DESC LIMIT 200")->fetchAll();
        echo json_encode(['ok'=>true,'rows'=>$rows]);
        exit;
    }

    // ── BACKUP MANUALE ───────────────────────────────────────
    if ($act === 'backup_now') {
        header('Content-Type: application/json; charset=utf-8');
        $pin = $_POST['pin'] ?? '';
        if ($pin !== SETTINGS_PIN) { echo json_encode(['ok'=>false,'err'=>'PIN richiesto']); exit; }
        eseguiBackup();
        $dest = BACKUP_DIR.'/gestione_ticket_backup.sqlite';
        $size = file_exists($dest) ? round(filesize($dest)/1024,1).'KB' : '?';
        echo json_encode(['ok'=>true,'msg'=>"Backup completato ($size)"]);
        exit;
    }

    // ── CSV EXPORT ────────────────────────────────────────────
    if ($act === 'export_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="gestione_ticket_'.date('Ymd').'.csv"');
        $rows = $pdo->query("SELECT id,tipo,dettaglio,data_richiesta,richiedente,
            assegnato_a,descrizione,ticket_aperto,numero_ticket,priorita,
            stato,data_chiusura,note,created_at FROM lavorazioni ORDER BY id DESC")->fetchAll();
        $out = fopen('php://output','w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM per Excel
        fputcsv($out,['ID','Tipo','Dettaglio','Data Richiesta','Richiedente',
            'Assegnato','Descrizione','Ticket','N. Ticket','Priorità',
            'Stato','Data Chiusura','Note','Creato il'],';');
        foreach($rows as $r) {
            fputcsv($out,array_values($r),';');
        }
        fclose($out);
        exit;
    }

    // ── CONFIG / IMPOSTAZIONI ─────────────────────────────────
    if ($act === 'get_config') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'tipi_richiesta' => json_decode(cfg('tipi_richiesta','[]'),true),
            'tipi_licenza'   => json_decode(cfg('tipi_licenza','[]'),true),
            'assegnati'      => json_decode(cfg('assegnati','[]'),true),
            'ai_ok'          => !empty(cfg('anthropic_key','')),
        ]);
        exit;
    }

    if ($act === 'salva_config') {
        header('Content-Type: application/json; charset=utf-8');
        $pin = $_POST['pin'] ?? '';
        if ($pin !== SETTINGS_PIN) {
            echo json_encode(['ok'=>false,'err'=>'PIN errato']);
            exit;
        }
        $campi = ['tipi_richiesta','tipi_licenza','assegnati','anthropic_key','ai_system_prompt'];
        foreach ($campi as $c) {
            if (isset($_POST[$c])) {
                cfg_write($pdo, $c, $_POST[$c]);
            }
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    if ($act === 'verifica_pin') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => ($_POST['pin']??'') === SETTINGS_PIN]);
        exit;
    }

    if ($act === 'lista_ip_bloccati') {
        header('Content-Type: application/json; charset=utf-8');
        if (($_POST['pin']??'') !== SETTINGS_PIN) { echo json_encode(['ok'=>false,'err'=>'PIN non valido']); exit; }
        $db = rl_db();
        $rows = $db->query("SELECT ip, count, last_try FROM attempts WHERE blocked=1 OR count>=2 ORDER BY last_try DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['last_try_fmt'] = date('d/m/Y H:i', $r['last_try']);
        echo json_encode(['ok'=>true,'list'=>$rows]);
        exit;
    }

    if ($act === 'sblocca_ip') {
        header('Content-Type: application/json; charset=utf-8');
        if (($_POST['pin']??'') !== SETTINGS_PIN) { echo json_encode(['ok'=>false,'err'=>'PIN non valido']); exit; }
        $ip = trim($_POST['ip'] ?? '');
        if (!$ip) { echo json_encode(['ok'=>false,'err'=>'IP mancante']); exit; }
        rl_reset($ip);
        audit_log('IP_SBLOCCATO', $utente_sigla, "IP $ip sbloccato manualmente");
        echo json_encode(['ok'=>true]);
        exit;
    }


    // ── AI ASSISTANT ──────────────────────────────────────────
    if ($act === 'ai_chat') {
        header('Content-Type: application/json; charset=utf-8');
        // FIX B12: verifica disponibilità cURL
        if (!function_exists('curl_init')) {
            echo json_encode(['ok'=>false,'err'=>'cURL non disponibile su questo server PHP.']); exit;
        }
        $apiKey = cfg('anthropic_key','');
        if (empty($apiKey)) {
            echo json_encode(['ok'=>false,'err'=>'Chiave API Anthropic non configurata. Vai in ⚙ Impostazioni.']);
            exit;
        }
        $messaggio   = trim($_POST['messaggio'] ?? '');
        $storico_raw = json_decode($_POST['storico'] ?? '[]', true) ?: [];
        if (empty($messaggio)) { echo json_encode(['ok'=>false,'err'=>'Messaggio vuoto']); exit; }

        // FIX B10: tronca lo storico agli ultimi 10 scambi (20 messaggi) per limitare i token
        if (count($storico_raw) > 20) {
            $storico_raw = array_slice($storico_raw, -20);
        }

        $systemPrompt = cfg('ai_system_prompt', '');

        // Aggiunge contesto live dal DB
        $statsRow = $pdo->query("SELECT COUNT(*) tot, SUM(stato='aperto') aperti, SUM(stato='chiuso') chiusi FROM lavorazioni")->fetch();
        $systemPrompt .= "\n\nCONTESTO ATTUALE (aggiornato ora): Totale lavorazioni: {$statsRow['tot']}, Aperte: {$statsRow['aperti']}, Chiuse: {$statsRow['chiusi']}.";
        $systemPrompt .= "\nConfigurazione assegnati: ".cfg('assegnati','[]');
        $systemPrompt .= "\nTipi richiesta configurati: ".cfg('tipi_richiesta','[]');

        // Costruisce array messaggi per Anthropic
        $messages = [];
        foreach ($storico_raw as $m) {
            if (!empty($m['ruolo']) && !empty($m['testo'])) {
                $messages[] = ['role' => $m['ruolo'] === 'utente' ? 'user' : 'assistant', 'content' => $m['testo']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $messaggio];

        $payload = json_encode([
            'model'      => 'claude-sonnet-4-20250514',
            'max_tokens' => 1500,
            'system'     => $systemPrompt,
            'messages'   => $messages,
        ]);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) { echo json_encode(['ok'=>false,'err'=>'Errore rete: '.$err]); exit; }

        $resp = json_decode($raw, true);
        if (!empty($resp['content'][0]['text'])) {
            echo json_encode(['ok'=>true,'risposta'=>$resp['content'][0]['text']]);
        } else {
            echo json_encode(['ok'=>false,'err'=>'Risposta API non valida: '.substr($raw,0,200)]);
        }
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'err'=>'Azione non riconosciuta']);
    exit;
}

// ── Dati iniziali SSR ───────────────────────────────────────────
$initCfg = [
    'tipi_richiesta' => json_decode(cfg('tipi_richiesta','[]'), true),
    'tipi_licenza'   => json_decode(cfg('tipi_licenza','[]'), true),
    'assegnati'      => json_decode(cfg('assegnati','[]'), true),
    'ai_ok'          => !empty(cfg('anthropic_key','')),
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestione Ticket v<?= APP_VERSION ?></title>
<!-- Favicon -->
<link rel="icon" type="image/x-icon" href="assets/favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<!-- PWA -->
<link rel="manifest" href="/?manifest=1">
<meta name="theme-color" content="#ffffff">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Gestione Ticket">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
</head>
<body>

<!-- ═══ HEADER ═══════════════════════════════════════════════ -->
<header>
    <div class="hlogo-wrap">
        <img src="assets/logo.png" alt="Logo" class="hlogo-img">
    </div>
    <div>
        <div class="htitle">Gestione Lavorazioni IT</div>
        <div class="hsub">Reparto IT - v<?= APP_VERSION ?></div>
    </div>
    <div class="hright">
        <span id="sync-ind" class="sync-ind">⟳</span>
        <span class="dot-live"></span>
        <span id="ora-live"></span>
        <button class="hbtn" onclick="exportCsv()" title="Esporta CSV">⬇ CSV</button>
        <button class="hbtn" onclick="apriDashboard()" title="Dashboard KPI">📊 KPI</button>
        <button class="hbtn" onclick="apriReportMensile()" title="Report mensile">📋 Report</button>
        <button class="hbtn" onclick="apriImpostazioni()" <?= $is_readonly ? 'style="display:none"' : '' ?> title="Impostazioni">⚙ Impostazioni</button>
        <button class="hbtn" id="btn-ai-toggle" onclick="toggleAI()" title="Assistente AI">🤖 AI</button>
        <?php if($utente_sigla): ?>
        <span class="hbtn" style="background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.15);cursor:default;gap:6px">
            <span style="font-size:.85rem"><?= $utente_sigla === 'FF' ? '👨‍💻' : ($utente_sigla === 'FP' ? '🧑‍💼' : '👤') ?></span>
            <span><?= htmlspecialchars($utente_nome) ?></span><?php if($is_readonly): ?><span style="background:#fef3c7;color:#92400e;font-size:.65rem;font-weight:700;padding:2px 7px;border-radius:10px;margin-left:6px;letter-spacing:.05em">👁 SOLA LETTURA</span><?php endif; ?>
        </span>
<a href="?cambia_utente=1" class="hbtn" style="background:#1d4f88;border-color:#1d4f88;color:#fff;text-decoration:none" title="Cambia utente">⇄</a>        <?php endif; ?>
    </div>
</header>

<div class="page-body" id="page-body">
<div class="wrap">

    <!-- ═══ TOGGLE MOBILE + NUOVA LAVORAZIONE ══════════════ -->
    <div class="toolbar-mobile-header">
        <div class="toolbar-mobile-toggle" id="toolbar-toggle" onclick="toggleFiltri()">
            <span>📊 Stats &amp; Filtri</span>
            <span id="toolbar-toggle-ico">▼</span>
        </div>
        <?php if(!$is_readonly): ?><button class="btn-p btn-nuova-mobile" onclick="apriModal()">＋ Nuova</button><?php endif; ?>
    </div>

    <!-- ═══ PANNELLO COLLASSABILE: STATS + FILTRI ═══════════ -->
    <div id="toolbar-wrap" class="toolbar-collassabile">

        <!-- Stats dentro il pannello -->
        <div class="stats" id="stats-area"></div>

        <!-- Filtri -->
        <div class="toolbar">
            <span class="tlbl">Stato</span>
            <select id="f-stato" onchange="caricaLista()">
                <option value="tutti" selected>Tutti</option>
                <option value="aperto">Aperti</option>
                <option value="presa in carico">Presa in carico</option>
                <option value="attesa">In attesa</option>
                <option value="chiuso">Chiusi</option>
            </select>
            <div class="sep"></div>
            <span class="tlbl">Assegnato</span>
            <select id="f-assegnato" onchange="caricaLista()">
                <option value="tutti">Tutti</option>
            </select>
            <div class="sep"></div>
            <span class="tlbl">Tipo</span>
            <select id="f-tipo" onchange="caricaLista()">
                <option value="tutti">Tutti</option>
            </select>
            <div class="sep"></div>
            <span class="tlbl">Priorità</span>
            <select id="f-priorita" onchange="caricaLista()">
                <option value="tutti">Tutte</option>
                <option value="urgente">🔴 Urgente</option>
                <option value="alta">🟡 Alta</option>
                <option value="normale">⚪ Normale</option>
            </select>
            <div class="sep"></div>
            <span class="tlbl">Cerca</span>
            <input type="text" id="f-cerca" placeholder="Cerca in descrizione, richiedente…"
                style="border:1px solid var(--bordo);border-radius:5px;padding:5px 9px;font-size:13px;font-family:inherit;background:#fff;color:var(--testo);min-width:200px"
                oninput="cercaDebounce()">
            <!-- Nuova lavorazione visibile solo su desktop -->
            <div class="ml-auto flex-gap desktop-only">
                <?php if(!$is_readonly): ?><button class="btn-p" onclick="apriModal()">＋ Nuova Lavorazione</button><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══ TABELLA ══════════════════════════════════════════ -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th class="th-sort" onclick="setSort('id')" data-col="id"># <span class="sort-ico">↕</span></th>
                    <th class="th-sort" onclick="setSort('tipo')" data-col="tipo">Tipo Richiesta <span class="sort-ico">↕</span></th>
                    <th class="th-sort" onclick="setSort('data_richiesta')" data-col="data_richiesta">Data Rich. <span class="sort-ico">↕</span></th>
                    <th class="th-sort" onclick="setSort('richiedente')" data-col="richiedente">Richiedente <span class="sort-ico">↕</span></th>
                    <th>Assegnato</th>
                    <th>Lavoro da Svolgere</th>
                    <th>Ticket</th>
                    <th class="th-sort" onclick="setSort('priorita')" data-col="priorita">Priorità <span class="sort-ico">↕</span></th>
                    <th class="th-sort" onclick="setSort('stato')" data-col="stato">Stato <span class="sort-ico">↕</span></th>
                    <th class="th-sort" onclick="setSort('data_chiusura')" data-col="data_chiusura">Chiusura <span class="sort-ico">↕</span></th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody id="tabella-body">
                <tr class="row-vuota"><td colspan="11">Caricamento...</td></tr>
            </tbody>
        </table>
    </div>

    <!-- ═══ FOOTER TABELLA: counter + paginazione ════════════ -->
    <div class="table-footer" id="table-footer">
        <span id="row-counter" class="row-counter"></span>
        <div class="pagination" id="pagination"></div>
    </div>

</div><!-- /wrap -->

<div class="ai-panel" id="ai-panel">
    <div class="ai-header">
        <span style="font-size:1.1rem">🤖</span>
        <h3>Assistente IT</h3>
        <span class="ai-badge <?= empty(cfg('anthropic_key','')) ? 'no' : '' ?>" id="ai-stato-badge">
            <?= empty(cfg('anthropic_key','')) ? 'NON CONF.' : 'CLAUDE' ?>
        </span>
        <button class="btn-ai-close" onclick="toggleAI()" title="Chiudi">✕</button>
    </div>

    <div class="ai-chat" id="ai-chat">
        <div class="ai-empty" id="ai-empty">
            <div class="ai-empty-icon">💬</div>
            <div class="ai-empty-txt">Chiedimi qualcosa sulle lavorazioni, sui dati o su come modificare l'app.</div>
        </div>
    </div>

    <div class="ai-suggerimenti" id="ai-chips">
        <div class="ai-sug-lbl">Esempi rapidi</div>
        <button class="ai-chip" onclick="aiChip('Quante lavorazioni urgenti abbiamo aperte?')">📊 Lavorazioni urgenti aperte</button>
        <button class="ai-chip" onclick="aiChip('Come aggiungo un nuovo campo al form?')">🛠 Come modificare il form</button>
        <button class="ai-chip" onclick="aiChip('Genera un report testuale delle lavorazioni della settimana')">📝 Report settimanale</button>
        <button class="ai-chip" onclick="aiChip('Come faccio il backup del database SQLite?')">💾 Backup database</button>
    </div>

    <div class="ai-input-area">
        <div class="ai-input-row">
            <textarea class="ai-textarea" id="ai-input" placeholder="Scrivi un messaggio… (Invio = invia, Shift+Invio = a capo)" rows="1"
                onkeydown="aiKeydown(event)" oninput="autoResize(this)"></textarea>
            <button class="btn-ai-send" onclick="aiInvia()" title="Invia">➤</button>
        </div>
    </div>
</div><!-- /page-body -->

<!-- ═══ MODAL - DASHBOARD KPI ════════════════════════════════ -->
<div class="overlay" id="dash-overlay">
    <div class="modal" style="max-width:780px">
        <div class="mhead" style="background:#0b1929">
            <span style="font-size:1.1rem">📊</span>
            <h2>Dashboard KPI - Reparto IT</h2>
            <button onclick="chiudiDash()" style="margin-left:auto;background:none;border:none;color:rgba(255,255,255,.6);font-size:1.2rem;cursor:pointer">✕</button>
        </div>
        <div class="mbody" id="dash-body" style="gap:16px">
            <div style="text-align:center;padding:40px;color:#94a3b8">Caricamento…</div>
        </div>
        <div class="mfoot">
            <button class="btn-cancel" onclick="chiudiDash()">Chiudi</button>
            <button class="btn-p" onclick="caricaDashboard()">↻ Aggiorna</button>
        </div>
    </div>
</div>

<!-- ═══ MODAL - REPORT MENSILE ═══════════════════════════════ -->
<div class="overlay" id="report-overlay">
    <div class="modal" style="max-width:420px">
        <div class="mhead"><span style="font-size:1.1rem">📋</span><h2>Report Mensile</h2></div>
        <div class="mbody" style="gap:14px">
            <div class="form-row">
                <label>Seleziona Mese</label>
                <input type="month" id="report-mese" style="border:1.5px solid var(--bordo);border-radius:6px;padding:8px 11px;font-size:13px;font-family:inherit;width:100%">
            </div>
            <p style="font-size:.78rem;color:var(--testo-m)">
                Genera un file CSV con tutte le lavorazioni del mese selezionato, incluso un riepilogo finale. Compatibile con Excel.
            </p>
        </div>
        <div class="mfoot">
            <button class="btn-cancel" onclick="chiudiReport()">Annulla</button>
            <button class="btn-p" onclick="scaricaReport()">⬇ Scarica CSV</button>
        </div>
    </div>
</div>

<!-- ═══ BANNER OFFLINE ═══════════════════════════════════════ -->
<div id="offline-banner" style="display:none;position:fixed;bottom:70px;left:50%;transform:translateX(-50%);
    background:rgba(220,38,38,.9);color:#fff;border-radius:8px;padding:10px 20px;
    font-size:.82rem;font-weight:600;z-index:9999;backdrop-filter:blur(8px);
    box-shadow:0 4px 20px rgba(0,0,0,.3);display:none;align-items:center;gap:8px">
    ⚠️ Connessione al server persa - riconnessione automatica in corso…
</div>

<!-- ═══ MODAL - VISUALIZZAZIONE DETTAGLIO
     ═══════════════════════════════════════════════════════════ -->
<div class="overlay" id="view-overlay">
    <div class="modal" style="max-width:560px">
        <div class="mhead" id="view-head">
            <span style="font-size:1.1rem">📋</span>
            <h2 id="view-title">Dettaglio Lavorazione</h2>
            <button onclick="chiudiView()" style="margin-left:auto;background:none;border:none;color:rgba(255,255,255,.6);font-size:1.2rem;cursor:pointer;line-height:1">✕</button>
        </div>
        <div class="mbody" id="view-body" style="gap:10px"></div>

        <!-- F7: thread commenti -->
        <div style="padding:0 22px 16px">
            <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:10px;border-top:1px solid #f1f5f9;padding-top:16px">
                💬 Commenti
            </div>
            <div id="commenti-lista" style="display:flex;flex-direction:column;gap:8px;max-height:200px;overflow-y:auto;margin-bottom:10px"></div>
            <div style="display:flex;gap:7px;align-items:flex-end">
                <input type="text" id="commento-autore" placeholder="Il tuo nome"
                    style="width:110px;border:1.5px solid #e2e8f0;border-radius:6px;padding:6px 9px;font-size:12px;font-family:inherit;flex-shrink:0">
                <textarea id="commento-testo" placeholder="Scrivi un commento…" rows="1"
                    style="flex:1;border:1.5px solid #e2e8f0;border-radius:6px;padding:6px 9px;font-size:12px;font-family:inherit;resize:none;min-height:34px"
                    onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();inviaCommento()}"></textarea>
                <button class="btn-p" onclick="inviaCommento()" style="padding:6px 12px;font-size:12px;flex-shrink:0">✉</button>
            </div>
        </div>

        <div class="mfoot">
            <button class="btn-cancel" onclick="chiudiView()">Chiudi</button>
            <button class="btn-outline" onclick="stampaLavorazione()" style="display:inline-flex;align-items:center;gap:5px">🖨 Stampa</button>
            <button class="btn-outline" onclick="duplicaLavorazione(_viewId)" style="display:inline-flex;align-items:center;gap:5px">📋 Duplica</button>
            <button class="btn-edit" onclick="apriModificaDaView()" style="font-size:13px;padding:7px 16px">✏ Modifica</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL - NUOVA LAVORAZIONE
     ═══════════════════════════════════════════════════════════ -->
<div class="overlay" id="modal-overlay">
    <div class="modal">
        <div class="mhead"><span style="font-size:1.1rem">📝</span><h2>Nuova Lavorazione</h2></div>
        <div class="mbody">
            <div class="form-row">
                <label>Tipo Richiesta <span class="obl">*</span></label>
                <select id="m-tipo" onchange="onTipoChange()"></select>
            </div>
            <!-- ── Blocco Licenze (visibile solo se tipo=Licenze) ── -->
            <div class="form-row hidden" id="row-licenza">
                <label>Categoria <span class="obl">*</span></label>
                <!-- Tre card selezionabili -->
                <div class="lic-cat-wrap" id="m-lic-cat-wrap">
                    <label class="lic-cat-card active" data-val="Nuova Licenza">
                        <input type="radio" name="m_sotto_lic" value="Nuova Licenza" checked>
                        <span class="lic-cat-ico">🆕</span>
                        <span class="lic-cat-lbl">Nuova Licenza</span>
                        <span class="lic-cat-sub">Prima attivazione</span>
                    </label>
                    <label class="lic-cat-card" data-val="Rinnovo">
                        <input type="radio" name="m_sotto_lic" value="Rinnovo">
                        <span class="lic-cat-ico">🔄</span>
                        <span class="lic-cat-lbl">Rinnovo</span>
                        <span class="lic-cat-sub">Estendi esistente</span>
                    </label>
                    <label class="lic-cat-card" data-val="Inserimento Rinnovo">
                        <input type="radio" name="m_sotto_lic" value="Inserimento Rinnovo">
                        <span class="lic-cat-ico">📋</span>
                        <span class="lic-cat-lbl">Inserimento Rinnovo</span>
                        <span class="lic-cat-sub">Registra dati rinnovo</span>
                    </label>
                </div>
                <!-- Nome licenza: lista + testo libero -->
                <div style="margin-top:12px">
                    <label>Nome Licenza <span class="obl">*</span></label>
                    <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:8px;align-items:center;margin-top:5px">
                        <select id="m-dettaglio" onchange="onNomeLicenzaSelect('m')"
                            style="border:1.5px solid var(--bordo);border-radius:6px;padding:7px 10px;font-size:13px;font-family:inherit">
                        </select>
                        <span style="color:var(--testo-m);font-size:.78rem;text-align:center">oppure</span>
                        <input type="text" id="m-nome-licenza-libero" placeholder="Scrivi nome nuovo…"
                            oninput="onNomeLicenzaLibero('m')"
                            style="border:1.5px solid var(--bordo);border-radius:6px;padding:7px 10px;font-size:13px;font-family:inherit">
                    </div>
                    <p id="m-lic-hint" style="font-size:.7rem;color:var(--testo-m);margin-top:5px">
                        💡 Per <b>Nuova Licenza</b>: scrivi il nome nel campo libero. Per <b>Rinnovo</b>: seleziona dalla lista o scrivi il nome.
                    </p>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-row">
                    <label>Data Richiesta <span class="obl">*</span></label>
                    <input type="date" id="m-data">
                </div>
                <div class="form-row">
                    <label>Richiedente / Risorsa</label>
                    <input type="text" id="m-richiedente" placeholder="es. Ufficio Acquisti, AA…"
                        list="richiedenti-list" autocomplete="off">
                    <datalist id="richiedenti-list"></datalist>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-row">
                    <label>Assegnato a <span class="obl">*</span></label>
                    <select id="m-assegnato"></select>
                </div>
                <div class="form-row">
                    <label>Priorità</label>
                    <select id="m-priorita">
                        <option value="normale">⚪ Normale</option>
                        <option value="alta">🟡 Alta</option>
                        <option value="urgente">🔴 Urgente</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <label>Lavoro da Svolgere <span class="obl">*</span></label>
                <textarea id="m-descrizione" placeholder="Descrivi l'attività nel dettaglio…"></textarea>
            </div>
            <div class="form-row">
                <label class="check-wrap">
                    <input type="checkbox" id="m-ticket" onchange="onTicketChange()">
                    <span>Apertura ticket necessaria per il completamento</span>
                </label>
            </div>
            <div class="form-row hidden" id="row-ticket">
                <label>Numero Ticket</label>
                <input type="text" id="m-num-ticket" placeholder="es. TKT-2025-001">
            </div>
            <div class="form-row">
                <label>Note Aggiuntive</label>
                <textarea id="m-note" placeholder="Riferimenti, contatti, urgenza…" style="min-height:52px"></textarea>
            </div>
            <div class="form-row">
                <label>Allegati (Drag & Drop)</label>
                <div class="dropzone" id="dropzone-m">
                    <span class="dropzone-ico">📁</span>
                    <span class="dropzone-txt">Trascina file qui o clicca per caricare</span>
                </div>
                <div class="allegati-list" id="allegati-m"></div>
            </div>
        </div>
        <div class="mfoot">
            <button class="btn-cancel" onclick="chiudiModal()">Annulla</button>
            <button class="btn-p" onclick="salva()">💾 Salva Lavorazione</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL - MODIFICA LAVORAZIONE
     ═══════════════════════════════════════════════════════════ -->
<div class="overlay" id="edit-overlay">
    <div class="modal">
        <div class="mhead"><span style="font-size:1.1rem">✏️</span><h2>Modifica Lavorazione <span id="edit-id-label" style="opacity:.6;font-size:.8rem"></span></h2></div>
        <div class="mbody">
            <div class="form-row">
                <label>Tipo Richiesta <span class="obl">*</span></label>
                <select id="e-tipo" onchange="onEditTipoChange()"></select>
            </div>
            <!-- ── Blocco Licenze (visibile solo se tipo=Licenze) ── -->
            <div class="form-row hidden" id="edit-row-licenza">
                <label>Categoria <span class="obl">*</span></label>
                <div class="lic-cat-wrap" id="e-lic-cat-wrap">
                    <label class="lic-cat-card active" data-val="Nuova Licenza">
                        <input type="radio" name="e_sotto_lic" value="Nuova Licenza" checked>
                        <span class="lic-cat-ico">🆕</span>
                        <span class="lic-cat-lbl">Nuova Licenza</span>
                        <span class="lic-cat-sub">Prima attivazione</span>
                    </label>
                    <label class="lic-cat-card" data-val="Rinnovo">
                        <input type="radio" name="e_sotto_lic" value="Rinnovo">
                        <span class="lic-cat-ico">🔄</span>
                        <span class="lic-cat-lbl">Rinnovo</span>
                        <span class="lic-cat-sub">Estendi esistente</span>
                    </label>
                    <label class="lic-cat-card" data-val="Inserimento Rinnovo">
                        <input type="radio" name="e_sotto_lic" value="Inserimento Rinnovo">
                        <span class="lic-cat-ico">📋</span>
                        <span class="lic-cat-lbl">Inserimento Rinnovo</span>
                        <span class="lic-cat-sub">Registra dati rinnovo</span>
                    </label>
                </div>
                <div style="margin-top:12px">
                    <label>Nome Licenza <span class="obl">*</span></label>
                    <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:8px;align-items:center;margin-top:5px">
                        <select id="e-dettaglio" onchange="onNomeLicenzaSelect('e')"
                            style="border:1.5px solid var(--bordo);border-radius:6px;padding:7px 10px;font-size:13px;font-family:inherit">
                        </select>
                        <span style="color:var(--testo-m);font-size:.78rem;text-align:center">oppure</span>
                        <input type="text" id="e-nome-licenza-libero" placeholder="Scrivi nome nuovo…"
                            oninput="onNomeLicenzaLibero('e')"
                            style="border:1.5px solid var(--bordo);border-radius:6px;padding:7px 10px;font-size:13px;font-family:inherit">
                    </div>
                </div>
            </div>
            <div class="form-grid">
                <div class="form-row">
                    <label>Data Richiesta <span class="obl">*</span></label>
                    <input type="date" id="e-data">
                </div>
                <div class="form-row">
                    <label>Richiedente / Risorsa</label>
                    <input type="text" id="e-richiedente" placeholder="es. Ufficio Acquisti, AA…">
                </div>
            </div>
            <div class="form-grid">
                <div class="form-row">
                    <label>Assegnato a <span class="obl">*</span></label>
                    <select id="e-assegnato"></select>
                </div>
                <div class="form-row">
                    <label>Priorità</label>
                    <select id="e-priorita">
                        <option value="normale">⚪ Normale</option>
                        <option value="alta">🟡 Alta</option>
                        <option value="urgente">🔴 Urgente</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <label>Lavoro da Svolgere <span class="obl">*</span></label>
                <textarea id="e-descrizione" placeholder="Descrivi l'attività nel dettaglio…"></textarea>
            </div>
            <div class="form-row">
                <label class="check-wrap">
                    <input type="checkbox" id="e-ticket" onchange="onEditTicketChange()">
                    <span>Apertura ticket necessaria per il completamento</span>
                </label>
            </div>
            <div class="form-row hidden" id="edit-row-ticket">
                <label>Numero Ticket</label>
                <input type="text" id="e-num-ticket" placeholder="es. TKT-2025-001">
            </div>
            <div class="form-row">
                <label>Stato</label>
                <select id="e-stato">
                    <option value="aperto">🟡 Aperto</option>
                    <option value="presa in carico">🔵 Presa in carico</option>
                    <option value="attesa">🟠 In attesa</option>
                    <option value="chiuso">✅ Chiuso</option>
                </select>
            </div>
            <div class="form-row">
                <label>Note Aggiuntive</label>
                <textarea id="e-note" placeholder="Riferimenti, contatti, urgenza…" style="min-height:52px"></textarea>
            </div>
            <div class="form-row">
                <label>Allegati (Drag & Drop)</label>
                <div class="dropzone" id="dropzone-e">
                    <span class="dropzone-ico">📁</span>
                    <span class="dropzone-txt">Trascina file qui o clicca per caricare</span>
                </div>
                <div class="allegati-list" id="allegati-e"></div>
            </div>
        </div>
        <div class="mfoot">
            <button class="btn-cancel" onclick="chiudiModifica()">Annulla</button>
            <button class="btn-p" onclick="salvaModifica()">💾 Salva Modifiche</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL - IMPOSTAZIONI
     ═══════════════════════════════════════════════════════════ -->
<div class="overlay" id="sett-overlay">
    <div class="modal" style="max-width:640px">
        <div class="mhead"><span style="font-size:1.1rem">⚙</span><h2>Impostazioni</h2></div>

        <!-- PIN guard -->
        <div id="sett-pin-area" class="pin-guard">
            <p>Inserisci il PIN per accedere alle impostazioni</p>
            <input type="password" id="sett-pin-input" maxlength="6" placeholder="• • • •" onkeydown="if(event.key==='Enter')verificaPin()">
            <button class="btn-p" onclick="verificaPin()">🔓 Accedi</button>
        </div>

        <!-- Contenuto impostazioni (nascosto prima del PIN) -->
        <div id="sett-content" style="display:none">
            <div class="sett-tabs">
                <button class="sett-tab active" onclick="settTab('tipi',this)">Tipi Richiesta</button>
                <button class="sett-tab" onclick="settTab('licenze',this)">Licenze</button>
                <button class="sett-tab" onclick="settTab('assegnati',this)">Assegnati</button>
                <button class="sett-tab" onclick="settTab('ai',this)">🤖 AI</button>
                <button class="sett-tab" onclick="settTab('backup',this)">💾 Backup & Log</button>
                <button class="sett-tab" onclick="settTab('sicurezza',this);caricaIpBloccati()">🔒 Sicurezza</button>
            </div>

            <!-- Tab: Tipi -->
            <div class="sett-section active" id="sett-tipi">
                <div class="form-row"><label>Tipi di Richiesta Attivi <span style="font-weight:400;color:var(--testo-m)">- trascina per riordinare</span></label>
                    <div class="sett-chip-wrap" id="chips-tipi"></div>
                </div>
                <div class="sett-add-row">
                    <input type="text" id="add-tipo-inp" placeholder="Nuovo tipo…" onkeydown="if(event.key==='Enter')addTipo()">
                    <button class="btn-p" onclick="addTipo()">＋ Aggiungi</button>
                </div>
            </div>

            <!-- Tab: Licenze -->
            <div class="sett-section" id="sett-licenze">
                <div class="form-row"><label>Tipi di Licenza</label>
                    <div class="sett-chip-wrap" id="chips-licenze"></div>
                </div>
                <div class="sett-add-row">
                    <input type="text" id="add-lic-inp" placeholder="Nuova licenza…" onkeydown="if(event.key==='Enter')addLicenza()">
                    <button class="btn-p" onclick="addLicenza()">＋ Aggiungi</button>
                </div>
            </div>

            <!-- Tab: Assegnati -->
            <div class="sett-section" id="sett-assegnati">
                <div class="form-row"><label>Persone Assegnabili</label>
                    <div class="sett-chip-wrap" id="chips-assegnati"></div>
                </div>
                <div class="sett-add-row">
                    <input type="text" id="add-ass-sigla" placeholder="Sigla (es. GR)" maxlength="4" style="max-width:90px">
                    <input type="text" id="add-ass-nome" placeholder="Nome completo">
                    <button class="btn-p" onclick="addAssegnato()">＋</button>
                </div>
            </div>

            <!-- Tab: AI -->
            <div class="sett-section" id="sett-ai">
                <div class="form-row">
                    <label>Chiave API Anthropic (Claude)</label>
                    <input type="text" id="sett-api-key" placeholder="sk-ant-api03-…" style="font-family:var(--mono);font-size:.8rem">
                    <p style="font-size:.72rem;color:var(--testo-m);margin-top:6px">
                        Ottieni la chiave su <a href="https://console.anthropic.com" target="_blank" style="color:var(--blu-accent)">console.anthropic.com</a>.
                        Il costo per uso normale è di qualche centesimo al mese.
                    </p>
                </div>
                <div class="form-row">
                    <label>Prompt di sistema AI (avanzato)</label>
                    <textarea id="sett-ai-prompt" style="min-height:100px;font-size:.78rem"></textarea>
                </div>
            </div>

            <!-- Tab: Backup & Log -->
            <div class="sett-section" id="sett-backup">
                <div class="form-row">
                    <label>Backup Database</label>
                    <p style="font-size:.78rem;color:var(--testo-m);margin-bottom:10px">
                        Il backup automatico viene eseguito ogni 24 ore nella cartella
                        <code style="font-family:var(--mono);background:#f1f5f9;padding:2px 6px;border-radius:4px">sqlite_backup_data/</code>.
                        Puoi forzarne uno manuale ora.
                    </p>
                    <button class="btn-p" onclick="backupManuale()" style="width:auto">💾 Backup Manuale Ora</button>
                    <span id="backup-status" style="margin-left:10px;font-size:.78rem;color:var(--testo-m)"></span>
                </div>
                <div class="form-row" style="margin-top:16px">
                    <label>Log Accessi e Operazioni <span style="font-weight:400;color:var(--testo-m)">(ultimi 200)</span></label>
                    <div id="audit-log-wrap" style="max-height:280px;overflow-y:auto;font-family:var(--mono);font-size:.7rem;background:#f8fafc;border-radius:6px;padding:10px;border:1px solid var(--bordo);line-height:1.7">
                        <span style="color:#94a3b8">Clicca "Mostra Log" per caricare</span>
                    </div>
                    <button class="btn-outline" onclick="mostraLog()" style="margin-top:8px;width:auto">📋 Mostra Log</button>
                </div>
            </div>


            <!-- Tab: Sicurezza -->
            <div class="sett-section" id="sett-sicurezza">
                <div class="form-row">
                    <label>🔒 IP Bloccati</label>
                    <p style="font-size:.78rem;color:var(--testo-m);margin-bottom:12px">
                        Gli IP vengono bloccati automaticamente dopo 2 tentativi di accesso falliti.
                        Clicca <b>Sblocca</b> per riabilitare l'accesso.
                    </p>
                    <div id="ip-bloccati-wrap" style="font-size:.82rem">
                        <span style="color:#94a3b8">Caricamento…</span>
                    </div>
                    <button class="btn-outline" onclick="caricaIpBloccati()" style="margin-top:10px;width:auto">
                        ↻ Aggiorna lista
                    </button>
                </div>
            </div>

            <div class="mfoot">
                <button class="btn-cancel" onclick="chiudiImpostazioni()">Chiudi</button>
                <button class="btn-p" onclick="salvaSett()">💾 Salva Configurazione</button>
            </div>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
     ═══════════════════════════════════════════════════════════ -->
<!-- Globals SSR: dichiarati prima di app.js -->
<script>
'use strict';
var CFG = <?= json_encode($initCfg) ?>;
const UTENTE = <?= json_encode(['sigla' => $utente_sigla, 'nome' => $utente_nome]) ?>;
const IS_READONLY = <?= json_encode((bool)$is_readonly) ?>;

// Toggle filtri su mobile
function toggleFiltri() {
    const wrap = document.getElementById('toolbar-wrap');
    const ico  = document.getElementById('toolbar-toggle-ico');
    const aperto = wrap.classList.toggle('filtri-aperti');
    ico.textContent = aperto ? '▲' : '▼';
}
// ── Gestione IP bloccati (tab Sicurezza impostazioni) ─────────
async function caricaIpBloccati() {
    const wrap = document.getElementById('ip-bloccati-wrap');
    if (!wrap) return;
    const pin = document.getElementById('sett-pin-input')?.value || '';
    wrap.innerHTML = '<span style="color:#94a3b8">Caricamento…</span>';
    const res = await api({_action:'lista_ip_bloccati', pin});
    if (!res.ok) { wrap.innerHTML = '<span style="color:#dc2626">Errore: '+esc(res.err||'')+'</span>'; return; }
    if (!res.list || res.list.length === 0) {
        wrap.innerHTML = '<span style="color:#16a34a">✅ Nessun IP bloccato</span>';
        return;
    }
    wrap.innerHTML = res.list.map(r => `
        <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;background:#fee2e2;border-radius:6px;margin-bottom:6px;border-left:3px solid #dc2626">
            <span style="font-family:var(--mono);font-weight:700;flex:1">${esc(r.ip)}</span>
            <span style="font-size:.72rem;color:#64748b">${esc(r.last_try_fmt)} · ${r.count} tentativ${r.count===1?'o':'i'}</span>
            <button class="btn-p" onclick="sbloccaIp('${esc(r.ip)}')"
                style="background:#dc2626;padding:4px 12px;font-size:.75rem;width:auto">
                🔓 Sblocca
            </button>
        </div>`).join('');
}

async function sbloccaIp(ip) {
    const pin = document.getElementById('sett-pin-input')?.value || '';
    if (!confirm('Sbloccare IP: ' + ip + '?')) return;
    const res = await api({_action:'sblocca_ip', ip, pin});
    if (res.ok) { toast('IP ' + ip + ' sbloccato'); caricaIpBloccati(); }
    else toast('Errore: ' + (res.err||''), true);
}


</script>
<script src="app.js"></script>

<!-- ═══ PWA + ONESIGNAL ════════════════════════════════════════ -->
<script src="https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js" defer></script>
<script>
// Registrazione Service Worker PWA (separato da OneSignal)
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .then(() => console.log('PWA SW registrato'))
        .catch(err => console.warn('PWA SW fallito:', err));
}

// Inizializzazione OneSignal
window.OneSignalDeferred = window.OneSignalDeferred || [];
OneSignalDeferred.push(async function(OneSignal) {
    await OneSignal.init({
        appId: "d94f94d6-95ff-4862-90f2-d73817a09130",
        serviceWorkerPath: "OneSignalSDKWorker.js",
        notifyButton: {
            enable: true,          // Mostra il campanellino in basso a destra
            size: 'medium',
            position: 'bottom-right',
            offset: { bottom: '20px', right: '20px' },
            text: {
                'tip.state.unsubscribed':  'Attiva notifiche',
                'tip.state.subscribed':    'Notifiche attive',
                'tip.state.blocked':       'Notifiche bloccate',
                'message.prenotify':       'Clicca per ricevere notifiche',
                'message.action.subscribed':   'Notifiche attivate!',
                'message.action.resubscribed': 'Notifiche riattivate!',
                'message.action.unsubscribed': 'Notifiche disattivate.',
                'dialog.main.title':       'Notifiche',
                'dialog.main.button.subscribe':   'ATTIVA',
                'dialog.main.button.unsubscribe': 'DISATTIVA',
                'dialog.blocked.title':    'Sblocca le notifiche',
                'dialog.blocked.message':  'Segui queste istruzioni per abilitare le notifiche:',
            },
        },
    });
});
</script>
</body>
</html>
