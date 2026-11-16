import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../ui/admin.js',import.meta.url),'utf8');
const client=source.slice(source.indexOf('let sessionRefresh='),source.indexOf('\nfunction persist('));
const expired='画面の認証情報が古くなっています 再読み込みしてください';
function setup(responses){const calls=[];const ctx=vm.createContext({fetch:async(path,options)=>{calls.push({path,options});const [status,data]=responses.shift();return {status,ok:status>=200&&status<300,json:async()=>data};}});vm.runInContext("let csrf='old',conflict=false;"+client,ctx);return {calls,run:()=>vm.runInContext("requestAPI('/api/admin/posts',{method:'POST',body:'unchanged'})",ctx)};}
test('古い認証情報だけを更新し新規作成を一度だけ再送',async()=>{const c=setup([[403,{error:expired}],[200,{authenticated:true,csrf:'fresh'}],[201,{post:{id:5}}]]);assert.equal((await c.run()).post.id,5);assert.equal(c.calls.length,3);assert.equal(c.calls[1].path,'/api/session');assert.equal(c.calls[2].options.headers['X-CSRF-Token'],'fresh');assert.equal(c.calls[2].options.body,'unchanged');});
test('ログイン切れでは再送せず入力画面を維持',async()=>{const c=setup([[403,{error:expired}],[200,{authenticated:false}]]);await assert.rejects(c.run(),/別のタブでログイン/);assert.equal(c.calls.length,2);});
test('認証更新後も拒否されたら再送を打ち切る',async()=>{const c=setup([[403,{error:expired}],[200,{authenticated:true,csrf:'fresh'}],[403,{error:expired}]]);await assert.rejects(c.run(),/認証情報/);assert.equal(c.calls.length,3);});
for(const status of [403,409,503])test(`その他の拒否 ${status} は再送しない`,async()=>{const c=setup([[status,{error:'rejected'}]]);await assert.rejects(c.run(),/rejected/);assert.equal(c.calls.length,1);});
