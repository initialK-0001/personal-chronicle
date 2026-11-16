const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const segmenter=new Intl.Segmenter('ja',{granularity:'grapheme'});
export const characters=text=>[...segmenter.segment(text||'')].map(x=>x.segment);
const blockText=b=>b.type==='image'?'':b.text|| (b.type==='link'?b.url:'')||'';
export function summary(post){const length=post.blocks.reduce((n,b)=>n+characters(blockText(b)).length,0);const images=post.blocks.filter(b=>b.type==='image').length;return {length,images,truncated:length>140||images>1};}
export function shownBlocks(post,full=false){if(full)return post.blocks;let remaining=140,images=0;return post.blocks.flatMap(b=>{if(b.type==='image')return ++images===1?[b]:[];const chars=characters(blockText(b));if(!remaining&&chars.length)return [];const text=chars.slice(0,remaining).join('');remaining-=Math.min(chars.length,remaining);return [{...b,text}];});}
export function blockHTML(b){if(b.type==='image')return `<figure><img src="${esc(b.src)}" alt="${esc(b.alt||'投稿画像')}" loading="lazy">${b.alt?`<figcaption>${esc(b.alt)}</figcaption>`:''}</figure>`;const text=esc(b.text);if(b.type==='heading')return `<h3>${text}</h3>`;if(b.type==='quote')return `<blockquote>${text}</blockquote>`;if(b.type==='link')return /^https?:\/\//i.test(b.url)?`<p><a href="${esc(b.url)}" target="_blank" rel="noopener noreferrer">${text||esc(b.url)}</a></p>`:'<p>リンク先を確認してください</p>';return `<p>${text}</p>`;}
export function timelineHTML(post,url,admin=false){
 const info=summary(post),image=post.blocks.find(b=>b.type==='image');
 const article=!!post.title?.trim()||info.length>140;
 const linked=article||info.truncated;
 const heading=post.title?`<h2>${linked?`<a href="${esc(url)}">${esc(post.title)}</a>`:esc(post.title)}</h2>`:'';
 const date=post.date?esc(new Date(post.date).toLocaleDateString('ja-JP',{timeZone:'Asia/Tokyo'})):'';
 const body=shownBlocks(post).filter(b=>!article||b.type!=='image').map(blockHTML).join('');
 const thumbnail=article&&image?`<a class="article-thumbnail" href="${esc(url)}" aria-label="${esc(post.title||'投稿全体を見る')}"><img src="${esc(image.src)}" alt="${esc(image.alt||'投稿画像')}" loading="lazy"></a>`:'';
 return `<article class="timeline-post ${article?'article-row':'short-post'} ${thumbnail?'has-thumbnail':''}" ${linked?`data-destination="${esc(url)}"`:''}>${thumbnail}<div class="entry-body">${post.sample?'<p class="hint">表示確認用の記事</p>':''}${heading}<div class="timeline-content">${body}${info.length>140?'<span aria-hidden="true">…</span>':''}</div>${info.truncated?`<a class="readmore" href="${esc(url)}">${info.images>1?'投稿全体を見る':'続きを読む'}</a>`:''}<div class="postmeta"><span>${post.tags.map(esc).join(' / ')}</span><time>${date}</time>${!admin?`<a href="${esc(url)}#comments">コメント</a>`:String()}</div>${admin&&!post.sample?`<div class="post-management">${post.visibility==='trash'?`<span>削除済み</span><button data-restore="${esc(post.id)}">戻す</button>`:`<span>${post.visibility==='public'?'公開中':'非公開'}</span><button data-edit="${esc(post.id)}">編集</button>${post.visibility==='public'?`<button data-unpublish="${esc(post.id)}">非公開にする</button>`:''}<button data-trash="${esc(post.id)}">削除</button>`}</div>`:''}</div></article>`;
}

// ポーズは作品集に含めない 投稿自体は保持する
export const collectionEligible=post=>!(post.tags||[]).includes('ポーズ');
