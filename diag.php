<?php
header('Content-Type: text/plain; charset=utf-8');
echo "PHP: " . PHP_VERSION . "\n";
echo "ZipArchive: " . (class_exists('ZipArchive') ? "OK" : "MISSING") . "<br>";
echo "python -V: " . shell_exec('python -V 2>&1');
echo "python3 -V: " . shell_exec('python3 -V 2>&1');
$which = (stripos(PHP_OS,'WIN')===0 ? 'where' : 'which');
echo "which/where pdftotext: " . shell_exec("$which pdftotext 2>&1") . "\n";
echo "POPPLER_PDFTOTEXT: " . (getenv('POPPLER_PDFTOTEXT') ?: '(não definida)') . "\n";
