<?php
declare(strict_types=1);
namespace Chronicle;
final class Web {
    public static function escape(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function url(string $origin,string $path):string {
        return rtrim($origin,'/').implode('/',array_map(fn($part)=>rawurlencode(rawurldecode($part)),explode('/',$path)));
    }
    public static function blocks(array $blocks):string {
        $html='';foreach($blocks as $b){$text=self::escape($b['text']??'');$html.=match($b['type']){
            'image'=>'<figure><img src="'.self::escape($b['src']).'" alt="'.self::escape($b['alt']??'').'" loading="lazy"></figure>',
            'heading'=>'<h2>'.$text.'</h2>', 'quote'=>'<blockquote>'.$text.'</blockquote>',
            'link'=>'<p><a href="'.self::escape($b['url']).'" rel="noopener noreferrer">'.$text.'</a></p>',
            default=>'<p>'.$text.'</p>',
        };}return $html;
    }
    public static function card(array $p):string {
        $url=$p['url']??'/?post='.rawurlencode($p['id']);$text='';$rawText='';$image=null;$images=0;
        foreach($p['blocks'] as $b){if($b['type']==='image'){$image??=$b;$images++;}else {$part=$b['text']??($b['type']==='link'?($b['url']??''):'');$rawText.=$part;$text.=$part.' ';}}
        $text=trim(preg_replace('/\s+/u',' ',$text));preg_match_all('/\X/u',$text,$chars);preg_match_all('/\X/u',$rawText,$rawChars);$long=count($rawChars[0])>140;$article=trim($p['title'])!==''||$long;$excerpt=implode('',array_slice($chars[0],0,140));
        $body=($excerpt!==''?'<p>'.self::escape($excerpt).($long?'…':'').'</p>':'').(!$article&&$image?self::blocks([$image]):'');
        $thumb=$article&&$image?'<a class="article-thumbnail" href="'.self::escape($url).'"><img src="'.self::escape($image['src']).'" alt="'.self::escape($image['alt']??'').'" loading="lazy"></a>':'';
        return '<article class="timeline-post '.($article?'article-row':'short-post').($thumb?' has-thumbnail':'').'">'.$thumb.'<div class="entry-body">'.($p['title']!==''?'<h2><a href="'.self::escape($url).'">'.self::escape($p['title']).'</a></h2>':'').'<div class="timeline-content">'.$body.'</div><div class="postmeta">'.($long||$images>1?'<a class="readmore" href="'.self::escape($url).'">続きを読む</a>':'').'<time>'.self::date($p['date']).'</time><a href="'.self::escape($url).'#comments">コメント</a></div></div></article>';
    }
    private static function shortBlocks(array $blocks):array{$seen=false;return array_values(array_filter($blocks,function($b)use(&$seen){if($b['type']!=='image')return true;if($seen)return false;$seen=true;return true;}));}
    public static function date(?string $date):string {if(!$date)return '';return (new \DateTimeImmutable($date))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y/m/d');}
    public static function page(string $template,array $posts,string $origin,?array $post=null,string $theme=''):string {
        $title=$post?($post['title']?:'投稿'):($theme?:'Brand-new TOKYO');$html=preg_replace('~<title>.*?</title>~s','<title>'.self::escape($title).($title==='Brand-new TOKYO'?'':' | Brand-new TOKYO').'</title>',$template);
        $html=str_replace(['href="style.css"','src="public.js"'],['href="/style.css"','src="/public.js"'],$html);
        if($theme!=='')$html=str_replace('<h1 id="pageTitle">投稿一覧</h1>','<h1 id="pageTitle">'.self::escape($theme).'</h1>',$html);
        if($theme!=='')$html=str_replace('<body>','<body data-theme="'.self::escape($theme).'">',$html);
        $path=$post?'/?post='.rawurlencode($post['id']):'/';
        if($post){$content='<a href="/">一覧へ戻る</a><div class="rendered"><time>'.self::date($post['date']).'</time>'.($post['title']!==''?'<h1>'.self::escape($post['title']).'</h1>':'').self::blocks($post['blocks']).'</div>';
            $html=str_replace('<article id="articlePage" hidden></article>','<article id="articlePage">'.$content.'</article>',$html);
            $html=str_replace(['class="resulthead"','id="feed"'],['class="resulthead" hidden','id="feed" hidden'],$html);
        }else{
            if($theme!=='')$posts=array_values(array_filter($posts,fn($p)=>in_array($theme,$p['tags'],true)));
            usort($posts,fn($a,$b)=>strcmp($b['date']??'',$a['date']??''));
            $html=str_replace('<section id="feed" class="feed" aria-label="投稿一覧"></section>','<section id="feed" class="feed timeline" aria-label="投稿一覧">'.implode('',array_map([self::class,'card'],$posts)).'</section>',$html);
        }
        // Category callers supply their own canonical route after rendering.
        if($theme==='')$html=str_replace('</head>','<link rel="canonical" href="'.self::escape(rtrim($origin,'/').$path).'"></head>',$html);
        return $html;
    }
    public static function sitemap(string $origin,array $posts,Legacy $legacy,array $works=[]):string {
        $urls=[rtrim($origin,'/').'/',rtrim($origin,'/').'/works'];foreach($posts as $p)$urls[]=rtrim($origin,'/').'/?post='.rawurlencode($p['id']);
        foreach($legacy->records() as $r)$urls[]=self::url($origin,$r['path']);foreach($legacy->categories() as $c)$urls[]=self::url($origin,$c['path']);
        return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('',array_map(fn($u)=>'<url><loc>'.self::escape($u).'</loc></url>',array_unique($urls))).'</urlset>';
    }
}
