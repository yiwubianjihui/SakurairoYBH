# -*- coding: utf-8 -*-
"""生成单文件网页翻译工作台（数据内嵌，可离线使用）。"""
import json, os, re, html

OUT = r'E:/dsh/_probe_dir/qsm-i18n'
WORK = os.path.join(OUT, 'workbench')
os.makedirs(WORK, exist_ok=True)

d = json.load(open(os.path.join(OUT, 'qsm-master.json'), encoding='utf-8'))
E = d['entries']

PROMO = re.compile(r'(upgrade|pro\b|premium|buy|pricing|addon|add-on|purchase|subscription|license|bundle|paid)', re.I)
PH = re.compile(r'%(?:\d+\$)?[sd]')

items = []
for i, e in enumerate(E):
    src = e['msgid']
    draft = (e.get('draft') or e.get('official') or '')
    file0 = e['refs'][0].split(':')[0] if e['refs'] else ''
    mod = file0.split('/')[-1]
    flags = []
    if e['official'].strip():
        flags.append('official')
    if e['plural']:
        flags.append('plural')
    if PH.search(src) or (e['plural'] and PH.search(e['plural'])):
        flags.append('placeholder')
    if PROMO.search(src):
        flags.append('promo')
    if len(src) <= 12:
        flags.append('short')
    if src.strip() != src:
        flags.append('spaced')
    items.append({
        'i': i, 'src': src, 'plural': e['plural'] or '', 'ctx': e['ctx'] or '',
        'tr': draft, 'mod': mod, 'ref': e['refs'][0] if e['refs'] else '',
        'nref': len(e['refs']), 'flags': flags,
    })

print('工作台条目: %d' % len(items))

data_js = json.dumps(items, ensure_ascii=False).replace('</', '<\\/')

HTML = r'''<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>QSM 汉化工作台 · Quiz And Survey Master 11.2.6</title>
<style>
:root{
  --bg:#f5f6f8; --panel:#fff; --line:#e3e6ec; --line2:#eef0f4;
  --tx:#1f2430; --tx2:#5b6472; --tx3:#8b93a3;
  --ac:#2563eb; --ac-soft:#eaf0ff; --ok:#0f9d58; --ok-soft:#e7f6ee;
  --warn:#c77700; --warn-soft:#fff4e0; --danger:#d33; --promo:#9333ea; --promo-soft:#f6ecff;
  --radius:10px;
}
*{box-sizing:border-box}
html,body{margin:0;height:100%}
body{background:var(--bg);color:var(--tx);font:14px/1.6 -apple-system,"Segoe UI","Microsoft YaHei",sans-serif}
a{color:var(--ac)}
header{position:sticky;top:0;z-index:20;background:var(--panel);border-bottom:1px solid var(--line)}
.hd{display:flex;align-items:center;gap:16px;padding:12px 20px}
.hd h1{margin:0;font-size:16px;font-weight:650;letter-spacing:.2px;white-space:nowrap}
.hd .sub{color:var(--tx3);font-size:12.5px}
.hd .spacer{flex:1}
.stat{display:flex;gap:18px;align-items:center}
.stat b{font-size:18px;font-weight:660;font-variant-numeric:tabular-nums}
.stat span{display:block;font-size:11.5px;color:var(--tx3);line-height:1.3}
.bar{height:6px;background:var(--line2);border-radius:99px;overflow:hidden;margin:0 20px 10px}
.bar>i{display:block;height:100%;background:linear-gradient(90deg,#22c55e,#0f9d58);width:0;transition:width .25s}
.tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center;padding:0 20px 12px}
input[type=search],select{height:34px;padding:0 10px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--tx);font-size:13px;outline:none}
input[type=search]{min-width:260px}
input[type=search]:focus,select:focus{border-color:var(--ac);box-shadow:0 0 0 3px var(--ac-soft)}
button{height:34px;padding:0 13px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--tx);font-size:13px;cursor:pointer;white-space:nowrap}
button:hover{border-color:#c9d0db;background:#fafbfc}
button.primary{background:var(--ac);border-color:var(--ac);color:#fff}
button.primary:hover{background:#1d55cf}
button.ghost{background:transparent}
.seg{display:flex;border:1px solid var(--line);border-radius:8px;overflow:hidden}
.seg button{border:0;border-radius:0;height:32px;background:#fff}
.seg button+button{border-left:1px solid var(--line)}
.seg button.on{background:var(--ac-soft);color:var(--ac);font-weight:600}
main{padding:0 20px 80px;max-width:1500px;margin:0 auto}
.row{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:12px 14px;margin-top:10px;display:grid;grid-template-columns:44px 1fr 300px;gap:14px;align-items:start}
.row.done{border-color:#c9ecd9;background:#fbfefc}
.row.edited{border-color:#cddcff;background:#fbfcff}
.row:hover{border-color:#cfd6e0}
.no{color:var(--tx3);font-size:12px;font-variant-numeric:tabular-nums;padding-top:6px}
.src{font-size:13.5px;color:var(--tx);word-break:break-word}
.src .meta{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:5px;align-items:center}
.chip{font-size:11px;padding:1px 7px;border-radius:99px;border:1px solid var(--line);color:var(--tx2);background:#fafbfc}
.chip.official{background:var(--ok-soft);border-color:#c2e6d2;color:#0b7a44}
.chip.placeholder{background:var(--warn-soft);border-color:#f0dcb4;color:var(--warn)}
.chip.promo{background:var(--promo-soft);border-color:#e2cdfa;color:var(--promo)}
.chip.short{background:#eef2f7;color:var(--tx2)}
.chip.spaced{background:#fff0f0;border-color:#f6cfcf;color:#c0392b}
.chip.file{font-family:ui-monospace,Consolas,monospace;color:var(--tx3);background:#f6f7f9}
.pl{color:var(--tx3);font-size:12px;margin-top:4px;display:block}
.pl b{color:var(--tx2);font-weight:600}
.right{display:flex;flex-direction:column;gap:8px}
.right textarea{width:100%;min-height:64px;padding:9px 10px;border:1px solid var(--line);border-radius:8px;font:13.5px/1.55 inherit;resize:vertical;outline:none;color:var(--tx);background:#fff}
.right textarea:focus{border-color:var(--ac);box-shadow:0 0 0 3px var(--ac-soft)}
.right textarea.ok{border-color:#bfe3cf;background:#fbfffc}
.acts{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.acts button{height:28px;font-size:12px;padding:0 9px}
.acts .saved{font-size:11.5px;color:var(--ok);margin-left:auto}
.empty{text-align:center;color:var(--tx3);padding:50px 0}
.pager{display:flex;gap:8px;align-items:center;justify-content:center;padding:20px 0}
.pager span{color:var(--tx2);font-size:13px}
.gloss{margin-top:12px;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:12px 14px}
.gloss summary{cursor:pointer;font-weight:600;font-size:13.5px}
.gloss table{margin-top:10px;border-collapse:collapse;width:100%;font-size:13px}
.gloss td{border-bottom:1px solid var(--line2);padding:5px 8px}
.gloss td:first-child{color:var(--tx3);font-family:ui-monospace,Consolas,monospace;width:38%}
.toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%) translateY(20px);background:#1f2430;color:#fff;padding:9px 16px;border-radius:8px;font-size:13px;opacity:0;transition:.25s;pointer-events:none;z-index:99}
.toast.on{opacity:1;transform:translateX(-50%) translateY(0)}
@media(max-width:1000px){.row{grid-template-columns:1fr}.no{padding-top:0}.right{margin-top:2px}}
</style>
</head>
<body>
<header>
  <div class="hd">
    <h1>QSM 汉化工作台</h1>
    <div class="sub">Quiz And Survey Master <b>11.2.6</b> · 简体中文</div>
    <div class="spacer"></div>
    <div class="stat">
      <div><b id="s-total">0</b><span>条目总数</span></div>
      <div><b id="s-done">0</b><span>已确认</span></div>
      <div><b id="s-edit">0</b><span>已修改</span></div>
      <div><b id="s-pct">0%</b><span>完成度</span></div>
    </div>
  </div>
  <div class="bar"><i id="bar"></i></div>
  <div class="tools">
    <input type="search" id="q" placeholder="搜索英文原文 / 中文译文…">
    <select id="f-flag">
      <option value="">全部条目</option>
      <option value="todo">未确认</option>
      <option value="done">已确认</option>
      <option value="official">官方已有译文</option>
      <option value="placeholder">含占位符 %s/%d</option>
      <option value="plural">复数形式</option>
      <option value="promo">付费/推广类</option>
      <option value="short">短词（易歧义）</option>
      <option value="spaced">首尾含空格</option>
    </select>
    <select id="f-mod"><option value="">全部模块</option></select>
    <div class="seg" id="seg-size">
      <button data-n="30">30/页</button>
      <button data-n="60" class="on">60/页</button>
      <button data-n="120">120/页</button>
      <button data-n="0">全部</button>
    </div>
    <div class="spacer" style="flex:1"></div>
    <button id="btn-confirm-page">本页全部确认</button>
    <button id="btn-export-json" class="primary">导出 JSON</button>
    <button id="btn-export-po">导出 .po</button>
    <button id="btn-export-csv">导出 CSV</button>
    <button id="btn-import">导入</button>
    <button id="btn-reset" class="ghost">清除本地修改</button>
  </div>
</header>
<main>
  <div id="list"></div>
  <div class="pager" id="pager"></div>

  <details class="gloss">
    <summary>术语表（统一译法，供参考）</summary>
    <table>
      <tr><td>Quiz / Survey</td><td>测验 / 问卷</td></tr>
      <tr><td>Question / Answer</td><td>题目 / 答案</td></tr>
      <tr><td>Result / Score / Points</td><td>结果 / 得分 / 分数</td></tr>
      <tr><td>Category</td><td>分类</td></tr>
      <tr><td>Template</td><td>模板</td></tr>
      <tr><td>Addon / Add-on</td><td>附加组件</td></tr>
      <tr><td>Bundle</td><td>套装</td></tr>
      <tr><td>Theme / Appearance</td><td>主题 / 外观</td></tr>
      <tr><td>Dashboard</td><td>仪表盘</td></tr>
      <tr><td>Migration</td><td>迁移</td></tr>
      <tr><td>Response / Submission</td><td>回答 / 提交</td></tr>
      <tr><td>Shortcode</td><td>短代码</td></tr>
      <tr><td>Webhook</td><td>Webhook（不译）</td></tr>
      <tr><td>Proctor / Proctoring</td><td>监考</td></tr>
      <tr><td>Placeholder 规则</td><td><code>%s</code>、<code>%d</code>、<code>%1$s</code> 必须原样保留；中文单复数同形</td></tr>
    </table>
  </details>
</main>
<div class="toast" id="toast"></div>

<script>
const DATA = __DATA__;
const LSKEY = 'ybh-qsm-i18n-v1';
let state = {};          // i -> {t: 译文, s: 状态}
let page = 1, size = 60;
try { state = JSON.parse(localStorage.getItem(LSKEY) || '{}'); } catch(e) { state = {}; }

const $ = s => document.querySelector(s);
const toast = (m) => { const t = $('#toast'); t.textContent = m; t.classList.add('on'); clearTimeout(t._h); t._h = setTimeout(()=>t.classList.remove('on'), 1800); };

function tr(i){ const s = state[i]; return s && typeof s.t === 'string' ? s.t : DATA[i].tr; }
function st(i){ const s = state[i]; return s && s.s ? s.s : ''; }
function isDone(i){ return st(i) === 'ok'; }
function isEdited(i){ return state[i] && state[i].t !== undefined && state[i].t !== DATA[i].tr; }

function save(){ localStorage.setItem(LSKEY, JSON.stringify(state)); refreshStat(); }

function refreshStat(){
  const total = DATA.length;
  let done = 0, edit = 0;
  for (let i=0;i<total;i++){ if (isDone(i)) done++; if (isEdited(i)) edit++; }
  $('#s-total').textContent = total;
  $('#s-done').textContent = done;
  $('#s-edit').textContent = edit;
  const pct = total ? (done/total*100) : 0;
  $('#s-pct').textContent = pct.toFixed(1) + '%';
  $('#bar').style.width = pct + '%';
}

const MODS = [...new Set(DATA.map(d=>d.mod))].sort();
MODS.forEach(m => { const o = document.createElement('option'); o.value = m; o.textContent = m; $('#f-mod').appendChild(o); });

function filtered(){
  const q = $('#q').value.trim().toLowerCase();
  const ff = $('#f-flag').value, fm = $('#f-mod').value;
  return DATA.filter(d => {
    if (fm && d.mod !== fm) return false;
    if (q && !(d.src.toLowerCase().includes(q) || tr(d.i).toLowerCase().includes(q))) return false;
    if (ff === 'todo' && isDone(d.i)) return false;
    if (ff === 'done' && !isDone(d.i)) return false;
    if (ff && ff !== 'todo' && ff !== 'done' && !d.flags.includes(ff)) return false;
    return true;
  });
}

function esc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

const FLAGNAME = {official:'官方译文', placeholder:'占位符', plural:'复数', promo:'付费/推广', short:'短词', spaced:'含空格'};

function render(){
  const rows = filtered();
  const total = rows.length;
  const pages = size ? Math.max(1, Math.ceil(total/size)) : 1;
  if (page > pages) page = pages;
  const slice = size ? rows.slice((page-1)*size, page*size) : rows;
  const box = $('#list');
  if (!total){ box.innerHTML = '<div class="empty">没有符合条件的条目</div>'; $('#pager').innerHTML=''; return; }
  box.innerHTML = slice.map(d => {
    const t = tr(d.i), cls = isDone(d.i) ? 'done' : (isEdited(d.i) ? 'edited' : '');
    const chips = d.flags.map(f => '<span class="chip '+f+'">'+FLAGNAME[f]+'</span>').join('')
      + '<span class="chip file">'+esc(d.ref)+'</span>'
      + (d.nref>1 ? '<span class="chip">另 '+ (d.nref-1) +' 处</span>' : '');
    const plural = d.plural ? '<span class="pl">复数形式：<b>'+esc(d.plural)+'</b></span>' : '';
    const ctx = d.ctx ? '<span class="pl">上下文：<b>'+esc(d.ctx)+'</b></span>' : '';
    return '<div class="row '+cls+'" data-i="'+d.i+'">'
      + '<div class="no">#'+(d.i+1)+'</div>'
      + '<div><div class="meta">'+chips+'</div><div class="src">'+esc(d.src)+'</div>'+ctx+plural+'</div>'
      + '<div class="right"><textarea data-i="'+d.i+'" spellcheck="false">'+esc(t)+'</textarea>'
      + '<div class="acts">'
      + '<button data-act="ok" data-i="'+d.i+'">'+(isDone(d.i)?'取消确认':'确认')+'</button>'
      + '<button data-act="src" data-i="'+d.i+'">复制原文</button>'
      + '<button data-act="reset" data-i="'+d.i+'">还原</button>'
      + '<span class="saved">'+(isDone(d.i)?'✓ 已确认':'')+'</span>'
      + '</div></div></div>';
  }).join('');
  // 分页
  if (size && pages>1){
    const mk = (p,l,dis) => '<button '+(dis?'disabled':'')+' data-p="'+p+'">'+l+'</button>';
    $('#pager').innerHTML = mk(1,'首页',page<=1)+mk(page-1,'上一页',page<=1)
      + '<span>第 '+page+' / '+pages+' 页 · 共 '+total+' 条</span>'
      + mk(page+1,'下一页',page>=pages)+mk(pages,'末页',page>=pages);
  } else { $('#pager').innerHTML = '<span>共 '+total+' 条</span>'; }
}

let timers = {};
document.addEventListener('input', e => {
  const ta = e.target.closest('textarea[data-i]');
  if (!ta) return;
  const i = +ta.dataset.i;
  clearTimeout(timers[i]);
  timers[i] = setTimeout(() => {
    state[i] = Object.assign({}, state[i], {t: ta.value});
    if (!state[i].t) delete state[i].t;
    save();
  }, 250);
});
document.addEventListener('click', e => {
  const b = e.target.closest('button'); if (!b) return;
  if (b.dataset.p){ page = +b.dataset.p; render(); window.scrollTo({top:0,behavior:'smooth'}); return; }
  const act = b.dataset.act, i = +b.dataset.i;
  if (act === 'ok'){
    const v = (state[i] && state[i].t !== undefined) ? state[i].t : DATA[i].tr;
    state[i] = Object.assign({}, state[i], {t: v, s: isDone(i) ? '' : 'ok'});
    save();
    const row = b.closest('.row');
    row.classList.toggle('done', isDone(i));
    row.classList.toggle('edited', isEdited(i));
    b.textContent = isDone(i) ? '取消确认' : '确认';
    row.querySelector('.saved').textContent = isDone(i) ? '✓ 已确认' : '';
    row.querySelector('textarea').classList.toggle('ok', isDone(i));
    return;
  }
  if (act === 'src'){ navigator.clipboard.writeText(DATA[i].src); toast('已复制英文原文'); return; }
  if (act === 'reset'){
    delete state[i]; save();
    const row = b.closest('.row'); row.querySelector('textarea').value = DATA[i].tr;
    row.classList.remove('done','edited'); row.querySelector('.saved').textContent = '';
    b.closest('.acts').querySelector('[data-act=ok]').textContent = '确认';
    return;
  }
});
$('#q').addEventListener('input', ()=>{ page=1; render(); });
$('#f-flag').addEventListener('change', ()=>{ page=1; render(); });
$('#f-mod').addEventListener('change', ()=>{ page=1; render(); });
$('#seg-size').addEventListener('click', e => {
  const b = e.target.closest('button'); if (!b) return;
  size = +b.dataset.n; page = 1;
  [...$('#seg-size').children].forEach(x=>x.classList.toggle('on', x===b));
  render();
});
$('#btn-confirm-page').addEventListener('click', () => {
  const rows = filtered(); const s = size ? rows.slice((page-1)*size, page*size) : rows;
  s.forEach(d => { const v = (state[d.i] && state[d.i].t !== undefined) ? state[d.i].t : DATA[d.i].tr;
    state[d.i] = Object.assign({}, state[d.i], {t: v, s: 'ok'}); });
  save(); render(); toast('已确认本页 '+s.length+' 条');
});

function dl(name, text, mime){
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([text], {type: (mime||'text/plain')+';charset=utf-8'}));
  a.download = name; a.click(); setTimeout(()=>URL.revokeObjectURL(a.href), 3000);
}
function poesc(s){ return s.replace(/\\/g,'\\\\').replace(/"/g,'\\"').replace(/\n/g,'\\n'); }
$('#btn-export-json').addEventListener('click', () => {
  const out = {};
  DATA.forEach(d => { const t = tr(d.i); if (state[d.i] && (state[d.i].t !== undefined || state[d.i].s)) out[d.src] = {t: t, s: st(d.i)||''}; });
  const payload = {plugin:'quiz-master-next', version:'11.2.6', locale:'zh_CN',
                   exported:new Date().toISOString(),
                   entries: DATA.map(d=>({src:d.src, plural:d.plural, ctx:d.ctx, tr:tr(d.i), s:st(d.i)||''}))};
  dl('qsm-zh_CN-workbench.json', JSON.stringify(payload,null,1), 'application/json');
  toast('已导出 JSON（'+DATA.length+' 条）');
});
$('#btn-export-po').addEventListener('click', () => {
  let out = 'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Language: zh_CN\\n"\n"Plural-Forms: nplurals=1; plural=0;\\n"\n\n';
  DATA.forEach(d => {
    const t = tr(d.i); if (!t) return;
    if (d.ctx) out += 'msgctxt "'+poesc(d.ctx)+'"\n';
    out += 'msgid "'+poesc(d.src)+'"\n';
    if (d.plural) out += 'msgid_plural "'+poesc(d.plural)+'"\nmsgstr[0] "'+poesc(t)+'"\n\n';
    else out += 'msgstr "'+poesc(t)+'"\n\n';
  });
  dl('quiz-master-next-zh_CN.po', out); toast('已导出 .po');
});
$('#btn-export-csv').addEventListener('click', () => {
  const q = s => '"' + String(s).replace(/"/g,'""') + '"';
  let out = '\ufeff' + ['原文','译文','状态','模块','引用'].join(',') + '\r\n';
  DATA.forEach(d => { out += [q(d.src), q(tr(d.i)), q(isDone(d.i)?'已确认':''), q(d.mod), q(d.ref)].join(',') + '\r\n'; });
  dl('qsm-zh_CN.csv', out, 'text/csv'); toast('已导出 CSV（Excel 可直接打开）');
});
$('#btn-import').addEventListener('click', () => {
  const inp = document.createElement('input'); inp.type='file'; inp.accept='.json,.csv';
  inp.onchange = () => {
    const f = inp.files[0]; if (!f) return;
    const r = new FileReader();
    r.onload = () => {
      let n = 0;
      try {
        const bySrc = {}; DATA.forEach(d => bySrc[d.src] = d.i);
        if (/\.json$/i.test(f.name)){
          const j = JSON.parse(r.result);
          (j.entries || []).forEach(e => {
            const i = bySrc[e.src]; if (i === undefined) return;
            if (e.tr !== undefined){ state[i] = Object.assign({}, state[i], {t:e.tr}); n++; }
            if (e.s) state[i].s = e.s;
          });
        } else {
          const lines = r.result.replace(/^\ufeff/,'').split(/\r?\n/).slice(1);
          lines.forEach(line => {
            if (!line.trim()) return;
            const cells = []; let cur='', qq=false;
            for (let k=0;k<line.length;k++){ const c=line[k];
              if (qq){ if (c==='"' && line[k+1]==='"'){cur+='"';k++;} else if (c==='"'){qq=false;} else cur+=c; }
              else if (c==='"') qq=true; else if (c===','){cells.push(cur);cur='';} else cur+=c; }
            cells.push(cur);
            const i = bySrc[cells[0]]; if (i === undefined) return;
            if (cells[1] !== undefined){ state[i] = Object.assign({}, state[i], {t: cells[1]}); n++; }
            if (cells[2] === '已确认') state[i].s = 'ok';
          });
        }
        save(); render(); toast('已导入 '+n+' 条译文');
      } catch(err){ toast('导入失败：'+err.message); }
    };
    r.readAsText(f, 'utf-8');
  };
  inp.click();
});
$('#btn-reset').addEventListener('click', () => {
  if (!confirm('将清除本地保存的全部修改与确认标记，恢复为初始草稿。确定吗？')) return;
  state = {}; save(); render(); toast('已清除本地修改');
});
window.addEventListener('beforeunload', () => localStorage.setItem(LSKEY, JSON.stringify(state)));

render(); refreshStat();
</script>
</body>
</html>
'''

HTML = HTML.replace('__DATA__', data_js)
p = os.path.join(WORK, 'index.html')
open(p, 'w', encoding='utf-8', newline='\n').write(HTML)
print('已生成 %s  (%.0f KB)' % (p, os.path.getsize(p)/1024))
