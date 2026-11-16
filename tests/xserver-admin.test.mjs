import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../xserver/ui/admin.js',import.meta.url),'utf8');
const focus=source.split('\n').find(line=>line.startsWith("window.addEventListener('focus'"));
function fixture(){
 let handler,resolve;const editor={open:false};
 const context={window:{addEventListener:(_,fn)=>handler=fn},ready:true,dirty:false,uploading:false,editVersion:0,queued:Promise.resolve(),openEditor:{busy:false},$:()=>editor,state:{old:true},etag:{old:1},requestAPI:()=>new Promise(r=>resolve=r),render:()=>{}};
 vm.createContext(context);vm.runInContext(focus,context);
 return {context,editor,run:()=>handler(),finish:()=>resolve({state:{fresh:true},etag:{fresh:2}})};
}
test('late focus refresh cannot replace revision after editor opens',async()=>{const f=fixture();const p=f.run();f.editor.open=true;f.finish();await p;assert.deepEqual(f.context.etag,{old:1});});
test('late focus refresh cannot replace revision after a completed edit/save',async()=>{const f=fixture();const p=f.run();f.context.editVersion++;f.context.queued=Promise.resolve();f.finish();await p;assert.deepEqual(f.context.etag,{old:1});});
test('idle focus refresh still updates server state',async()=>{const f=fixture();const p=f.run();f.finish();await p;assert.deepEqual(f.context.etag,{fresh:2});});
test('draft view uses navigation and ordinary rendering',()=>{assert.ok(source.includes("$('#draftButton').onclick=()=>{location.href=basePage+'?view=drafts';};"));assert.ok(source.includes("(view!=='drafts'||!p.date)"));assert.ok(source.includes("view==='drafts'?'下書き'"));});
