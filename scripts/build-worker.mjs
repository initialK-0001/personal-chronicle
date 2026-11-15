import {readFileSync,writeFileSync,mkdirSync,readdirSync} from 'node:fs';
import path from 'node:path';
const assets={};
function walk(dir){for(const e of readdirSync(dir,{withFileTypes:true})){if(e.name==='server'||e.name==='.openai')continue;const p=path.join(dir,e.name);if(e.isDirectory())walk(p);else {const ext=path.extname(p);assets['/'+p.slice(5)]={type:({'.html':'text/html; charset=utf-8','.css':'text/css','.js':'text/javascript','.png':'image/png','.jpg':'image/jpeg','.zip':'application/zip','.md':'text/plain; charset=utf-8'})[ext]||'application/octet-stream',body:readFileSync(p).toString('base64')};}}}walk('dist');
const api=readFileSync('server/api.mjs','utf8').replaceAll('export ','');
const worker=`${api}\nconst assets=${JSON.stringify(assets)};\nexport default {async fetch(request,env){const url=new URL(request.url);if(url.pathname.startsWith('/api/'))return api(request,env);if(!['GET','HEAD'].includes(request.method))return new Response('',{status:405});const a=assets[url.pathname==='/'?'/index.html':url.pathname];if(!a)return new Response('Not found',{status:404});return new Response(request.method==='HEAD'?null:Uint8Array.from(atob(a.body),c=>c.charCodeAt(0)),{headers:{'Content-Type':a.type,'Cache-Control':'no-cache','X-Content-Type-Options':'nosniff','Referrer-Policy':'same-origin'}});}};`;
mkdirSync('dist/server',{recursive:true});mkdirSync('dist/.openai',{recursive:true});writeFileSync('dist/server/index.js',worker);writeFileSync('dist/.openai/hosting.json',readFileSync('.openai/hosting.json'));
console.log('Worker built');
