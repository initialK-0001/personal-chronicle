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
        $nested=$this->db->inTransaction();
        if ($nested) $this->db->exec('SAVEPOINT chronicle_dialogue_op');
        else $this->db->beginTransaction();
        try {
            $result=$op();
            if ($nested) $this->db->exec('RELEASE SAVEPOINT chronicle_dialogue_op');
            else $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($nested) $this->db->exec('ROLLBACK TO SAVEPOINT chronicle_dialogue_op');
            else $this->db->rollBack();
            throw $e;
        }
    }

    public function captureAnchor(array $input): ?int {
        $thread=self::field($input,'thread',128);
        $ids=$input['ids']??null;
        if (!preg_match('/^dot-html-[a-f0-9]{64}$/D',$thread) || !is_array($ids) || !array_is_list($ids) || !$ids || count($ids)>250)
            throw new Failure('会話IDの照合条件を確認してください');
        $keys=[];
        foreach ($ids as $id) $keys[]=$this->key(['chatgpt-personal',$thread,self::field(['id'=>$id],'id',512)]);
        $marks=implode(',',array_fill(0,count($keys),'?'));
        $known=$this->query("SELECT id FROM chronicle_utterances WHERE dialogue_id IS NOT NULL AND id IN ($marks)",$keys)->fetchAll(PDO::FETCH_COLUMN);
        $matches=array_intersect($keys,$known);
        return $matches ? array_key_first($matches) : null;
    }

    public function receive(array $batch): array {
        $thread=self::field($batch,'thread',512);
        $section=self::field($batch,'section',512);
        $title=self::field($batch,'title',500);
        $source=self::field($batch,'source',160);
        $id=$this->key([$source,$thread,$section]);
        $messages=$batch['messages']??null;
        $anchor=$batch['captureAnchor']??null;
        $capture=$source==='chatgpt-personal' && preg_match('/^dot-html-[a-f0-9]{64}$/D',$thread);
        if ($anchor!==null && (!$capture || !is_array($anchor) || !is_int($anchor['seq']??null) || $anchor['seq']<0))
            throw new Failure('ZIPの照合条件を確認してください');
        if (array_diff(array_keys($batch),['thread','section','title','source','messages','captureAnchor']) ||
            !is_array($messages)||!array_is_list($messages)||!$messages||count($messages)>100)
            throw new Failure('受信形式を確認してください');
        return $this->transaction(function() use($source,$thread,$title,$id,$messages,$anchor,$capture) {
            $counts=['added'=>0,'updated'=>0,'existing'=>0,'deleted'=>0];
            $offset=0;
            $mysql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
            if ($anchor!==null) {
                $key=$this->key([$source,$thread,self::field($anchor,'id',512)]);
                $found=$this->query('SELECT dialogue_id,seq FROM chronicle_utterances WHERE id=?'.($mysql?' FOR UPDATE':''),[$key])->fetch();
                if (!$found || $found['dialogue_id']===null) throw new Failure('照合元が変わりました。もう一度取り込んでください',409);
                $id=$found['dialogue_id'];
                $offset=(int)$found['seq']-$anchor['seq'];
            }
            $changed=false;
            foreach ($messages as $m) {
                if (!is_array($m)||array_diff(array_keys($m),['id','seq','version','speaker','role','date','text','format','parent','captureInfo']) ||
                    !in_array($m['role']??null,['user','assistant'],true) ||
                    !is_int($m['seq']??null)||$m['seq']<0||!is_int($m['version']??null)||$m['version']<0)
                    throw new Failure('発言の形式を確認してください');
                $key=$this->key([$source,$thread,self::field($m,'id',512)]);
                $date=$m['date']??null;
                if ($date!==null && (!is_string($date)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$date) ||
                    strtotime($date)===false||gmdate('Y-m-d\TH:i:s\Z',strtotime($date))!==$date)) throw new Failure('元日時を確認してください');
                if (!in_array($m['format']??null,['text','markdown'],true)) throw new Failure('本文書式を確認してください');
                $parent=isset($m['parent'])?$this->key([$source,$thread,self::field($m,'parent',512)]):null;
                $data=['speaker'=>self::field($m,'speaker',300),'role'=>$m['role'],'date'=>$date,
                    'text'=>self::field($m,'text',800000),'format'=>$m['format'],'parent'=>$parent];
                if (isset($m['captureInfo'])) {
                    $meta=$m['captureInfo'];
                    if (!$capture || !is_array($meta) || array_diff(array_keys($meta),['元の日時','本文内リンク','本文外の添付表示']) ||
                        strlen(Store::json($meta))>120000) throw new Failure('保存画面の補足情報を確認してください');
                    if (isset($meta['元の日時'])) self::field($meta,'元の日時',64);
                    foreach (['本文内リンク'=>['表示','URL'],'本文外の添付表示'=>['種類','表示名','説明','URL','実体']] as $group=>$fields) {
                        if (!isset($meta[$group])) continue;
                        if (!is_array($meta[$group]) || !array_is_list($meta[$group])) throw new Failure('リンク・添付表示の形式を確認してください');
                        foreach ($meta[$group] as $item) {
                            if (!is_array($item) || array_diff(array_keys($item),$fields)) throw new Failure('リンク・添付表示の項目を確認してください');
                            foreach ($item as $value) if (!is_string($value)) throw new Failure('リンク・添付表示の値を確認してください');
                        }
                    }
                    $data['captureInfo']=$meta;
                }
                $body=Store::json($data);
                // Lock the section before reading its messages, including on replay.
                $seq=$m['seq']+$offset;
                $upsert=$mysql?'ON DUPLICATE KEY UPDATE id=id':'ON CONFLICT(id) DO NOTHING';
                $this->query("INSERT INTO chronicle_dialogues(id,title,revision) VALUES(?,?,0) ".$upsert,[$id,$title]);
                $old=$this->query('SELECT * FROM chronicle_utterances WHERE id=?'.($mysql?' FOR UPDATE':''),[$key])->fetch();
                if ($old && $old['dialogue_id']===null) { $counts['deleted']++; continue; } // Erased source message.
                if ($old && ($old['dialogue_id']!==$id || (int)$old['seq']!==$seq)) throw new Failure('取得元の順序または区間が競合しています',409);
                $version=$m['version'];
                $previous=$this->query('SELECT data_json FROM chronicle_utterance_versions WHERE utterance_id=? AND version_no=?',[$key,$version])->fetchColumn();
                if ($previous!==false) {
                    if ($previous === $body) { $counts['existing']++; continue; }
                    // A generic export label can later be identified by matching the actual speaker.
                    $stored=json_decode($previous,true,512,JSON_THROW_ON_ERROR);
                    $incoming=json_decode($body,true,512,JSON_THROW_ON_ERROR);
                    if ($capture) {
                        // The first visible message in a saved window can lack its original parent.
                        if ($incoming['parent']===null || $stored['parent']===null) $incoming['parent']=$stored['parent'];
                        if (isset($stored['captureInfo']) && !isset($incoming['captureInfo'])) $incoming['captureInfo']=$stored['captureInfo'];
                        $body=Store::json($incoming);
                        if ($stored===$incoming) { $counts['existing']++; continue; }
                        if (!isset($stored['captureInfo']) && isset($incoming['captureInfo'])) {
                            $enriched=$stored+['captureInfo'=>$incoming['captureInfo']];
                            if ($enriched===$incoming) {
                                $this->query('UPDATE chronicle_utterance_versions SET data_json=? WHERE utterance_id=? AND version_no=?',[$body,$key,$version]);
                                $changed=true;
                                $counts['updated']++;
                                continue;
                            }
                        }
                    }
                    $unknown='応答者（表示名不明）';
                    if ($m['role']==='assistant' && ($stored['speaker']===$unknown || $incoming['speaker']===$unknown)) {
                        $stored['speaker']=$incoming['speaker'];
                        if ($stored===$incoming) {
                            if ($incoming['speaker']!==$unknown && (string)$old['released_version']!==(string)$version) {
                                $this->query('UPDATE chronicle_utterance_versions SET data_json=? WHERE utterance_id=? AND version_no=?',[$body,$key,$version]);
                                $changed=true;
                                $counts['updated']++;
                            } else $counts['existing']++;
                            continue;
                        }
                    }
                    throw new Failure('同じ版に異なる原文があります',409);
                }
                if (!$old && $capture && $this->query('SELECT 1 FROM chronicle_utterances WHERE dialogue_id=? AND seq=?',[$id,$seq])->fetchColumn())
                    throw new Failure('同じ位置に別の発言があります。保存範囲を確認してください',409);
                if (!$old) $this->query('INSERT INTO chronicle_utterances(id,dialogue_id,seq,version_no) VALUES(?,?,?,?)',[$key,$id,$seq,$version]);
                elseif ($version>(int)$old['version_no']) $this->query('UPDATE chronicle_utterances SET version_no=? WHERE id=?',[$version,$key]);
                $this->query('INSERT INTO chronicle_utterance_versions VALUES(?,?,?)',[$key,$version,$body]);
                $counts[$old?'updated':'added']++;
                $changed=true;
            }
            if ($changed) {
                $first=$this->query('SELECT v.data_json FROM chronicle_utterances u JOIN chronicle_utterance_versions v
                    ON v.utterance_id=u.id AND v.version_no=u.version_no WHERE u.dialogue_id=? ORDER BY u.seq,u.id LIMIT 1',[$id])->fetchColumn();
                $this->query('UPDATE chronicle_dialogues SET revision=CASE WHEN revision=0 THEN ? ELSE revision+1 END,first_date=? WHERE id=?',[random_int(1,2**46),json_decode($first,true,512,JSON_THROW_ON_ERROR)['date'],$id]);
            }
            // Replayed tombstones must not recreate the erased conversation title.
            $this->query('DELETE FROM chronicle_dialogues WHERE id=? AND revision=0',[$id]);
            return $counts;
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
            if ($public) unset($data['captureInfo']);
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
