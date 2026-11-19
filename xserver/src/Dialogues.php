<?php
declare(strict_types=1);
namespace Chronicle;

use PDO;

final class Dialogues {
    public function __construct(private PDO $db, private string $secret, private ?string $ledger=null) {}

    public static function initialize(PDO $db): void {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $text = $mysql ? 'MEDIUMTEXT' : 'TEXT';
        $db->exec("CREATE TABLE IF NOT EXISTS chronicle_dialogues (
            id CHAR(64) PRIMARY KEY, title VARCHAR(500) NOT NULL, revision BIGINT NOT NULL,
            public_title VARCHAR(500), public_date VARCHAR(30), first_date VARCHAR(30)
        )".$tail);
        $db->exec("CREATE TABLE IF NOT EXISTS chronicle_utterances (
            id CHAR(64) PRIMARY KEY, dialogue_id CHAR(64), seq BIGINT, version_no BIGINT,
            released_version BIGINT, UNIQUE(dialogue_id,seq,id)
        )".$tail);
        $db->exec("CREATE TABLE IF NOT EXISTS chronicle_utterance_versions (
            utterance_id CHAR(64) NOT NULL, version_no BIGINT NOT NULL, data_json $text NOT NULL,
            PRIMARY KEY(utterance_id,version_no)
        )".$tail);
    }

    private function query(string $sql, array $params=[]): \PDOStatement {
        $q=$this->db->prepare($sql);
        $q->execute($params);
        return $q;
    }

    private function key(array $parts): string {
        return hash_hmac('sha256',Store::json($parts),$this->secret);
    }

    private static function field(array $a,string $key,int $bytes): string {
        $v=$a[$key]??null;
        if (!is_string($v)||$v===''||strlen($v)>$bytes) throw new Failure('入力項目を確認してください（'.$key.'）');
        return $v;
    }

    private function transaction(callable $op): mixed {
        $this->db->beginTransaction();
        try {
            $result=$op();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function receive(array $batch): void {
        $thread=self::field($batch,'thread',512);
        $section=self::field($batch,'section',512);
        $title=self::field($batch,'title',500);
        $source=self::field($batch,'source',160);
        $id=$this->key([$source,$thread,$section]);
        $messages=$batch['messages']??null;
        if (array_diff(array_keys($batch),['thread','section','title','source','messages']) ||
            !is_array($messages)||!array_is_list($messages)||!$messages||count($messages)>100)
            throw new Failure('受信形式を確認してください');
        $this->transaction(function() use($source,$thread,$title,$id,$messages) {
            $changed=false;
            foreach ($messages as $m) {
                if (!is_array($m)||array_diff(array_keys($m),['id','seq','version','speaker','role','date','text','format','parent']) ||
                    !in_array($m['role']??null,['user','assistant'],true) ||
                    !is_int($m['seq']??null)||$m['seq']<0||!is_int($m['version']??null)||$m['version']<0)
                    throw new Failure('発言の形式を確認してください');
                $key=$this->key([$source,$thread,self::field($m,'id',512)]);
                $date=$m['date']??null;
                if ($date!==null && (!is_string($date)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$date) ||
                    strtotime($date)===false||gmdate('Y-m-d\TH:i:s\Z',strtotime($date))!==$date)) throw new Failure('元日時を確認してください');
                if (!in_array($m['format']??null,['text','markdown'],true)) throw new Failure('本文書式を確認してください');
                $parent=isset($m['parent'])?$this->key([$source,$thread,self::field($m,'parent',512)]):null;
                $body=Store::json(['speaker'=>self::field($m,'speaker',300),'role'=>$m['role'],'date'=>$date,
                    'text'=>self::field($m,'text',800000),'format'=>$m['format'],'parent'=>$parent]);
                // Lock the section before reading its messages, including on replay.
                $mysql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
                $upsert=$mysql?'ON DUPLICATE KEY UPDATE id=id':'ON CONFLICT(id) DO NOTHING';
                $this->query("INSERT INTO chronicle_dialogues(id,title,revision) VALUES(?,?,0) ".$upsert,[$id,$title]);
                $old=$this->query('SELECT * FROM chronicle_utterances WHERE id=?'.($mysql?' FOR UPDATE':''),[$key])->fetch();
                if ($old && $old['dialogue_id']===null) continue; // Erased source message.
                if ($old && ($old['dialogue_id']!==$id || (int)$old['seq']!==$m['seq'])) throw new Failure('取得元の順序または区間が競合しています',409);
                $version=$m['version'];
                $previous=$this->query('SELECT data_json FROM chronicle_utterance_versions WHERE utterance_id=? AND version_no=?',[$key,$version])->fetchColumn();
                if ($previous!==false) {
                    if ($previous!==$body) throw new Failure('同じ版に異なる原文があります',409);
                    continue;
                }
                if (!$old) $this->query('INSERT INTO chronicle_utterances(id,dialogue_id,seq,version_no) VALUES(?,?,?,?)',[$key,$id,$m['seq'],$version]);
                elseif ($version>(int)$old['version_no']) $this->query('UPDATE chronicle_utterances SET version_no=? WHERE id=?',[$version,$key]);
                $this->query('INSERT INTO chronicle_utterance_versions VALUES(?,?,?)',[$key,$version,$body]);
                $changed=true;
            }
            if ($changed) {
                $first=$this->query('SELECT v.data_json FROM chronicle_utterances u JOIN chronicle_utterance_versions v
                    ON v.utterance_id=u.id AND v.version_no=u.version_no WHERE u.dialogue_id=? ORDER BY u.seq,u.id LIMIT 1',[$id])->fetchColumn();
                $this->query('UPDATE chronicle_dialogues SET revision=CASE WHEN revision=0 THEN ? ELSE revision+1 END,first_date=? WHERE id=?',[random_int(1,2**46),json_decode($first,true,512,JSON_THROW_ON_ERROR)['date'],$id]);
            }
            // Replayed tombstones must not recreate the erased conversation title.
            $this->query('DELETE FROM chronicle_dialogues WHERE id=? AND revision=0',[$id]);
        });
    }

    public function listing(int $page,string $month='',string $term='',string $state=''): array {
        $where=[]; $args=[];
        if ($month!=='') {
            if (!preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D',$month)) throw new Failure('対象年月を確認してください');
            $start=new \DateTimeImmutable($month.'-01',new \DateTimeZone('Asia/Tokyo'));
            $where[]='first_date>=? AND first_date<?';
            foreach ([$start,$start->modify('+1 month')] as $d) $args[]=gmdate('Y-m-d\TH:i:s\Z',$d->getTimestamp());
        }
        if ($term!=='') {
            if (strlen($term)>500) throw new Failure('検索語が長すぎます');
            $where[]="(d.title LIKE ? ESCAPE '!' OR EXISTS (SELECT 1 FROM chronicle_utterances u JOIN chronicle_utterance_versions v
                ON v.utterance_id=u.id AND v.version_no=u.version_no WHERE u.dialogue_id=d.id AND v.data_json LIKE ? ESCAPE '!'))";
            $pattern=static fn(string $s)=>'%'.strtr($s,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
            $args[]=$pattern($term);
            $args[]=$pattern(substr(Store::json($term),1,-1));
        }
        if ($state==='public') $where[]='public_title IS NOT NULL';
        elseif ($state==='private') $where[]='public_title IS NULL';
        elseif ($state!=='') throw new Failure('公開状態を確認してください');
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $offset=$page*50;
        return $this->query("SELECT * FROM chronicle_dialogues d $clause ORDER BY first_date DESC,id LIMIT 51 OFFSET $offset",$args)->fetchAll();
    }

    public function detail(string $id,bool $public,int $page): array {
        $section=$this->query('SELECT * FROM chronicle_dialogues WHERE id=?',[$id])->fetch();
        if (!$section||($public&&$section['public_title']===null)) throw new Failure('記録がありません',404);
        $column=$public?'released_version':'version_no';
        $offset=$page*50;
        $rows=$this->query("SELECT u.id,u.released_version,u.version_no,v.data_json FROM chronicle_utterances u
            JOIN chronicle_utterance_versions v ON v.utterance_id=u.id AND v.version_no=u.$column
            WHERE u.dialogue_id=? ORDER BY u.seq,u.id LIMIT 51 OFFSET $offset",[$id])->fetchAll();
        $more=count($rows)>50;
        if ($more) array_pop($rows);
        $messages=[];
        foreach ($rows as $r) {
            $data=json_decode($r['data_json'],true,512,JSON_THROW_ON_ERROR);
            if (!$public) $data+=['id'=>$r['id'],'released'=>$r['released_version']!==null,
                'currentPublic'=>$r['released_version']!==null && (int)$r['released_version']===(int)$r['version_no']];
            $messages[]=$data;
        }
        return ['title'=>$public?$section['public_title']:$section['title'],'public'=>$section['public_title']!==null,
            'publicTitle'=>$section['public_title'],'revision'=>(int)$section['revision'],'messages'=>$messages,'more'=>$more];
    }

    public function change(string $id,array $input): void {
        $action=$input['action']??'';
        if (!in_array($action,['release','unpublish','delete'],true)||!is_int($input['revision']??null)) throw new Failure('操作を確認してください');
        $this->transaction(function() use($id,$input,$action) {
            if (!$this->query('UPDATE chronicle_dialogues SET revision=revision+1 WHERE id=? AND revision=?',[$id,$input['revision']])->rowCount())
                throw new Failure('記録が更新されました。再度開いて確認してください',409);
            if ($action==='release') {
                $title=self::field($input,'title',500);
                $ids=$input['messages']??null;
                if (!is_array($ids)||!$ids||count($ids)>50) throw new Failure('公開する発言を選んでください');
                foreach ($ids as $key) {
                    if (!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key) ||
                        !$this->query('SELECT 1 FROM chronicle_utterances WHERE id=? AND dialogue_id=?',[$key,$id])->fetchColumn())
                        throw new Failure('対象外の発言です');
                    $this->query('UPDATE chronicle_utterances SET released_version=version_no WHERE id=?',[$key]);
                }
                $first=$this->query('SELECT data_json FROM chronicle_utterances u JOIN chronicle_utterance_versions v
                    ON u.id=v.utterance_id AND u.released_version=v.version_no WHERE u.dialogue_id=? ORDER BY u.seq,u.id LIMIT 1',[$id])->fetchColumn();
                $date=json_decode($first,true,512,JSON_THROW_ON_ERROR)['date']??($input['date']??$this->query('SELECT public_date FROM chronicle_dialogues WHERE id=?',[$id])->fetchColumn());
                if (!$date || !is_string($date) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$date) || strtotime($date)===false || gmdate('Y-m-d\TH:i:s\Z',strtotime($date))!==$date)
                    throw new Failure('元日時不明のため掲載日を指定してください');
                $this->query('UPDATE chronicle_dialogues SET public_title=?,public_date=? WHERE id=?',[$title,$date,$id]);
            } elseif ($action==='unpublish') {
                $this->query('UPDATE chronicle_utterances SET released_version=NULL WHERE dialogue_id=?',[$id]);
                $this->query('UPDATE chronicle_dialogues SET public_title=NULL,public_date=NULL WHERE id=?',[$id]);
            } else {
                $keys=$this->query('SELECT id FROM chronicle_utterances WHERE dialogue_id=?',[$id])->fetchAll(PDO::FETCH_COLUMN);
                self::journal($this->ledger,true,static function($file) use($keys) {
                    $data=implode("\n",$keys)."\n";
                    if (fwrite($file,$data)!==strlen($data)||!fflush($file)||!fsync($file)) throw new Failure('削除台帳を保存できません',503);
                });
                $this->query('DELETE FROM chronicle_utterance_versions WHERE utterance_id IN (SELECT id FROM chronicle_utterances WHERE dialogue_id=?)',[$id]);
                $this->query('UPDATE chronicle_utterances SET dialogue_id=NULL,seq=NULL,version_no=NULL,released_version=NULL WHERE dialogue_id=?',[$id]);
                $this->query('DELETE FROM chronicle_dialogues WHERE id=?',[$id]);
            }
        });
    }

    // The ledger is provisioned once outside the backup set. Refuse missing or public paths.
    private static function journal(?string $path,bool $append,callable $operation): void {
        $root=realpath(($_SERVER['DOCUMENT_ROOT']??'')?:__DIR__.'/../public');
        $real=$path?realpath($path):false;
        if (!$real||!is_file($real)||is_link($path)||($root&&str_starts_with($real,$root.'/')))
            throw new Failure('公開領域外の削除台帳を確認してください',503);
        $file=fopen($real,$append?'ab':'rb');
        if (!$file) throw new Failure('削除台帳を開けません',503);
        try {
            if (!flock($file,$append?LOCK_EX:LOCK_SH)) throw new Failure('削除台帳をロックできません',503);
            $operation($file);
        } finally {fclose($file);}
    }

    public static function replayDeletions(PDO $db,?string $path): void {
        self::journal($path,false,static function($file) use($db) {
            $version=$db->prepare('DELETE FROM chronicle_utterance_versions WHERE utterance_id=?');
            $erase=$db->prepare('UPDATE chronicle_utterances SET dialogue_id=NULL,seq=NULL,version_no=NULL,released_version=NULL WHERE id=?');
            $insert=$db->prepare(($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE').' INTO chronicle_utterances(id) VALUES(?)');
            while (($line=fgets($file,67))!==false) {
                if (!preg_match('/^[a-f0-9]{64}\n$/D',$line)) throw new Failure('削除台帳を照合できません',503);
                $key=trim($line);
                $version->execute([$key]); $erase->execute([$key]); $insert->execute([$key]);
            }
            if (!feof($file)) throw new Failure('削除台帳を読み切れません',503);
            $db->exec('DELETE FROM chronicle_dialogues WHERE NOT EXISTS (SELECT 1 FROM chronicle_utterances WHERE dialogue_id=chronicle_dialogues.id)');
            $db->exec('UPDATE chronicle_dialogues SET public_title=NULL,public_date=NULL WHERE NOT EXISTS (SELECT 1 FROM chronicle_utterances WHERE dialogue_id=chronicle_dialogues.id AND released_version IS NOT NULL)');
        });
    }

    public function cards(): array {
        $cards=[];
        $rows=$this->query('SELECT d.id,d.public_title,d.public_date,v.data_json FROM chronicle_dialogues d
            JOIN chronicle_utterances u ON u.id=(SELECT a.id FROM chronicle_utterances a WHERE a.dialogue_id=d.id AND a.released_version IS NOT NULL ORDER BY a.seq,a.id LIMIT 1)
            JOIN chronicle_utterance_versions v ON v.utterance_id=u.id AND v.version_no=u.released_version WHERE d.public_title IS NOT NULL');
        foreach ($rows as $r) {
            $text=json_decode($r['data_json'],true,512,JSON_THROW_ON_ERROR)['text'];
            $cards[]=['id'=>'dialogue-'.$r['id'],'url'=>'/dialogues/'.$r['id'],'date'=>$r['public_date'],'title'=>$r['public_title'],
                'tags'=>['対話記録'],'blocks'=>[['type'=>'paragraph','text'=>mb_substr($text,0,401)]]];
        }
        return $cards;
    }
}
