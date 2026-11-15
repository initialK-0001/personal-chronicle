import {timelineHTML,collectionEligible} from './timeline.js';
'use strict';
const $=s=>document.querySelector(s),$$=s=>[...document.querySelectorAll(s)];
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const id=()=>crypto.randomUUID();
const textBlock=text=>({id:id(),type:'paragraph',text});
const imgBlock=(src,alt)=>({id:id(),type:'image',src,alt});
const samples=[
{id:'sample-article',title:'VRM1.0の表情について',tags:['VRM','技術記事'],date:'2022-10-23T11:34',sample:true,blocks:[imgBlock('assets/expression.jpg','VRM1.0の表情'),textBlock('VRM1.0の表情操作についての既存記事'),{id:id(),type:'heading',text:'表情の設定'},textBlock('プリセットの表情とカスタムの表情を扱う記事の表示見本'),{id:id(),type:'link',text:'元の記事を読む',url:'https://brand-new.tokyo/vrm1-expression'}]},
{id:'sample-pose',title:'基本立ちポーズ',tags:['VRM','ポーズ'],date:'2022-05-01T12:00',sample:true,blocks:[imgBlock('assets/pose.png','基本立ちポーズ')]},
{id:'sample-gallery',title:'ピースポーズと立ちポーズ',tags:['VRM','ポーズ'],date:'2022-05-01T11:00',sample:true,blocks:[imgBlock('assets/peace.png','Wピース'),imgBlock('assets/style.png','立ちポーズ')]},
{id:'sample-cover',title:'VRMお手軽ポーズ用ポーズ集',tags:['VRM','ポーズ'],date:'2022-05-01T10:00',sample:true,blocks:[imgBlock('assets/cover.jpg','ポーズ集'),textBlock('VRMお手軽ポーズ用のポーズをまとめたページ'),{id:id(),type:'link',text:'元の記事を読む',url:'https://brand-new.tokyo/vrm_otegaru_poses_01'}]},
{id:'sample-note',title:'',tags:['メモ'],date:'2022-04-30T12:00',sample:true,blocks:[textBlock('短文投稿の表示見本\n\nタイトルなしでも本文から表示\n画像や文章はあとから追加可能')]},
{id:'sample-thought',title:'制作記録の表示見本',tags:['制作記録'],date:'2022-04-29T10:00',sample:true,blocks:[textBlock('画像と文章を同じ投稿に保存'),{id:id(),type:'heading',text:'記録する項目'},textBlock('使用したモデル\nポーズと表情\n撮影設定\n変更した箇所') ]}
];
let state={posts:[]},view='all',search='',tag='';
const prose=p=>p.blocks.filter(b=>b.type==='paragraph'||b.type==='quote').map(b=>b.text).join('\n');
const dateLabel=p=>p.date?esc(new Date(p.date).toLocaleDateString('ja-JP',{timeZone:'Asia/Tokyo'})):'';
const allPosts=()=>[...state.posts,...samples];
function filtered(){const pool=new URLSearchParams(location.search).has('works')?allPosts().filter(collectionEligible):allPosts();return pool.filter(p=>(view!=='images'||p.blocks.some(b=>b.type==='image'))&&(view!=='articles'||prose(p).trim())&&(!tag||p.tags.includes(tag))&&(!search||[p.title,prose(p),...p.tags].join(' ').toLowerCase().includes(search.toLowerCase()))).sort((a,b)=>(b.date||'').localeCompare(a.date||''));}
function render(){const posts=filtered();$('#feed').className='feed timeline';$('#empty').hidden=!!posts.length;$('#pageTitle').textContent=search?'検索結果':tag||'投稿一覧';$('#activeFilters').innerHTML=[search?`<span>検索 ${esc(search)}</span>`:'',tag?`<span>${esc(tag)}</span>`:'',(tag||search)?'<button id="resetSearch">条件を解除</button>':''].join('');$('#resetSearch')?.addEventListener('click',clearFilters);
$('#feed').innerHTML=posts.map(p=>timelineHTML(p,postURL(p),false)).join('');
 $$('#feed [data-destination]').forEach(el=>el.onclick=e=>{if(!e.target.closest('a,button')&&!window.getSelection()?.toString())location.href=el.dataset.destination;});
 $$('#feed [data-edit]').forEach(el=>el.onclick=()=>editPost(allPosts().find(p=>p.id===el.dataset.edit)));
 renderPage();
const themes=['すべて',...new Set(allPosts().flatMap(p=>p.tags))];$('#themes').innerHTML=themes.map(t=>`<button data-tag="${esc(t)}" class="${(t==='すべて'&&!tag)||tag===t?'active':''}">${esc(t)}</button>`).join('');$$('#themes button').forEach(b=>b.onclick=()=>{tag=b.dataset.tag==='すべて'?'':b.dataset.tag;if(location.search){location.href=basePage+'?theme='+encodeURIComponent(tag);return;}render();});}
function clearFilters(){if(location.search){location.href=basePage;return;}search='';tag='';view='all';$('#search').value='';$$('[data-view]').forEach(x=>x.setAttribute('aria-pressed',String(x.dataset.view==='all')));render();}
function renderContent(p){return `<div class="rendered"><div class="hint">${dateLabel(p)}${p.updatedAt&&p.updatedAt!==p.date?'　更新 '+esc(new Date(p.updatedAt).toLocaleString('ja-JP',{timeZone:'Asia/Tokyo'})):''} ${p.tags.map(esc).join(' / ')}</div>${p.title?`<h1>${esc(p.title)}</h1>`:''}${p.blocks.map(b=>b.type==='image'?`<figure><img src="${esc(b.src)}" alt="${esc(b.alt||'投稿画像')}">${b.alt?`<figcaption>${esc(b.alt)}</figcaption>`:''}</figure>`:b.type==='heading'?`<h2>${esc(b.text)}</h2>`:b.type==='quote'?`<blockquote>${esc(b.text)}</blockquote>`:b.type==='link'?`<p>${/^https?:\/\//i.test(b.url)?`<a href="${esc(b.url)}" target="_blank" rel="noopener noreferrer">${esc(b.text||b.url)}</a>`:'リンク先を確認してください'}</p>`:`<p>${esc(b.text)}</p>`).join('')}</div>`;}


const basePage='/';
const postURL=p=>basePage+'?post='+encodeURIComponent(p.id);
function editPost(p){}
function renderPage(){
 const query=new URLSearchParams(location.search),postId=query.get('post');const articlePage=$('#articlePage');const collection=query.has('collections');const detail=!!postId||collection;
 $('#feed').hidden=detail;$('#empty').hidden=detail||!!filtered().length;$('.resulthead').hidden=detail;$('#activeFilters').hidden=detail;articlePage.hidden=!detail;
 if(!detail)return;
 if(collection){articlePage.innerHTML='<a href="'+basePage+'">一覧へ戻る</a><h1>作品集</h1><div class="collectionlinks">'+[...new Set(allPosts().filter(collectionEligible).flatMap(p=>p.tags))].map(t=>`<a href="${basePage}?works=1&theme=${encodeURIComponent(t)}">${esc(t)}</a>`).join('')+'</div>';return;}
 const p=allPosts().find(p=>p.id===postId);
 articlePage.innerHTML=`<a href="${basePage}">一覧へ戻る</a>`+(p?renderContent(p):'<h1>投稿が見つかりません</h1>')+'';
 document.title=p?(p.title||'投稿')+' | Brand-new TOKYO':'投稿が見つかりません | Brand-new TOKYO';
 
}

search=new URLSearchParams(location.search).get('search')||'';$('#search').value=search;tag=new URLSearchParams(location.search).get('theme')||'';view=new URLSearchParams(location.search).get('view')||'all';$$('[data-view]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.view===view)));

$$('[data-view]').forEach(b=>b.onclick=()=>{if(location.search){location.href=basePage+'?view='+b.dataset.view;return;}view=b.dataset.view;$$('[data-view]').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));render();});$('#search').oninput=e=>{search=e.target.value;render();};$('#searchForm').onsubmit=e=>{e.preventDefault();search=$('#search').value;if(location.search){location.href=basePage+'?search='+encodeURIComponent(search);return;}render();};$('#brand').onclick=clearFilters;$('#clearFilters').onclick=clearFilters;$('#collectionsButton').onclick=()=>{location.href=basePage+'?collections=1';};

render();
