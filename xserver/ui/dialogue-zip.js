// Read only the root conversation file. All other entries stay compressed on the device.
const jsonName = 'conversations.json';
const maxJsonBytes = 64 * 1024 * 1024;
const broken = () => Error('ZIPを正しく読み込めませんでした。保存したファイルを確認してください。');

export async function dialogueJSON(file) {
    const tailStart = Math.max(0, file.size - 65557);
    const tail = new DataView(await file.slice(tailStart).arrayBuffer());
    let end = tail.byteLength - 22;
    while (end >= 0 && !(tail.getUint32(end,true) === 0x06054b50 &&
        end + 22 + tail.getUint16(end + 20,true) === tail.byteLength)) end--;
    if (end < 0) throw broken();
    const count = tail.getUint16(end+10,true);
    const bytes = tail.getUint32(end+12,true);
    const start = tail.getUint32(end+16,true);
    if (tail.getUint32(end+4,true) !== 0 || count !== tail.getUint16(end+8,true) ||
        bytes > 8*1024*1024 || start + bytes !== tailStart + end) throw broken();
    const dir = new DataView(await file.slice(start,start+bytes).arrayBuffer());
    const decoder = new TextDecoder();
    let entry, cursor = 0;
    for (let i=0; i<count; i++) {
        if (cursor+46 > bytes || dir.getUint32(cursor,true) !== 0x02014b50) throw broken();
        const size = dir.getUint16(cursor+28,true);
        const next = cursor+46+size+dir.getUint16(cursor+30,true)+dir.getUint16(cursor+32,true);
        if (next>bytes) throw broken();
        const name = decoder.decode(new Uint8Array(dir.buffer,cursor+46,size));
        if (name === jsonName) {
            if (entry) throw Error('会話JSONがZIP内に重複しています。');
            entry = {flags:dir.getUint16(cursor+8,true), method:dir.getUint16(cursor+10,true),
                crc:dir.getUint32(cursor+16,true), packed:dir.getUint32(cursor+20,true),
                length:dir.getUint32(cursor+24,true), offset:dir.getUint32(cursor+42,true)};
        }
        cursor = next;
    }
    if (cursor !== bytes) throw broken();
    if (!entry) throw Error('会話記録用ZIPを選んでください。conversations.json が見つかりません。');
    const {flags,method,crc,packed,length,offset} = entry;
    if (flags & 1 || ![0,8].includes(method)) throw Error('暗号化または未対応の圧縮方式です。');
    if (length>maxJsonBytes || packed>maxJsonBytes+65536) throw Error('会話JSONは64 MiBまで読み込めます。');
    const header = new DataView(await file.slice(offset,offset+30).arrayBuffer());
    if (header.byteLength<30 || header.getUint32(0,true)!==0x04034b50 ||
        flags!==header.getUint16(6,true) || method!==header.getUint16(8,true)) throw broken();
    const nameEnd=offset+30+header.getUint16(26,true);
    if (decoder.decode(await file.slice(offset+30,nameEnd).arrayBuffer())!==jsonName) throw broken();
    const dataStart=nameEnd+header.getUint16(28,true);
    if (dataStart+packed>start || (!method && packed!==length)) throw broken();
    let stream=file.slice(dataStart,dataStart+packed).stream();
    if (method===8) stream=stream.pipeThrough(new DecompressionStream('deflate-raw'));
    const reader=stream.getReader(), output=new Uint8Array(length);
    let position=0, sum=0xffffffff;
    const crcTable=new Uint32Array(256);
    for (let n=0;n<256;n++) {
        let c=n;
        for(let bit=0;bit<8;bit++) c=(c>>>1)^((c&1)?0xedb88320:0);
        crcTable[n]=c;
    }
    try {
        while (true) {
            const {value,done}=await reader.read();
            if (done) break;
            if (position+value.length>length) throw broken();
            output.set(value,position);
            position+=value.length;
            for(const octet of value) sum=(sum>>>8)^crcTable[(sum^octet)&255];
        }
    } catch {await reader.cancel().catch(()=>{});throw broken();}
    if (position!==length || ((sum^0xffffffff)>>>0)!==crc) throw broken();
    try {return new TextDecoder('utf-8',{fatal:true}).decode(output);}
    catch {throw broken();}
}
