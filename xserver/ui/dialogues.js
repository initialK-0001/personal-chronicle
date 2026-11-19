import {mountImport} from './dialogue-import.js';
const element = id => document.getElementById(id);
const params = new URLSearchParams(location.search);
const id = params.get('id');
const page = Number(params.get('page') || 0);
const path = '/api/admin/dialogues' + (id ? '/' + encodeURIComponent(id) : '');
let csrf, record;

function node(tag, text) {
    const n = document.createElement(tag);
    n.textContent = text;
    return n;
}
function link(text, id, page) {
    const a = node('a', text);
    const query = new URLSearchParams(id ? '' : location.search);
    query.set('page',page);
    if (id) query.set('id',id);
    a.href = '/admin/dialogues?' + query;
    return a;
}
async function request(url, data) {
    const options = data ? {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body:JSON.stringify(data)} : {};
    const response = await fetch(url, options);
    const result = await response.json();
    if (!response.ok) throw Error(result.error || '読み込みに失敗しました');
    return result;
}
async function start() {
    const session = await request('/api/session');
    if (!session.authenticated) {location.replace('/login'); return;}
    csrf = session.csrf;
    const query = new URLSearchParams(params);
    query.delete('id');
    const result = await request(path + '?' + query);
    let more;
    if (id) {
        record = result;
        element('record').hidden = false;
        element('title').textContent = record.title;
        element('publicTitle').value = record.publicTitle ?? record.title;
        element('visibility').textContent = record.public ? '選択した発言を公開中' : '非公開';
        element('unpublish').hidden = !record.public;
        element('dateLabel').hidden = record.public || record.messages.every(m => m.date);
        for (const msg of record.messages) {
            const row = node('article','');
            const select = document.createElement('input');
            select.type = 'checkbox';
            select.value = msg.id;
            const label = node('label','');
            label.append(select, msg.speaker + '　' + (msg.date ? new Date(msg.date).toLocaleString('ja-JP',{timeZone:'Asia/Tokyo'}) : '日時不明'));
            const body = node('p',msg.text);
            body.style.whiteSpace = 'pre-wrap';
            body.style.overflowWrap = 'anywhere';
            row.append(label,node('small', msg.currentPublic ? '公開中' : msg.released ? '変更は非公開' : '非公開'),body);
            element('messages').append(row);
        }
        more = record.more;
    } else {
        element('historyImport').hidden = false;
        element('filters').hidden=false;
        for (const key of ['month','state','q']) element('filters').elements[key].value=params.get(key)||'';
        mountImport(request,()=>location.reload());
        for (const s of result.sections) {
            const p = node('p','');
            p.append(link(s.title,s.id,0), s.public_title === null ? '　非公開' : '　一部または全部を公開中');
            element('list').append(p);
        }
        if (!result.sections.length) element('status').textContent = 'このページに記録はありません。';
        more = result.more;
    }
    if (page > 0) element('pages').append(link('前のページ',id,page-1),'　');
    if (more) element('pages').append(link('次のページ',id,page+1));
}
async function change(action) {
    const payload = {action, revision:record.revision};
    if (action === 'release') {
        payload.messages = Array.from(document.querySelectorAll('#messages input:checked'), n => n.value);
        payload.title = element('publicTitle').value;
        if (element('date').value) payload.date = new Date(element('date').value+'T00:00:00+09:00').toISOString().replace('.000Z','Z');
        if (!payload.messages.length) throw Error('公開する発言を選んでください');
        if (!confirm('「'+ payload.title +'」に選択した'+payload.messages.length+'件の発言を公開します。公開済みの他の発言も引き続き表示します。')) return;
    }
    if (action === 'delete' && !confirm('「'+record.title+'」の全ページの発言と公開版を削除しますか？')) return;
    await request(path,payload);
    if (action === 'delete') location.replace('/admin/dialogues');
    else location.reload();
}
function report(e) {element('status').textContent = e.message;}
for (const [button,action] of [['release','release'],['unpublish','unpublish'],['erase','delete']]) {
    element(button).onclick = () => change(action).catch(report);
}
start().catch(report);
