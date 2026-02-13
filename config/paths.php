<?php
/**
 * config/paths.php
 * Auto-detecta o ambiente (Windows/XAMPP vs Linux/Docker) e define os caminhos.
 * Incluir UMA VEZ em cada script PHP que precise dos binários ou do $BASE.
 */

if (PHP_OS_FAMILY === 'Windows') {
    // ===== Ambiente local (XAMPP no Windows) =====
    define('APP_PYTHON',    'C:\\Users\\DESENV-ERICH\\AppData\\Local\\Programs\\Python\\Python312\\python.exe');
    define('APP_PDFTOTEXT', 'C:\\poppler\\Library\\bin\\pdftotext.exe');
    define('APP_TESSERACT', 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe');
    define('APP_POPPLER',   'C:\\poppler\\Library\\bin');
    define('APP_BASE',      'C:\\xampp\\htdocs\\Cleanalyze');
} else {
    // ===== Ambiente Docker (Linux/Debian) =====
    define('APP_PYTHON',    'python3');
    define('APP_PDFTOTEXT', 'pdftotext');
    define('APP_TESSERACT', 'tesseract');
    define('APP_POPPLER',   '/usr/bin');
    define('APP_BASE',      '/var/www/html');
}

define('APP_OCR_LANG',   'por+eng');
define('APP_UPLOADS',    APP_BASE . DIRECTORY_SEPARATOR . 'uploads');
define('APP_SEP',        DIRECTORY_SEPARATOR);
