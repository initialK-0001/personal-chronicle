import {dialogueJSON} from './dialogue-zip.js';
import {batches} from './dialogue-export.js';
import {packMessages} from './dialogue-sync.js';

function parseSections(conversation) {
    const rows=batches(conversation,{kind:'export',speaker:'応答者（表示名不明）',useSourceNames:true});
    const sections=new Map();
    for (const row of rows) {
        if (!sections.has(row.section)) sections.set(row.section,[]);
        sections.get(row.section).push(row);
    }
    return [...sections.values()];
}

export async function importZip(file,request,progress=()=>{},onSection=()=>{}) {
    let conversations;
    const json=await dialogueJSON(file);
    try {conversations=JSON.parse(json);} catch {throw Error('ZIP内の会話JSONを解析できません。');}
    if (!Array.isArray(conversations)||!conversations.length) throw Error('ZIP内に会話がありません。');
    const sections=conversations.flatMap(parseSections);
    const totals={added:0,updated:0,existing:0,deleted:0};
    for (const rows of sections) {
        const {thread}=rows[0];
        let anchor=null;
        if (/^dot-html-[a-f0-9]{64}$/.test(thread)) {
            for (let i=0;i<rows.length && !anchor;i+=250) {
                const ids=rows.slice(i,i+250).map(row=>row.messages[0].id);
                const found=await request('/api/admin/dialogues/capture-anchor',{thread,ids});
                if (found.anchor!=null) {
                    if (!Number.isInteger(found.anchor)||found.anchor<0||found.anchor>=ids.length) throw Error('照合結果が不正です。');
                    const {id,seq}=rows[i+found.anchor].messages[0];
                    anchor={id,seq};
                }
            }
        }
        // Include the anchor before packing so batch-size limits count it too.
        if (anchor) for (const row of rows) row.captureAnchor=anchor;
        for (const packet of packMessages(rows)) {
            const result=await request('/api/admin/dialogues/import',packet);
            for (const key of Object.keys(totals)) {
                if (!Number.isInteger(result[key]) || result[key]<0) throw Error('受信件数を確認できません。');
                totals[key]+=result[key];
            }
            if (result.sectionId) onSection(result.sectionId,packet.title);
            progress({...totals});
        }
    }
    return totals;
}

export function mountZipImport(request,refresh,readLink) {
    const section=document.getElementById('zipImport'),form=document.getElementById('zipForm');
    const input=document.getElementById('zipFile'),status=document.getElementById('zipStatus');
    const records=document.getElementById('zipRecords');
    section.hidden=false;
    const summary=r=>'新規 '+r.added+'件 ／ 更新 '+r.updated+'件 ／ 登録済み '+r.existing+'件'+(r.deleted?' ／ 削除済み '+r.deleted+'件':'');
    form.onsubmit=async event=>{
        event.preventDefault();
        const file=input.files[0],button=form.querySelector('button');
        if (!file || button.disabled) return;
        input.disabled=button.disabled=true;
        status.textContent='ZIPを確認しています。';
        records.replaceChildren();
        const seen=new Set();
        function showRecord(id,title) {
            if (seen.has(id)) return;
            seen.add(id);
            const row=document.createElement('p');
            row.append(readLink(title,id));
            records.append(row);
        }
        let confirmed='';
        try {
            const totals=await importZip(file,request,r=>{confirmed=summary(r);status.textContent='取込中　'+confirmed;},showRecord);
            status.textContent='取り込み完了　'+summary(totals);
        } catch(e) {
            status.textContent='取り込みを完了できませんでした。'+e.message+(confirmed?'　確認済み　'+confirmed:'');
        } finally {
            try {if(confirmed) await refresh();}
            catch {status.textContent+='　一覧の更新には再読み込みしてください。';}
            input.disabled=button.disabled=false;
        }
    };
}
