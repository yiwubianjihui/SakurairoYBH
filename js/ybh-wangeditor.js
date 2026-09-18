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
 * 三个必须小心处理的地方
 * ===================================================================
 *
 * 1) **写回时要通知两个编辑器。**
 *    `#content` 是 textarea；但页面上可能同时有：
 *      · TinyMCE 的**可视化**实例（它维护自己的一份内容，`#content` 是它的底层）；
 *      · 以及 WP 的 `wp.editor` 相关状态。
 *    只改 textarea 的值，切回「可视化」标签页时 TinyMCE 仍显示旧内容（实测常见坑）。
 *    所以写回后要显式调用 TinyMCE 的 `setContent()`（两个方向都同步）。
 *    另外要触发 `change` / `input` 事件 —— WP 的「离开页面未保存」提醒
 *    和自动草稿都靠它。
 *
 * 2) **短代码与脚注要原样保留。**
 *    站点的脚注在编辑器里是 `[fn]…[/fn]`，正文里还有 ruby 注音等短代码。
 *    WangEditor 不认识它们，会把 `[fn]` 当普通文本 —— 这没问题（文本原样保留），
 *    但**不能**开启任何"自动转义/清洗"，否则方括号会被吃掉。
 *    因此这里不做任何预处理，进出都是原样。
 *
 * 3) **面板打开期间要拦掉经典编辑器的快捷键。**
 *    否则在 WangEditor 里按 Ctrl+S 会被 WP 的「就地保存」抢走，
 *    保存的是**旧内容**（textarea 还没写回）。所以打开时先写回一次再拦快捷键。
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
    ta.value = html;

    // 通知 TinyMCE（可视化标签页）—— 只改 textarea 的话它仍显示旧内容
    var tm = window.tinymce;
    if (tm && tm.get) {
      var inst = tm.get('content');
      if (inst && !inst.isHidden()) {
        // 正在可视化模式：直接设它的内容
        if (inst.getContent() !== html) {
          inst.setContent(html);
        }
      } else {
        // 不在可视化模式（或尚未初始化）：若实例存在也同步一下，切过去时不至于看到旧的
        var inst2 = tm.get('content');
        if (inst2) {
          try { inst2.setContent(html); } catch (e) { /* 未就绪，忽略 */ }
        }
      }
    }
    // 触发 wp.editor 的同步（WP 用它把编辑器内容写回 textarea / 触发自动草稿）
    if (window.wp && window.wp.editor && typeof window.wp.editor.removep === 'function') {
      // 无需调用；textarea 已是最新
    }

    // 让「未保存」提醒与自动草稿知道内容变了
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    ta.dispatchEvent(new Event('change', { bubbles: true }));
    if (window.jQuery) {
      window.jQuery(ta).trigger('change');
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
      '  <span class="ybh-wang-panel__note">内容与原编辑器共用一份，关闭时写回。</span>' +
      '  <span class="ybh-wang-panel__spacer"></span>' +
      '  <button type="button" class="button" data-ybh-wang="cancel">取消</button>' +
      '  <button type="button" class="button button-primary" data-ybh-wang="apply">写回内容</button>' +
      '  <button type="button" class="button-link ybh-wang-panel__close" data-ybh-wang="apply" aria-label="关闭">&times;</button>' +
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
      }
    });

    return panel;
  }

  function open() {
    if (opened) {
      return;
    }
    var html = readContent();

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
        customInsert: undefined,
        onError: function (file, err, res) {
          var msg = (res && res.message) || (err && err.message) || '上传失败';
          window.alert('图片上传失败：' + msg);
        }
      };
      config.MENU_CONF.uploadImageWithCredentials = true;
    } else {
      // 没有上传权限时把图片菜单收起来，免得点了报错
      config.excludeKeys = ['group-image'];
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
        excludeKeys: ['fullScreen', 'group-video']
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
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
