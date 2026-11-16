<?php
declare(strict_types=1);
namespace Chronicle;
/** Read literal mysqldump data only. Never execute source SQL or load WordPress. */
final class WordPressExport {
 private const SAFE=['home','siteurl','permalink_structure','timezone_string','gmt_offset','uploads_use_yearmonth_folders'];
 public static function rows(string $s):\Generator {
  $i=0;$n=strlen($s);$esc=['0'=>"\0",'n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>"\x08",'Z'=>"\x1a"];
  while($i<$n){if($s[$i++]!=='(')throw new \RuntimeException('VALUES start');$row=[];
   while(true){if(($s[$i]??'')==="'"){$i++;$v='';$closed=false;
     while($i<$n){$c=$s[$i++];if($c==='\\'){if($i>=$n)throw new \RuntimeException('escape');$c=$s[$i++];$v.=$esc[$c]??$c;}elseif($c==="'"){if(($s[$i]??'')==="'"){$v.="'";$i++;}else{$closed=true;break;}}else{$v.=$c;}}
     if(!$closed||!mb_check_encoding($v,'UTF-8'))throw new \RuntimeException('string');
    }else{$end=$i+strcspn($s,',)',$i);$t=substr($s,$i,$end-$i);$i=$end;
     if($t==='NULL')$v=null;elseif(preg_match('/^-?\d+$/D',$t)){if(filter_var($t,FILTER_VALIDATE_INT)!==false)$v=(int)$t;else $v=$t;}elseif(preg_match('/^-?\d+\.\d+(?:[eE][+-]?\d+)?$/D',$t))$v=$t;else throw new \RuntimeException('literal');}
    $row[]=$v;$c=$s[$i++]??'';if($c===')')break;if($c!==',')throw new \RuntimeException('delimiter');
   }yield $row;if($i<$n&&($s[$i++]!==','||$i===$n))throw new \RuntimeException('row delimiter');
  }
 }
 public static function read(string $source,string $prefix,string $expectedSha):array {
  if(!preg_match('/^[A-Za-z0-9_]+$/D',$prefix)||!hash_equals($expectedSha,hash_file('sha256',$source)))throw new \RuntimeException('source hash');
  $allow=array_fill_keys(array_map(fn($s)=>$prefix.$s,['posts','postmeta','terms','term_taxonomy','term_relationships','comments','options']),true);
  $f=gzopen($source,'rb');if(!$f)throw new \RuntimeException('source');$schemas=[];$data=[];$current=null;
  try{while(!gzeof($f)){$line=gzgets($f);if($line===false)break;
   if(preg_match('/^CREATE TABLE `([^`]+)` \(/',$line,$m)){$current=isset($allow[$m[1]])?$m[1]:null;if($current!==null){$schemas[$current]=[];$data[$current]=[];}continue;}
   if($current!==null){if(preg_match('/^  `([A-Za-z0-9_]+)`/',$line,$m))$schemas[$current][]=$m[1];if(str_starts_with($line,')'))$current=null;continue;}
   if(!preg_match('/^INSERT INTO `([^`]+)`/',$line,$m)||!isset($allow[$m[1]]))continue;$name=$m[1];
   $start='INSERT INTO `'.$name.'` VALUES ';$line=rtrim($line,"\r\n");if(!str_starts_with($line,$start)||!str_ends_with($line,';')||!isset($schemas[$name]))throw new \RuntimeException('INSERT format');
   foreach(self::rows(substr($line,strlen($start),-1)) as $row){if(count($row)!==count($schemas[$name]))throw new \RuntimeException('columns');$r=array_combine($schemas[$name],$row);if($name===$prefix.'options'&&!in_array($r['option_name'],self::SAFE,true))continue;$data[$name][]=$r;}
  }}finally{gzclose($f);}
  foreach(array_keys($allow) as $name)if(!isset($schemas[$name]))throw new \RuntimeException('missing table');
  $options=array_column($data[$prefix.'options'],'option_value','option_name');
  if(($options['permalink_structure']??null)!=='/%postname%'||parse_url($options['home']??'',PHP_URL_HOST)!=='brand-new.tokyo')throw new \RuntimeException('site configuration');
  $zone=new \DateTimeZone($options['timezone_string']?:'UTC');$utc=new \DateTimeZone('UTC');
  $date=function(string $v)use($zone,$utc):?string{if(str_starts_with($v,'0000-'))return null;$d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,$zone);if(!$d||$d->format('Y-m-d H:i:s')!==$v)throw new \RuntimeException('date');return $d->setTimezone($utc)->format('c');};
  $terms=array_column($data[$prefix.'terms'],null,'term_id');$tax=array_column($data[$prefix.'term_taxonomy'],null,'term_taxonomy_id');$rels=[];$meta=[];
  foreach($data[$prefix.'term_relationships'] as $r){$t=$tax[$r['term_taxonomy_id']];$term=$terms[$t['term_id']];$rels[$r['object_id']][]=['term_id'=>$term['term_id'],'name'=>$term['name'],'slug'=>$term['slug'],'taxonomy'=>$t['taxonomy']];}
  foreach($rels as &$rel)usort($rel,fn($a,$b)=>$a['term_id']<=>$b['term_id']);unset($rel);
  foreach($data[$prefix.'postmeta'] as $r)$meta[$r['post_id']][$r['meta_key']][]=$r['meta_value'];
  $posts=$data[$prefix.'posts'];usort($posts,fn($a,$b)=>$a['ID']<=>$b['ID']);$records=[];$downloads=[];
  foreach($posts as $r){$id=$r['ID'];if(in_array($r['post_type'],['post','page'],true)){
    $a=['legacy_id'=>$id,'path'=>'/'.$r['post_name'],'status'=>$r['post_status'],'type'=>$r['post_type'],'title'=>$r['post_title'],'original_html'=>$r['post_content'],'original_sha256'=>hash('sha256',$r['post_content']),'published_at'=>$date($r['post_date']),'updated_at'=>$date($r['post_modified']),'terms'=>$rels[$id]??[],'original_record'=>$r];
    $thumb=$meta[$id]['_thumbnail_id'][0]??null;if($thumb!==null&&isset($meta[$thumb]['_wp_attached_file'][0]))$a['thumbnail']='/wp-content/uploads/'.$meta[$thumb]['_wp_attached_file'][0];$records[]=$a;
   }elseif($r['post_type']==='wpdmpro'){
    $m=array_map(fn($v)=>end($v),$meta[$id]??[]);$raw=$m['__wpdm_files']??'a:0:{}';$files=self::files($raw);$access=$m['__wpdm_access']??'';
    $downloads[]=['id'=>$id,'files'=>$files,'public'=>$r['post_status']==='publish'&&empty($m['__wpdm_password'])&&(str_contains($access,'guest')||$access==='')];
   }
  }
  return ['schema_version'=>1,'publication_enabled'=>false,'records'=>$records,'downloads'=>$downloads];
 }
 private static function files(string $raw):array {
  if(!preg_match('/\Aa:(\d+):\{/',$raw,$m))throw new \RuntimeException('download format');$pos=strlen($m[0]);$files=[];
  for($i=0;$i<(int)$m[1];$i++){if(!preg_match('/\Ai:\d+;s:(\d+):"/',substr($raw,$pos),$p))throw new \RuntimeException('download item');$pos+=strlen($p[0]);$value=substr($raw,$pos,(int)$p[1]);$pos+=(int)$p[1];if(substr($raw,$pos,2)!=='";'||!mb_check_encoding($value,'UTF-8'))throw new \RuntimeException('download length');$pos+=2;$files[]=$value;}
  if(substr($raw,$pos)!=='}')throw new \RuntimeException('download tail');return $files;
 }
 public static function canonical(mixed $v):string {
  $sort=function($x)use(&$sort){if(!is_array($x))return $x;if(!array_is_list($x))ksort($x,SORT_STRING);foreach($x as &$y)$y=$sort($y);return $x;};
  return json_encode($sort($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_LINE_TERMINATORS|JSON_THROW_ON_ERROR);
 }
}
