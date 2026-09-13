/**
 * YBH · 经典编辑器「页面外壳」增强（admin 端，运行在编辑器页面本身而非 iframe 内）
 *
 * 本文件负责三件事（任务清单第 1、3 项）：
 *
 *   ① 查找 / 替换面板
 *      · Ctrl+F 打开「查找」，Ctrl+H 打开「查找并替换」；
 *      · 可视化（TinyMCE）与「文本」两个标签页都支持；
 *      · 引擎按**文本节点**匹配与替换 —— 不碰 HTML 标签与属性，
 *        因此绝不会像"整段字符串替换"那样把 <a href="…"> 里的字改坏；
 *      · 当前命中用**选区**高亮（不改 DOM），所以随时关面板都不会留下残留标记。
 *
 *   ② Ctrl+S 就地保存（不离开编辑器）
 *      · WordPress 只在「文本」标签下绑了 Ctrl+S（wp-admin/js/post.js L1263），
 *        触发的是 autosave；「可视化」标签下 Ctrl+S 完全没人拦，会弹浏览器"另存为"。
 *        两种标签下行为不一致，作者体感就是"按了没反应 / 直接退出编辑"。
 *      · 这里在**捕获阶段**统一接管 Ctrl+S / Cmd+S：preventDefault + 就地 AJAX 保存
 *        （admin-ajax.php?action=ybh_quick_save，见 inc/ybh/quick-save.php），
 *        保存完只更新右下角提示，绝不跳转、绝不刷新、绝不丢光标位置。
 *      · 保存失败（网络/权限/锁定）时明确报错，而不是静默失败。
 *
 *   ③ 保存反馈提示（右下角 toast）
 *      让作者对"到底存没存上"有确定答案 —— 这正是用户反馈里最痛的一点。
 *
 * 与其它部分的关系：
 *   · 可视化区（TinyMCE iframe）里的按钮与 Ctrl+F/H 由 js/ybh-editor.js 注册，
 *     它们通过 window.YBH_Editor.openFindReplace() 调用本文件的同一套面板；
 *   · 本文件不处理脚注标记（那是 js/ybh-editor.js 的职责）。
 */
(function () {
  'use strict';

  if (typeof window.jQuery === 'undefined') { return; }

  var $ = window.jQuery;
  var doc = document;

  /* ======================================================================
   * 工具
   * ==================================================================== */

  function qs(sel, root) { return (root || doc).querySelector(sel); }

  function getTextarea() { return qs('#content'); }

  /** 当前处于可视化模式且编辑器已就绪时返回 TinyMCE 实例，否则 null */
  function visualEditor() {
    if (typeof window.tinymce === 'undefined' || !window.tinymce.get) { return null; }
    var ed = window.tinymce.get('content');
    if (!ed || ed.isHidden && ed.isHidden()) { return null; }
    return ed;
  }

  function activeMode() { return visualEditor() ? 'visual' : 'text'; }

  /* ======================================================================
   * ③ Toast 提示
   * ==================================================================== */

  var toastEl = null, toastTimer = null;
  function toast(msg, kind, ms) {
    if (!toastEl) {
      toastEl = doc.createElement('div');
      toastEl.className = 'ybh-ed-toast';
      toastEl.setAttribute('role', 'status');
      toastEl.setAttribute('aria-live', 'polite');
      doc.body.appendChild(toastEl);
    }
    toastEl.textContent = msg;
    toastEl.className = 'ybh-ed-toast ybh-ed-toast--' + (kind || 'info') + ' is-show';
    if (toastTimer) { clearTimeout(toastTimer); }
    toastTimer = setTimeout(function () {
      toastEl.className = 'ybh-ed-toast ybh-ed-toast--' + (kind || 'info');
    }, ms || 2600);
  }

  /* ======================================================================
   * ① 查找 / 替换
   * ==================================================================== */

  var panel = null;
  var fr = {
    open: false,
    query: '',
    replace: '',
    caseSensitive: false,
    matches: [],   // visual: [{node,start,end}]  text: [{start,end}]
    index: 0,
    mode: 'visual'
  };

  /* ---------- 文本节点收集（可视化模式） ---------- */

  function contentRoot(ed) { return ed.getBody(); }

  /** 收集参与查找的文本节点：跳过 script/style，避免改到不可见内容 */
  function textNodes(root) {
    var out = [];
    var walker = doc.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
      acceptNode: function (n) {
        var p = n.parentNode;
        if (!p) { return NodeFilter.FILTER_REJECT; }
        var tag = (p.nodeName || '').toLowerCase();
        if (tag === 'script' || tag === 'style') { return NodeFilter.FILTER_REJECT; }
        if (!n.nodeValue) { return NodeFilter.FILTER_REJECT; }
        return NodeFilter.FILTER_ACCEPT;
      }
    });
    var n;
    while ((n = walker.nextNode())) { out.push(n); }
    return out;
  }

  function collectVisual(ed) {
    var q = fr.query;
    fr.matches = [];
    if (!q) { return; }
    var nodes = textNodes(contentRoot(ed));
    var hay = fr.caseSensitive ? null : q.toLowerCase();
    for (var i = 0; i < nodes.length; i++) {
      var data = nodes[i].nodeValue;
      var probe = fr.caseSensitive ? data : data.toLowerCase();
      var from = 0, at;
      while ((at = probe.indexOf(hay === null ? q : hay, from)) !== -1) {
        fr.matches.push({ node: nodes[i], start: at, end: at + q.length });
        from = at + q.length;                       // 不重叠匹配
        if (fr.matches.length > 5000) { return; }   // 安全上限
      }
    }
  }

  function collectText(ta) {
    var q = fr.query;
    fr.matches = [];
    if (!q) { return; }
    var data = ta.value;
    var probe = fr.caseSensitive ? data : data.toLowerCase();
    var needle = fr.caseSensitive ? q : q.toLowerCase();
    var from = 0, at;
    while ((at = probe.indexOf(needle, from)) !== -1) {
      fr.matches.push({ start: at, end: at + q.length });
      from = at + q.length;
      if (fr.matches.length > 5000) { return; }
    }
  }

  function recollect() {
    if (fr.mode === 'visual') {
      var ed = visualEditor();
      if (ed) { collectVisual(ed); } else { fr.matches = []; }
    } else {
      var ta = getTextarea();
      if (ta) { collectText(ta); } else { fr.matches = []; }
    }
    if (fr.index >= fr.matches.length) { fr.index = 0; }
    updateCount();
  }

  function updateCount() {
    if (!panel) { return; }
    var el = qs('.ybh-fr__count', panel);
    if (!el) { return; }
    if (!fr.query) { el.textContent = ''; return; }
    el.textContent = fr.matches.length
      ? ('第 ' + (fr.index + 1) + ' / 共 ' + fr.matches.length + ' 处')
      : '未找到';
  }

  /** 把当前命中设为选区（可视化）——不改 DOM，因此没有残留风险 */
  function focusVisualMatch() {
    var ed = visualEditor();
    if (!ed || !fr.matches.length) { return; }
    var m = fr.matches[fr.index];
    if (!m || !m.node || !m.node.parentNode) { return; }
    var rng = ed.dom.createRng();
    rng.setStart(m.node, m.start);
    rng.setEnd(m.node, m.end);
    ed.selection.setRng(rng);
    // 滚到视野内（TinyMCE 4 有 scrollIntoView，5 用原生）
    try {
      if (ed.selection.scrollIntoView) { ed.selection.scrollIntoView(); }
      else {
        var p = m.node.parentNode;
        if (p && p.scrollIntoView) { p.scrollIntoView({ block: 'center' }); }
      }
    } catch (e) { /* 忽略：滚动失败不影响定位 */ }
    ed.focus();
  }

  function focusTextMatch() {
    var ta = getTextarea();
    if (!ta || !fr.matches.length) { return; }
    var m = fr.matches[fr.index];
    ta.focus();
    ta.setSelectionRange(m.start, m.end);
    // 让选区滚到可见位置：用行号估算
    var line = ta.value.slice(0, m.start).split('\n').length;
    var lh = parseFloat(window.getComputedStyle(ta).lineHeight) || 18;
    ta.scrollTop = Math.max(0, (line - 4) * lh);
  }

  function focusMatch() {
    if (fr.mode === 'visual') { focusVisualMatch(); } else { focusTextMatch(); }
    updateCount();
  }

  function step(delta) {
    if (!fr.matches.length) { recollect(); }
    if (!fr.matches.length) { updateCount(); return; }
    fr.index = (fr.index + delta + fr.matches.length) % fr.matches.length;
    focusMatch();
  }

  /** 替换当前命中 */
  function replaceOne() {
    if (!fr.matches.length) { recollect(); }
    if (!fr.matches.length) { return; }
    var m = fr.matches[fr.index];
    if (fr.mode === 'visual') {
      if (!m.node || !m.node.parentNode) { recollect(); return; }
      var data = m.node.nodeValue;
      m.node.nodeValue = data.slice(0, m.start) + fr.replace + data.slice(m.end);
    } else {
      var ta = getTextarea();
      ta.value = ta.value.slice(0, m.start) + fr.replace + ta.value.slice(m.end);
    }
    // 替换后重建匹配表：长度可能变了，索引保持在同一位置附近
    var keep = fr.index;
    recollect();
    fr.index = Math.min(keep, Math.max(0, fr.matches.length - 1));
    focusMatch();
    afterChange();
  }

  /** 全部替换 */
  function replaceAll() {
    recollect();
    if (!fr.matches.length) { return; }
    var n = fr.matches.length;
    if (fr.mode === 'visual') {
      // 按节点分组、从后往前改，避免偏移失效
      var byNode = [];
      for (var i = 0; i < fr.matches.length; i++) {
        var m = fr.matches[i];
        var last = byNode[byNode.length - 1];
        if (last && last.node === m.node) { last.items.push(m); }
        else { byNode.push({ node: m.node, items: [m] }); }
      }
      for (var j = 0; j < byNode.length; j++) {
        var g = byNode[j];
        if (!g.node || !g.node.parentNode) { continue; }
        var data = g.node.nodeValue;
        for (var k = g.items.length - 1; k >= 0; k--) {
          var it = g.items[k];
          data = data.slice(0, it.start) + fr.replace + data.slice(it.end);
        }
        g.node.nodeValue = data;
      }
    } else {
      var ta = getTextarea();
      var out = '', cursor = 0;
      for (var x = 0; x < fr.matches.length; x++) {
        out += ta.value.slice(cursor, fr.matches[x].start) + fr.replace;
        cursor = fr.matches[x].end;
      }
      out += ta.value.slice(cursor);
      ta.value = out;
    }
    recollect();
    focusMatch();
    afterChange();
    toast('已替换 ' + n + ' 处', 'ok');
  }

  /** 替换后同步到编辑器内部状态，避免"可视化看着没变" */
  function afterChange() {
    var ed = visualEditor();
    if (ed) { ed.nodeChanged(); }
  }

  /* ---------- 面板 DOM ---------- */

  function buildPanel() {
    panel = doc.createElement('div');
    panel.className = 'ybh-fr';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', '查找与替换');
    panel.innerHTML = [
      '<div class="ybh-fr__row">',
      '  <label class="ybh-fr__lbl" for="ybh-fr-q">查找</label>',
      '  <input id="ybh-fr-q" class="ybh-fr__input" type="text" autocomplete="off" spellcheck="false">',
      '  <span class="ybh-fr__count"></span>',
      '  <button type="button" class="ybh-fr__x" title="关闭（Esc）">×</button>',
      '</div>',
      '<div class="ybh-fr__row">',
      '  <label class="ybh-fr__lbl" for="ybh-fr-r">替换</label>',
      '  <input id="ybh-fr-r" class="ybh-fr__input" type="text" autocomplete="off" spellcheck="false">',
      '</div>',
      '<div class="ybh-fr__row ybh-fr__row--actions">',
      '  <button type="button" class="ybh-fr__btn" data-act="prev" title="上一个（Shift+Enter）">上一个</button>',
      '  <button type="button" class="ybh-fr__btn" data-act="next" title="下一个（Enter）">下一个</button>',
      '  <button type="button" class="ybh-fr__btn" data-act="one">替换</button>',
      '  <button type="button" class="ybh-fr__btn ybh-fr__btn--primary" data-act="all">全部替换</button>',
      '  <label class="ybh-fr__chk"><input type="checkbox" id="ybh-fr-cs"> 区分大小写</label>',
      '</div>'
    ].join('\n');
    doc.body.appendChild(panel);

    var q = qs('#ybh-fr-q', panel);
    var r = qs('#ybh-fr-r', panel);
    var cs = qs('#ybh-fr-cs', panel);

    q.addEventListener('input', function () { fr.query = q.value; fr.index = 0; recollect(); if (fr.query) { focusMatch(); } });
    r.addEventListener('input', function () { fr.replace = r.value; });
    cs.addEventListener('change', function () { fr.caseSensitive = cs.checked; fr.index = 0; recollect(); });

    // Enter 下一个 / Shift+Enter 上一个 / Esc 关闭（在输入框里也生效）
    panel.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.preventDefault(); closePanel(); return; }
      if (e.key === 'Enter') {
        e.preventDefault();
        step(e.shiftKey ? -1 : 1);
      }
    });

    panel.addEventListener('click', function (e) {
      var act = e.target && e.target.getAttribute && e.target.getAttribute('data-act');
      if (!act && e.target && e.target.classList.contains('ybh-fr__x')) { closePanel(); return; }
      if (act === 'prev') { step(-1); }
      else if (act === 'next') { step(1); }
      else if (act === 'one') { replaceOne(); }
      else if (act === 'all') { replaceAll(); }
    });

    return panel;
  }

  function openPanel(withReplace) {
    if (!panel) { buildPanel(); }
    fr.mode = activeMode();
    panel.classList.add('is-open');
    fr.open = true;

    var q = qs('#ybh-fr-q', panel);
    // 用编辑器里已选中的文字预填查找框（作者的直觉期望）
    try {
      var sel = '';
      if (fr.mode === 'visual') {
        var ed = visualEditor();
        sel = ed ? (ed.selection.getContent({ format: 'text' }) || '') : '';
      } else {
        var ta = getTextarea();
        if (ta) { sel = ta.value.slice(ta.selectionStart, ta.selectionEnd); }
      }
      sel = sel.replace(/\s+/g, ' ').trim();
      if (sel && sel.length <= 60) { q.value = sel; fr.query = sel; }
    } catch (e) { /* 取选区失败不影响打开面板 */ }

    q.focus();
    q.select();
    recollect();
    if (fr.query) { focusMatch(); }
  }

  function closePanel() {
    if (panel) { panel.classList.remove('is-open'); }
    fr.open = false;
    var ed = visualEditor();
    if (ed) { ed.focus(); }
  }

  /* ---------- 对外接口（供 TinyMCE 里的按钮/快捷键调用） ---------- */

  window.YBH_Editor = {
    openFindReplace: function (withReplace) { openPanel(!!withReplace); },
    closeFindReplace: closePanel,
    toast: toast,
    save: quickSave
  };

  /* ---------- 页面级快捷键：Ctrl+F / Ctrl+H ----------
     注意用**捕获阶段**，这样能抢在浏览器默认行为与
     classic-editor 插件绑在 textarea 上的处理器之前。 */

  function isFindKey(e) {
    return (e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'f' || e.key === 'F');
  }
  function isReplaceKey(e) {
    return (e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'h' || e.key === 'H');
  }

  doc.addEventListener('keydown', function (e) {
    // 可视化模式下，键盘事件发生在 iframe 内部，由 js/ybh-editor.js 处理
    if (activeMode() === 'visual') { return; }

    if (isFindKey(e)) { e.preventDefault(); e.stopPropagation(); openPanel(false); return; }
    if (isReplaceKey(e)) { e.preventDefault(); e.stopPropagation(); openPanel(true); return; }
    if (e.key === 'Escape' && fr.open) { e.preventDefault(); closePanel(); }
  }, true);

  /* ======================================================================
   * ② Ctrl+S 就地保存
   * ==================================================================== */

  var saving = false;

  function syncVisualToTextarea() {
    var ed = visualEditor();
    if (ed) {
      try { ed.save(); } catch (e) { /* TinyMCE 未就绪时忽略 */ }
    }
  }

  /**
   * 取当前文章 ID。
   *
   * 必须容错：本地化数据里的 postId 在 post-new.php 上是 0（新建文章还没有 ID），
   * 而 JS 里 `"0"` 是**真值** —— 直接写 `a || b` 会让 "0" 把真正的 #post_ID 挡掉，
   * 于是请求带着 post_id=0 发出去，服务端只能回「缺少文章 ID」。
   * 这里统一转成正整数再判断，并依次尝试多个来源。
   */
  function resolvePostId() {
    var d = window.YBH_EditorData || {};
    var byId = qs('#post_ID');
    var byName = qs('input[name="post_ID"]');
    var fromUrl = (window.location.search.match(/[?&]post=(\d+)/) || [])[1];
    var cands = [
      d.postId,
      byId ? byId.value : '',
      byName ? byName.value : '',
      fromUrl
    ];
    for (var i = 0; i < cands.length; i++) {
      var v = parseInt(cands[i], 10);
      if (!isNaN(v) && v > 0) { return v; }
    }
    return 0;
  }

  function quickSave() {
    if (saving) { return; }
    var ta = getTextarea();
    if (!ta) { return; }

    var postId = resolvePostId();
    if (!postId) {
      toast('保存失败：识别不到文章 ID，请刷新页面后重试', 'err', 5000);
      return;
    }

    syncVisualToTextarea();   // 可视化 → textarea，保证取到最新内容

    var data = {
      action: 'ybh_quick_save',
      nonce: (window.YBH_EditorData && window.YBH_EditorData.nonce) || '',
      post_id: postId,
      post_title: qs('#title') ? qs('#title').value : '',
      post_content: ta.value
    };

    var ex = qs('#excerpt');
    if (ex) { data.post_excerpt = ex.value; }

    saving = true;
    toast('保存中…', 'info', 60000);

    $.ajax({
      url: (window.YBH_EditorData && window.YBH_EditorData.ajaxUrl) || window.ajaxurl,
      type: 'POST',
      dataType: 'json',
      data: data
    }).done(function (res) {
      if (res && res.success) {
        var d = res.data || {};
        toast('已保存 ' + (d.human || ''), 'ok');
        // 让 WP 自己的"有未保存改动"判断归零，避免离开页面时误报
        try {
          if (window.wp && wp.autosave && wp.autosave.server && wp.autosave.getCompareString) {
            wp.autosave.server.initialCompareString = wp.autosave.getCompareString();
          }
        } catch (e2) { /* 忽略 */ }
        $(doc).trigger('ybh-quick-saved', [d]);
      } else {
        toast('保存失败：' + ((res && res.data && res.data.message) || '未知错误'), 'err');
      }
    }).fail(function (xhr) {
      var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message)
        ? xhr.responseJSON.data.message
        : ('HTTP ' + (xhr ? xhr.status : '?'));
      toast('保存失败：' + msg, 'err');
    }).always(function () {
      saving = false;
    });
  }

  /* Ctrl+S / Cmd+S：两种标签页统一接管，绝不跳转 */
  function isSaveKey(e) {
    return (e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && (e.key === 's' || e.key === 'S');
  }

  doc.addEventListener('keydown', function (e) {
    if (!isSaveKey(e)) { return; }
    e.preventDefault();
    e.stopPropagation();
    quickSave();
  }, true);

  // 「文本」标签下的 textarea 也单独兜一层（有些浏览器事件不从 textarea 冒到 document）
  $(function () {
    var ta = getTextarea();
    if (ta) {
      ta.addEventListener('keydown', function (e) {
        if (isSaveKey(e)) { e.preventDefault(); e.stopPropagation(); quickSave(); }
      }, true);
    }
    // 首次进入时给一个轻提示，让作者知道 Ctrl+S 可用
    if (window.YBH_EditorData && window.YBH_EditorData.showHint) {
      toast('提示：按 Ctrl+S 可就地保存，不会离开编辑器', 'info', 4200);
    }
  });
})();
