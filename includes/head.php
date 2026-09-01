<?php
/**
 * <head> padrão do Cleanalyze.
 *
 * A identidade visual BBZ (paleta, tipografia, componentes) fica em
 * assets/css/bbz.css — não redefina as cores da marca nas páginas.
 *
 * A base URL é calculada como no navbar, para funcionar da raiz, de web/ e de auth/.
 */
$_headBase    = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
$_headDocRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$_headBaseUrl = rtrim('/' . ltrim(str_replace($_headDocRoot, '', $_headBase), '/'), '/');
?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/png" href="<?= $_headBaseUrl ?>/assets/img/favicon.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
  <link href="<?= $_headBaseUrl ?>/assets/css/bbz.css" rel="stylesheet">
