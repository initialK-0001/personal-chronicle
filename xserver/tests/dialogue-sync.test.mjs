import test from 'node:test';
import assert from 'node:assert/strict';
import {isHistoryFile,importFile} from '../ui/dialogue-sync.js';
const c=(thread,role='assistant',n=1)=>{
    const mapping={};
    for (let i=0;i<n;i++) mapping[i]={parent:i?String(i-1):null,message:{
        id:thread+'-'+i,author:{role,metadata:{display_name:'グリ'}},content:{content_type:'text',parts:['本文\n'+i]},create_time:i*60
    }};
    return {id:thread,mapping,current_node:String(n-1)};
};
const file=(...entries)=>({text:async()=>JSON.stringify(entries)});
test('会話のファイルだけを対象に、原文と表示名を保存し、上限ごとに区切る',async()=>{
    assert.ok(isHistoryFile('conversations-001.json'));assert.ok(isHistoryFile('conversations.json'));
    assert.ok(!isHistoryFile('user.json'));assert.ok(!isHistoryFile('conversations.json.tmp'));
    const calls=[];
    const r=await importFile(file(c('dot','assistant',102),{},c('other')),async(path,p)=>calls.push(p),async()=>null);
    assert.deepEqual(calls.map(p=>p.messages.length),[100,2,1]);
    assert.equal(calls[0].messages[0].speaker,'グリ'); assert.equal(calls[1].messages[1].text,'本文\n101');
    assert.equal(r.skipped.length,1);assert.equal(r.complete,2);assert.equal(r.messages,103);
});
test('元表示名不明と照合済みの名前を区別し、受信失敗は件数に加えない',async()=>{
    const d=c('d');d.mapping[0].message.author.metadata={};
    let who;
    await importFile(file(d),async(_,p)=>{who=p.messages[0].speaker;},async()=>null);
    assert.equal(who,'応答者（表示名不明）');
    await importFile(file(d),async(_,p)=>{who=p.messages[0].speaker;},async()=> 'グリ');
    assert.equal(who,'グリ');
    const result=await importFile(file(d),async()=>{throw Object.assign(Error('競合'),{status:409});},async()=>null);
    assert.equal(result.complete,0);assert.equal(result.messages,0);assert.equal(result.skipped.length,1);
    await assert.rejects(importFile(file(d),async()=>{throw Error('オフライン');},async()=>null),/オフライン/);
});
