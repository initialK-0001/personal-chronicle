<?php
declare(strict_types=1);
namespace Chronicle;
use RuntimeException;
final class Backup {
    private const TABLES=[
        'chronicle_posts'=>['id','revision','draft_json','snapshot_json','visibility','created_at','published_at','updated_at'],
        'chronicle_history'=>['id','post_id','revision','action','snapshot_json','saved_at'],
        'chronicle_imports'=>['legacy_id','legacy_path','original_status','original_json','sha256'],
        'chronicle_downloads'=>['legacy_id','data_json'],
        'chronicle_assets'=>['hash','mime','bytes'],
        'chronicle_comments'=>['id','post_key','name','body','status','reply','revision','created_at'],
        'chronicle_legacy_edits'=>['legacy_id','revision','draft_json','snapshot_json','visibility','published_at','updated_at'],
        'chronicle_legacy_history'=>['legacy_id','revision','action','state_json','saved_at'],
        'chronicle_contact'=>['id','name','email','subject','body','status','revision','created_at'],
        'chronicle_works'=>['id','parent_id','revision','draft_json','snapshot_json','visibility','created_at','updated_at'],
        'chronicle_dialogues'=>['id','title','revision','public_title','public_date','first_date'],
        'chronicle_utterances'=>['id','dialogue_id','seq','version_no','released_version'],
        'chronicle_utterance_versions'=>['utterance_id','version_no','data_json'],
    ];
    private static function privatePath(string $path):void {
        $parent=realpath(dirname($path));$root=realpath(($_SERVER['DOCUMENT_ROOT']??'')?:__DIR__.'/../public');
        if(!$parent||is_link($path)||($root&&($parent===$root||str_starts_with($parent,$root.'/'))))throw new RuntimeException('公開領域外の保存先を指定してください');
    }
    private static function copy(string $source,string $dest):void {
        if(!is_file($source)||is_link($source))throw new RuntimeException('バックアップ対象のファイルを確認してください');
        if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0700,true))throw new RuntimeException('保存先を作成できません');
        if(file_exists($dest)||!copy($source,$dest))throw new RuntimeException('ファイルを保存できません');chmod($dest,0600);
    }
    private static function legacyFiles(string $root):array {
        $result=[];$root=realpath($root);if(!$root||!is_dir($root))throw new RuntimeException('旧画像の保存先を確認してください');
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){if($file->isLink())throw new RuntimeException('シンボリックリンクは保存できません');if($file->isFile())$result[substr($file->getPathname(),strlen($root)+1)]=$file->getPathname();}ksort($result);return $result;
    }
    public static function export(Store $store,array $config,string $destination):array {
        self::privatePath($destination);foreach(['images','legacy_uploads'] as $key){$input=realpath($config[$key]??'');$parent=realpath(dirname($destination));if($input&&($parent===$input||str_starts_with($parent,$input.'/')))throw new RuntimeException('バックアップは画像の保存先と分けてください');}if(file_exists($destination))throw new RuntimeException('保存先が存在するため上書きしません');
        $stage=$destination.'.partial-'.bin2hex(random_bytes(6));if(!mkdir($stage,0700))throw new RuntimeException('保存先を作成できません');
        $db=$store->db;if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql')$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();
        try{
            $tables=[];foreach(self::TABLES as $table=>$cols)$tables[$table]=$db->query('SELECT '.implode(',',$cols).' FROM '.$table.' ORDER BY '.$cols[0])->fetchAll();
            $payload=Store::json(['format'=>5,'tables'=>$tables]);if(file_put_contents($stage.'/database.json',$payload)===false)throw new RuntimeException('DBを書き出せません');chmod($stage.'/database.json',0600);
            $files=['database.json'=>hash('sha256',$payload)];
            foreach($tables['chronicle_assets'] as $asset){$hash=$asset['hash'];if(!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new RuntimeException('画像IDが不正です');$src=$config['images'].'/'.$hash;if(!is_file($src)||!hash_equals($hash,hash_file('sha256',$src)))throw new RuntimeException('画像が欠落または変化しています');self::copy($src,$stage.'/images/'.$hash);$files['images/'.$hash]=$hash;}
            // Include all legacy uploads, not only currently public references.
            if($tables['chronicle_imports']||!empty($config['legacy_uploads']))foreach(self::legacyFiles($config['legacy_uploads']??'') as $relative=>$src){self::copy($src,$stage.'/legacy/'.$relative);$files['legacy/'.$relative]=hash_file('sha256',$stage.'/legacy/'.$relative);}
            $manifest=['format'=>5,'created_at'=>gmdate('c'),'files'=>$files,'rows'=>array_map('count',$tables)];if(file_put_contents($stage.'/manifest.json',Store::json($manifest))===false)throw new RuntimeException('台帳を保存できません');chmod($stage.'/manifest.json',0600);
            $db->commit();if(!rename($stage,$destination))throw new RuntimeException('バックアップを確定できません');return $manifest['rows'];
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;} // Incomplete .partial directory retained for diagnosis, never used as a finished backup.
    }
    private static function verify(string $source):array {
        $root=realpath($source);if(!$root||is_link($source)||!is_file($root.'/manifest.json'))throw new RuntimeException('バックアップがありません');
        $manifest=json_decode(file_get_contents($root.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        if(!in_array($manifest['format']??null,[1,2,3,4,5],true)||!is_array($manifest['files']??null)||!isset($manifest['files']['database.json']))throw new RuntimeException('バックアップ形式が不正です');
        foreach($manifest['files'] as $name=>$sha){
            if(!is_string($name)||!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha)||str_contains($name,'\\')||str_contains($name,"\0")||in_array('..',explode('/',$name),true)||in_array('.',explode('/',$name),true)||($name!=='database.json'&&!preg_match('~^(images/[a-f0-9]{64}|legacy/[^/].*)$~D',$name)))throw new RuntimeException('ファイル台帳が不正です');
            $file=realpath($root.'/'.$name);if(!$file||!str_starts_with($file,$root.'/')||is_link($root.'/'.$name)||!is_file($file)||!hash_equals($sha,hash_file('sha256',$file)))throw new RuntimeException('バックアップが欠落または変化しています');
        }
        $data=json_decode(file_get_contents($root.'/database.json'),true,512,JSON_THROW_ON_ERROR);
        if(($data['format']??null)!==$manifest['format'])throw new RuntimeException('DB形式が不正です');
        if($data['format']<5){$n=[1=>6,2=>8,3=>9,4=>10][$data['format']];$old=array_slice(array_keys(self::TABLES),0,$n);if(array_keys($data['tables']??[])!==$old)throw new RuntimeException('DB形式が不正です');foreach(array_slice(array_keys(self::TABLES),$n) as $table)$data['tables'][$table]=[];}
        if(array_keys($data['tables']??[])!==array_keys(self::TABLES))throw new RuntimeException('DB形式が不正です');
        foreach(self::TABLES as $table=>$cols){if(!is_array($data['tables'][$table]))throw new RuntimeException('DB形式が不正です');foreach($data['tables'][$table] as $row){if(!is_array($row)||array_keys($row)!==$cols)throw new RuntimeException('DB列が一致しません');foreach($row as $value)if($value!==null&&!is_scalar($value))throw new RuntimeException('DB値が不正です');}}
        foreach($data['tables']['chronicle_assets'] as $a)if(($manifest['files']['images/'.$a['hash']]??null)!==$a['hash'])throw new RuntimeException('画像台帳が一致しません');
        return [$root,$manifest,$data['tables']];
    }
    public static function restore(Store $store,array $config,string $source):array {
        [$root,$manifest,$tables]=self::verify($source);
        // Targets must already be provisioned, private, empty, and offline.
        $destinations=['images'=>$config['images']??''];if(array_filter(array_keys($manifest['files']),fn($p)=>str_starts_with($p,'legacy/')))$destinations['legacy']=$config['legacy_uploads']??'';
        foreach($destinations as $dir){if(!$dir||!is_dir($dir)||is_link($dir)||count(scandir($dir))!==2)throw new RuntimeException('復元先は空のディレクトリにしてください');self::privatePath($dir.'/probe');}
        $roots=array_map('realpath',$destinations);foreach($roots as $a){if($a===$root||str_starts_with($a,$root.'/')||str_starts_with($root,$a.'/'))throw new RuntimeException('復元先とバックアップを分けてください');}if(count($roots)===2){[$a,$b]=array_values($roots);if($a===$b||str_starts_with($a,$b.'/')||str_starts_with($b,$a.'/'))throw new RuntimeException('画像の復元先を分けてください');}
        $db=$store->db;$db->beginTransaction();
        try{
            foreach(array_merge(array_keys(self::TABLES),['chronicle_login_limits','chronicle_comment_limits']) as $table)if((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('復元先DBが空ではないため中止しました');
            foreach(self::TABLES as $table=>$cols){$q=$db->prepare('INSERT INTO '.$table.'('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')');foreach($tables[$table] as $row)$q->execute(array_values($row));}
            if (($config['dialogues_enabled']??false)||isset($config['dialogue_deletion_ledger'])||$tables['chronicle_utterances'])
                Dialogues::replayDeletions($db,$config['dialogue_deletion_ledger']??null);
            foreach($manifest['files'] as $name=>$sha){if($name==='database.json')continue;[$kind,$relative]=explode('/',$name,2);$dest=$destinations[$kind].'/'.$relative;self::copy($root.'/'.$name,$dest);if(!hash_equals($sha,hash_file('sha256',$dest)))throw new RuntimeException('復元画像の照合に失敗しました');}
            $db->commit();return array_map('count',$tables);
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;} // Never delete files or overwrite targets after a failure. Retry with a new empty target.
    }
}
