import {mountComments} from '/comments.js';
const samples=[];
import {timelineHTML,collectionEligible} from './timeline.js';
'use strict';
const $=s=>document.querySelector(s),$$=s=>[...document.querySelectorAll(s)];
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const id=()=>crypto.randomUUID();
const textBlock=text=>({id:id(),type:'paragraph',text});
const imgBlock=(src,alt)=>({id:id(),type:'image',src,alt});

let loading=true,loadError=false,visibleLimit=50,lastFilter='';
let state={posts:[]},view='all',search='',tag='';
const prose=p=>p.blocks.filter(b=>b.type==='paragraph'||b.type==='quote').map(b=>b.text).join('\n');
const dateLabel=p=>p.date?esc(new Date(p.date).toLocaleDateString('ja-JP',{timeZone:'Asia/Tokyo'})):'';
const allPosts=()=>[...state.posts,...samples.filter(p=>!state.posts.some(saved=>saved.id===p.id))];
function filtered(){const pool=new URLSearchParams(location.search).has('works')?allPosts().filter(collectionEligible):allPosts();return pool.filter(p=>(view!=='images'||p.blocks.some(b=>b.type==='image'))&&(view!=='articles'||(prose(p).trim()&&!p.blocks.some(b=>b.type==='image')))&&(!tag||p.tags.includes(tag))&&(!search||[p.title,prose(p),...p.tags].join(' ').toLowerCase().includes(search.toLowerCase()))).sort((a,b)=>(b.date||'').localeCompare(a.date||''));}
function render(){const filterKey=JSON.stringify([view,search,tag]);if(filterKey!==lastFilter){visibleLimit=50;lastFilter=filterKey;}const posts=filtered();$('#feed').className='feed timeline';$('#empty').hidden=!!posts.length;$('#pageTitle').textContent=search?'検索結果':tag||'タイムライン';$('#activeFilters').innerHTML=[search?`<span>検索 ${esc(search)}</span>`:'',tag?`<span>${esc(tag)}</span>`:'',(tag||search)?'<button id="resetSearch">条件を解除</button>':''].join('');$('#resetSearch')?.addEventListener('click',clearFilters);
$('#feed').innerHTML=posts.slice(0,visibleLimit).map(p=>timelineHTML(p,postURL(p),false)).join('');
let more=$('#loadMorePosts');if(!more){more=document.createElement('button');more.id='loadMorePosts';more.textContent='さらに表示';$('#feed').after(more);}more.hidden=posts.length<=visibleLimit||new URLSearchParams(location.search).has('post');more.onclick=()=>{visibleLimit+=50;render();};
 $$('#feed [data-destination]').forEach(el=>el.onclick=e=>{if(!e.target.closest('a,button')&&!window.getSelection()?.toString())location.href=el.dataset.destination;});
 $$('#feed [data-edit]').forEach(el=>el.onclick=()=>editPost(allPosts().find(p=>p.id===el.dataset.edit)));
 renderPage();
const themes=['すべて',...new Set(allPosts().flatMap(p=>p.tags))];$('#themes').innerHTML=themes.map(t=>`<button data-tag="${esc(t)}" class="${(t==='すべて'&&!tag)||tag===t?'active':''}">${esc(t)}</button>`).join('');$$('#themes button').forEach(b=>b.onclick=()=>{tag=b.dataset.tag==='すべて'?'':b.dataset.tag;if(location.search){location.href=basePage+'?theme='+encodeURIComponent(tag);return;}render();});}
function clearFilters(){if(location.search){location.href=basePage;return;}search='';tag='';view='all';$('#search').value='';$$('[data-view]').forEach(x=>x.setAttribute('aria-pressed',String(x.dataset.view==='all')));render();}
function renderContent(p){return `${p.sample?'<p class="hint">表示確認用の記事</p>':''}<div class="rendered"><div class="hint">${dateLabel(p)}${p.updatedAt&&p.updatedAt!==p.date?'　更新 '+esc(new Date(p.updatedAt).toLocaleString('ja-JP',{timeZone:'Asia/Tokyo'})):''} ${p.tags.map(esc).join(' / ')}</div>${p.title?`<h1>${esc(p.title)}</h1>`:''}${p.blocks.map(b=>b.type==='image'?`<figure><img src="${esc(b.src)}" alt="${esc(b.alt||'投稿画像')}">${b.alt?`<figcaption>${esc(b.alt)}</figcaption>`:''}</figure>`:b.type==='heading'?`<h2>${esc(b.text)}</h2>`:b.type==='quote'?`<blockquote>${esc(b.text)}</blockquote>`:b.type==='link'?`<p>${/^https?:\/\//i.test(b.url)?`<a href="${esc(b.url)}" target="_blank" rel="noopener noreferrer">${esc(b.text||b.url)}</a>`:'リンク先を確認してください'}</p>`:`<p>${esc(b.text)}</p>`).join('')}</div>`;}


const basePage='/';
if(new URLSearchParams(location.search).has('collections'))location.replace('/works');
const postURL=p=>p.url||basePage+'?post='+encodeURIComponent(p.id);
function workNavigation(p){const pool=allPosts().filter(p=>collectionEligible(p)),roots=pool.filter(p=>p.work?.role==='work'),rootIds=new Set(roots.map(p=>p.id)),versions=pool.filter(p=>p.work?.role==='version'&&rootIds.has(p.work.parent)),visible=[...roots,...versions];const link=p=>`<a href="${esc(postURL(p))}">${esc(p.title||'作品紹介')}</a>`;let html='';const parent=visible.find(x=>x.id===p.work?.parent);if(parent)html+=`<p>ギャラリー ${link(parent)}</p>`;if(visible.some(x=>x.id===p.id)){const children=versions.filter(x=>x.work.parent===p.id);if(children.length)html+='<h2>バージョン</h2><ul>'+children.map(x=>`<li>${link(x)}</li>`).join('')+'</ul>';const ids=new Set([p.id,...children.map(x=>x.id)]);const related=pool.filter(x=>x.work?.role==='related'&&ids.has(x.work.parent)).sort((a,b)=>(b.date||'').localeCompare(a.date||''));if(related.length)html+='<h2>関連投稿</h2><section class="feed timeline">'+related.map(x=>timelineHTML(x,postURL(x),false)).join('')+'</section>';}
return html?`<nav class="work-navigation" aria-label="作品との関連">${html}</nav>`:'';}
function editPost(p){}
function renderPage(){
 const query=new URLSearchParams(location.search),postId=query.get('post');const articlePage=$('#articlePage');const collection=query.has('collections');const detail=!!postId||collection;
 $('#feed').hidden=detail;$('#empty').hidden=detail||!!filtered().length;$('#empty').textContent=loadError?'投稿を読み込めません 再読み込みしてください':loading?'読み込み中':'該当する投稿はありません';$('.resulthead').hidden=detail;$('#activeFilters').hidden=detail;articlePage.hidden=!detail;
 if(!detail)return;
 if(collection){articlePage.innerHTML='<a href="'+basePage+'">一覧へ戻る</a><h1>ギャラリー</h1><div class="collectionlinks">'+[...new Set(allPosts().filter(collectionEligible).flatMap(p=>p.tags))].map(t=>`<a href="${basePage}?works=1&theme=${encodeURIComponent(t)}">${esc(t)}</a>`).join('')+'</div>';return;}
 const p=allPosts().find(p=>p.id===postId);
 articlePage.innerHTML=`<a href="${basePage}">一覧へ戻る</a>`+(p?renderContent(p):`<h1>${loadError?'投稿を読み込めません 再読み込みしてください':loading?'読み込み中':'投稿が見つかりません'}</h1>`)+'';
 if(p){articlePage.insertAdjacentHTML('beforeend',workNavigation(p));mountComments(articlePage,p.id);}
 document.title=p?(p.title||'投稿')+' | Brand-new TOKYO':'投稿が見つかりません | Brand-new TOKYO';
 
}

search=new URLSearchParams(location.search).get('search')||'';$('#search').value=search;tag=new URLSearchParams(location.search).get('theme')||document.body.dataset.theme||'';view=new URLSearchParams(location.search).get('view')||'all';$$('[data-view]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.view===view)));

$$('[data-view]').forEach(b=>b.onclick=()=>{if(location.search){location.href=basePage+'?view='+b.dataset.view;return;}view=b.dataset.view;$$('[data-view]').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));render();});$('#search').oninput=e=>{search=e.target.value;render();};$('#searchForm').onsubmit=e=>{e.preventDefault();search=$('#search').value;if(location.search){location.href=basePage+'?search='+encodeURIComponent(search);return;}render();};$('#brand').onclick=clearFilters;$('#clearFilters').onclick=clearFilters;$('#collectionsButton').onclick=()=>{location.href='/works';};

render();

(async()=>{try{const r=await fetch('/api/public/posts',{cache:'no-store'});if(!r.ok)throw Error();const data=await r.json();state.posts=data.posts;loading=false;render();}catch{loading=false;loadError=true;render();}})();
