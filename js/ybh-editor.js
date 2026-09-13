/**
 * YBH · 经典编辑器「脚注」按钮 + 编辑器内可视化
 *
 * 需求（任务清单第 6 项）：为编辑器添加脚注功能。
 * 目标：投稿者**不必手写短代码**，也能看见脚注在文章里的位置与内容。
 *
 * ---------------------------------------------------------------
 * 两类形态（同一份内容的两种表示）
 *
 *   源码形态（数据库里真正存的）   [fn]注释文字[/fn]
 *   可视形态（编辑器里看到的）      <span class="ybh-fn-chip">注释文字</span>
 *
 *   保存 → `GetContent` 把可视形态换回源码形态；
 *   载入 → `BeforeSetContent` 把源码形态换成可视形态。
 *   也就是说：**数据库里永远只有 `[fn]…[/fn]`**，
 *   前台渲染由 `inc/ybh/footnotes.php` 负责，编辑器标记不会外泄。
 *
 *   `PostProcess` 一并转换：撤销/重做栈里存源码形态，
 *   这样 Ctrl+Z 回到过去时也会重新经过 `BeforeSetContent`，不会留下裸标记。
 *
 * ---------------------------------------------------------------
 * 为什么可视形态是可编辑的行内元素，而不是「不可编辑的胶囊」
 *
 *   胶囊（contenteditable=false）看着更"控件化"，但要改注释文字就得
 *   删掉重插，写作时很别扭。这里选择**行内、可直接改写**的方案：
 *   注释文字就在正文里，点进去就能改，和写普通文字一样。
 *   编号不用手工维护 —— `css/editor-style.css` 用 CSS 计数器
 *   （`counter-increment: ybh-fn`）按出现顺序自动显示「注1」「注2」…
 *   与前台渲染的编号规则完全一致。
 *
 *   代价（已在交接文档写明）：在编辑器里手动删掉整段可视标记，
 *   等于删掉这条脚注；文本模式下看到的是原始 `[fn]…[/fn]`。
 *
 * ---------------------------------------------------------------
 * 与其它插件的关系
 *
 *   · 工具栏数组由 `inc/ybh/editor.php` 的 prio 999 过滤器写死，
 *     因此按钮名 `ybh_footnote` 必须写进**那个数组**，不能另挂过滤器追加；
 *   · 注音按钮（`ruby`）由 wp-yomigana 插件自己挂载，本插件不碰它；
 *   · 插件文件通过 `mce_external_plugins` 注册。注意 WP 核心
 *     （class-wp-editor.php L504）会把「已出现在 `tiny_mce_plugins` 里的名字」
 *     从 external_plugins 中剔除，所以**不要**再往 `tiny_mce_plugins` 里加本插件名；
 *     TinyMCE 会自动把 external_plugins 的名字追加进 plugins 列表（已实测确认）。
 */
(function () {
  'use strict';

  if (typeof window.tinymce === 'undefined' || !window.tinymce.PluginManager) {
    return;
  }

  var OPEN = '[fn]';
  var CLOSE = '[/fn]';
  var CHIP = 'ybh-fn-chip';
  var PLACEHOLDER = '脚注内容';

  /** 源码形态 → 可视形态 */
  var SRC_RE = /\[fn\]([\s\S]*?)\[\/fn\]/g;
  /** 可视形态 → 源码形态（属性顺序不敏感，只要 class 里有 ybh-fn-chip） */
  var VIS_RE = /<span[^>]*class="[^"]*\bybh-fn-chip\b[^"]*"[^>]*>([\s\S]*?)<\/span>/g;

  function toVisual(html) {
    if (typeof html !== 'string' || html.indexOf(OPEN) === -1) {
      return html;
    }
    return html.replace(SRC_RE, function (m, inner) {
      return '<span class="' + CHIP + '">' + inner + '</span>';
    });
  }

  function toSource(html) {
    if (typeof html !== 'string' || html.indexOf(CHIP) === -1) {
      return html;
    }
    return html.replace(VIS_RE, function (m, inner) {
      return OPEN + inner + CLOSE;
    });
  }

  /** 取当前光标所在（或刚插入）的脚注标记 */
  function currentChip(editor) {
    var rng;
    try {
      rng = editor.selection.getRng();
    } catch (e) {
      rng = null;
    }
    if (rng) {
      var node = rng.startContainer;
      if (node && node.nodeType === 3) {
        node = node.parentNode;
      }
      var found = editor.dom.getParent(node, 'span.' + CHIP);
      if (found) {
        return found;
      }
    }
    var node2 = editor.selection.getNode();
    var found2 = node2 ? editor.dom.getParent(node2, 'span.' + CHIP) : null;
    if (found2) {
      return found2;
    }
    var all = editor.dom.select('span.' + CHIP);
    return all.length ? all[all.length - 1] : null;
  }

  /** 插入一条脚注；有选中文字就把它作为注释文字，否则给一个占位并选中 */
  function insertFootnote(editor) {
    var selected = '';
    try {
      selected = editor.selection.getContent({ format: 'text' }) || '';
    } catch (e) {
      selected = '';
    }
    selected = selected.replace(/\s+/g, ' ').trim();

    var text = selected || PLACEHOLDER;
    var html = '<span class="' + CHIP + '">' + editor.dom.encode(text) + '</span>';

    editor.execCommand('mceInsertContent', false, html);

    var chip = currentChip(editor);
    if (chip) {
      // 选中注释文字：直接打字即可覆盖（占位符场景尤其重要）
      var rng = editor.dom.createRng();
      rng.selectNodeContents(chip);
      editor.selection.setRng(rng);
    }
    editor.nodeChanged();
  }

  tinymce.PluginManager.add('ybh_footnote', function (editor) {
    /* ---- 形态互转 ---- */
    editor.on('BeforeSetContent', function (e) {
      e.content = toVisual(e.content);
    });
    editor.on('GetContent', function (e) {
      e.content = toSource(e.content);
    });
    editor.on('PostProcess', function (e) {
      e.content = toSource(e.content);
    });

    /* ---- 工具栏按钮（TinyMCE 4 / 5 双兼容） ---- */
    var onClick = function () {
      insertFootnote(editor);
    };

    if (editor.ui && editor.ui.registry && editor.ui.registry.addButton) {
      // TinyMCE 5+
      editor.ui.registry.addButton('ybh_footnote', {
        icon: 'superscript',
        tooltip: '插入脚注',
        onAction: onClick
      });
    } else if (editor.addButton) {
      // TinyMCE 4（WordPress 长期使用的版本）
      editor.addButton('ybh_footnote', {
        icon: 'superscript',
        tooltip: '插入脚注',
        onclick: onClick
      });
    }
  });
})();
