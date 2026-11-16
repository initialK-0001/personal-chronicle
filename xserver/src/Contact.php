<?php
declare(strict_types=1);
namespace Chronicle;
final class Contact {
    public function __construct(private Store $store,private string $secret,private bool $enabled){}
    public function submit(array $input,string $ip):void {
        if(!$this->enabled)throw new Failure('お問い合わせは受け付けていません',404);
        $p=[];foreach(['name'=>300,'email'=>254,'subject'=>1000,'body'=>12000] as $key=>$max){$v=$input[$key]??'';if(!is_string($v)||strlen($v)>$max||!preg_match('//u',$v)||str_contains($v,"\0"))throw new Failure('入力内容を確認してください');$p[$key]=trim($v);}
        if($p['body']==='')throw new Failure('本文を入力してください');
        if($p['email']!==''&&!filter_var($p['email'],FILTER_VALIDATE_EMAIL))throw new Failure('メールアドレスを確認してください');
        if(($input['website']??'')!=='')return;
        $db=$this->store->db;$db->beginTransaction();
        try{
            $bucket=hash_hmac('sha256','contact:'.$ip.':'.intdiv(time(),3600),$this->secret);
            $insert=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
            $q=$db->prepare($insert.' INTO chronicle_comment_limits(bucket,attempts,expires) VALUES(?,0,?)');$q->execute([$bucket,time()+7200]);
            $q=$db->prepare('UPDATE chronicle_comment_limits SET attempts=attempts+1 WHERE bucket=? AND attempts<3');$q->execute([$bucket]);if($q->rowCount()!==1)throw new Failure('しばらく待ってから送信してください',429);
            $q=$db->prepare('DELETE FROM chronicle_comment_limits WHERE expires<?');$q->execute([time()]);
            $q=$db->prepare("INSERT INTO chronicle_contact(name,email,subject,body,status,revision,created_at) VALUES(?,?,?,?,'unread',1,?)");$q->execute([$p['name'],$p['email'],$p['subject'],$p['body'],gmdate('Y-m-d\TH:i:s\Z')]);$db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public function listing():array{return $this->store->db->query('SELECT id,name,email,subject,body,status,revision,created_at FROM chronicle_contact ORDER BY id DESC')->fetchAll();}
    public function change(int $id,int $revision,string $action):void {
        if(!in_array($action,['read','unread','archived'],true))throw new Failure('操作を確認してください');
        $q=$this->store->db->prepare('UPDATE chronicle_contact SET status=?,revision=revision+1 WHERE id=? AND revision=?');$q->execute([$action,$id,$revision]);if($q->rowCount()!==1)throw new Failure('内容が変更されています 読み直してください',409);
    }
}
