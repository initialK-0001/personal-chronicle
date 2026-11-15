"""秘密情報・Git内部・配備書庫を含めず、試作ソースを持ち出せるZIPにする。"""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
root=Path(__file__).resolve().parent.parent
paths=[root/'README.md',root/'AGENTS.md',root/'.gitignore']
for folder in ['docs','scripts','dist']:
    paths.extend(p for p in (root/folder).rglob('*') if p.is_file() and p.suffix not in ['.zip','.gz','.pyc'])
with ZipFile(root/'dist/source.zip','w',ZIP_DEFLATED) as z:
    for p in sorted(set(paths)):
        z.write(p,'brand-new-tokyo/'+str(p.relative_to(root)))
print('source.zip prepared')
