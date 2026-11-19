import {inspect,batches} from './dialogue-export.js';

export function mountImport(request,refresh,rememberSpeaker) {
    const byId = id=>document.getElementById(id);
    let selected;
    function reset() {
        selected=null;
        byId('importMatched').disabled=true;
        byId('importStatus').textContent='';
    }
    const fields=['exports','userMarker','replyMarker','speaker'];
    for (const id of fields) byId(id).addEventListener('input',reset);
    byId('inspect').onclick=async()=>{
        for (const id of [...fields,'inspect']) byId(id).disabled=true;
        reset();
        try {
            const hits=[];
            let unknownFiles=0;
            let unknown=0;
            if (!byId('exports').files.length) throw Error('展開した会話JSONを選んでください');
            for (const file of byId('exports').files) {
                let data;
                try {data=JSON.parse(await file.text());} catch {unknownFiles++; continue;}
                if (!Array.isArray(data)) {unknownFiles++; continue;}
                const {matches,unsupported}=inspect(data,byId('userMarker').value,byId('replyMarker').value);
                hits.push(...matches);
                unknown+=unsupported;
            }
            if (hits.length!==1) throw Error('両方の文が一致する会話は'+hits.length+'件です。照合する文や対象ファイルを確認してください。未対応形式 '+unknown+'件、判定できないファイル '+unknownFiles+'件。');
            byId('importStatus').textContent='双方の文が同じ会話に見つかりました。';
            selected=batches(hits[0],{kind:'dot',speaker:byId('speaker').value.trim()});
            byId('importStatus').textContent+=' 取り込める本文は'+selected.length+'発言です。';
            byId('importMatched').disabled=false;
        } catch(e) {
            byId('importStatus').textContent+= e instanceof SyntaxError ? ' JSONを読み取れません。' : ' '+e.message;
        } finally {
            for (const id of [...fields,'inspect']) byId(id).disabled=false;
        }
    };
    byId('importMatched').onclick=async()=>{
        if (!selected) return;
        for (const id of [...fields,'inspect','importMatched']) byId(id).disabled=true;
        let completed=0;
        try {
            for (const batch of selected) {
                await request('/api/admin/dialogues/import',batch);
                completed++;
            }
            await rememberSpeaker(selected[0].thread, byId('speaker').value.trim());
            refresh();
        } catch(e) {
            byId('importStatus').textContent='受信確認 '+completed+' / '+selected.length+'。再実行で照合します。'+e.message;
        } finally {
            for (const id of [...fields,'inspect','importMatched']) byId(id).disabled=false;
        }
    };
}
