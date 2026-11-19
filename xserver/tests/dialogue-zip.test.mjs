import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {dialogueJSON} from '../ui/dialogue-zip.js';
import {importZip} from '../ui/dialogue-zip-import.js';

function zip(entries,method='deflate') {
    const source='import json,io,sys,zipfile\nb=io.BytesIO()\nwith zipfile.ZipFile(b,"w",compression=zipfile.ZIP_DEFLATED if sys.argv[1]=="deflate" else zipfile.ZIP_STORED) as z:\n for name,text in json.load(sys.stdin): z.writestr(name,text)\nsys.stdout.buffer.write(b.getvalue())';
    return new Blob([execFileSync('python3',['-c',source,method],{input:JSON.stringify(entries)})]);
}

test('会話JSONだけを取り込み、他のファイルや破損したZIPを送信しない',async()=>{
    const body='[{"発言":"改行\\nと絵文字🍀"}]';
    for (const method of ['store','deflate']) assert.equal(await dialogueJSON(zip([['会話記録.txt','別の文'],['conversations.json',body]],method)),body);
    let sent=false;
    await assert.rejects(importZip(zip([['元の保存.html','原文外']]),()=>{sent=true;}),/見つかりません/);
    const data=new Uint8Array(await zip([['conversations.json',body]],'store').arrayBuffer());
    const i=data.indexOf(91);data[i]^=1;
    await assert.rejects(importZip(new Blob([data]),()=>{sent=true;}),/ZIP/);
    assert.equal(sent,false);
});

test('登録済みの会話も取り込み結果から開け、全発言削除済みの会話は開けない',async()=>{
    const entries=['既存の会話','削除した会話'].map((title,i)=>({
        id:'thread-'+i,title,current_node:'1',mapping:{'1':{message:{
            id:'m'+i,author:{role:'user'},content:{content_type:'text',parts:['試験']}
        }}}
    }));
    const file=zip([['conversations.json',JSON.stringify(entries)]]);
    const links=[];
    const send=async(_,packet)=>packet.title===entries[0].title
        ? {existing:1,deleted:0,added:0,updated:0,sectionId:'a'.repeat(64)}
        : {existing:0,deleted:1,added:0,updated:0,sectionId:null};
    const totals=await importZip(file,send,undefined,(id,title)=>links.push({id,title}));
    assert.deepEqual(links,[{id:'a'.repeat(64),title:entries[0].title}]);
    assert.equal(totals.existing,1);
    assert.equal(totals.deleted,1);
});
