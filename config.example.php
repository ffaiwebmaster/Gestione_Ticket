<?php
// ================================================================
// Gestione Ticket — Configurazione
// ================================================================
// ISTRUZIONI:
// 1. Rinomina questo file in "config.php"
// 2. Modifica i valori qui sotto con i tuoi dati
// 3. Non committare mai config.php su Git (già in .gitignore)
// ================================================================

// PIN per accedere al pannello Impostazioni
define('AUTH_PASSWORD', 'CAMBIA_QUESTA_PASSWORD');

// Segreto TOTP per il 2FA (genera il tuo con il comando nel README)
define('TOTP_SECRET', 'GENERA_UN_NUOVO_SEGRETO');

// PIN di accesso alle Impostazioni
define('SETTINGS_PIN', 'CAMBIA_QUESTO_PIN');

// Accesso solo da rete interna (true = nessun login in LAN)
define('INTERNAL_ONLY', true);

// ── Email / SMTP (opzionale — per invio QR 2FA via email) ────
// Lascia vuoto se non usi l'invio email
define('SMTP_HOST', '');           // es. smtp-relay.brevo.com
define('SMTP_PORT', 587);
define('SMTP_USER', '');           // utente SMTP
define('SMTP_PASS', '');           // password SMTP
define('SMTP_FROM', '');           // es. noreply@tuodominio.it
