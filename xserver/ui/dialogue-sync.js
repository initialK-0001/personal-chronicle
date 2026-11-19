import {batches} from './dialogue-export.js';

const endpoint = '/api/admin/dialogues/import';
const byteLength = value => new TextEncoder().encode(JSON.stringify(value)).length;
export const isHistoryFile = name => /^conversations(?:[-_]\d+)?\.json$/i.test(name);

export function packMessages(rows) {
    const groups = [];
    for (const row of rows) {
        const last = groups.at(-1);
        if (last && last.section === row.section && last.messages.length < 100) {
            const joined = {...last,messages:[...last.messages,...row.messages]};
            if (byteLength(joined) <= 1000000) { groups[groups.length-1] = joined; continue; }
        }
        groups.push(row);
    }
    return groups;
}

export async function importFile(file, send, labelFor, active = () => true, progress = () => {}) {
    const records = JSON.parse(await file.text());
    if (!Array.isArray(records)) throw Error('会話一覧の形式を確認できません。');
    const report = {complete:0,messages:0,skipped:[]};
    for (const c of records) {
        if (!active()) break;
        let packets;
        try {
            if (!c || typeof c !== 'object') throw Error('会話の形式を確認できません');
            const speaker = await labelFor(c.conversation_id || c.id);
            packets = packMessages(batches(c,{kind:'export',speaker:speaker || '応答者（表示名不明）',useSourceNames:!speaker}));
        } catch (e) {
            report.skipped.push({title:typeof c?.title==='string'?c.title:'タイトル不明',reason:e.message});
            continue;
        }
        let failed = false;
        for (const p of packets) {
            if (!active()) return report;
            try { await send(endpoint,p); }
            catch (e) {
                if (![400,409,413].includes(e.status)) throw e;
                report.skipped.push({title:c.title || 'タイトル不明',reason:e.message});
                failed = true;
                break;
            }
            report.messages += p.messages.length;
            progress(report);
        }
        if (!failed) report.complete++;
    }
    return report;
}

function settings() {
    const opened = new Promise((resolve,reject)=>{
        const req = indexedDB.open('chronicle-dialogue-import',1);
        req.onupgradeneeded = ()=>req.result.createObjectStore('settings');
        req.onsuccess = ()=>resolve(req.result);
        req.onerror = ()=>reject(req.error);
    });
    return async (key, value, operation='get')=>{
        const db = await opened;
        return new Promise((resolve,reject)=>{
            const tx = db.transaction('settings',operation==='get'?'readonly':'readwrite');
            const s = tx.objectStore('settings');
            const req = operation==='get'?s.get(key):operation==='delete'?s.delete(key):s.put(value,key);
            tx.oncomplete = ()=>resolve(req.result);
            tx.onabort = ()=>reject(tx.error || Error('この端末の設定を保存できません'));
        });
    };
}

export function mountSync(send,refreshList) {
    const el = id => document.getElementById(id);
    const status = el('syncStatus'), issues = el('syncIssues');
    const setting = settings();
    let folder = null, generation = 0, busy = false;
    const known = new Map();
    function draw(results) {
        issues.replaceChildren();
        for (const [file,report] of results) {
            for (const entry of report.skipped) {
                const li = document.createElement('li');
                li.textContent = file+' ／ '+entry.title+' ／ '+entry.reason;
                issues.append(li);
            }
        }
    }
    async function scan() {
        if (!folder || busy) return;
        const current=folder, token=generation, active=()=>token===generation;
        busy=true;
        try {
            if (await current.queryPermission({mode:'read'}) !== 'granted') {
                if (active()) status.textContent='フォルダーを読む許可が必要です。「接続・再開」を押してください。';
                return;
            }
            const results=[];
            let received=false;
            for await (const [name,handle] of current.entries()) {
                if (!active()) return;
                if (handle.kind!=='file' || !isHistoryFile(name)) continue;
                const f=await handle.getFile();
                const saved=known.get(name);
                if (saved && saved.modified===f.lastModified && saved.size===f.size) {
                    results.push([name,saved.report]);
                    continue;
                }
                status.textContent=name+' を確認中です。';
                const report=await importFile(f,send,id=>setting('speaker:'+id),active,r=>{
                    if (active()) status.textContent=name+' を受信中（確認済み '+r.messages+'発言）';
                });
                if (!active()) return;
                received ||= report.messages>0;
                known.set(name,{modified:f.lastModified,size:f.size,report});
                results.push([name,report]);
            }
            if (!active()) return;
            if (received) await refreshList();
            draw(results);
            const sum=k=>results.reduce((n,[,r])=>n+(k==='skipped'?r.skipped.length:r[k]),0);
            const time=new Date().toLocaleTimeString('ja-JP');
            status.textContent=results.length
                ? time+' 照合済み ／ '+sum('complete')+'会話 ／ 受信確認 '+sum('messages')+'発言 ／ 要確認 '+sum('skipped')+'会話'
                : '接続中です。このフォルダーに会話JSONが届くと取り込みます。';
        } catch(e) {
            if (active()) status.textContent='確認が途中で止まりました。受信済み分は保持します。'+e.message;
        } finally {busy=false;}
    }
    const connect=el('connectFolder'),stop=el('stopFolder');
    async function connectFolder() {
        if (folder && await folder.queryPermission({mode:'read'})!=='granted') {
            if (await folder.requestPermission({mode:'read'})!=='granted') return;
        } else {
            const selected=await window.showDirectoryPicker({id:'chronicle-dialogues',mode:'read'});
            await setting('folder',selected,'put');
            folder=selected;
        }
        generation++; known.clear(); stop.disabled=false; await scan();
    }
    connect.onclick=()=>connectFolder().catch(e=>{if(e.name!=='AbortError') status.textContent=e.message;});
    stop.onclick=async()=>{
        generation++; folder=null; known.clear(); stop.disabled=true; issues.replaceChildren();
        try {await setting('folder',null,'delete');status.textContent='フォルダーの自動取り込みを停止しました。';}
        catch(e){status.textContent=e.message;}
    };
    if (typeof window.showDirectoryPicker!=='function') {
        connect.disabled=true;
        status.textContent='このブラウザーではフォルダーの自動取り込みを利用できません。';
    } else {
        const token=generation;
        setting('folder').then(saved=>{
            if (token===generation && !folder && saved) {folder=saved;stop.disabled=false;scan();}
        }).catch(e=>{status.textContent=e.message;});
        window.setInterval(scan,60000);
        window.addEventListener('focus',scan);
    }
    return async (id,speaker)=>{
        await setting('speaker:'+id,speaker,'put');
        known.clear();
    };
}
