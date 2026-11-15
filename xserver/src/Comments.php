<?php
declare(strict_types=1);
namespace Chronicle;
final class Comments {
    public function __construct(private Store $store,private Legacy $legacy,private string $secret){}
    public function visiblePost(string $key):bool {
        if(preg_match('/^[1-9][0-9]*$/D',$key)){$q=$this->store->db->prepare("SELECT id FROM chronicle_posts WHERE id=? AND visibility='public'");$q->execute([$key]);return $q->fetchColumn()!==false;}
        if(preg_match('/^legacy-([1-9][0-9]*)$/D',$key,$m))foreach($this->legacy->records() as $r)if((string)$r['legacy_id']===$m[1])return true;
        return false;
    }
    private function text(mixed $value,int $max,bool $required=false):string {
        if(!is_string($value)||strlen($value)>$max||!preg_match('//u',$value)||str_contains($value,"\0"))throw new Failure('入力内容を確認してください');
        $value=trim($value);if($required&&$value==='')throw new Failure('コメントを入力してください');return $value;
    }
    public function submit(array $input,string $ip):void {
        $post=$this->text($input['post']??'',80,true);
        if(!$this->visiblePost($post))throw new Failure('記事がありません',404);
        $name=$this->text($input['name']??'',300);$body=$this->text($input['body']??'',12000,true);
        if(($input['website']??'')!=='')return; // Honeypot never persists submitted data.
        $db=$this->store->db;$db->beginTransaction();
        try{
            $bucket=hash_hmac('sha256','comments:'.$ip.':'.intdiv(time(),900),$this->secret);
            $insert=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
            $q=$db->prepare($insert.' INTO chronicle_comment_limits(bucket,attempts,expires) VALUES(?,0,?)');$q->execute([$bucket,time()+1800]);
            $q=$db->prepare('UPDATE chronicle_comment_limits SET attempts=attempts+1 WHERE bucket=? AND attempts<5');$q->execute([$bucket]);if($q->rowCount()!==1)throw new Failure('しばらく待ってから送信してください',429);
            $q=$db->prepare('DELETE FROM chronicle_comment_limits WHERE expires<?');$q->execute([time()]);
            $q=$db->prepare("INSERT INTO chronicle_comments(post_key,name,body,status,created_at) VALUES(?,?,?,'pending',?)");$q->execute([$post,$name,$body,gmdate('Y-m-d\TH:i:s\Z')]);$db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public function published(string $post):array {
        if(!$this->visiblePost($post))throw new Failure('記事がありません',404);
        $q=$this->store->db->prepare("SELECT name,body,reply,created_at FROM chronicle_comments WHERE post_key=? AND status='approved' ORDER BY id");$q->execute([$post]);return $q->fetchAll();
    }
    public function moderation():array {
        return $this->store->db->query("SELECT id,post_key,name,body,status,reply,revision,created_at FROM chronicle_comments WHERE status!='deleted' ORDER BY id DESC")->fetchAll();
    }
    public function change(int $id,int $revision,string $action,mixed $reply=null):void {
        if(!in_array($action,['approve','delete','reply'],true))throw new Failure('未対応の操作です');
        $text=$action==='reply'?$this->text($reply,12000,true):'';$db=$this->store->db;$db->beginTransaction();
        try{
            $q=$db->prepare("UPDATE chronicle_comments SET revision=revision+1 WHERE id=? AND revision=? AND status!='deleted'");$q->execute([$id,$revision]);if($q->rowCount()!==1)throw new Failure('内容が変更されています 読み直してください',409);
            $q=$db->prepare('SELECT status FROM chronicle_comments WHERE id=?');$q->execute([$id]);$status=$q->fetchColumn();
            if($action==='reply'&&$status!=='approved')throw new Failure('承認済みのコメントに返信してください',409);
            if($action==='reply'){$q=$db->prepare('UPDATE chronicle_comments SET reply=? WHERE id=?');$q->execute([$text,$id]);}
            else{$q=$db->prepare('UPDATE chronicle_comments SET status=? WHERE id=?');$q->execute([$action==='approve'?'approved':'deleted',$id]);}
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
}
