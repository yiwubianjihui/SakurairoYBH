/**
 * YBH · WangEditor 5 全屏面板（附加入口）
 *
 * ===================================================================
 * 它做什么
 * ===================================================================
 *
 *   点「用 WangEditor 编辑」→ 盖一层全屏面板，在里面用 WangEditor 编辑
 *   同一篇内容 → 关闭时把 HTML 写回经典编辑器的 `#content`。
 *
 *   原编辑器（TinyMCE 可视化 / 文本）**完全不动**：
 *   我们只在打开时读一次它的值、在关闭时写回一次，
 *   中间不碰它的 DOM、不碰它的实例。
 *
 * ===================================================================
 * 工具栏的取舍（用户明确要求）
 * ===================================================================
 *
 * ① **禁止调整字号 / 行高 / 字体** —— 本站正文字体、字号、行高是主题统一定的
 *    （`.entry-content p { line-height: 2 }` 等），作者在正文里改这些只会让
 *    同一篇文章里出现几种排版，前后不一致。所以这三个菜单**直接不出现在工具栏**，
 *    而不是"能用但不建议" —— 看不见就不会误用。
 *    另外 `color`/`bgColor` 也一并去掉：正文颜色同样由主题控制。
 *
 * ② **补齐原 TinyMCE 的功能** —— 自定义菜单在 js/ybh-wangeditor-menus.js 里注册，
 *    这里只负责把它们排进工具栏。
 *
 * ===================================================================
 * 三个必须小心处理的地方
 * ===================================================================
 *
 * 1) **写回时要通知两个编辑器。**
 *    `#content` 是 textarea；但页面上可能同时有 TinyMCE 的**可视化**实例。
 *    只改 textarea 的值，切回「可视化」标签页时 TinyMCE 仍显示旧内容（实测常见坑）。
 *    所以写回后要显式调用 TinyMCE 的 `setContent()`。
 *
 * 2) **短代码与脚注要原样保留。**
 *    站点的脚注在编辑器里是 `[fn]…[/fn]`，正文里还有 ruby 注音等短代码。
 *    WangEditor 不认识它们，会把 `[fn]` 当普通文本 —— 这没问题（文本原样保留），
 *    但**不能**开启任何"自动转义/清洗"，否则方括号会被吃掉。
 *
 * 3) **面板打开期间要拦掉经典编辑器的快捷键。**
 *    否则在 WangEditor 里按 Ctrl+S 会被 WP 的「就地保存」抢走，
 *    保存的是**旧内容**。所以打开时先写回一次再拦快捷键。
 */
(function () {
  'use strict';

  if (typeof window.wangEditor === 'undefined') {
    return;
  }

  var W = window.wangEditor;
  var CFG = window.YBH_WANG || {};

  var panel = null;
  var editor = null;
  var toolbar = null;
  var opened = false;

  /* ---------- 与经典编辑器交换内容 ---------- */

  function textarea() {
    return document.getElementById('content');
  }

  function readContent() {
    var ta = textarea();
    var raw = ta ? ta.value : '';
    if (!window.YBH_Content || !window.YBH_Content.normalize) {
      return raw;
    }
    /*
     * ⚠️ **失败必须往"不清洗"方向失败，绝不能往"丢内容"方向失败。**
     *
     * 实测遇到过：某次往返里 normalize 返回了空串，而写回时又照写，
     * 结果 textarea 被清空 —— 如果那时作者点了保存，**文章内容就没了**。
     * 内容丢失是不可接受的，所以这里加硬兜底：
     * 只要"输入非空、输出为空"，就**原样返回输入**（宁可不清洗）。
     */
    var norm = '';
    try {
      norm = window.YBH_Content.normalize(raw);
    } catch (e) {
      return raw;
    }
    if (!norm && String(raw).replace(/[\s\u00a0]/g, '') !== '') {
      return raw;   // 兜底：不清洗，但绝不丢
    }
    return norm;
  }

  function writeContent(html) {
    var ta = textarea();
    if (!ta) {
      return;
    }
    ta.value = stripInlineTypography(html);

    // 通知 TinyMCE（可视化标签页）—— 只改 textarea 的话它仍显示旧内容
    var tm = window.tinymce;
    if (tm && tm.get) {
      var inst = tm.get('content');
      if (inst) {
        try {
          if (inst.getContent() !== ta.value) {
            inst.setContent(ta.value);
          }
        } catch (e) { /* 未就绪，忽略 */ }
      }
    }

    // 让「未保存」提醒与自动草稿知道内容变了
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    ta.dispatchEvent(new Event('change', { bubbles: true }));
    if (window.jQuery) {
      window.jQuery(ta).trigger('change');
    }
  }

  /**
   * 剥掉正文里的**行内排版样式** + 规范化结构。
   *
   * 用户明确要求「禁止调整字号、行高、字体」，而且反馈过
   * 「编辑后页间距变得很小」「切换编辑器多出许多回车空行」——
   * 这两件事都源于内容在两个编辑器之间往返时没有统一归约。
   *
   * 现在统一交给 `window.YBH_Content.normalize()`（TinyMCE 那边也用同一个），
   * 它一次做完：换行统一、纯文本包 `<p>`、空段落删除、块间裸换行删除、
   * 内联排版样式剥离（**含 margin/padding** —— 这是"间距变小"的真凶：
   * 内联 `margin:0` 优先于主题的 `p { margin: 0 0 10px }`）。
   */
  function stripInlineTypography(html) {
    if (!window.YBH_Content || typeof window.YBH_Content.normalize !== 'function') {
      return fallbackStripStyles(html);
    }
    var norm = '';
    try {
      norm = window.YBH_Content.normalize(html);
    } catch (e) {
      return fallbackStripStyles(html);
    }
    /*
     * 与 readContent 同一条硬规矩：**输入非空、输出为空 ⇒ 回退到输入**。
     * 写回这一步一旦清空，作者再一保存就是真的丢内容，不可接受。
     */
    if (!norm && String(html).replace(/[\s\u00a0]/g, '') !== '') {
      return fallbackStripStyles(html);
    }
    return norm;
  }

  /** 兜底：至少把内联排版样式剥掉（不清结构，绝不丢内容） */
  function fallbackStripStyles(html) {
    if (!html || typeof html !== 'string') {
      return html;
    }
    return html.replace(/\sstyle="([^"]*)"/gi, function (whole, styles) {
      var kept = styles.split(';').filter(function (one) {
        var prop = one.split(':')[0].trim().toLowerCase();
        if (!prop) { return false; }
        return ['line-height', 'font-size', 'font-family', 'color',
                'background-color', 'background',
                'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
                'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
                'text-indent', 'letter-spacing', 'word-spacing'].indexOf(prop) < 0;
      });
      return kept.length ? ' style="' + kept.join(';') + '"' : '';
    });
  }

  /* ---------- 工具栏配置 ---------- */

  /*
   * 要**排除**的内置菜单。
   * 前三个是用户明确要求禁止的（字号 / 行高 / 字体）；
   * color/bgColor 同理 —— 正文颜色由主题控制，作者改了就与全站不一致；
   * group-video 本站不用视频（封面另有机制），去掉以免误插。
   */
  var EXCLUDE = [
    'fontSize', 'fontFamily', 'lineHeight',   // ← 用户明确要求禁止
    'color', 'bgColor',                        // ← 同理：正文颜色由主题定
    'group-video',                             // ← 本站不用视频
    'fullScreen'                               // ← 面板本身已是全屏，留着重复
  ];

  /*
   * 工具栏顺序：先按原 TinyMCE 的分组习惯排，再补上 YBH 自定义菜单。
   * 自定义菜单名（在 js/ybh-wangeditor-menus.js 里注册）：
   *   ybhFootnote / ybhSup / ybhSub / ybhIndent / ybhFindReplace /
   *   ybhRuby / ybhCharmap / ybhPasteText / ybhMore
   *
   * ⚠️ T44 的「清理空段落」按钮（ybhCleanParas）已撤掉：
   *    2026-09-22 起**空行是要保留的内容**，留着这个按钮只会误删作者的空行。
   */
  var TOOLBAR_KEYS = [
    // 段落与标题格式（对应 TinyMCE 的 formatselect）
    'headerSelect',
    '|',
    // 行内格式
    'bold', 'italic', 'underline', 'through', 'code',
    'ybhSup', 'ybhSub', 'ybhRuby',
    '|',
    // 列表与引用
    'bulletedList', 'numberedList', 'blockquote',
    '|',
    // 对齐（对应 alignleft/center/right）
    'justifyLeft', 'justifyCenter', 'justifyRight',
    '|',
    // YBH 专属：脚注 / 段首缩进
    'ybhFootnote', 'ybhIndent',
    '|',
    // 链接、图片、表格、分割线
    'insertLink', 'uploadImage', 'insertTable', 'divider',
    '|',
    // YBH 专属：更多分隔符
    'ybhMore',
    '|',
    // 编辑辅助
    'ybhFindReplace', 'ybhPasteText', 'ybhCharmap',
    '|',
    // 撤销
    'undo', 'redo'
  ];

  /* ---------- 段首缩进的渲染（否则 .ybh-indent 在编辑面里看不出来） ---------- */

  function registerIndentRenderer() {
    if (!W.Boot || !W.SlateElement || !W.SlateNode || !W.h) {
      return;
    }
    try {
      W.Boot.registerRenderElem({
        type: 'paragraph',
        renderElem: function (elem, children, editor) {
          // 用 WangEditor 自己的 p 渲染，只是多挂一个 class
          var cls = elem.ybhIndent ? 'ybh-indent' : '';
          return W.h('p', { className: cls }, children);
        }
      });
    } catch (e) {
      // 覆盖内置 paragraph 渲染失败时不影响使用（只是缩进看不出来）
    }
  }

  /* ---------- 纯文本粘贴：按新规范自己排版 ---------- */

  /**
   * **1 个回车 = 分段、2 个回车 = 空行**（2026-09-22 起的新规范）。
   *
   * WangEditor 内置的文本粘贴用的是旧语义（单个换行 → 硬换行），
   * 所以整条接管：只在**捕获阶段**监听 —— WangEditor 自己的处理器绑在编辑区上，
   * 我们在它的祖先节点上捕获，必然先拿到事件；`stopPropagation()` 之后它就不再处理。
   *
   * ⚠️ 接管范围与 TinyMCE 那侧保持一致（见 js/ybh-editor-para.js 的 bindTextPaste）：
   *    · 没有 `text/html`（纯文本）→ 接管；
   *    · `text/html` 只是「p/br/span、无属性」这种简单内容 → 也接管
   *      （这类内容按纯文本解释才对，"两个回车 = 空行"才认得出来）；
   *    · 真富内容（带样式的 Word/微信/网页）→ 交给 WangEditor 自己解析，
   *      写回时再由 YBH_Content.normalize 统一规范化。
   */
  function bindTextPaste(host) {
    if (!host || !host.addEventListener) {
      return;
    }
    host.addEventListener('paste', function (e) {
      var cd = e.clipboardData;
      if (!cd || !cd.getData) {
        return;
      }
      var html = '';
      var text = '';
      try { html = cd.getData('text/html') || ''; } catch (err) { html = ''; }
      try { text = cd.getData('text/plain') || ''; } catch (err) { text = ''; }
      if (!text) {
        return;
      }
      if (html && !isSimpleHtml(html)) {
        return;
      }
      e.preventDefault();
      e.stopPropagation();

      var C = window.YBH_Content;
      if (!C || typeof C.textToParagraphs !== 'function') {
        return;
      }
      if (editor && typeof editor.dangerouslyInsertHtml === 'function') {
        editor.dangerouslyInsertHtml(C.textToParagraphs(text));
      }
    }, true);
  }

  /** 与 TinyMCE 侧同一套判定：只有 p/br/span 且不带属性 = 简单内容 */
  function isSimpleHtml(html) {
    var stripped = String(html || '').replace(/<\/?(?:p|br|span)\s*\/?>/gi, '');
    return !/<[a-zA-Z!\/][^>]*>/.test(stripped);
  }

  /* ---------- 面板 ---------- */

  function buildPanel() {
    if (panel) {
      return panel;
    }

    panel = document.createElement('div');
    panel.className = 'ybh-wang-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', 'WangEditor 编辑器');
    panel.innerHTML =
      '<div class="ybh-wang-panel__head">' +
      '  <strong class="ybh-wang-panel__title">WangEditor</strong>' +
      '  <span class="ybh-wang-panel__note">' +
      '内容与原编辑器共用一份；字号 / 行高 / 字体由主题统一控制，这里不提供调整。' +
      '  </span>' +
      '  <span class="ybh-wang-panel__spacer"></span>' +
      '  <span class="ybh-wang-panel__saved" data-ybh-wang="saved-hint" aria-live="polite"></span>' +
      '  <button type="button" class="button" data-ybh-wang="cancel">关闭</button>' +
      '  <button type="button" class="button" data-ybh-wang="apply">写回内容</button>' +
      '  <button type="button" class="button" data-ybh-wang="save">保存文章</button>' +
      '  <button type="button" class="button-link ybh-wang-panel__close" data-ybh-wang="cancel" aria-label="关闭">&times;</button>' +
      '</div>' +
      '<div class="ybh-wang-panel__body">' +
      '  <div class="ybh-wang-panel__toolbar" data-ybh-wang="toolbar"></div>' +
      '  <div class="ybh-wang-panel__editor" data-ybh-wang="editor"></div>' +
      '</div>';

    document.body.appendChild(panel);

    panel.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-ybh-wang]') : null;
      if (!t) {
        return;
      }
      var act = t.getAttribute('data-ybh-wang');
      if (act === 'apply') {
        close(true);
      } else if (act === 'cancel') {
        close(false);
      } else if (act === 'save') {
        saveFromPanel();
      }
    });

    return panel;
  }

    /** 取文章 ID（新建文章页 #post_ID 可能是空，回落到本地化数据与 URL） */
  function resolvePostId() {
    var byId = document.getElementById('post_ID');
    if (byId && byId.value && byId.value !== '0') {
      return byId.value;
    }
    var byName = document.querySelector('input[name="post_ID"]');
    if (byName && byName.value && byName.value !== '0') {
      return byName.value;
    }
    var d = window.YBH_EditorData || {};
    if (d.postId && String(d.postId) !== '0') {
      return String(d.postId);
    }
    var m = window.location.search.match(/[?&]post=(\d+)/);
    if (m) { return m[1]; }
    return '';
  }

  /**
   * 面板里直接「保存文章」。
   *
   * 复用的是**已有的就地保存**（`window.YBH_Editor.save()` → admin-ajax
   * `ybh_quick_save`，见 inc/ybh/quick-save.php），不另造一套 ——
   * 那套还负责同步 TinyMCE、把 WP 的"有未保存改动"标记归零，
   * 自己写一套很容易漏掉这些。
   *
   * ⚠️ 顺序不能反：**必须先写回 `#content`，再 save()** ——
   * quickSave 读的就是 textarea 的值，先保存就会存进旧内容。
   *
   * 保存成功后**不关面板**：作者多半还要接着写。
   */
  function saveFromPanel() {
    var hint = panel ? panel.querySelector('[data-ybh-wang="saved-hint"]') : null;

    if (editor && typeof editor.getHtml === 'function') {
      writeContent(editor.getHtml());
    }

    if (!window.YBH_Editor || typeof window.YBH_Editor.save !== 'function') {
      setHint('就地保存不可用，请用右上角的「更新 / 发布」按钮', true);
      return;
    }

    // quickSave 不返回 Promise，它用 jQuery 的 done/fail + 自定义事件反馈，
    // 所以这里挂一次性监听来更新面板上的提示。
    var done = false;
    var onSaved = function (ev, d) {
      done = true;
      var human = (d && d.human) ? d.human : '';
      setHint('已保存 ' + human, false);
      if (window.jQuery) { window.jQuery(document).off('ybh-quick-saved', onSaved); }
    };
    if (window.jQuery) {
      window.jQuery(document).one('ybh-quick-saved', onSaved);
    }

    setHint('保存中…', false);
    window.YBH_Editor.save();

    // 4 秒还没等到成功事件，就把提示收回（避免一直停在"保存中"）
    setTimeout(function () {
      if (!done && hint && hint.textContent === '保存中…') {
        setHint('保存请求已发出；若未生效请看右上角提示', false);
      }
    }, 4000);
  }

  function setHint(text, isErr) {
    if (!panel) {
      return;
    }
    var hint = panel.querySelector('[data-ybh-wang="saved-hint"]');
    if (!hint) {
      return;
    }
    hint.textContent = text || '';
    hint.className = 'ybh-wang-panel__saved' + (isErr ? ' is-err' : '');
  }

  function open() {
    if (opened) {
      return;
    }
    var html = readContent();

    registerIndentRenderer();

    buildPanel();
    panel.classList.add('is-open');
    document.documentElement.classList.add('ybh-wang-open');
    opened = true;

    var editorEl = panel.querySelector('[data-ybh-wang="editor"]');
    var toolbarEl = panel.querySelector('[data-ybh-wang="toolbar"]');

    var config = {
      placeholder: '在这里写正文……（支持 Markdown 快捷输入，如 # 空格 变标题）',
      // 站点正文里大量使用 [fn]…[/fn] 与其它短代码：**不要**做任何自动转义
      MENU_CONF: {}
    };

    if (CFG.canUpload && CFG.uploadUrl) {
      config.MENU_CONF.uploadImage = {        server: CFG.uploadUrl,
        fieldName: 'wangeditor',
        maxFileSize: 10 * 1024 * 1024,
        allowedFileTypes: ['image/*'],
        // WP 的 admin-ajax 需要 action 与 nonce 一起 POST
        meta: { action: CFG.action, nonce: CFG.nonce },
        metaWithUrl: false,
        headers: {},
        onError: function (file, err, res) {
          var msg = (res && res.message) || (err && err.message) || '上传失败';
          window.alert('图片上传失败：' + msg);
        }
      };
    } else {
      config.excludeKeys = EXCLUDE.concat(['group-image']);
    }

    editor = W.createEditor({
      selector: editorEl,
      html: html,
      config: config
    });

    // 纯文本粘贴按新规范（1 个回车 = 分段、2 个回车 = 空行）自己排版
    bindTextPaste(editorEl);

    toolbar = W.createToolbar({
      editor: editor,
      selector: toolbarEl,
      config: {
        // ① 禁止字号 / 行高 / 字体（用户明确要求）
        excludeKeys: EXCLUDE,
        // ② 按指定顺序排（含 YBH 自定义菜单）
        toolbarKeys: TOOLBAR_KEYS
      }
    });

    // Esc 关闭（写回）；Ctrl+S 在面板里表示"写回"，不交给 WP
    panel.addEventListener('keydown', onKey, true);
    setTimeout(function () {
      if (editor && editor.focus) {
        editor.focus();
      }
    }, 60);
  }

  function onKey(e) {
    if (e.key === 'Escape') {
      e.preventDefault();
      close(true);
    } else if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
      e.preventDefault();
      close(true);
    }
  }

  function close(apply) {
    if (!opened) {
      return;
    }
    opened = false;

    if (apply && editor && typeof editor.getHtml === 'function') {
      writeContent(editor.getHtml());
      if (window.YBH_Editor && typeof window.YBH_Editor.toast === 'function') {
        window.YBH_Editor.toast('已写回编辑器内容（还没保存文章）');
      }
    }


    panel.removeEventListener('keydown', onKey, true);
    document.documentElement.classList.remove('ybh-wang-open');

    // 销毁编辑器实例，避免再次打开时两个实例抢同一个容器
    try {
      if (toolbar && toolbar.destroy) { toolbar.destroy(); }
      if (editor && editor.destroy) { editor.destroy(); }
    } catch (e) { /* 忽略销毁异常 */ }
    toolbar = null;
    editor = null;

    if (panel) {
      panel.classList.remove('is-open');
    }
  }

  /* ---------- 打开按钮 ---------- */
  function bind() {
    var btn = document.getElementById('ybh-wang-open');
    if (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        open();
      });
    }
    bindFormSafety();
  }

  /**
   * 安全网：WordPress 自己的「保存草稿 / 发布 / 更新」提交前，**自动写回内容**。
   *
   * 为什么必须有：按用户要求回退之后，WangEditor 只是"文字输入工具"——
   * 保存与发布都由 WordPress 自己的按钮负责（面板里不再有发布按钮）。
   * 而作者很容易在没点「写回内容」的情况下直接去点「发布」，
   * 那样提交的就是 `#content` 里的**旧内容**，等于白写一场。
   *
   * 做法：在表单 `submit` 的**捕获阶段**写回，抢在 WordPress 自己的提交逻辑之前。
   */
  function bindFormSafety() {
    var form = document.getElementById('post');
    if (!form) {
      return;
    }
    form.addEventListener('submit', function () {
      if (!opened || !editor || typeof editor.getHtml !== 'function') {
        return;   // 面板没开，没什么要写回的
      }
      writeContent(editor.getHtml());
    }, true);
  }



  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
