import {readFileSync,writeFileSync,mkdirSync,readdirSync} from 'node:fs';
import path from 'node:path';
const assets={};
function walk(dir){for(const e of readdirSync(dir,{withFileTypes:true})){if(e.name==='server'||e.name==='.openai')continue;const p=path.join(dir,e.name);if(e.isDirectory())walk(p);else {const ext=path.extname(p);assets['/'+p.slice(5)]={type:({'.html':'text/html; charset=utf-8','.css':'text/css','.js':'text/javascript','.png':'image/png','.jpg':'image/jpeg','.zip':'application/zip','.md':'text/plain; charset=utf-8'})[ext]||'application/octet-stream',body:readFileSync(p).toString('base64')};}}}walk('dist');
const sources=['server/access.mjs','server/api.mjs','server/routes.mjs'].map(p=>readFileSync(p,'utf8').replace(/^import .*;\n/gm,'').replaceAll('export ',''));
const worker=sources.join('\n')+'\nconst assets='+JSON.stringify(assets)+';\nexport default {fetch(request,env){return handle(request,env,assets)}};';
mkdirSync('dist/server',{recursive:true});mkdirSync('dist/.openai',{recursive:true});writeFileSync('dist/server/index.js',worker);writeFileSync('dist/.openai/hosting.json',readFileSync('.openai/hosting.json'));
console.log('Worker built');
