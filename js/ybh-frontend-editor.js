/**
 * YBH · 前台编辑器控制器（T64 起，T66 修复若干实际问题）
 *
 * ===================================================================
 * 这是什么
 * ===================================================================
 *   把写作界面从 wp-admin 搬到站点前台（`/write/`）。编辑器本体沿用 WangEditor 5
 *   （与后台附加面板同一套产物与自定义菜单），这里只做：组装编辑器、保存、状态反馈。
 *
 * ===================================================================
 * 这一版修掉的四个**实测出来的**问题
 * ===================================================================
 * 1. **pjax 下的"未保存"误报**（站长反馈「退出编辑器在主站刷新可能提示是否保存」）：
 *    本站是 pjax —— 换页只替换 `#page`，**不卸载文档**。于是写作页注册的
 *    `beforeunload` 与编辑器实例在跳回主站之后还活着，之后任何一次刷新/跳转
 *    都会弹"是否离开"。
 *    修法：① `beforeunload` 只在真的脏时才挂着；② 处理函数先判断
 *    **编辑器容器是否还在文档里** —— 已被 pjax 换掉就直接放行；
 *    ③ 收到 `pjax:complete` 就把脏标记清零、并在新页面又是写作页时**重新初始化**
 *       （否则从主站 pjax 回 `/write/` 会看到一个空的编辑器）。
 * 2. **首次挂载被误判成"用户改了东西"**：创建编辑器时写入初始 HTML 会触发一次
 *    `change`。现在初始化后短暂忽略，只有真正的用户输入才算脏。
 * 3. **摘要字段已弃用**：不再收集，也不再提交（端点仍兼容）。
 * 4. **分类 / 标签**：新增字段。权限一律走 WordPress 自己的能力 ——
 *    `post_tag` 的分配能力是 `edit_posts`（投稿者有），`category` 是
 *    `manage_categories`（投稿者没有，故对他不显示选择框）。
 *
 * 保存：POST admin-ajax.php（action=ybh_front_save, nonce, post_id, status,
 * post_title, post_content, ybh_lang, ybh_tags, ybh_cats）。
 * 服务端做能力分流（没有 publish_posts 的账号一律 pending）。
 * T68：标签带搜索建议（/wp-json/wp/v2/tags）+ 已选胶囊；分类改**单选**；
 * 元信息收进可折叠右侧栏；管理员在侧栏里有"切换到后台编辑器"链接。
 */
(function () {
  'use strict';

  var cfg = window.YBH_FE || {};
  var W = window.wangEditor;

  /* 与后台面板保持同一套工具栏：禁掉字号/字体/行高/颜色（正文样式由主题定），
     并补上 YBH 自定义菜单（脚注、上下标、注音、缩进、查找替换、特殊字符…）。 */
  var EXCLUDE = ['fontSize', 'fontFamily', 'lineHeight', 'color', 'bgColor', 'group-video', 'fullScreen'];
  var TOOLBAR_KEYS = [
    'headerSelect', '|',
    'bold', 'italic', 'underline', 'through', 'code',
    'ybhSup', 'ybhSub', 'ybhRuby', '|',
    'bulletedList', 'numberedList', 'blockquote', '|',
    'justifyLeft', 'justifyCenter', 'justifyRight', '|',
    'ybhFootnote', 'ybhIndent', '|',
    'insertLink', 'uploadImage', 'insertTable', 'divider', '|',
    'ybhMore', '|',
    'ybhFindReplace', 'ybhPasteText', 'ybhCharmap', '|',
    'undo', 'redo'
  ];

  /**
   * 初始化（可重入）：DOM 就绪时调用一次；pjax 换页后再调一次。
   * 用容器上的 `data-ybh-fe-init` 防止同一容器被重复初始化
   * （WangEditor 会抛 `Repeated create editor by selector`）。
   */
  function initEditor() {
    var host = document.getElementById('ybh-fe-body');
    if (!host || !cfg.ajaxUrl) { return; }
    if (host.getAttribute('data-ybh-fe-init') === '1') { return; }
    if (typeof W === 'undefined') { setStatus('编辑器资源没加载成功，请刷新页面重试。', 'err'); return; }
    host.setAttribute('data-ybh-fe-init', '1');

    var statusEl = document.getElementById('ybh-fe-status');
    var titleEl = document.getElementById('ybh-fe-title');
    var langEl = document.getElementById('ybh-fe-lang');
    var tagsEl = document.getElementById('ybh-fe-tags');
    var catsEl = document.getElementById('ybh-fe-cats');
    var btnDraft = document.getElementById('ybh-fe-save-draft');
    var btnSubmit = document.getElementById('ybh-fe-submit');
    var linkView = document.getElementById('ybh-fe-view');
    var toolbarHost = document.getElementById('ybh-fe-toolbar');
    var blankBox = document.getElementById('ybh-fe-blank');
    var blankText = document.getElementById('ybh-fe-blank-text');
    var blankBtn = document.getElementById('ybh-fe-blank-clean');

    var postId = parseInt(host.getAttribute('data-post') || '0', 10) || 0;
    var busy = false;
    var dirty = false;
    var editor = null;
    var acceptingChanges = false;   // 见文件头第 2 点

    function setStatus(text, kind) {
      if (!statusEl) { return; }
      statusEl.textContent = text || '';
      statusEl.setAttribute('data-kind', kind || 'idle');
    }

    function onBeforeUnload(e) {
      // 容器已被 pjax 换掉 / 已销毁 ⇒ 这一页早已不是写作页，放行（并顺手摘掉自己）
      if (!dirty || !document.body.contains(host)) {
        window.removeEventListener('beforeunload', onBeforeUnload);
        return undefined;
      }
      e.preventDefault();
      e.returnValue = '';
      return '';
    }

    function markDirty(v) {
      if (v === dirty) { return; }
      dirty = v;
      if (dirty) {
        window.addEventListener('beforeunload', onBeforeUnload);
      } else {
        window.removeEventListener('beforeunload', onBeforeUnload);
      }
    }

    /** 取正文 HTML；出站前过一遍主题的内容规范化（与后台同一个 normalizer） */
    function currentHtml() {
      var h = editor ? editor.getHtml() : '';
      if (window.YBH_Content && typeof window.YBH_Content.normalize === 'function') {
        try { h = window.YBH_Content.normalize(h); } catch (e) { /* 规范化失败就用原样，别把内容丢了 */ }
      }
      return h;
    }

    function selectedCats() {
      if (!catsEl) { return []; }
      // T68：分类改**单选**（radio，含"不设分类"空值项）；空值不算已选
      var out = [];
      [].forEach.call(catsEl.querySelectorAll('input[name="ybh_cats"]:checked'), function (i) {
        if (i.value) { out.push(i.value); }
      });
      return out;
    }

    /* ---------- 标签组件（T68）----------
     * #ybh-fe-tags（hidden）保存已选标签（逗号分隔，保存就发它）；
     * #ybh-fe-tag-input 是可见的搜索框：输入时拉 /wp-json/wp/v2/tags 的建议，
     * 回车/点击选中；不在建议里的输入会作为**新标签**提交（quick-save 自动创建）。
     */
    function parseTags(v) {
      return String(v || '').split(/[,，、;；]+/).map(function (s) { return s.trim(); }).filter(Boolean);
    }

    function initTags() {
      var hidden = document.getElementById('ybh-fe-tags');
      var vis = document.getElementById('ybh-fe-tag-input');
      var box = document.getElementById('ybh-fe-tags-box');
      var list = document.getElementById('ybh-fe-tag-list');
      if (!hidden || !vis || !box || !list) { return; }
      var tags = parseTags(hidden.value);
      var timer = 0;

      function sync() {
        hidden.value = tags.join('，');
        render();
        markDirty(true);
      }
      function render() {
        [].slice.call(box.querySelectorAll('.ybh-fe__tag')).forEach(function (ch) { ch.parentNode.removeChild(ch); });
        tags.forEach(function (t, idx) {
          var chip = document.createElement('span');
          chip.className = 'ybh-fe__tag';
          var label = document.createElement('span');
          label.textContent = t;
          chip.appendChild(label);
          var x = document.createElement('button');
          x.type = 'button';
          x.textContent = '×';
          x.setAttribute('aria-label', '移除标签 ' + t);
          x.addEventListener('click', function () { tags.splice(idx, 1); sync(); });
          chip.appendChild(x);
          box.insertBefore(chip, vis);
        });
      }
      function addTag(t) {
        t = (t || '').trim();
        if (!t) { return; }
        if (tags.length >= 20) { setStatus('标签最多 20 个。', 'warn'); return; }
        if (tags.indexOf(t) === -1) { tags.push(t); sync(); }
      }
      function showSuggestions(names, q) {
        list.textContent = '';
        var qNorm = (q || '').trim();
        var hasExact = names.some(function (n) { return n === qNorm; });
        if (!names.length && !qNorm) { list.hidden = true; return; }
        names.forEach(function (n) {
          var li = document.createElement('li');
          li.textContent = n;
          li.addEventListener('mousedown', function (e) { e.preventDefault(); addTag(n); vis.value = ''; list.hidden = true; });
          list.appendChild(li);
        });
        if (qNorm && !hasExact) {
          var li = document.createElement('li');
          li.className = 'is-new';
          li.textContent = '＋ 新建「' + qNorm + '」';
          li.addEventListener('mousedown', function (e) { e.preventDefault(); addTag(qNorm); vis.value = ''; list.hidden = true; });
          list.appendChild(li);
        }
        list.hidden = false;
      }
      function search(q) {
        var base = cfg.restTags || '/wp-json/wp/v2/tags';
        fetch(base + '?search=' + encodeURIComponent(q) + '&per_page=8', { credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : []; })
          .then(function (arr) {
            showSuggestions((Array.isArray(arr) ? arr : []).map(function (x) { return x.name; }), q);
          })
          .catch(function () { list.hidden = true; });
      }

      vis.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ',' || e.key === '，') {
          e.preventDefault();
          addTag(vis.value);
          vis.value = '';
          list.hidden = true;
        } else if (e.key === 'Backspace' && vis.value === '' && tags.length) {
          tags.pop();
          sync();
        }
      });
      vis.addEventListener('input', function () {
        var q = vis.value.trim();
        clearTimeout(timer);
        if (!q) { list.hidden = true; return; }
        timer = setTimeout(function () { search(q); }, 220);
      });
      document.addEventListener('click', function (e) {
        if (list.hidden) { return; }
        if (!box.contains(e.target)) { list.hidden = true; }
      });
      render();
    }

    /* ---------- 选项栏折叠（T68；状态记在 localStorage） ---------- */
    function initSideToggle() {
      var root = document.querySelector('.ybh-fe');
      var btn = document.getElementById('ybh-fe-side-toggle');
      if (!root || !btn) { return; }
      var KEY = 'ybh_fe_side_collapsed';
      var collapsed = false;
      try { collapsed = localStorage.getItem(KEY) === '1'; } catch (e) {}
      function apply() {
        root.classList.toggle('is-side-collapsed', collapsed);
        btn.textContent = collapsed ? '显示选项栏' : '收起选项栏';
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      }
      if (btn.getAttribute('data-side-init') !== '1') {
        btn.setAttribute('data-side-init', '1');
        btn.addEventListener('click', function () {
          collapsed = !collapsed;
          try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) {}
          apply();
        });
      }
      apply();
    }

    /** 多余空行提示（保存后由服务端的 blank_count 驱动；不自动改内容） */
    function showBlankNotice(count) {
      if (!blankBox) { return; }
      count = parseInt(count, 10) || 0;
      if (count > 0) {
        if (blankText) {
          blankText.textContent = '检测到 ' + count + ' 处多余空行（被两个正文段落包夹的空段，'
            + '通常是保存链路带进来的，不是你敲的）。连续空档与脚注、代码块内部不受影响。';
        }
        blankBox.hidden = false;
      } else {
        blankBox.hidden = true;
      }
    }

    /** 把服务端清理后的正文写回编辑面（不触发"未保存"标记） */
    function replaceHtml(html) {
      if (!editor || !html) { return false; }
      acceptingChanges = false;
      try {
        if (typeof editor.setHtml === 'function') {
          editor.setHtml(html);
        } else if (typeof editor.dangerouslyInsertHtml === 'function') {
          editor.clear();
          editor.dangerouslyInsertHtml(html);
        } else {
          acceptingChanges = true;
          return false;
        }
      } catch (e) {
        acceptingChanges = true;
        return false;
      }
      setTimeout(function () { acceptingChanges = true; markDirty(false); }, 250);
      return true;
    }

    /* ---------- 编辑器 ----------
     * ⚠️ 这个 WangEditor 构建的 API 形态与官方文档不同：
     *   · `createEditor()` 当场把编辑器建好，返回实例**没有** `.create()`；
     *   · 工具栏要用 `createToolbar()` **另外**创建（后台面板就是这么做的）。
     */
    var editorConfig = {
      placeholder: (cfg.i18n && cfg.i18n.placeholder) || '在这里写正文……',
      scroll: false,
      MENU_CONF: {}
    };

    if (cfg.canUpload && cfg.uploadUrl) {
      editorConfig.MENU_CONF.uploadImage = {
        server: cfg.uploadUrl,
        fieldName: 'wangeditor',
        maxFileSize: 10 * 1024 * 1024,
        allowedFileTypes: ['image/*'],
        // admin-ajax 需要 action 与 nonce 一起 POST；post_id 让附件挂到这篇文章上
        meta: { action: cfg.uploadAction, nonce: cfg.uploadNonce, post_id: String(postId) },
        metaWithUrl: false,
        headers: {},
        onError: function (file, err, res) {
          var msg = (res && res.message) || (err && err.message) || '上传失败';
          setStatus('图片上传失败：' + msg, 'err');
        }
      };
    }

    try {
      editor = W.createEditor({
        selector: '#ybh-fe-body',
        html: host.getAttribute('data-html') || '',
        config: editorConfig
      });
      if (editor && typeof editor.create === 'function') { editor.create(); }
    } catch (e) {
      setStatus('编辑器初始化失败：' + ((e && e.message) || '未知错误'), 'err');
      return;
    }

    var toolbarKeys = TOOLBAR_KEYS;
    if (!cfg.canUpload || !cfg.uploadUrl) {
      toolbarKeys = toolbarKeys.filter(function (k) { return k !== 'uploadImage' && k !== 'group-image'; });
    }
    if (W.createToolbar && toolbarHost) {
      try {
        W.createToolbar({
          editor: editor,
          selector: '#ybh-fe-toolbar',
          config: { excludeKeys: EXCLUDE, toolbarKeys: toolbarKeys }
        });
      } catch (e) {
        setStatus('工具栏没能完整加载（正文编辑不受影响）：' + ((e && e.message) || ''), 'warn');
      }
    }

    // 初始 HTML 写入会触发一次 change（见文件头第 2 点）⇒ 稍后才开始"计数"
    setTimeout(function () { acceptingChanges = true; }, 400);

    /* ---------- 保存 ---------- */
    function save(status, clean) {
      if (busy) { return; }
      busy = true;
      setStatus('正在保存……', 'busy');

      var data = new FormData();
      data.append('action', cfg.saveAction);
      data.append('nonce', cfg.saveNonce);
      data.append('post_id', String(postId));
      data.append('status', status);
      if (clean) { data.append('ybh_clean', '1'); }   // 「一键清理空行」走同一条保存路径
      data.append('post_title', titleEl ? titleEl.value : '');
      data.append('post_content', currentHtml());
      // T65：文章语言（地区字形）。空串 = 跟随站点。
      if (langEl) { data.append('ybh_lang', langEl.value); }
      // 分类 / 标签（服务端会再按能力过滤一次）；分类单选，只发一个值
      if (tagsEl) { data.append('ybh_tags', tagsEl.value); }
      var cats = selectedCats();
      if (cats.length) { data.append('ybh_cats', cats[0]); }

      fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: data
      }).then(function (r) {
        return r.json();
      }).then(function (res) {
        busy = false;
        if (!res || !res.success) {
          setStatus((res && res.data && res.data.message) || '保存失败，请重试。', 'err');
          return;
        }
        var d = res.data || {};
        if (d.id && !postId) {
          // 新文章第一次保存：记住 id 并换地址栏，刷新后不会重复建文章
          postId = parseInt(d.id, 10) || 0;
          host.setAttribute('data-post', String(postId));
          if (d.editUrl && window.history && window.history.replaceState) {
            try { window.history.replaceState(null, '', d.editUrl); } catch (e) {}
          }
          if (linkView && d.viewUrl) { linkView.href = d.viewUrl; linkView.hidden = false; }
        }
        markDirty(false);
        setStatus(d.message || '已保存', 'ok');
        // 保存时检查多余空行（站长要求：提醒 + 一键清理，不自动改）
        if (typeof d.blank_count === 'number') { showBlankNotice(d.blank_count); }
        if (d.cleaned > 0) {
          if (!replaceHtml(d.html)) {
            // 编辑面 API 不可用时至少让作者知道"库里已经清理过了"
            setStatus(d.message + '（刷新页面即可看到）', 'ok');
          }
          showBlankNotice(0);
        }
      }).catch(function () {
        busy = false;
        setStatus('网络异常，没保存上。内容还在页面上，请重试。', 'err');
      });
    }

    /* ---------- 事件 ---------- */
    if (btnDraft) { btnDraft.addEventListener('click', function () { save('draft'); }); }
    if (blankBtn) { blankBtn.addEventListener('click', function () { save('draft', true); }); }
    if (btnSubmit) {
      btnSubmit.addEventListener('click', function () {
        save(btnSubmit.getAttribute('data-status') || 'pending');
      });
    }
    if (titleEl) { titleEl.addEventListener('input', function () { markDirty(true); }); }
    if (catsEl) { catsEl.addEventListener('change', function () { markDirty(true); }); }

    initTags();
    initSideToggle();
    initMathButton();

    /* T65：切换文章语言时**立刻**把 lang 打到编辑区上，作者马上能看到正确字形 */
    if (langEl) {
      langEl.addEventListener('change', function () {
        markDirty(true);
        var v = langEl.value || '';
        if (v) { host.setAttribute('lang', v); } else { host.removeAttribute('lang'); }
        setStatus(v ? ('已切换为 ' + langEl.options[langEl.selectedIndex].text + '（保存后生效）') : '已切回跟随站点（保存后生效）', 'warn');
      });
    }

    /* ---------- 插入公式（T68）----------
     * 主题前台已内置 MathJax（检测到 $…$ / \(…\) 等定界符就自动加载排版）。
     * 这里只在光标处插入一对定界符，剩下交给作者写公式。 */
    function initMathButton() {
      var btn = document.getElementById('ybh-fe-insert-math');
      if (!btn || btn.getAttribute('data-math-init') === '1') { return; }
      btn.setAttribute('data-math-init', '1');
      btn.addEventListener('click', function () {
        var editable = host.querySelector('.w-e-text-container [contenteditable="true"]');
        if (!editable) { setStatus('编辑区还没准备好。', 'warn'); return; }
        editable.focus();
        var ok = false;
        try { ok = document.execCommand('insertText', false, '\\(\\)'); } catch (e) {}
        if (!ok) {
          // execCommand 不可用的兜底：手动插文本节点
          var sel = window.getSelection();
          var node = document.createTextNode('\\(\\)');
          sel.getRangeAt(0).insertNode(node);
          sel.getRangeAt(0).setStart(node, 2);
          sel.getRangeAt(0).setEnd(node, 2);
        } else {
          // 光标移到 \( 与 \) 中间，方便直接输入
          try {
            var s = window.getSelection();
            if (s && s.modify) {
              s.modify('extend', 'backward', 'character');
              s.modify('move', 'forward', 'character');
              s.collapseToEnd();
            }
          } catch (e) {}
        }
        markDirty(true);
      });
    }

    editor.on('change', function () {
      if (!acceptingChanges) { return; }
      markDirty(true);
      setStatus('有未保存的改动', 'warn');
    });

    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.keyCode === 83)) {
        e.preventDefault();
        save('draft');
      }
    }, true);

    setStatus((cfg.i18n && cfg.i18n.ready) || '就绪：写好后点「保存草稿」或「提交审核」', 'idle');

    // 暴露给 pjax 处理器：换页时用它清脏标记与销毁实例
    window.__ybhFeTeardown = function () {
      markDirty(false);
      try { if (editor && typeof editor.destroy === 'function') { editor.destroy(); } } catch (e) {}
      editor = null;
      if (host) { host.removeAttribute('data-ybh-fe-init'); }
    };
  }

  /**
   * 带重试的初始化：pjax 的 `pjax:complete` 有时**早于**新内容挂进 DOM 触发
   * （实测：回来后 `#ybh-fe-body` 已在文档里，但初始化没跑到 —— 事件先到、DOM 后到）。
   * 与其猜对方的时序，不如按需重试几次：拿到容器就初始化，最多等 ~2 秒。
   */
  function initWithRetry(tries) {
    var host = document.getElementById('ybh-fe-body');
    if (host) {
      initEditor();
      if (host.getAttribute('data-ybh-fe-init') === '1') { return; }
    }
    if (tries > 0) { setTimeout(function () { initWithRetry(tries - 1); }, 250); }
  }

  /** pjax 换页：旧页面的编辑器随 DOM 消失 —— 清掉脏标记，并在新页面又是写作页时重新初始化 */
  document.addEventListener('pjax:complete', function () {
    if (typeof window.__ybhFeTeardown === 'function') {
      try { window.__ybhFeTeardown(); } catch (e) {}
      window.__ybhFeTeardown = null;
    }
    initWithRetry(8);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEditor);
  } else {
    initEditor();
  }
})();
