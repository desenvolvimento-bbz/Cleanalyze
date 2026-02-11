<?php
$PYTHON    = 'C:\\Users\\SEU_USUARIO\\AppData\\Local\\Programs\\Python\\Python312\\python.exe';
$PDFTOTEXT = 'C:\\poppler\\Library\\bin\\pdftotext.exe';
$BASE      = 'C:\\xampp\\htdocs\\Cleanalyze';

$pdf   = $BASE . '\\uploads\\INADIMPLENCIA SISTEMA.pdf';
$config= $BASE . '\\config\\inadimplencia.json';
$modelo= $BASE . '\\modelo_planilha_inadimplencia.xlsx';
$saida = $BASE . '\\uploads\\saida_inadimplencia.xlsx';

$cmd = "\"$PYTHON\" \"$BASE\\cleanalize_cli.py\" "
     . "--pdftotext \"$PDFTOTEXT\" "
     . "--pdf \"$pdf\" "
     . "--tipo inadimplencia "
     . "--config \"$config\" "
     . "--modelo \"$modelo\" "
     . "--saida \"$saida\" 2>&1";

exec($cmd, $out, $code);

if ($code === 0) {
  echo "OK! Planilha gerada em: $saida";
} else {
  echo "<pre>Falha (code=$code)\n" . htmlspecialchars(implode("\n", $out)) . "</pre>";
}
?>
