"""Build the self-contained CLI release for the private Xserver application directory."""
from pathlib import Path
import base64
import gzip
import hashlib
import json
import sys

root = Path(__file__).resolve().parents[1]
# Compare with the actually deployed versions, including the site's header change.
files = {
    'src/Dialogues.php': None,
    'ui/dialogue-export.js': None, 'ui/dialogue-sync.js': None, 'ui/dialogue-import.js': None,
    'ui/dialogues.js': None, 'ui/dialogues.html': None,
    'src/Store.php': 'b46e3eea32bf53d707b8696913bffde5495984deaf012d30118c0a8727adde06',
    'src/bootstrap.php': '4f0b792adcb87bfd6e531c4d12b284dea2fe625d4e448a27d17b8d8682539cdb',
    'src/Backup.php': '6b8798a3371eecb6dcccf688f1634350201b5f4f7173abc4605173896c816b4c',
    'src/Web.php': '2f05db2b77b3cdde5573ccb9e64b4e91eab00658ab9afb77320feb9183aa5b64',
    'ui/admin.html': '724a04ac809be78fc5fe4d93ca3724ed48344d86ae368742a3d2c8c1eec4c866',
    'public/index.php': '4599bb765ecde39727c325efa8503a88e0b9d8387b13d9eb665e435f4e6fa2ad'
}
payload = {}
for relative, before in files.items():
    contents = (root / 'xserver' / relative).read_bytes()
    payload[relative] = dict(before=before, after=hashlib.sha256(contents).hexdigest(), data=base64.b64encode(contents).decode())
encoded = base64.b64encode(gzip.compress(json.dumps(payload,separators=(',',':')).encode(),mtime=0)).decode()
template = (root/'scripts/install-dialogues.php').read_text()
assert template.count('__DIALOGUE_BUNDLE__') == 1
Path(sys.argv[1]).write_text(template.replace('__DIALOGUE_BUNDLE__',encoded))
