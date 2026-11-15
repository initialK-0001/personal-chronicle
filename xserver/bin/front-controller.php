<?php
declare(strict_types=1);
// Generate the sole PHP entry for public_html; do not copy src/config/vendor into public_html.
if(PHP_SAPI!=='cli')exit(1);
if(count($argv)!==3)throw new RuntimeException('設定JSONと公開ディレクトリの絶対パスを指定してください');
$config=realpath($argv[1]);$public=realpath($argv[2]);$app=realpath(__DIR__.'/..');
if(!$config||!is_file($config)||!$public||!is_dir($public))throw new RuntimeException('配置先を確認してください');
foreach([$config,$app] as $private)if($private===$public||str_starts_with($private,$public.'/'))throw new RuntimeException('本体と設定は公開領域外へ置いてください');
echo "<?php\ndeclare(strict_types=1);\nputenv(".var_export('CHRONICLE_CONFIG='.$config,true).");\nrequire ".var_export($app.'/public/index.php',true).";\n";
