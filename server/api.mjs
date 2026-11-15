// Private prototype storage. Host authentication must strip client-supplied identity headers.
const json=(data,status=200)=>Response.json(data,{status,headers:{'Cache-Control':'no-store','X-Content-Type-Options':'nosniff'}});
const fail=(message,status=400)=>{throw Object.assign(new Error(message),{status});};
const str=(v,max)=>typeof v==='string'&&v.length<=max?v:fail('入力内容を確認してください');
export function cleanPost(p){
 if(!p||!Array.isArray(p.blocks)||p.blocks.length>300||!Array.isArray(p.tags)||p.tags.length>30)fail('投稿の形式を確認してください');
 if(!/^[\w-]{1,80}$/.test(p.id))fail('投稿IDが不正です');
 return {id:p.id,title:str(p.title,500),tags:p.tags.map(t=>str(t,80)),blocks:p.blocks.map(b=>{
  const x={id:str(b.id,80),type:b.type};
  if(b.type==='image'){if(!/^(?:assets\/(?:pose\.png|peace\.png|style\.png|cover\.jpg|expression\.jpg)|\/api\/images\/[a-f0-9]{64})$/.test(b.src))fail('画像を先に保存してください');return {...x,src:b.src,alt:str(b.alt||'',2000),bytes:Number(b.bytes)||0};}
  if(!['paragraph','heading','quote','link'].includes(b.type))fail('未対応の書式です');
  x.text=str(b.text||'',200000);if(b.type==='link'){try{const u=new URL(b.url);if(!['https:','http:'].includes(u.protocol))fail('リンクが不正です');x.url=u.href;}catch{fail('リンクが不正です');}}return x;
 })};
}
async function limited(request,max){if(Number(request.headers.get('Content-Length'))>max)fail('容量の上限を超えています',413);const reader=request.body?.getReader();if(!reader)return new Uint8Array();let size=0;const chunks=[];while(true){const {value,done}=await reader.read();if(done)break;size+=value.length;if(size>max){await reader.cancel();fail('容量の上限を超えています',413);}chunks.push(value);}const bytes=new Uint8Array(size);let i=0;for(const c of chunks){bytes.set(c,i);i+=c.length;}return bytes;}
const hash=async bytes=>Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes)),b=>b.toString(16).padStart(2,'0')).join('');
export async function api(request,env){try{
 const url=new URL(request.url),path=url.pathname,user=request.headers.get('oai-authenticated-user-id');
 if(!user)return json({error:'ログインしてください'},401);
 if(!env.BUCKET)return json({error:'保存先に接続できません'},503);
 const prefix='users/'+await hash(new TextEncoder().encode(user))+'/';
 if(!['GET','HEAD'].includes(request.method)&& (request.headers.get('Origin')!==url.origin||request.headers.get('X-Chronicle-Request')!=='1'))return json({error:'この画面から操作してください'},403);
 if(path.startsWith('/api/images/')){
  if(request.method!=='GET')return json({error:'未対応の操作です'},405);
  const imageId=path.slice('/api/images/'.length);if(!/^[a-f0-9]{64}$/.test(imageId))return json({error:'画像がありません'},404);
  const file=await env.BUCKET.get(prefix+'images/'+imageId);if(!file)return json({error:'画像がありません'},404);
  return new Response(file.body,{headers:{'Content-Type':file.httpMetadata.contentType,'Cache-Control':'private, no-store','X-Content-Type-Options':'nosniff'}});
 }
 if(path==='/api/upload'&&request.method==='POST'){
  const data=await limited(request,8*1024*1024),head=String.fromCharCode(...data.slice(0,12));let type='';
  if(data[0]===137&&head.slice(1,4)==='PNG'&&data[4]===13&&data[5]===10)type='image/png';
  else if(data[0]===255&&data[1]===216&&data[2]===255)type='image/jpeg';
  else if(head.startsWith('GIF87a')||head.startsWith('GIF89a'))type='image/gif';
  else if(head.startsWith('RIFF')&&head.slice(8,12)==='WEBP')type='image/webp';
  if(!type)fail('対応していない画像形式です');const key=await hash(data);await env.BUCKET.put(prefix+'images/'+key,data,{httpMetadata:{contentType:type}});return json({src:'/api/images/'+key,bytes:data.length});
 }
 if(path!=='/api/state')return json({error:'見つかりません'},404);
 const key=prefix+'state.json',file=await env.BUCKET.get(key),current=file?await file.json():{draft:null,posts:[],revision:0};
 if(request.method==='GET')return json({state:current,etag:file?.etag||null});
 if(request.method!=='POST')return json({error:'未対応の操作です'},405);
 let input;try{input=JSON.parse(new TextDecoder().decode(await limited(request,1024*1024)));}catch(e){if(e.status)throw e;fail('入力形式が不正です');}
 if(input.etag!==(file?.etag||null))return json({error:'別の端末で変更されています 入力を書き出してから読み直してください'},409);
 const next=structuredClone(current),now=new Date().toISOString();
 if(input.action==='draft'){
  const p=cleanPost(input.post),prior=current.posts.find(x=>x.id===p.id)|| (current.draft?.id===p.id?current.draft:null);
  next.draft={...p,date:prior?.date||null,updatedAt:prior?.updatedAt||null,draftSavedAt:now};
 }else if(input.action==='publish'){
  const p=cleanPost(input.post);if(!p.blocks.some(b=>b.type==='image'||b.text?.trim()))fail('画像か本文を追加してください');
  const prior=current.posts.find(x=>x.id===p.id)||(current.draft?.id===p.id?current.draft:null);
  const post={...p,date:prior?.date||now,updatedAt:now};next.posts=[post,...current.posts.filter(x=>x.id!==p.id)];next.draft=null;
 }else if(input.action==='unpublish'){
  const p=current.posts.find(x=>x.id===input.id);if(!p)fail('投稿がありません',404);
  if(current.draft&&current.draft.id!==p.id)fail('編集中の下書きがあります',409);
  next.draft={...p,draftSavedAt:now};next.posts=current.posts.filter(x=>x.id!==p.id);
 }else fail('未対応の操作です');
 for(const b of (next.draft?.blocks||next.posts.find(p=>p.id===input.post?.id)?.blocks||[])){if(b.type==='image'&&b.src.startsWith('/api/')){if(!await env.BUCKET.head(prefix+'images/'+b.src.split('/').pop()))fail('画像を確認できません');}}
 next.revision=current.revision+1;const data=JSON.stringify(next);if(new TextEncoder().encode(data).length>1024*1024)fail('試作の保存容量に達しました',413);
 const condition=new Headers(file?{'If-Match':file.httpEtag}:{'If-None-Match':'*'});
 const saved=await env.BUCKET.put(key,data,{onlyIf:condition,httpMetadata:{contentType:'application/json'}});if(!saved)return json({error:'別の端末で変更されています 入力を書き出してから読み直してください'},409);
 return json({state:next,etag:saved.etag});
 }catch(e){return json({error:e.status?e.message:'保存処理に失敗しました 再度お試しください'},e.status||500);}}
