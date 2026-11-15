<?php
declare(strict_types=1);
require_once __DIR__.'/Store.php';
require_once __DIR__.'/Auth.php';
require_once __DIR__.'/Media.php';
require_once __DIR__.'/Legacy.php';
function chronicle_boot():array {
    $path=getenv('CHRONICLE_CONFIG');
    if(!$path||!is_file($path))throw new Chronicle\Failure('設定ファイルを確認してください',503);
    $config=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    foreach(['dsn','origin','password_hash','rate_secret','sessions'] as $key)if(!is_string($config[$key]??null)||$config[$key]==='')throw new Chronicle\Failure('設定を確認してください',503);
    $local=($config['local_test']??false)===true;
    if($local&&!preg_match('~^http://127\.0\.0\.1:\d+$~D',$config['origin']))throw new Chronicle\Failure('テスト環境の指定が不正です',503);
    if(!$local&&!preg_match('~^https://[A-Za-z0-9.-]+(?::\d+)?$~D',$config['origin']))throw new Chronicle\Failure('HTTPSの設定を確認してください',503);
    if(!password_get_info($config['password_hash'])['algo']||strlen($config['rate_secret'])<32)throw new Chronicle\Failure('認証設定を確認してください',503);
    $root=realpath(($_SERVER['DOCUMENT_ROOT']??'')?:__DIR__.'/../public');
    if($root&&str_starts_with(realpath($path),$root.DIRECTORY_SEPARATOR))throw new Chronicle\Failure('設定は公開領域外に置いてください',503);
    if($root&&str_starts_with(realpath($config['sessions'])?:'', $root.DIRECTORY_SEPARATOR))throw new Chronicle\Failure('セッションは公開領域外に置いてください',503);
    if(str_starts_with($config['dsn'],'sqlite:')&&$root&&str_starts_with(realpath(dirname(substr($config['dsn'],7)))?:'', $root))throw new Chronicle\Failure('DBは公開領域外に置いてください',503);
    if(PHP_SAPI!=='cli'&&!$local&&($_SERVER['HTTPS']??'')!=='on')throw new Chronicle\Failure('HTTPSで接続してください',403);
    $db=new PDO($config['dsn'],$config['db_user']??null,$config['db_password']??null);
    return [new Chronicle\Store($db),$config];
}
