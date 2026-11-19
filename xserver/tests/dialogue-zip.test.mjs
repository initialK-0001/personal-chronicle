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
