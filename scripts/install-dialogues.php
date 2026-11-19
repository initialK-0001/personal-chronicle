<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
umask(0077);
ini_set('display_errors','0');
$root=__DIR__;
$configFile=$root.'/private/config.json';
$release=$root.'/private/dialogues-release-20261119';
$reportFile=$root.'/dialogues-deployment-v2.json';
$lock=fopen($root.'/dialogues-deployment.lock','c');
if (!$lock || is_file($reportFile)) exit;
if (!flock($lock,LOCK_EX|LOCK_NB)) {
    file_put_contents($root.'/dialogues-previous-running.json','{"previous_running":true}');
    exit;
}
function verify(bool $ok,string $step):void {
    if (!$ok) throw new RuntimeException($step);
}
function putFile(string $path,string $bytes,int $mode):void {
    if (!is_dir(dirname($path))) verify(mkdir(dirname($path),0700,true),'directory');
    $tmp=tempnam(dirname($path),'dialogue-stage-');
    verify(is_string($tmp),'temporary');
    try {
        verify(file_put_contents($tmp,$bytes)===strlen($bytes) && chmod($tmp,$mode),'write');
        verify(rename($tmp,$path),'replace');
    } finally { if (is_file($tmp)) unlink($tmp); }
}
$report=['time'=>gmdate('c'),'success'=>false];
$phase='verify_release';
register_shutdown_function(static function() use (&$report,&$phase,$reportFile) {
    if (!is_file($reportFile)) {
        $error=error_get_last();
        $report['phase']=$phase;
        $report['shutdown_type']=$error['type']??null;
        $report['line']=$error['line']??null;
        $report['file']=isset($error['file'])?basename($error['file']):null;
        file_put_contents($reportFile,json_encode($report,JSON_INVALID_UTF8_SUBSTITUTE));
    }
});
try {
    $items=json_decode(gzdecode(base64_decode('__DIALOGUE_BUNDLE__',true)),true,512,JSON_THROW_ON_ERROR);
    $raw=file_get_contents($configFile);
    $cfg=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    $local=($cfg['local_test']??false) && str_starts_with($cfg['dsn'],'sqlite:') && preg_match('~^http://127\.0\.0\.1:\d+$~D',$cfg['origin']);
    verify(PHP_VERSION_ID>=80300 && ($local || ($cfg['origin']==='https://brand-new.tokyo' && str_starts_with($cfg['dsn'],'mysql:'))),'environment');
    $ledger=$root.'/private/dialogue-deletion-ledger.txt';
    $enabled=($cfg['dialogues_enabled']??false)===true;
    verify(!$enabled || (is_file($release.'/ready') && ($cfg['dialogue_deletion_ledger']??null)===$ledger),'existing_configuration');
    foreach ($items as $path=>$item) {
        $dest=$root.'/xserver/'.$path;
        $previous=$release.'/previous/'.$path;
        $actual=is_file($dest)?hash_file('sha256',$dest):null;
        $applied=is_file($release.'/ready') && $actual===$item['after'];
        verify($applied || $actual===$item['before'],'previous:'.$path);
        if ($item['before']!==null) {
            if (!is_file($previous) && !$applied) putFile($previous,file_get_contents($dest),0600);
            verify(is_file($previous) && hash_file('sha256',$previous)===$item['before'],'backup:'.$path);
        }
        $stage=$release.'/stage/'.$path;
        $data=base64_decode($item['data'],true);
        verify(is_string($data) && hash('sha256',$data)===$item['after'],'payload:'.$path);
        putFile($stage,$data,0600);
        if (str_ends_with($path,'.php')) {
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($stage).' 2>&1',$output,$code);
            verify($code===0,'syntax:'.$path);
        }
    }
    if (!is_file($release.'/config-before.json')) putFile($release.'/config-before.json',$raw,0600);
    if (!$enabled) verify(hash_file('sha256',$release.'/config-before.json')===hash('sha256',$raw),'config_changed');
    $phase='load_classes';
    require $release.'/stage/src/Store.php';
    require $release.'/stage/src/Dialogues.php';
    $phase='connect';
    putFile($release.'/progress.txt',$phase,0600);
    $db=new PDO($cfg['dsn'],$cfg['db_user']??null,$cfg['db_password']??null,[PDO::ATTR_TIMEOUT=>12]);
    if (!$local) {
        $db->exec('SET SESSION lock_wait_timeout=12');
        $db->exec('SET SESSION innodb_lock_wait_timeout=12');
    }
    $phase='initialize';
    putFile($release.'/progress.txt',$phase,0600);
    $store=new Chronicle\Store($db);
    // Add the three dialogue tables only. Existing posts, images and accounts are untouched.
    Chronicle\Dialogues::initialize($db);
    $tables=['chronicle_dialogues','chronicle_utterances','chronicle_utterance_versions'];
    foreach ($tables as $t) $db->query("SELECT 1 FROM $t LIMIT 0");
    // The outer transaction is always rolled back, including on a failed check.
    $phase='private_replay';
    putFile($release.'/progress.txt',$phase,0600);
    $probeSecret=bin2hex(random_bytes(32));
    $probe=new Chronicle\Dialogues($db,$probeSecret);
    $batch=['source'=>'synthetic','thread'=>'db-check','section'=>'one','title'=>'検証','messages'=>[
        ['id'=>'one','seq'=>0,'version'=>1,'role'=>'assistant','speaker'=>'試験','date'=>'2026-01-01T00:00:00Z','format'=>'text','text'=>"改行\nを保持"]]];
    $db->beginTransaction();
    try {
        $before=$probe->cards();
        $probe->receive($batch);
        $probe->receive($batch);
        $id=hash_hmac('sha256',Chronicle\Store::json(['synthetic','db-check','one']),$probeSecret);
        $detail=$probe->detail($id,false,0);
        verify($before===$probe->cards() && count($detail['messages'])===1 && $detail['messages'][0]['text']===$batch['messages'][0]['text'],'private_and_replay');
    } finally { $db->rollBack(); }
    $q=$db->prepare('SELECT COUNT(*) FROM chronicle_dialogues WHERE id=?');
    $q->execute([$id]);
    verify((int)$q->fetchColumn()===0,'probe_removed');
    $report['private_replay_rollback']=true;
    $phase='activate';
    if (!file_exists($ledger)) {
        $f=fopen($ledger,'xb'); verify($f!==false,'ledger_create'); fclose($f);
    }
    verify(!is_link($ledger) && is_file($ledger) && chmod($ledger,0600) && is_writable($ledger),'ledger');
    putFile($release.'/ready',"ready\n",0600);
    // Install dependencies first. The entry point and the feature flag are switched last.
    foreach ($items as $path=>$item) {
        $dest=$root.'/xserver/'.$path;
        if (!is_file($dest) || hash_file('sha256',$dest)!==$item['after']) {
            verify((is_file($dest)?hash_file('sha256',$dest):null)===$item['before'],'concurrent:'.$path);
            putFile($dest,file_get_contents($release.'/stage/'.$path),0644);
        }
        verify(hash_file('sha256',$dest)===$item['after'],'readback:'.$path);
    }
    if (!$enabled) {
        verify(file_get_contents($configFile)===$raw,'config_changed');
        $cfg['dialogue_deletion_ledger']=$ledger;
        $cfg['dialogues_enabled']=true;
        putFile($configFile,json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),0600);
    }
    $report+=['files'=>count($items),'tables'=>count($tables)];
    $report['success']=true;
} catch (Throwable $e) {
    $report['failure_class']=get_class($e);
    $report['phase']=$phase;
    // Whitelist our check names; never include DB connection data or driver error text.
    if (get_class($e)===RuntimeException) $report['check']=$e->getMessage();
    if ($e instanceof PDOException) {
        $report['sqlstate']=$e->getCode();
        $report['driver_code']=$e->errorInfo[1]??null;
        $report['phase']=$phase??'initialize';
    }
}
putFile($reportFile,json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),0600);
