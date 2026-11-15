import test from 'node:test';import assert from 'node:assert/strict';import {api} from '../server/api.mjs';import {publicAPI} from '../server/public.mjs';import {handle} from '../server/routes.mjs';import {createHash} from 'node:crypto';
class Bucket{data=new Map();async get(k){const v=this.data.get(k);return v?{...v,json:async()=>JSON.parse(v.text),body:new Blob([v.text]).stream()}:null;}async head(k){return this.get(k);}async put(k,data,opts={}){const old=this.data.get(k),condition=opts.onlyIf;if(condition?.get('If-Match')&&condition.get('If-Match')!==old?.httpEtag)return null;if(condition?.get('If-None-Match')==='*'&&old)return null;const text=typeof data==='string'?data:Buffer.from(data).toString('binary');const etag=createHash('sha256').update(text).digest('hex');const v={text,etag,httpEtag:'"'+etag+'"',httpMetadata:opts.httpMetadata};this.data.set(k,v);return v;}}
const req=(user='one',input,extra={})=>new Request('https://site.test/api/state',{method:input?'POST':'GET',headers:{...(user?{'oai-authenticated-user-id':user,'oai-authenticated-user-email':user==='two'?'other@example.invalid':'owner@example.invalid'}:{}),...(input?{'Origin':'https://site.test','X-Chronicle-Request':'1','Content-Type':'application/json'}:{}),...extra},...(input?{body:JSON.stringify(input)}:{})});
const post={id:'test-post',title:'',tags:[],date:'1990-01-01',blocks:[{id:'text',type:'paragraph',text:'本文です。'}]};

const publicRequest=(path='/api/public/posts')=>new Request('https://site.test'+path);
const setup=()=>({BUCKET:new Bucket(),CHRONICLE_ADMIN_EMAIL:'owner@example.invalid'});
const read=async env=>(await publicAPI(publicRequest(),env)).json();
const save=async(env,action,p=post,etag)=>{if(etag===undefined)etag=(await(await api(req(),env)).json()).etag;return api(req('one',{action,post:p,id:p.id,etag}),env);};
test('only explicit release exposes a projection and editing draft cannot change it',async()=>{
 const env=setup();await save(env,'publish');assert.deepEqual(await read(env),{posts:[]});
 await save(env,'release');let data=await read(env);assert.equal(data.posts[0].blocks[0].text,post.blocks[0].text);
 assert.deepEqual(Object.keys(data.posts[0]).sort(),['blocks','date','id','tags','title','updatedAt']);assert.equal(data.posts[0].blocks[0].id,undefined);
 const first=data.posts[0].date;
 await save(env,'draft',{...post,title:'未公開の編集'});assert.equal((await read(env)).posts[0].title,'');
 await save(env,'release',{...post,title:'更新を公開'});data=await read(env);assert.equal(data.posts[0].title,'更新を公開');assert.equal(data.posts[0].date,first);
 const old=(await(await api(req(),env)).json()).etag;
 await save(env,'draft',{...post,title:'途中の文章'});
 assert.equal((await save(env,'release',{...post,title:'古い端末'},old)).status,409);assert.equal((await read(env)).posts[0].title,'更新を公開');
 await save(env,'unpublish');assert.deepEqual(await read(env),{posts:[]});const state=(await(await api(req(),env)).json()).state;assert.equal(state.draft.title,'途中の文章');assert.equal(state.posts[0].title,'更新を公開');
});
test('draft-only image denied and shared published image revoked at final unpublish',async()=>{
 const env=setup();const upload=new Request('https://site.test/api/upload',{method:'POST',headers:{'oai-authenticated-user-id':'one','oai-authenticated-user-email':'owner@example.invalid',Origin:'https://site.test','X-Chronicle-Request':'1'},body:Uint8Array.from([137,80,78,71,13,10,26,10])});
 const {src}=await(await api(upload,env)).json();const imagePost={...post,blocks:[{id:'image',type:'image',src,alt:'画像'}]};const publicSrc=src.replace('/api/images/','/api/public/images/');
 await save(env,'draft',imagePost);assert.equal((await publicAPI(publicRequest(publicSrc),env)).status,404);
 await save(env,'release',imagePost);const image=await publicAPI(publicRequest(publicSrc),env);assert.equal(image.status,200);assert.equal(image.headers.get('cache-control'),'no-store');assert.equal((await read(env)).posts[0].blocks[0].src,publicSrc);
 const second={...imagePost,id:'second'};await save(env,'release',second);await save(env,'unpublish',imagePost);assert.equal((await publicAPI(publicRequest(publicSrc),env)).status,200);
 await save(env,'unpublish',second);assert.equal((await publicAPI(publicRequest(publicSrc),env)).status,404);assert.equal((await api(publicRequest(src),env)).status,401);
});
test('anonymous public route is read only while private route remains protected',async()=>{
 const env=setup();assert.equal((await handle(publicRequest(),env,{})).status,200);assert.equal((await handle(publicRequest('/api/state'),env,{})).status,401);
 assert.equal((await handle(new Request('https://site.test/api/public/posts',{method:'POST'}),env,{})).status,405);
});
