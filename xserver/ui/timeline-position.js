// Keep return positions per history entry; session storage also supports explicit return links.
const key='chronicle-timeline-return-v1';
const current=()=>location.pathname+location.search;
const read=()=>{try{return JSON.parse(sessionStorage.getItem(key)||'null');}catch{return null;}};
const write=v=>{try{sessionStorage.setItem(key,JSON.stringify(v));}catch{}};
const valid=s=>s&&typeof s.url==='string'&&s.url.startsWith('/')&&!s.url.startsWith('//')&&typeof s.id==='string';
let applied=false;
export function returnPosition(){const s=read();if(valid(s)&&s.pending&&s.url===current())return s;const h=history.state?.timelinePosition;return valid(h)&&h.url===current()?h:null;}
export function restoreTimelinePosition(){
 const s=returnPosition();if(applied||!s)return;
 const target=[...document.querySelectorAll('#feed [data-post-id]')].find(e=>e.dataset.postId===s.id);if(!target)return;
 applied=true;write({...s,pending:false});let stopped=false;
 const stop=()=>{stopped=true;observer.disconnect();};
 const adjust=()=>{if(!stopped)window.scrollBy(0,target.getBoundingClientRect().top-s.offset);};
 const observer=new ResizeObserver(adjust);observer.observe(document.querySelector('#feed'));
 for(const name of ['wheel','touchstart','pointerdown','keydown'])window.addEventListener(name,stop,{once:true,passive:true});
 requestAnimationFrame(()=>{adjust();requestAnimationFrame(adjust);});setTimeout(stop,5000);
}
if(!location.pathname.startsWith('/admin')&&document.querySelector('#feed'))history.scrollRestoration='manual';
document.addEventListener('click',e=>{
 if(location.pathname.startsWith('/admin'))return;
 const row=e.target.closest('#feed [data-post-id]');
 if(row&&!e.target.closest('button')&&!window.getSelection()?.toString()){
  const link=e.target.closest('a');const destination=link?.href||row.dataset.destination;if(!destination)return;
  const dest=new URL(destination,location.href);if(dest.origin!==location.origin)return;
  const s={url:current(),id:row.dataset.postId,offset:row.getBoundingClientRect().top,limit:document.querySelectorAll('#feed [data-post-id]').length,destination:dest.pathname+dest.search,pending:false};
  history.replaceState({...history.state,timelinePosition:s},'');write(s);
 }
 const back=e.target.closest('a');const s=read();
 if(back?.textContent.trim()==='一覧へ戻る'&&valid(s)&&s.destination===current()){
  e.preventDefault();write({...s,pending:true});
  if(document.referrer===location.origin+s.url&&history.length>1)history.back();else location.assign(s.url);
 }
},true);
window.addEventListener('pageshow',e=>{if(e.persisted){applied=false;restoreTimelinePosition();}});
