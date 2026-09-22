/**
 * YBH · 编辑器段落规范（T44 起，2026-09-22 按新规范重写）
 *
 * ===================================================================
 * 新规范：**1 个回车 = 分段（<p>），2 个回车 = 空行（<p>&nbsp;</p>）**
 * ===================================================================
 *
 * 用户两条要求：
 *   ① 「编辑器里敲的空行，发布后不该被去掉」；
 *   ② 「粘贴时，文档里的一个回车要变成一个分段，两个回车变成空行」。
 *
 * -------------------------------------------------------------------
 * 为什么用 `<p>&nbsp;</p>` 表示空行
 * -------------------------------------------------------------------
 *   真的空的 `<p></p>` 在浏览器里**不占高度** —— 空块的自身上下外边距会
 *   自己塌陷掉（CSS 规范行为），前台看过去那一行就"消失"了。
 *   实测（自删式探针跑过 wpautop）：
 *       `<p><br></p>`   → wpautop 把 `<br>` 吃掉，剩 `<p></p>`  ⇒ 空行丢失
 *       `<p></p>`       → wpautop 只留一个孤立 `</p>`         ⇒ 空行丢失
 *       `<p>&nbsp;</p>` → 原样保留                             ⇒ ✅ 唯一稳的写法
 *   所以**空行的规范形一律是 `<p>&nbsp;</p>`**。
 *
 * -------------------------------------------------------------------
 * 本插件做三件事
 * -------------------------------------------------------------------
 *   ① **取内容时**（GetContent）：把空段落规范成 `<p>&nbsp;</p>`，
 *      并去掉正文首尾的空段落。**不再删除空段落** —— 那是上一版（T44）的行为，
 *      正是用户这次要求修掉的毛病。
 *   ② **粘贴时**：接管「纯文本粘贴」并自己排版（内置逻辑是旧语义）；
 *      带格式的粘贴在 PastePostProcess 里做结构化归约（`<br>` → 段落）。
 *   ③ 不再在**打开编辑器时**清空段落（那会把作者有意留的空行吃掉）。
 *
 * -------------------------------------------------------------------
 * 与其它配置的关系
 * -------------------------------------------------------------------
 *   · `forced_root_block = 'p'`（见 inc/ybh/editor.php）保证回车生成真段落。
 *   · 本插件名**不能**加进 `tiny_mce_plugins`（WP 核心会把 external_plugins 里同名的剔掉，
 *     结果插件既不加载也不报错）—— 与 ybh_footnote 同一个坑，见 editor.php 的注释。
 *   · 服务端还有一道 `content_save_pre`（见 inc/ybh/editor.php::ybh_normalize_paragraphs），
 *     覆盖"不走编辑器"的写入路径（XML-RPC、REST、App、导入等）。
 *
 * -------------------------------------------------------------------
 * 关于本机 TinyMCE 版本的两个实测结论（免得后人再踩）
 * -------------------------------------------------------------------
 *   1. `paste_text_linebreaktype` **不存在**（wp-includes/js/tinymce/plugins/paste/plugin.js
 *      里 0 命中）⇒ 设置它没有任何效果。
 *   2. 纯文本粘贴走内置 `Newlines.convert`：`文本.split(/\n\n/)` 分块、块内 `\n` → `<br>`。
 *      也就是**旧语义**（单个回车 = 换行、空行 = 分段）⇒ 必须由本插件改写。
 */
(function () {
  'use strict';

  if (typeof tinymce === 'undefined') {
    return;
  }

  var CANON_EMPTY = '<p>&nbsp;</p>';

  /* ---------- 工具 ---------- */

  /** 去掉所有标签与空白实体后是否为空 */
  function isBlankHtml(html) {
    return String(html || '')
      .replace(/<[^>]*>/g, '')
      .replace(/&nbsp;|&#160;|&#xa0;/gi, '')
      .replace(/[\s\u00a0\u200b\ufeff]/g, '') === '';
  }

  /**
   * 这个块是不是「空行」（只有空白 / `&nbsp;` / `<br>`）。
   *
   * ⚠️ 判定要**保守**：只要出现任何真实元素（`<span>`、`<img>`、脚注小标记…）就算有内容，
   *    宁可漏判（前台照样是个空行），也不能误判成空 —— 误判会把正文里的行内元素删掉。
   *    脚注在编辑器里就是行内标记，绝不能碰。
   */
  function isEmptyBlock(el) {
    if (!el || el.nodeType !== 1) {
      return false;
    }
    var kids = el.childNodes;
    for (var i = 0; i < kids.length; i++) {
      var n = kids[i];
      if (n.nodeType === 1 && n.tagName !== 'BR') {
        return false;
      }
      if (n.nodeType === 3 && n.nodeValue.replace(/[\s\u00a0\u200b\ufeff]/g, '') !== '') {
        return false;
      }
    }
    return true;
  }

  function makeEmptyP(doc) {
    var p = doc.createElement('p');
    p.innerHTML = '&nbsp;';
    return p;
  }

  /** 复制段落属性（对齐等），临时块用 */
  function cloneAttrs(from, to) {
    if (!from.attributes) {
      return to;
    }
    for (var i = 0; i < from.attributes.length; i++) {
      to.setAttribute(from.attributes[i].name, from.attributes[i].value);
    }
    return to;
  }

  /* ---------- ① 空段落规范化 ---------- */

  /** TinyMCE 的空块占位 `<br data-mce-bogus="1">` 一律去掉 */
  function stripBogus(root) {
    var list = root.querySelectorAll('br[data-mce-bogus]');
    for (var i = list.length - 1; i >= 0; i--) {
      if (list[i].parentNode) {
        list[i].parentNode.removeChild(list[i]);
      }
    }
  }

  /** 空段落 → `<p>&nbsp;</p>`（**只改写，不删除**） */
  function canonEmptyBlocks(root) {
    var ps = root.querySelectorAll('p');
    for (var i = 0; i < ps.length; i++) {
      var p = ps[i];
      if (isEmptyBlock(p)) {
        p.parentNode.replaceChild(makeEmptyP(p.ownerDocument), p);
      }
    }
  }

  /** 去掉首尾的空段落（正文两端的空行没有意义） */
  function trimOuterEmpty(root) {
    var first = root.firstChild;
    while (first) {
      if (first.nodeType === 3 && isBlankHtml(first.nodeValue)) {
        var next = first.nextSibling;
        root.removeChild(first);
        first = next;
        continue;
      }
      if (first.nodeType === 1 && /^(P|DIV)$/.test(first.tagName) && isEmptyBlock(first)) {
        var nx = first.nextSibling;
        root.removeChild(first);
        first = nx;
        continue;
      }
      break;
    }
    var last = root.lastChild;
    while (last) {
      if (last.nodeType === 3 && isBlankHtml(last.nodeValue)) {
        var prev = last.previousSibling;
        root.removeChild(last);
        last = prev;
        continue;
      }
      if (last.nodeType === 1 && /^(P|DIV)$/.test(last.tagName) && isEmptyBlock(last)) {
        var pv = last.previousSibling;
        root.removeChild(last);
        last = pv;
        continue;
      }
      break;
    }
  }

  /* ---------- ② 把段落按「回车」拆开（粘贴用） ---------- */

  /**
   * 把一个段落按 `<br>` / 裸换行拆成多个段落。
   * **每个 `<br>` 都是一次回车 ⇒ 一个分段**；连续两个 `<br>` 之间那个空段落
   * 自然就是「空行」（随后被规范化成 `<p>&nbsp;</p>`）。
   *
   * @returns {Array} 段落数组；没有可拆的换行时返回 `[原段落]`
   */
  function splitBlockAtBreaks(p) {
    var doc = p.ownerDocument;
    var parts = [];
    var cur = cloneAttrs(p, doc.createElement('p'));
    var split = false;
    var nodes = Array.prototype.slice.call(p.childNodes);

    function push() {
      parts.push(cur);
      cur = cloneAttrs(p, doc.createElement('p'));
      split = true;
    }

    for (var i = 0; i < nodes.length; i++) {
      var node = nodes[i];
      if (node.nodeType === 1 && node.tagName === 'BR') {
        push();
        continue;
      }
      if (node.nodeType === 3 && node.nodeValue.indexOf('\n') >= 0) {
        var chunks = node.nodeValue.split('\n');
        for (var c = 0; c < chunks.length; c++) {
          if (c > 0) {
            push();
          }
          if (chunks[c]) {
            cur.appendChild(doc.createTextNode(chunks[c]));
          }
        }
        continue;
      }
      cur.appendChild(node.cloneNode(true));
    }
    parts.push(cur);

    return split ? parts : [p];
  }

  /** 整棵子树里的段落都按 `<br>` 拆开 */
  function splitBreaksIntoParagraphs(root) {
    var ps = root.querySelectorAll('p');
    for (var i = 0; i < ps.length; i++) {
      var p = ps[i];
      if (!p.parentNode || isEmptyBlock(p)) {
        continue;   // 空段落本身就是「空行」，不拆
      }
      var parts = splitBlockAtBreaks(p);
      if (parts.length < 2) {
        continue;
      }
      var frag = p.ownerDocument.createDocumentFragment();
      for (var k = 0; k < parts.length; k++) {
        frag.appendChild(parts[k]);
      }
      p.parentNode.replaceChild(frag, p);
    }
  }

  /* ---------- ③ 粘贴：纯文本按新规范自己排版 ---------- */

  /** 与 getContent 共用的一份规范化（粘贴片段用） */
  function normalizeFragment(root) {
    stripBogus(root);
    canonEmptyBlocks(root);
    splitBreaksIntoParagraphs(root);
    canonEmptyBlocks(root);   // 拆出来的空段落也要变成 `&nbsp;`
    return root;
  }

  /**
   * 剪贴板里的 HTML 是不是"简单到只剩换行"的内容
   * （只有 `<p>` / `<br>` / `<span>` 且不带属性）。
   *
   * 为什么要单独判一次：TinyMCE 自己也有个同名的 `isPlainText()` 判断 ——
   * 命中时它会**丢掉 HTML、改用纯文本**再排版（`Newlines.convert`，旧语义），
   * 于是"两个回车"落成两个段落、空行就丢了。
   * 与其等它走错路再猜，不如在捕获阶段就把这类粘贴整条接过来，按纯文本规则排。
   */
  function isSimpleHtml(html) {
    var stripped = String(html || '').replace(/<\/?(?:p|br|span)\s*\/?>/gi, '');
    return !/<[a-zA-Z!\/][^>]*>/.test(stripped);
  }

  /**
   * 纯文本 → 段落 HTML（新规范）。
   * 1 个回车 = 一个 `<p>`；空行 = 一个 `<p>&nbsp;</p>`。
   */
  function textToParagraphs(text, editor) {
    var lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
    while (lines.length && isBlankHtml(lines[0])) {
      lines.shift();
    }
    while (lines.length && isBlankHtml(lines[lines.length - 1])) {
      lines.pop();
    }
    var encode = (editor && editor.dom && editor.dom.encode)
      ? function (s) { return editor.dom.encode(s); }
      : function (s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };

    var out = [];
    for (var i = 0; i < lines.length; i++) {
      var t = lines[i].replace(/^[ \t\u3000]+|[ \t\u3000]+$/g, '');
      if (t === '' || isBlankHtml(t)) {
        out.push(CANON_EMPTY);
      } else {
        out.push('<p>' + encode(t) + '</p>');
      }
    }
    return out.join('');
  }

  /**
   * 在**捕获阶段**监听 iframe 文档上的 paste：
   * 内置处理器绑在编辑区 body 上，文档捕获阶段一定先于它执行，
   * 于是"纯文本粘贴"这条路整条由我们接管（`stopPropagation` 之后内置逻辑就不再跑）。
   *
   * ⚠️ 接管范围：
   *    · 剪贴板里没有 `text/html`（纯文本）→ 接管；
   *    · 有 `text/html` 但内容"简单"（只有 p/br/span、无属性）→ 也接管
   *      （TinyMCE 自己会把这类内容当纯文本处理，而且用的是旧语义，见 isSimpleHtml）；
   *    · 真正的富内容（带样式的 Word/微信/网页）→ 交给 TinyMCE 清洗 + 我们的 PastePreProcess；
   *    · 用户开了「粘贴为纯文本」→ 一律按纯文本接管。
   *   「粘贴为纯文本」的状态由 paste 插件的 PastePlainTextToggle 事件与
   *   `paste_as_text` 初始配置跟踪。
   */
  function bindTextPaste(editor) {
    var pasteAsText = !!editor.getParam('paste_as_text', false);
    editor.on('PastePlainTextToggle', function (e) {
      pasteAsText = !!(e && e.state);
    });

    var onPaste = function (e) {
      var cd = e.clipboardData;
      if (!cd || !cd.getData) {
        return;
      }
      var html = '';
      var text = '';
      try { html = cd.getData('text/html') || ''; } catch (err) { html = ''; }
      try { text = cd.getData('text/plain') || ''; } catch (err) { text = ''; }

      if (text === '' && html === '') {
        return;
      }
      if (!pasteAsText && html !== '' && !isSimpleHtml(html)) {
        return;   // 真富内容：走 TinyMCE 自己的路 + PastePreProcess 归约
      }
      if (text === '') {
        return;   // 没有纯文本可用时不要冒险
      }

      // 整条接管：内置处理器不会再拿到这个事件
      e.preventDefault();
      e.stopPropagation();

      editor.undoManager.transact(function () {
        editor.insertContent(textToParagraphs(text, editor), { merge: false, paste: true });
      });
    };

    try {
      editor.getDoc().addEventListener('paste', onPaste, true);
    } catch (err) {
      /* iframe 还没就绪：init 之后必然就绪，这里只做兜底 */
    }
  }

  /* ---------- ④ 带格式粘贴：结构化归约 ---------- */

  /** 来源的内联样式 / 类名 / id / Word 的 mso-* 一律不带进正文 */
  function stripForeignMarkup(root) {
    var all = root.querySelectorAll('*');
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      el.removeAttribute('style');
      el.removeAttribute('class');
      el.removeAttribute('id');
      el.removeAttribute('align');
      el.removeAttribute('dir');
      el.removeAttribute('lang');
      var attrs = el.attributes ? Array.prototype.slice.call(el.attributes) : [];
      for (var a = 0; a < attrs.length; a++) {
        var nm = attrs[a].name || '';
        if (nm.indexOf('data-') !== 0 && nm.indexOf('mso') === 0) {
          el.removeAttribute(nm);
        }
      }
      // <b>/<i> 统一成 <strong>/<em>，免得两种并存
      if (el.tagName === 'B') {
        var s = el.ownerDocument.createElement('strong');
        while (el.firstChild) { s.appendChild(el.firstChild); }
        el.parentNode.replaceChild(s, el);
      } else if (el.tagName === 'I') {
        var em = el.ownerDocument.createElement('em');
        while (el.firstChild) { em.appendChild(el.firstChild); }
        el.parentNode.replaceChild(em, el);
      }
    }
  }

  /** div/section 这类"块"统一成 `<p>`（引用、标题、列表、表格保持原样） */
  function blocksToParagraphs(root) {
    var KEEP = /^(P|H[1-6]|BLOCKQUOTE|PRE|UL|OL|LI|TABLE|THEAD|TBODY|TR|TD|TH|FIGURE|FIGCAPTION|HR|IMG|VIDEO|AUDIO|IFRAME)$/;
    var blocks = root.querySelectorAll('div,section,article,header,footer,aside,nav,main,address');
    for (var b = blocks.length - 1; b >= 0; b--) {
      var blk = blocks[b];
      var doc = blk.ownerDocument;
      var p = doc.createElement('p');
      while (blk.firstChild) {
        var child = blk.firstChild;
        if (child.nodeType === 1 && KEEP.test(child.tagName) && child.tagName !== 'IMG' &&
            child.tagName !== 'VIDEO' && child.tagName !== 'AUDIO' && child.tagName !== 'IFRAME' &&
            child.tagName !== 'HR') {
          if (p.firstChild) {
            blk.insertBefore(p, child);
            p = doc.createElement('p');
          }
        } else {
          p.appendChild(child);
        }
      }
      if (p.firstChild) {
        blk.parentNode.insertBefore(p, blk);
      }
      while (blk.firstChild) {
        blk.parentNode.insertBefore(blk.firstChild, blk);
      }
      if (blk.parentNode) {
        blk.parentNode.removeChild(blk);
      }
    }
  }

  /* ---------- 注册 ---------- */

  tinymce.PluginManager.add('ybh_para', function (editor) {

    /* 带格式粘贴：先结构化归约，再规范化 */
    editor.on('PastePreProcess', function (e) {
      if (!e || typeof e.content !== 'string' || !e.content) {
        return;
      }
      // 内部复制的内容已经是规范形，不要再动
      if (e.internal) {
        return;
      }
      var tmp = editor.dom.create('div');
      tmp.innerHTML = e.content;
      stripForeignMarkup(tmp);
      blocksToParagraphs(tmp);
      normalizeFragment(tmp);
      trimOuterEmpty(tmp);
      e.content = tmp.innerHTML;
    });

    /* 纯文本粘贴由文档捕获阶段整条接管（见 bindTextPaste） */
    editor.on('init', function () {
      bindTextPaste(editor);
    });

    /*
     * 取内容：空段落一律规范成 `<p>&nbsp;</p>`（**不删**），首尾空段落去掉。
     * ⚠️ 这是"空行会被发布时去掉"这个老毛病的修复点 —— 上一版在这里删空段落。
     */
    editor.on('GetContent', function (e) {
      if (!e || typeof e.content !== 'string' || !e.content) {
        return;
      }
      var tmp = editor.dom.create('div');
      tmp.innerHTML = e.content;
      stripBogus(tmp);
      canonEmptyBlocks(tmp);
      trimOuterEmpty(tmp);
      e.content = tmp.innerHTML;
    });
  });
})();
