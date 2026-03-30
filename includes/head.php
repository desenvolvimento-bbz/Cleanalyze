  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<?php
  $_headBase = rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
  $_headDocRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  $_headBaseUrl = '/' . ltrim(str_replace($_headDocRoot, '', $_headBase), '/');
  $_headBaseUrl = rtrim($_headBaseUrl, '/');
?>
  <link rel="icon" type="image/png" href="<?= $_headBaseUrl ?>/assets/img/favicon.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    :root{ --azul:#04193b; --cinza:#b8b8c4; --cinzaClaro:#efeff4; }
    body{ background:var(--cinzaClaro); color:var(--azul); font-family:'Manrope',sans-serif; }
    .navbar{ background:var(--azul); }
    .navbar .navbar-brand, .navbar a{ color:#fff !important; }
    .btn-primary{ background:var(--azul); border-color:var(--azul); }
    .card{ border-color:var(--cinza); }
  </style>
