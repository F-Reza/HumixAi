"""Extract public translation keys. Run before translate-catalog.php."""
import json
import re
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parent.parent
keys = set(json.loads((root / 'public/assets/i18n-keys.json').read_text(encoding='utf-8')))
paths = list((root / 'resources/views').rglob('*.php')) + list((root / 'app').rglob('*.php')) + list((root / 'public/assets').glob('*.js'))
paths += list((root / 'integrations/wordpress/humixai').glob('*.js'))
for path in paths:
    source = path.read_text(encoding='utf-8')
    for match in re.finditer(r'''(?:__|\bt|\bfield|\bbutton|\bedit|\bstatus|\bselect)\(\s*(['"])((?:\\.|(?!\1).)*?)\1''', source):
        keys.add(match.group(2).replace("\\'", "'").replace('\\"', '"'))
for path in (root / 'integrations/wordpress/humixai').glob('*.js'):
    keys.update(re.findall(r"el\('[^']+',\s*'([^']+)'\)", path.read_text(encoding='utf-8')))
php = "foreach (['validation','auth','passwords','pagination'] as $group) { $a = require 'vendor/laravel/framework/src/Illuminate/Translation/lang/en/'.$group.'.php'; array_walk_recursive($a, function($v) use (&$out) { if(is_string($v)) $out[] = $v; }); } echo json_encode($out);"
keys.update(json.loads(subprocess.check_output(['php', '-r', php], cwd=root)))
keys.update(['Overview','Users','Jobs','System settings','AI providers','Audit log','Enabled','Disabled','Configured','Not configured','Active','Suspended','Administrator','Super administrator','Allow registration','Allow URL import','Daily word limit','Maximum batch size','Maximum characters','Job retention hours','user','admin','super_admin','queued','running','completed','partial','failed','cancelled','detect','humanize','combo','database','environment','none','Name','Email','Role','Status','Actions','ID','User','Action','Stage','Setting','Value','Provider','Source','Time','Target','Changed fields','Total','Suspended accounts','Documents','Teams','Used words','Reserved words','Failed queue jobs','Provider probabilities are estimates, not proof of authorship. Language-specific accuracy has not been evaluated by HumixAi.'])
keys.update(['scheduled', 'paused', 'limited', 'initial', 'typing', 'paste', 'cut', 'drop', 'undo', 'redo', 'mixed', 'unknown'])
keys.update(json.loads((root / 'integrations/wordpress/ui-keys.json').read_text(encoding='utf-8')))
keys = {key for key in keys if '{{' not in key and '}}' not in key}
(root / 'lang/en.json').write_text(json.dumps({key:key for key in sorted(keys)}, ensure_ascii=False, indent=2)+'\n', encoding='utf-8')
print(f'{len(keys)} public translation keys')
