"""Build a local-only, single-file checker from the same parser used by admin."""
from pathlib import Path
import sys

root = Path(__file__).resolve().parents[1]
parser = (root / 'xserver/ui/dialogue-export.js').read_text().replace('export function ', 'function ')
page = """<!doctype html>
<html lang="ja"><head><meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; connect-src 'none'; form-action 'none'">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>グリさんの会話照合</title>
<style>body{font:1rem/1.7 system-ui,sans-serif;background:#fafafa;color:#24292f;margin:3rem auto;max-width:40rem;padding:0 1rem}label{display:block;margin:1rem 0}input{display:block;max-width:100%;font:inherit}input[type=text]{width:96%}button{font:inherit;padding:.4rem .9rem}output{display:block;margin:1rem 0}</style>
</head><body><h1>グリさんの会話照合</h1>
<p>ChatGPTの書き出しZIPを展開し、会話のJSONファイルを選んでください。照合はこの端末内で行います。</p>
<label>会話JSON（複数可）<input id="files" type="file" multiple accept=".json"></label>
<label>自分の発言の一部<input id="user" type="text" autocomplete="off"></label>
<label>グリさんの返事の一部<input id="reply" type="text" autocomplete="off"></label>
<button id="run">照合する</button><output id="result" role="status"></output>
<script>
"""
script = """
const el=id=>document.getElementById(id);
el('run').onclick=async()=>{
    el('result').textContent='';
    const files=[...el('files').files], user=el('user').value, reply=el('reply').value;
    if(!files.length || !user.trim() || !reply.trim()){el('result').textContent='ファイルと双方の発言の一部を指定してください。';return;}
    el('run').disabled=true;
    let found=0, supported=0, unreadable=0, unknown=0;
    for(const f of files){
        try {
            const data=JSON.parse(await f.text());
            const report=inspect(data,user,reply);
            unknown+=report.unsupported;
            for(const c of report.matches){
                found++;
                try{batches(c,{kind:'dot',speaker:'相手'});supported++;}catch{}
            }
        } catch{unreadable++;}
    }
    el('result').textContent='双方の文が同じ会話に見つかった件数 '+found+'件。現在の取り込み形式に対応 '+supported+'件。判定できないファイル '+unreadable+'件、形式未対応の会話 '+unknown+'件。';
    el('run').disabled=false;
};
</script></body></html>
"""
Path(sys.argv[1]).write_text(page + parser + script)
