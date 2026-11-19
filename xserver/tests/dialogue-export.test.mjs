import test from 'node:test';
import assert from 'node:assert/strict';
import {batches,inspect} from '../ui/dialogue-export.js';
test('照合、原文、dotからの発言、24時間の再開、非表示発言の除外',()=>{
    const mapping = {};
    let last = null;
    function add(id,role,text,time,metadata={}) {
        mapping[id]={parent:last,message:{id,author:{role},content:{content_type:'text',parts:[text]},create_time:time,metadata}};
        last=id;
    }
    add('initial','assistant','最初の声掛け',10);
    add('q','user','私の文\n  改行',11);
    add('hidden','assistant','内部',12,{is_visually_hidden_from_conversation:true});
    add('a','assistant','返事「そのまま」',13);
    add('b','user','同じ会話を再開',86413);
    const c={id:'t',mapping,current_node:last};
    assert.deepEqual(inspect([c],'私の文','返事').matches,[c]);
    assert.deepEqual(inspect([c],'私の文','内部').matches,[]);
    const opts={kind:'dot',speaker:'グリ'};
    const all=batches(c,opts);
    assert.deepEqual(all.map(b=>b.section),['initial','initial','initial','b']);
    assert.deepEqual(all.map(b=>b.messages[0].speaker),['グリ','私','グリ','私']);
    assert.equal(all[1].messages[0].text,'私の文\n  改行');
    assert.throws(()=>batches({...c,current_node:'a'},opts),/分岐/);
    mapping.b.message.metadata.attachments=['file'];
    assert.throws(()=>batches(c,opts),/添付/);
});
