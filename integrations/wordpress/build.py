"""Build a deterministic installable WordPress ZIP, including six public UI catalogs."""
import json
from pathlib import Path
from zipfile import ZipFile, ZipInfo, ZIP_DEFLATED
root=Path(__file__).resolve().parents[2]
plugin=Path(__file__).resolve().parent/'humixai'
keys=json.loads((plugin.parent/'ui-keys.json').read_text(encoding='utf-8'))
(plugin/'lang').mkdir(exist_ok=True)
for locale in ('en','bn','hi','ur','ar','ne'):
    catalog=json.loads((root/'lang'/f'{locale}.json').read_text(encoding='utf-8'))
    missing=set(keys)-catalog.keys()
    if missing:raise SystemExit(f'{locale}: missing {sorted(missing)}')
    (plugin/'lang'/f'{locale}.json').write_text(json.dumps({k:catalog[k] for k in keys},ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
output=root/'output'/'humixai-wordpress.zip';output.parent.mkdir(exist_ok=True)
with ZipFile(output,'w',ZIP_DEFLATED) as archive:
    for path in sorted(plugin.rglob('*')):
        if path.is_file():
            info=ZipInfo('humixai/'+path.relative_to(plugin).as_posix(),(2026,11,12,0,0,0));info.compress_type=ZIP_DEFLATED
            archive.writestr(info,path.read_bytes())
print(output)
