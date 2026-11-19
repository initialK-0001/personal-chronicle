// Parse only the explicitly visible, linear, text-only export format.
// Reject unsupported records as a whole to avoid presenting gaps as a transcript.
function visible(message) {
    return message && ['assistant','user'].includes(message.author?.role) &&
        (!message.channel || message.channel === 'final') &&
        (!message.recipient || message.recipient === 'all') &&
        !message.metadata?.is_visually_hidden_from_conversation;
}
function messages(conversation) {
    const map = conversation.mapping;
    if (!map || typeof map !== 'object' || !Object.hasOwn(map,conversation.current_node))
        throw Error('未対応の履歴形式');
    const chain = [], seen = new Set();
    let key = conversation.current_node;
    while (key != null) {
        if (seen.has(key) || !Object.hasOwn(map,key)) throw Error('発言の順序を特定できません');
        seen.add(key);
        const n = map[key];
        if (visible(n.message)) chain.push(n.message);
        key = n.parent;
    }
    if (Object.entries(map).some(([key,n])=>!seen.has(key) && visible(n.message)))
        throw Error('分岐を含む履歴');
    return chain.reverse();
}
export function inspect(conversations, userMarker, replyMarker) {
    if (!Array.isArray(conversations)) throw Error('未対応の履歴形式');
    if (!userMarker.trim() || !replyMarker.trim()) throw Error('双方の原文の一部を入力してください');
    const matches = [];
    let unsupported = 0;
    for (const c of conversations) {
        // Identify a candidate even if the full conversation is not yet importable.
        if (!c?.mapping || typeof c.mapping!=='object') {unsupported++; continue;}
        const rows = Object.values(c.mapping).map(n=>n?.message).filter(visible);
        const contains = (role, marker) => rows.some(m=>m.author.role === role && Array.isArray(m.content?.parts) &&
            m.content.parts.some(p=>typeof p === 'string' && p.includes(marker)));
        if (contains('user',userMarker) && contains('assistant',replyMarker)) matches.push(c);
    }
    return {matches,unsupported};
}
export function batches(c, {kind, speaker}) {
    const thread = c.conversation_id || c.id;
    if (typeof thread !== 'string' || !thread || !speaker.trim()) throw Error('会話IDまたは表示名がありません');
    const result = [];
    let section, previous = null, previousId = null;
    const seen = new Set();
    for (const [seq,m] of messages(c).entries()) {
        if (typeof m.id !== 'string' || !m.id || seen.has(m.id)) throw Error('発言を識別できません');
        seen.add(m.id);
        if (m.content?.content_type !== 'text' || m.content.parts?.length !== 1 ||
            typeof m.content.parts[0] !== 'string' || !m.content.parts[0] ||
            m.metadata?.attachments?.length) throw Error('添付または未対応書式を含む履歴');
        const when = Number.isFinite(m.create_time) ? m.create_time : null;
        if (!section || (previous !== null && when !== null && when-previous>=86400 &&
            (m.author.role === 'user' || previousId?.role !== 'user'))) section = m.id;
        const date = when === null ? null : new Date(Math.floor(when)*1000).toISOString().replace('.000Z','Z');
        const timestamp = Math.round((m.update_time ?? m.create_time ?? 0)*1000000);
        if (!Number.isSafeInteger(timestamp) || timestamp < 0) throw Error('版日時を特定できません');
        const message = {id:m.id, seq, version:timestamp, date, role:m.author.role,
            speaker:m.author.role === 'user' ? '私' : speaker, format:'markdown', text:m.content.parts[0], parent:previousId?.id ?? null};
        const batch = {source:'chatgpt-personal', thread, section, title:c.title || (kind==='dot'?speaker+'との対話':'ChatGPTとの対話'), messages:[message]};
        if (new TextEncoder().encode(JSON.stringify(batch)).length > 1000000 || new TextEncoder().encode(message.text).length>800000)
            throw Error('受信上限を超える発言');
        result.push(batch);
        previous = when;
        previousId = m;
    }
    if (!result.length) throw Error('対象の発言がありません');
    return result;
}
