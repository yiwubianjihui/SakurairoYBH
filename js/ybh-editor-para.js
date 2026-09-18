/**
 * YBH · 编辑器段落规范化（T44）
 *
 * ===================================================================
 * 解决两个用户反馈的问题
 * ===================================================================
 *
 * ① 「经过编辑器编辑后，原本行间距再次变大，显得不统一」
 *
 *    根因是**空段落**。TinyMCE 在若干情形下会留下 `<p>&nbsp;</p>` 或 `<p></p>`：
 *      · 作者连按两次回车想"空一行"，编辑器生成的是两个段落，其中一个空的；
 *      · 从别处粘贴时来源里的空 `<div>` / `<br><br>` 被转成空段落；
 *      · 切换「可视化 ↔ 文本」标签页时来回转换也会攒下空段落。
 *    然后前台 `p` 有自己的上下间距，空段落就**再吃一份间距** ⇒
 *    「编辑一次，段距比原来大一点」，编辑几次越来越散。
 *
 *    做法：**在进出编辑器两个方向上都清掉空段落** ——
 *      · 打开编辑器时清一次（顺手修好历史内容）；
 *      · 保存取内容时再清一次（保证数据库里不会新写入空段落）。
 *    ⚠️ 只清"真正空"的段落（没有文字、没有图片、没有其它块级子元素）。
 *      作者手动插的分隔线、图片、视频一律不动。
 *
 * ② 「编辑器在粘贴时，也应该将换行符视为分段」
 *
 *    主题已经设了 `paste_text_linebreaktype = 'p'` —— 但那**只对纯文本粘贴生效**。
 *    从 Word / 网页 / 备忘录**带格式**粘贴时，TinyMCE 保留来源结构，
 *    而来源用的是 `<div>`、`<br><br>`、`<p class=MsoNormal>` 之类，
 *    于是黏出来的是"一堆带换行的行"而不是"段落"。
 *
 *    做法：在 `paste_preprocess` 里做一次**结构化归约**：
 *      · `<div>` / `<section>` / `<article>` 这类"块"→ `<p>`；
 *      · 连续 `<br>`（两个及以上）→ 段落分界（`</p><p>`）；
 *      · 单个 `<br>` 保留（作者写诗、写歌词时是有用的）；
 *      · 清掉来源的内联样式/类名/多余属性（字体、颜色、字号、缩进全不带过来）。
 *
 * ===================================================================
 * 与其它配置的关系
 * ===================================================================
 *   · `forced_root_block = 'p'`（见 inc/ybh/editor.php）保证回车生成真段落 —— 本插件不与之冲突，
 *     只负责"清理"和"粘贴归约"。
 *   · 本插件名**不能**加进 `tiny_mce_plugins`（WP 核心会把 external_plugins 里同名的剔掉，
 *     结果插件既不加载也不报错）—— 与 ybh_footnote 同一个坑，见 editor.php 的注释。
 *   · 服务端还有一道 `content_save_pre` 兜底（见 inc/ybh/editor-para.php），
 *     覆盖"不走编辑器"的写入路径（XML-RPC、REST、导入等）。
 */
(function () {
  'use strict';

  if (typeof tinymce === 'undefined') {
    return;
  }

  /* ---------- 工具：这个块是不是"真的空" ---------- */

  // 允许留存的空元素（作者有意为之的）：图片、视频、音频、iframe、hr、br
  var KEEP_TAGS = /^(IMG|VIDEO|AUDIO|IFRAME|HR|BR|TABLE|FIGURE|SOURCE|SVG|CANVAS|INPUT|BUTTON)$/;

  function isEffectivelyEmpty(node) {
    if (!node || node.nodeType !== 1) {
      return false;
    }
    // 里面只要还有"有意义的"元素就不算空
    var kids = node.children;
    for (var i = 0; i < kids.length; i++) {
      if (KEEP_TAGS.test(kids[i].tagName)) {
        return false;
      }
      if (!isEffectivelyEmpty(kids[i])) {
        return false;
      }
    }
    // 文本部分：把 &nbsp; / 各种空白都算空
    var txt = (node.textContent || '')
      .replace(/\u00a0/g, '')
      .replace(/[\s\u200b\ufeff]/g, '');
    return txt === '';
  }

  var BLOCK_SEL = 'p,div,section,article,h1,h2,h3,h4,h5,h6,blockquote,pre';

  /* ---------- ① 清空段落 ---------- */

  function stripEmptyBlocks(root) {
    var removed = 0;
    if (!root) {
      return 0;
    }
    // 从后往前删，避免 NodeList 失效
    var all = root.querySelectorAll ? root.querySelectorAll(BLOCK_SEL) : [];
    for (var i = all.length - 1; i >= 0; i--) {
      var el = all[i];
      // h1-h6 / pre / blockquote 即使是空的也别删（多半是作者刚插入还没写）
      if (/^(H[1-6]|PRE|BLOCKQUOTE)$/.test(el.tagName)) {
        continue;
      }
      if (isEffectivelyEmpty(el)) {
        if (el.parentNode) {
          el.parentNode.removeChild(el);
          removed++;
        }
      }
    }
    return removed;
  }

  /* ---------- ② 粘贴归约 ---------- */

  function normalizePasted(html) {
    if (!html) {
      return html;
    }

    var doc;
    try {
      doc = new DOMParser().parseFromString('<div id="ybh-root">' + html + '</div>', 'text/html');
    } catch (e) {
      return html;
    }
    var root = doc.getElementById('ybh-root');
    if (!root) {
      return html;
    }

    // 1) 去掉来源的内联样式、类名、id、以及 Word 那堆 mso-* 属性
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
      // <b>/<i> 在 TinyMCE 里统一成 <strong>/<em>，免得两种并存
      if (el.tagName === 'B') {
        var s = doc.createElement('strong');
        while (el.firstChild) { s.appendChild(el.firstChild); }
        el.parentNode.replaceChild(s, el);
      } else if (el.tagName === 'I') {
        var em = doc.createElement('em');
        while (el.firstChild) { em.appendChild(el.firstChild); }
        el.parentNode.replaceChild(em, el);
      }
    }

    // 2) 块级元素统一成 <p>（引用、标题、列表保持原样）
    var KEEP_BLOCK = /^(P|H[1-6]|BLOCKQUOTE|PRE|UL|OL|LI|TABLE|THEAD|TBODY|TR|TD|TH|FIGURE|FIGCAPTION|HR|IMG|VIDEO|AUDIO|IFRAME)$/;
    var blocks = root.querySelectorAll('div,section,article,header,footer,aside,nav,main,address');
    for (var b = blocks.length - 1; b >= 0; b--) {
      var blk = blocks[b];
      var p = doc.createElement('p');
      while (blk.firstChild) {
        var child = blk.firstChild;
        // 子节点本身是块级的话，先把前面这些行内内容收成一个段落
        if (child.nodeType === 1 && KEEP_BLOCK.test(child.tagName) && child.tagName !== 'IMG' &&
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
      // 末尾的换行残留
      while (blk.firstChild) {
        blk.parentNode.insertBefore(blk.firstChild, blk);
      }
      blk.parentNode.removeChild(blk);
    }

    // 3) 连续 <br>（两个及以上）→ 段落分界；单个 <br> 保留
    var brs = root.querySelectorAll('br');
    for (var k = brs.length - 1; k >= 0; k--) {
      var br = brs[k];
      var prev = br.previousSibling;
      var isDouble = false;
      while (prev && prev.nodeType === 3 && !prev.textContent.replace(/[\s\u00a0]/g, '')) {
        prev = prev.previousSibling;
      }
      if (prev && prev.nodeType === 1 && prev.tagName === 'BR') {
        isDouble = true;
      }
      if (!isDouble) {
        continue;
      }
      // 从这个 <br> 开始，把它之后直到下一个块级元素之前的内容，切到新段落里
      var next = br.nextSibling;
      var frag = doc.createDocumentFragment();
      while (next && !(next.nodeType === 1 && KEEP_BLOCK.test(next.tagName))) {
        var nn = next.nextSibling;
        frag.appendChild(next);
        next = nn;
      }
      var np = doc.createElement('p');
      np.appendChild(frag);
      if (next) {
        br.parentNode.insertBefore(np, next);
      } else {
        br.parentNode.appendChild(np);
      }
      if (br.parentNode) {
        br.parentNode.removeChild(br);
      }
    }

    // 4) 收尾：去掉空段落；把只剩一个 <br> 的段落也算空
    stripEmptyBlocks(root);

    return root.innerHTML;
  }

  /* ---------- 注册 ---------- */

  tinymce.PluginManager.add('ybh_para', function (editor) {

    // ② 粘贴：结构化归约
    editor.on('paste_preprocess', function (e) {
      // 纯文本粘贴交给 TinyMCE 自己的 paste_text_linebreaktype='p' 处理，
      // 这里只管带格式的那一路（否则会把 TinyMCE 刚生成的段落结构再拆一遍）
      if (e && e.mode === 'text') {
        return;
      }
      if (e && typeof e.content === 'string' && e.content) {
        e.content = normalizePasted(e.content);
      }
    });

    // ① 打开时清一次：顺手修好历史内容里攒下的空段落
    editor.on('init', function () {
      var body = editor.getBody();
      if (!body) {
        return;
      }
      var n = stripEmptyBlocks(body);
      if (n > 0) {
        // 让撤销栈里留下痕迹，作者反悔还能 Ctrl+Z
        editor.undoManager.add();
      }
    });

    // ① 取内容时再清一次：保证数据库里不会新写入空段落
    editor.on('GetContent', function (e) {
      if (!e || typeof e.content !== 'string' || !e.content) {
        return;
      }
      // GetContent 的 content 是字符串，临时塞进一个容器里用同一套逻辑处理
      var tmp = editor.dom.create('div');
      tmp.innerHTML = e.content;
      stripEmptyBlocks(tmp);
      e.content = tmp.innerHTML;
    });

    // 让作者能手动触发一次（工具栏第二行有按钮，见 editor.php）
    editor.addCommand('ybhCleanParagraphs', function () {
      var body = editor.getBody();
      if (!body) {
        return;
      }
      var n = stripEmptyBlocks(body);
      editor.undoManager.add();
      if (editor.notificationManager) {
        editor.notificationManager.open({
          text: n > 0 ? ('已清理 ' + n + ' 个空段落') : '没有发现空段落',
          type: n > 0 ? 'success' : 'info',
          timeout: 2200
        });
      } else if (window.YBH_Editor && window.YBH_Editor.toast) {
        window.YBH_Editor.toast(n > 0 ? ('已清理 ' + n + ' 个空段落') : '没有发现空段落');
      }
    });

    // 工具栏按钮：段距忽然变大时，作者可以自己点一下
    editor.addButton('ybh_cleanparas', {
      title: '清理空段落（段距突然变大的时候用）',
      icon: 'wp-code',
      text: false,
      onclick: function () {
        editor.execCommand('ybhCleanParagraphs');
      },
      onPostRender: function () {
        var btn = this;
        // 与「段首缩进」按钮同一套轻量提示：悬停提示已足够，这里不额外做状态标记
        btn.disabled(false);
      }
    });
  });
})();
