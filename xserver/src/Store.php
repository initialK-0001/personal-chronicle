<?php
declare(strict_types=1);
namespace Chronicle;
use PDO;
use RuntimeException;
final class Failure extends RuntimeException {
    public int $status;
    public function __construct(string $message, int $status=400) {parent::__construct($message);$this->status=$status;}
}
final class Store {
    public PDO $db;
    public function __construct(PDO $db) {$this->db=$db;$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);}
    public function initialize(): void {
        $mysql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id=$mysql?'BIGINT PRIMARY KEY AUTO_INCREMENT':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $text=$mysql?'MEDIUMTEXT':'TEXT';
        $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':'';
        $this->db->exec("CREATE TABLE IF NOT EXISTS chronicle_comments (id $id, post_key VARCHAR(80) NOT NULL, name VARCHAR(300) NOT NULL, body TEXT NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'pending', reply TEXT NULL, revision INTEGER NOT NULL DEFAULT 1, created_at VARCHAR(30) NOT NULL)".$tail);
        $this->db->exec("CREATE TABLE IF NOT EXISTS chronicle_comment_limits (bucket VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, expires BIGINT NOT NULL)".$tail);
        $this->db->exec("CREATE TABLE IF NOT EXISTS chronicle_downloads (legacy_id BIGINT PRIMARY KEY, data_json TEXT NOT NULL)".$tail);
        $this->db->exec("CREATE TABLE IF NOT EXISTS chronicle_assets (hash VARCHAR(64) PRIMARY KEY,mime VARCHAR(40) NOT NULL,bytes BIGINT NOT NULL)".$tail);
        $this->db->exec("CREATE TABLE IF NOT EXISTS chronicle_posts (id $id, revision INTEGER NOT NULL DEFAULT 1, draft_json $text NOT NULL, snapshot_json $text NULL, visibility VARCHAR(10) NOT NULL DEFAULT 'private', created_at VARCHAR(30) NOT NULL, published_at VARCHAR(30) NULL, updated_at VARCHAR(30) NULL)".$tail);
        $this->db->exec("CREATE TABLE IF NOT EXISTS chronicle_history (id $id, post_id BIGINT NOT NULL, revision INTEGER NOT NULL, action VARCHAR(20) NOT NULL, snapshot_json $text NOT NULL, saved_at VARCHAR(30) NOT NULL)".$tail);
        $this->db->exec("CREATE TABLE IF NOT EXISTS chronicle_imports (legacy_id BIGINT PRIMARY KEY, legacy_path VARCHAR(1000) NOT NULL, original_status VARCHAR(30) NOT NULL, original_json ".($mysql?'LONGTEXT':'TEXT')." NOT NULL, sha256 VARCHAR(64) NOT NULL)".$tail);
        $this->db->exec("CREATE TABLE IF NOT EXISTS chronicle_login_limits (bucket VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, expires BIGINT NOT NULL)".$tail);
    }
    public static function content(array $p): array {
        $s=function($v,int $max):string {if(!is_string($v)||strlen($v)>$max)throw new Failure('入力内容を確認してください');return $v;};
        if(!isset($p['blocks'],$p['tags'])||!is_array($p['blocks'])||!is_array($p['tags'])||count($p['blocks'])>300||count($p['tags'])>30)throw new Failure('投稿の形式を確認してください');
        $out=['title'=>$s($p['title']??'',2000),'tags'=>array_map(fn($v)=>$s($v,320),array_values($p['tags'])),'blocks'=>[]];
        foreach($p['blocks'] as $b){
            if(!is_array($b)||!in_array($b['type']??'', ['paragraph','heading','quote','link','image'],true))throw new Failure('この保存基盤ではまだ対応していない書式です');
            if($b['type']==='image'){if(!preg_match('~^/api/images/[a-f0-9]{64}$~D',$b['src']??''))throw new Failure('画像を先に保存してください');$out['blocks'][]=['id'=>$s($b['id']??'',80),'type'=>'image','src'=>$b['src'],'alt'=>$s($b['alt']??'',2000),'bytes'=>max(0,(int)($b['bytes']??0))];continue;}
            $x=['type'=>$b['type'],'text'=>$s($b['text']??'',200000)];
            if(isset($b['id']))$x['id']=$s($b['id'],80);
            if($b['type']==='link'){$url=$s($b['url']??'',4000);if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true))throw new Failure('リンクが不正です');$x['url']=$url;}
            $out['blocks'][]=$x;
        }
        if(strlen(self::json($out))>900000)throw new Failure('投稿が大きすぎます',413);
        return $out;
    }
    public static function json($value):string{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
    public function one(int $id):array {$q=$this->db->prepare('SELECT * FROM chronicle_posts WHERE id=?');$q->execute([$id]);$r=$q->fetch();if(!$r)throw new Failure('投稿がありません',404);return $r;}
    public function create(array $content):array {
        $p=self::content($content);$this->validateImages($p);$now=gmdate('Y-m-d\TH:i:s\Z');
        $q=$this->db->prepare('INSERT INTO chronicle_posts(draft_json,created_at) VALUES(?,?)');$q->execute([self::json($p),$now]);return $this->one((int)$this->db->lastInsertId());
    }
    public function change(int $id,int $expected,string $action,?array $content=null):array {
        if(!in_array($action,['draft','release','unpublish'],true))throw new Failure('未対応の操作です');
        $p=$content===null?null:self::content($content);if($p!==null)$this->validateImages($p);
        $this->db->beginTransaction();
        try {
            // This conditional write locks and increments the version before reading it.
            $q=$this->db->prepare('UPDATE chronicle_posts SET revision=revision+1 WHERE id=? AND revision=?');$q->execute([$id,$expected]);
            if($q->rowCount()!==1)throw new Failure('別の端末で変更されています 入力を残して読み直してください',409);
            $r=$this->one($id);$now=gmdate('Y-m-d\TH:i:s\Z');
            if($action==='draft'){
                if($p===null)throw new Failure('本文を指定してください');
                $q=$this->db->prepare('UPDATE chronicle_posts SET draft_json=? WHERE id=?');$q->execute([self::json($p),$id]);
            }elseif($action==='release'){
                $p=$p??json_decode($r['draft_json'],true,512,JSON_THROW_ON_ERROR);
                if(!array_filter($p['blocks'],fn($b)=>$b['type']==='image'||trim($b['text']??'')!==''))throw new Failure('本文を追加してください');
                $q=$this->db->prepare("UPDATE chronicle_posts SET draft_json=?,snapshot_json=?,visibility='public',published_at=?,updated_at=? WHERE id=?");$q->execute([self::json($p),self::json($p),$r['published_at']??$now,$now,$id]);
            }else{$q=$this->db->prepare("UPDATE chronicle_posts SET visibility='private' WHERE id=?");$q->execute([$id]);}
            $next=$this->one($id);$q=$this->db->prepare('INSERT INTO chronicle_history(post_id,revision,action,snapshot_json,saved_at) VALUES(?,?,?,?,?)');$q->execute([$id,$next['revision'],$action,self::json($next),$now]);
            $this->db->commit();return $next;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function publicPosts():array {
        $rows=$this->db->query("SELECT id,snapshot_json,published_at,updated_at FROM chronicle_posts WHERE visibility='public' ORDER BY published_at DESC,id DESC")->fetchAll();
        return array_map(function($r){$p=json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR);return ['id'=>(string)$r['id'],'title'=>$p['title'],'tags'=>$p['tags'],'blocks'=>array_map(function($b){unset($b['id']);if($b['type']==='image')$b['src']=str_replace('/api/images/','/api/public/images/',$b['src']);return $b;},$p['blocks']),'date'=>$r['published_at'],'updatedAt'=>$r['updated_at']];},$rows);
    }
    private function validateImages(array $p):void{foreach($p['blocks'] as $b)if($b['type']==='image'){$q=$this->db->prepare('SELECT hash FROM chronicle_assets WHERE hash=?');$q->execute([basename($b['src'])]);if(!$q->fetchColumn())throw new Failure('画像を確認できません');}}
    public function state(?int $draftId=null):array {
        $posts=[];$versions=[];$draft=null;
        foreach($this->db->query('SELECT * FROM chronicle_posts ORDER BY id DESC')->fetchAll() as $r){
            $p=json_decode($r['draft_json'],true,512,JSON_THROW_ON_ERROR);$p+=['id'=>(string)$r['id'],'date'=>$r['published_at'],'updatedAt'=>$r['updated_at'],'visibility'=>$r['visibility']];$posts[]=$p;$versions[(string)$r['id']]=(int)$r['revision'];if((int)$r['id']===$draftId)$draft=$p;
        }
        return ['state'=>['posts'=>$posts,'draft'=>$draft],'etag'=>(object)$versions];
    }
    public function stageImport(array $records):array {
        $this->db->beginTransaction();$added=0;$same=0;
        try{foreach($records as $r){
            if(!is_array($r)||!is_int($r['legacy_id']??null)||!is_string($r['path']??null)||!is_string($r['status']??null)||!is_string($r['original_html']??null))throw new Failure('移行データの形式が不正です');
            $data=self::json($r);$sha=hash('sha256',$data);$q=$this->db->prepare('SELECT sha256 FROM chronicle_imports WHERE legacy_id=?');$q->execute([$r['legacy_id']]);$old=$q->fetchColumn();
            if($old!==false){if(!hash_equals($old,$sha))throw new Failure('元データが変化しています 差分の確認が必要です',409);$same++;continue;}
            $q=$this->db->prepare('INSERT INTO chronicle_imports VALUES(?,?,?,?,?)');$q->execute([$r['legacy_id'],$r['path'],$r['status'],$data,$sha]);$added++;
        }$this->db->commit();return ['added'=>$added,'unchanged'=>$same,'published'=>0];}catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
}
