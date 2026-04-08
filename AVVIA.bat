@echo off
chcp 65001 >nul
title Gestione Ticket - Server Locale

:: Rileva IP della macchina sulla rete locale
for /f "tokens=2 delims=:" %%a in ('ipconfig ^| findstr "IPv4" ^| findstr "10. 192.168. 172."') do set "MYIP=%%a"
set "MYIP=%MYIP: =%"

:: Crea config.php locale (accesso LAN senza login)
(
echo ^<?php
echo define^('SETTINGS_PIN',  'CAMBIA_QUESTO_PIN'^);
echo define^('AUTH_PASSWORD', 'CAMBIA_QUESTA_PASSWORD'^);
echo define^('TOTP_SECRET',   'GENERA_UN_NUOVO_SEGRETO'^);
echo define^('INTERNAL_ONLY', true^);
) > "%~dp0config.php"

echo.
echo =======================================================
echo   Gestione Ticket - Task Manager
echo =======================================================
echo.
echo  LOCALE:  http://localhost:8080
if defined MYIP echo  RETE:    http://%MYIP%:8080
echo.
echo  Apri il browser all'indirizzo sopra.
echo  CTRL+C per fermare il server.
echo =======================================================
echo.

:: Modifica il percorso PHP se necessario (default: C:\php\php.exe)
set PHP_EXE=C:\php\php.exe

%PHP_EXE% -S 0.0.0.0:8080 -t "%~dp0"

echo.
echo  Server arrestato.
pause
