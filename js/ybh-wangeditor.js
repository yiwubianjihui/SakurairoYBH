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
    return ta ? ta.value : '';
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
   * 剥掉正文里的**行内排版样式**（行高 / 字号 / 字族 / 颜色）。
   *
   * 为什么必须剥：用户明确要求「禁止调整字号、行高、字体」。
   * 工具栏里那三个菜单已经隐藏了，但 WangEditor 仍会把默认行高写成段落的内联样式
   * （实测段落上会带 `style="line-height:1.73"`）。
   * 内联样式优先级高于主题 CSS，**存进数据库后就永久盖住前台排版** ——
   * 编辑几次，文章就跟全站不一致了。
   * 所以在写回 `#content` 这一步统一剥掉，从源头上保证"库里存的正文不含这类样式"。
   *
   * 只动这几条属性，其它 style（如对齐时的 text-align）保持原样。
   */
  function stripInlineTypography(html) {
    if (!html || typeof html !== 'string') {
      return html;
    }
    return html.replace(/\sstyle="([^"]*)"/gi, function (whole, styles) {
      var kept = styles.split(';').filter(function (one) {
        var prop = one.split(':')[0].trim().toLowerCase();
        if (!prop) { return false; }
        return ['line-height', 'font-size', 'font-family', 'color',
                'background-color', 'background'].indexOf(prop) < 0;
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
   *   ybhCleanParas / ybhRuby / ybhCharmap / ybhPasteText / ybhMore
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
    'ybhFindReplace', 'ybhCleanParas', 'ybhPasteText', 'ybhCharmap',
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
      '  <button type="button" class="button button-primary" data-ybh-wang="save">保存文章</button>' +
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
      config.MENU_CONF.uploadImage = {
        server: CFG.uploadUrl,
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

    // 用户主动关掉面板 ⇒ 本次会话不再自动弹（否则一关就被弹回来，没法退出）
    if (!apply || true) {
      sessionStorage.setItem('ybhWangOptOut', '1');
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

  /* ---------- 打开按钮 + 「默认编辑器」偏好 ---------- */
  function bind() {
    var btn = document.getElementById('ybh-wang-open');
    if (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        open();
      });
    }

    bindDefaultPref();
    maybeAutoOpen();
  }

  /**
   * 「默认编辑器」开关：勾选即把偏好写进用户资料（服务端持久化，
   * 换设备也跟着走），而不是只存浏览器。
   */
  function bindDefaultPref() {
    var cb = document.getElementById('ybh-editor-default-cb');
    if (!cb) {
      return;
    }
    cb.addEventListener('change', function () {
      var cfg = window.YBH_WANG_DEFAULT_CFG;
      if (!cfg || !cfg.ajaxUrl) {
        return;
      }
      var body = new URLSearchParams();
      body.append('action', 'ybh_editor_pref');
      body.append('nonce', cfg.nonce);
      body.append('editor', cb.checked ? 'wangeditor' : 'classic');

      fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      }).then(function (r) { return r.json(); }).then(function (j) {
        var msg = (j && j.success) ? '已保存：默认编辑器 = ' + (cb.checked ? 'WangEditor' : '经典编辑器')
                                   : '保存失败，请重试';
        if (window.YBH_Editor && window.YBH_Editor.toast) {
          window.YBH_Editor.toast(msg);
        }
      }).catch(function () {
        if (window.YBH_Editor && window.YBH_Editor.toast) {
          window.YBH_Editor.toast('保存失败，请检查网络');
        }
      });
    });
  }

  /**
   * 若用户偏好是 WangEditor，进编辑页就**自动展开面板**，不用再点按钮。
   *
   * ⚠️ 有两个"不要自动弹"的情况：
   *   · 用户点了「取消 / 关闭」—— 记在 sessionStorage 里，本次会话不再弹，
   *     否则一关就被弹回来，等于没法退出；
   *   · URL 带 `?ybh_editor=classic` —— 临时压过一次，方便排查。
   */
  function maybeAutoOpen() {
    var cfg = window.YBH_WANG_DEFAULT_CFG;
    if (!cfg) {
      return;
    }
    if (cfg.override === 'classic') {
      return;
    }
    if (cfg.current !== 'wangeditor') {
      return;
    }
    if (sessionStorage.getItem('ybhWangOptOut') === '1') {
      return;
    }
    /*
     * ⚠️ 这里**不能**用 `document.activeElement` 判断"作者是不是已经在打字了" ——
     * WordPress 打开编辑页时会**自动把焦点放到标题框**，于是 activeElement 永远是
     * 那个 INPUT，条件恒为真 ⇒ 永远不会自动弹（实测踩到）。
     * 改成看"标题框里**有没有内容**"：空的说明还没开始写，该弹；
     * 已经有标题了说明作者在写，不打扰。
     */
    var title = document.getElementById('title');
    if (title && title.value && title.value.trim() !== '') {
      return;
    }
    // 等经典编辑器初始化完再开，避免抢时序
    setTimeout(function () {
      if (!opened) {
        open();
      }
    }, 600);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
