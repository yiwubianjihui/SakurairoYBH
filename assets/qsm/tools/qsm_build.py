# -*- coding: utf-8 -*-
"""从 master.json 生成 .po / .mo / .l10n.php（沙箱无 msgfmt，MO 手写编译）。"""
import json, os, struct, collections

OUT = r'E:/dsh/_probe_dir/qsm-i18n'
DOMAIN = 'quiz-master-next'
d = json.load(open(os.path.join(OUT, 'qsm-master.json'), encoding='utf-8'))
E = d['entries']

meta = {
    'Project-Id-Version': 'Quiz And Survey Master 11.2.6 (YBH)',
    'MIME-Version': '1.0',
    'Content-Type': 'text/plain; charset=UTF-8',
    'Content-Transfer-Encoding': '8bit',
    'Language': 'zh_CN',
    'Plural-Forms': 'nplurals=1; plural=0;',
    'X-Generator': 'YBH-T25',
}

rows = []
for e in E:
    t = (e.get('draft') or e.get('official') or '').strip('\x00')
    if not t.strip():
        continue                      # 空译文不写入（回退原文）
    if e['ctx']:
        key = e['ctx'] + '\x04' + e['msgid']
    else:
        key = e['msgid']
    rows.append({'key': key, 'ctx': e['ctx'], 'msgid': e['msgid'],
                 'plural': e['plural'], 'msgstr': t})

# 去重（同 key 只留一条）
seen = {}
for r in rows:
    seen.setdefault(r['key'], r)
rows = list(seen.values())
print('写入口条目: %d' % len(rows))

# ---------------- PO ----------------
def poesc(t):
    return t.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')

lines = ['# T25 · Quiz And Survey Master (QSM) v11.2.6 简体中文语言包',
         '# 官方基底 232 条 + YBH 补译；由 qsm_build.py 生成', 'msgid ""', 'msgstr ""']
for k, v in meta.items():
    lines.append('"%s: %s\\n"' % (k, v))
lines.append('')
for r in rows:
    lines.append('msgid "%s"' % poesc(r['msgid']))
    if r['plural']:
        lines.append('msgid_plural "%s"' % poesc(r['plural']))
        lines.append('msgstr[0] "%s"' % poesc(r['msgstr']))
    else:
        lines.append('msgstr "%s"' % poesc(r['msgstr']))
    lines.append('')
po_path = os.path.join(OUT, 'quiz-master-next-zh_CN.po')
open(po_path, 'w', encoding='utf-8', newline='\n').write('\n'.join(lines))

# ---------------- MO ----------------
def build_mo(pairs):
    """pairs: list[(key, value)]  value 内含 \\x00 分隔复数形式"""
    pairs = sorted(pairs, key=lambda x: x[0].encode('utf-8'))
    n = len(pairs)
    o_off = 28
    t_off = 28 + 8 * n
    data_off = t_off + 8 * n
    o_tab = b''; t_tab = b''; data = b''
    for k, v in pairs:
        kb = k.encode('utf-8'); vb = v.encode('utf-8')
        o_tab += struct.pack('<II', len(kb), data_off + len(data))
        data += kb + b'\x00'
        t_tab += struct.pack('<II', len(vb), data_off + len(data))
        data += vb + b'\x00'
    hdr = struct.pack('<IIIIIII', 0x950412de, 0, n, o_off, t_off, 0, data_off + len(data))
    return hdr + o_tab + t_tab + data

header_val = ''.join('%s: %s\n' % (k, v) for k, v in meta.items())
pairs = [('', header_val)]
for r in rows:
    pairs.append((r['key'], r['msgstr']))
mo = build_mo(pairs)
mo_path = os.path.join(OUT, 'quiz-master-next-zh_CN.mo')
open(mo_path, 'wb').write(mo)

# ---------------- l10n.php ----------------
def phpesc(t):
    return t.replace('\\', '\\\\').replace("'", "\\'")

msgs = [("''", "'%s'" % phpesc(header_val))]
for r in rows:
    msgs.append(("'%s'" % phpesc(r['key']), "'%s'" % phpesc(r['msgstr'])))
l10n = ('<?php\nreturn [' +
        "'x-generator'=>'YBH-T25'," +
        "'plural-forms'=>'nplurals=1; plural=0;'," +
        "'project-id-version'=>'Quiz And Survey Master 11.2.6'," +
        "'language'=>'zh_CN'," +
        "'messages'=>[" + ','.join('%s=>%s' % m for m in msgs) + '],\n];\n')
l10n_path = os.path.join(OUT, 'quiz-master-next-zh_CN.l10n.php')
open(l10n_path, 'w', encoding='utf-8', newline='\n').write(l10n)

print('PO   %8d B  %s' % (os.path.getsize(po_path), po_path))
print('MO   %8d B  %s' % (os.path.getsize(mo_path), mo_path))
print('L10N %8d B  %s' % (os.path.getsize(l10n_path), l10n_path))
