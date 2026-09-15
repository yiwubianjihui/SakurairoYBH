/**
 * YBH · 经典编辑器「脚注 / 段首缩进 / 查找替换」按钮 + 编辑器内可视化
 *
 * 需求（任务清单第 6 项脚注；T33 第 1、2、4 项）：
 *   · 脚注：投稿者不必手写短代码，还能看见脚注的位置与内容；
 *   · 段首缩进：工具栏一键给光标所在段落加/去首行缩进；
 *   · 查找替换：工具栏入口 + Ctrl+F / Ctrl+H（面板本体在 js/ybh-post-editor.js —— 见下）。
 *
 * ---------------------------------------------------------------
 * 脚注的两类形态（同一份内容的两种表示）
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
 * T33：脚注在编辑区里怎么"预览"（第 2 项）
 *
 *   旧观感：注释文字整段铺在正文里，带底色，读句子时很跳。
 *   新观感（与前台一致）：**只显示一个上标编号**，
 *     · 鼠标悬停 → 该处展开显示注释全文（原生 title 提示 + CSS 展开）；
 *     · 光标点进去 → `is-active` 类被点亮，注释文字就地展开，可直接改；
 *     · 光标离开 → 自动收回成编号。
 *   `js/ybh-editor.js` 只负责打 `title` 与 `is-active` 这两个标记，
 *   具体长什么样全在 `css/editor-style.css`（编号用 CSS 计数器自动排，与前台同规则）。
 *
 * ---------------------------------------------------------------
 * 与其它部分的关系
 *
 *   · 工具栏数组由 `inc/ybh/editor.php` 的 prio 999 过滤器写死，
 *     因此按钮名（`ybh_footnote` / `ybh_indent` / `ybh_findreplace`）
 *     必须写进**那个数组**，不能另挂过滤器追加；
 *   · 注音按钮（`ruby`）由 ruby-markup-converter 插件自己挂载，本插件不碰它；
 *   · 插件文件通过 `mce_external_plugins` 注册。注意 WP 核心
 *     （class-wp-editor.php）会把「已出现在 `tiny_mce_plugins` 里的名字」
 *     从 external_plugins 中剔除，所以**不要**再往 `tiny_mce_plugins` 里加本插件名；
 *     TinyMCE 会自动把 external_plugins 的名字追加进 plugins 列表（已实测确认）。
 *   · 查找替换面板需要操作「文本」标签页与页面级快捷键，所以它的实现在 iframe 之外
 *     （`js/ybh-post-editor.js`），本插件只做转发。
 */
(function () {
  'use strict';

  if (typeof window.tinymce === 'undefined' || !window.tinymce.PluginManager) {
    return;
  }

  var OPEN = '[fn]';
  var CLOSE = '[/fn]';
  var CHIP = 'ybh-fn-chip';
  var CHIP_ACTIVE = 'is-active';
  var INDENT = 'ybh-indent';
  var PLACEHOLDER = '脚注内容';

  /** 源码形态 → 可视形态 */
  var SRC_RE = /\[fn\]([\s\S]*?)\[\/fn\]/g;
  /** 可视形态 → 源码形态（属性顺序不敏感，只要 class 里有 ybh-fn-chip） */
  var VIS_RE = /<span[^>]*class="[^"]*\bybh-fn-chip\b[^"]*"[^>]*>([\s\S]*?)<\/span>/g;

  /** 参与「段首缩进」的块级元素（不含 li：列表自带缩进，不该叠加） */
  var BLOCK_SEL = 'p,blockquote,h2,h3,h4,h5,h6';

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

  /** 页面级脚本暴露的宿主对象（查找替换面板 / 保存 / 提示都在那边） */
  function host() {
    try {
      return (window.parent && window.parent.YBH_Editor) ? window.parent.YBH_Editor : null;
    } catch (e) {
      return null;
    }
  }

  tinymce.PluginManager.add('ybh_footnote', function (editor) {
    /* ==================================================================
     * 脚注：形态互转
     * ================================================================ */
    editor.on('BeforeSetContent', function (e) {
      e.content = toVisual(e.content);
    });
    editor.on('GetContent', function (e) {
      e.content = toSource(e.content);
    });
    editor.on('PostProcess', function (e) {
      e.content = toSource(e.content);
    });

    /* ==================================================================
     * 脚注：预览标记（title 提示 + 光标处展开）
     * ================================================================ */

    function chipNodes() {
      return editor.dom.select('span.' + CHIP);
    }

    /** 给每个脚注挂上原生 title，悬停即见全文（不改内容，保存时本来就会被换回 [fn]） */
    function decorateChips() {
      var chips = chipNodes();
      for (var i = 0; i < chips.length; i++) {
        var t = (chips[i].textContent || '').replace(/\s+/g, ' ').trim();
        if (t) {
          chips[i].setAttribute('title', '脚注：' + t);
        } else {
          chips[i].setAttribute('title', '脚注（尚未填写内容）');
        }
      }
    }

    /** 光标所在（严格：不做"取最后一个"兜底）的脚注 */
    function chipAtCaret() {
      try {
        var rng = editor.selection.getRng();
        var node = rng.startContainer;
        if (node && node.nodeType === 3) {
          node = node.parentNode;
        }
        return editor.dom.getParent(node, 'span.' + CHIP) || null;
      } catch (e) {
        return null;
      }
    }

    /** 只让光标所在的那一条展开，其余收回成编号 */
    function markActiveChip() {
      var chips = chipNodes();
      var act = chipAtCaret();
      for (var i = 0; i < chips.length; i++) {
        editor.dom.removeClass(chips[i], CHIP_ACTIVE);
      }
      if (act) {
        editor.dom.addClass(act, CHIP_ACTIVE);
      }
    }

    editor.on('SetContent', function () {
      decorateChips();
      markActiveChip();
    });
    editor.on('nodeChange', function () {
      markActiveChip();
    });

    /**
     * T33：点一下编号徽章 = 就地编辑这条注释。
     *
     * 折叠态下徽章是 ::before 伪元素，注释文字被 font-size:0 压成零宽，
     * 浏览器点它只会在标记**旁边**落光标（实测：class 不会变成 is-active，
     * 所以"点击就地编辑"等于没生效）。这里显式接管：
     * 光标不在该标记内时，把选区设为整条注释内容 —— 于是展开可编辑，
     * 且与"插入脚注"的交互一致（选中即打即可覆盖）。
     */
    editor.on('click', function (e) {
      var chip = e.target ? editor.dom.getParent(e.target, 'span.' + CHIP) : null;
      if (!chip) {
        return;
      }
      // 光标本来就在这条标记里 → 交给浏览器正常处理
      if (chipAtCaret() === chip) {
        return;
      }
      var rng = editor.dom.createRng();
      rng.selectNodeContents(chip);
      editor.selection.setRng(rng);
      editor.nodeChanged();
    });

    /* ==================================================================
     * 脚注：插入
     * ================================================================ */

    /** 取当前光标所在（或刚插入）的脚注标记 */
    function currentChip() {
      var found = chipAtCaret();
      if (found) {
        return found;
      }
      var node2 = editor.selection.getNode();
      var found2 = node2 ? editor.dom.getParent(node2, 'span.' + CHIP) : null;
      if (found2) {
        return found2;
      }
      var all = chipNodes();
      return all.length ? all[all.length - 1] : null;
    }

    /** 插入一条脚注；有选中文字就把它作为注释文字，否则给一个占位并选中 */
    function insertFootnote() {
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

      var chip = currentChip();
      if (chip) {
        // 选中注释文字：直接打字即可覆盖（占位符场景尤其重要）
        var rng = editor.dom.createRng();
        rng.selectNodeContents(chip);
        editor.selection.setRng(rng);
      }
      decorateChips();
      editor.nodeChanged();
    }

    /* ==================================================================
     * T33 第 4 项：段首缩进（开关式）
     * ================================================================ */

    /** 与选区相交的所有块级元素；没有相交则退回"光标所在块" */
    function targetBlocks() {
      var out = [];
      var body = editor.getBody();
      var all = body.querySelectorAll(BLOCK_SEL);
      var rng;
      try {
        rng = editor.selection.getRng();
      } catch (e) {
        rng = null;
      }
      if (rng) {
        for (var i = 0; i < all.length; i++) {
          try {
            if (rng.intersectsNode(all[i])) {
              out.push(all[i]);
            }
          } catch (e2) { /* 个别环境没有 intersectsNode：走下面的兜底 */ }
        }
      }
      if (!out.length) {
        var one = editor.dom.getParent(editor.selection.getStart(), BLOCK_SEL, body);
        if (one) {
          out.push(one);
        }
      }
      return out;
    }

    function caretBlockIndented() {
      var one = editor.dom.getParent(editor.selection.getStart(), BLOCK_SEL, editor.getBody());
      return !!(one && editor.dom.hasClass(one, INDENT));
    }

    function toggleIndent() {
      var blocks = targetBlocks();
      if (!blocks.length) {
        return;
      }
      // 有一个没缩进 → 整批加上；全都有 → 整批去掉（按钮语义可预期）
      var anyOff = false;
      for (var i = 0; i < blocks.length; i++) {
        if (!editor.dom.hasClass(blocks[i], INDENT)) {
          anyOff = true;
          break;
        }
      }
      for (var j = 0; j < blocks.length; j++) {
        if (anyOff) {
          editor.dom.addClass(blocks[j], INDENT);
        } else {
          editor.dom.removeClass(blocks[j], INDENT);
        }
      }
      editor.nodeChanged();
      var h = host();
      if (h && h.toast) {
        h.toast(anyOff ? '已加首行缩进（' + blocks.length + ' 段）' : '已取消首行缩进（' + blocks.length + ' 段）', 'ok');
      }
    }

    /* ==================================================================
     * T33 第 1 项：查找替换（转发到页面级面板）
     * ================================================================ */

    function openFindReplace(withReplace) {
      var h = host();
      if (h && h.openFindReplace) {
        h.openFindReplace(!!withReplace);
      } else {
        window.alert('查找/替换面板尚未加载完成，请刷新页面后再试。');
      }
    }

    /* ==================================================================
     * 角标：上标 / 下标（<sup> / <sub>）
     * ================================================================ */

    /**
     * 切换角标。直接用 TinyMCE 自带的 superscript / subscript 命令 ——
     * 它们本来就是 <sup>/<sub> 的包装，而且**自带「再点一次取消」的语义**，
     * 不用自己判断光标处有没有角标（自己实现很容易在「选中半个角标」时出错）。
     *
     * ⚠️ 与脚注的区别（工具栏上必须让人一眼分得清）：
     *   · 角标 = 纯排版标记，存的就是 <sup>/<sub>，前台原样显示；
     *   · 脚注 = <span class="ybh-fn-chip">，保存时还原成 [fn]…[/fn]，
     *            前台渲染成**带自动编号的上标链接**，并在文末生成注释列表。
     *   两者在视觉上都可能出现在文字右上角，但用途完全不同，所以
     *   脚注按钮不用图标、改用文字「脚注」标出（见下面的按钮注册）。
     */
    function toggleScript(which) {
      editor.execCommand(which === 'sub' ? 'subscript' : 'superscript');
      editor.nodeChanged();
    }

    /** 光标处是否处于该角标状态（给按钮做高亮） */
    function scriptState(which) {
      try {
        return !!editor.queryCommandState(which === 'sub' ? 'subscript' : 'superscript');
      } catch (e) {
        return false;
      }
    }

    /* ==================================================================
     * 按钮注册（TinyMCE 4 / 5 双兼容）
     * ================================================================ */

    function addBtn(name, cfg) {
      if (editor.ui && editor.ui.registry && editor.ui.registry.addButton) {
        // TinyMCE 5+
        if (cfg.state) {
          editor.ui.registry.addToggleButton(name, {
            text: cfg.text,
            icon: cfg.icon,
            tooltip: cfg.tooltip,
            onAction: cfg.action,
            onSetup: function (btn) {
              var handler = function () { btn.setActive(!!cfg.state()); };
              editor.on('nodeChange', handler);
              return function () { editor.off('nodeChange', handler); };
            }
          });
        } else {
          editor.ui.registry.addButton(name, {
            text: cfg.text,
            icon: cfg.icon,
            tooltip: cfg.tooltip,
            onAction: cfg.action
          });
        }
      } else if (editor.addButton) {
        // TinyMCE 4（WordPress 长期使用的版本）
        editor.addButton(name, {
          text: cfg.text,
          icon: cfg.icon,
          tooltip: cfg.tooltip,
          onclick: cfg.action,
          onPostRender: cfg.state ? function () {
            var btn = this;
            editor.on('nodeChange', function () {
              btn.active(!!cfg.state());
            });
          } : undefined
        });
      }
    }

    addBtn('ybh_sup', {
      icon: 'superscript',
      tooltip: '上标 / 角标（如 x²、注①）　Ctrl+Shift+=',
      action: function () { toggleScript('sup'); },
      state: function () { return scriptState('sup'); }
    });

    addBtn('ybh_sub', {
      icon: 'subscript',
      tooltip: '下标 / 角标（如 H₂O）　Ctrl+=',
      action: function () { toggleScript('sub'); },
      state: function () { return scriptState('sub'); }
    });

    addBtn('ybh_footnote', {
      // ⚠️ 这里**刻意不用图标**：脚注原先借用的是 superscript 图标，
      // 和上面的「上标」按钮撞脸，作者根本分不清点下去会插入脚注还是打个上标。
      // 改成文字标签后与「首行缩进」「查找」两个按钮风格一致，
      // 而且不依赖 TinyMCE 图标字体、在任意后台配色方案下都清晰可辨。
      text: '脚注',
      tooltip: '插入脚注（正文处显示上标编号，文末自动生成注释列表）',
      action: insertFootnote
    });

    addBtn('ybh_indent', {
      text: '首行缩进',
      tooltip: '给当前段落加/去首行缩进两格（T33）',
      action: toggleIndent,
      state: caretBlockIndented
    });

    addBtn('ybh_findreplace', {
      text: '查找',
      tooltip: '查找 / 替换（Ctrl+F 查找，Ctrl+H 替换）',
      action: function () { openFindReplace(false); }
    });

    /* ==================================================================
     * 可视区快捷键：Ctrl+F 查找 / Ctrl+H 替换 / Ctrl+S 就地保存
     *   iframe 内的键盘事件不会冒泡到外层文档，
     *   所以这三组快捷键必须在这里再补一层
     *   （外层文档那一层服务「文本」标签页）。
     * ================================================================ */
    editor.on('keydown', function (e) {
      if (!(e.ctrlKey || e.metaKey) || e.altKey) {
        return;
      }
      var key = (e.key || '').toLowerCase();
      var code = e.keyCode || e.which;

      // Ctrl/Cmd + S：就地保存（不刷新、不跳转）
      if (key === 's' || code === 83) {
        e.preventDefault();
        e.stopPropagation();
        var hSave = host();
        if (hSave && hSave.save) {
          hSave.save();
        }
        return;
      }

      var isF = (key === 'f' || code === 70);
      var isH = (key === 'h' || code === 72);
      if (isF || isH) {
        e.preventDefault();
        e.stopPropagation();
        openFindReplace(isH);
        return;
      }

      // 角标快捷键：Ctrl+Shift+= 上标 / Ctrl+= 下标（Word、Google Docs 同款约定）。
      // ⚠️ 必须看 e.shiftKey 而不是只看 key：按住 Shift 打等号时 key 就是 '+'，
      // 用它判断会把「上标」误判成「下标」。
      if (key === '=' || key === '+' || code === 187) {
        e.preventDefault();
        e.stopPropagation();
        toggleScript(e.shiftKey ? 'sup' : 'sub');
      }
    });
  });
})();
