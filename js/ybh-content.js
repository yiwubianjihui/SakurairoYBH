/**
 * YBH · 编辑器内容的**统一规范化**（TinyMCE 与 WangEditor 共用同一套）
 *
 * ===================================================================
 * 新规范（2026-09-22 起）
 * ===================================================================
 *
 *   · **1 个回车 = 分段**（`<p>`）；
 *   · **2 个回车 = 空行**（`<p>&nbsp;</p>`）—— 空行是**要保留的内容**，
 *     用户明确要求「编辑器里敲的空行，发布后不该被去掉」。
 *
 *   ⚠️ 上一版（T44）这里是**删空段落**的，正是这次要修掉的行为。
 *      为什么空行必须写成 `&nbsp;`：真正空的 `<p></p>` 上下外边距会自己塌陷、
 *      前台不占高度（实测 wpautop 还会把 `<p><br></p>` 的 `<br>` 吃掉）⇒ 空行等于丢了。
 *
 * ===================================================================
 * 解决什么
 * ===================================================================
 *
 * 用户反馈：
 *   · 「使用编辑器编辑后，页间距变得很小」
 *   · 「TinyMCE 与 WangEditor 切换时会多出许多回车空行」
 *   · 「编辑器里输入的空行，发布时被去掉了」（本次）
 *
 * 前两条是**同一个根因的两种表现**：内容在两个编辑器之间往返时，
 * 没有任何一方做"归约"，于是各自按自己的习惯改写，越改越乱。
 *
 * 具体观察到的三种脏数据：
 *   · **纯文本 + CRLF 空行**（从「文本」标签页写、或纯文本粘贴进来的）——
 *     数据库里没有 `<p>`，靠前台 `wpautop` 事后补；补出来的段落里
 *     多余的空行会变成 `<p>&nbsp;</p>`，就是"多出来的回车空行"；
 *   · **内联排版样式**（`style="line-height:…; margin:0; font-size:…"`）——
 *     内联样式优先于主题 CSS，`margin:0` 一进去**段间距就没了**
 *     （这正是"页间距变得很小"）；
 *   · `<p><br></p>` / `<p>&nbsp;</p>` / 三个以上连续回车 —— 空段落。
 *
 * ===================================================================
 * 做法：定义一份"规范形"，两个编辑器在**进出边界**都过一遍
 * ===================================================================
 *
 *   规范形（canonical form）：
 *     · 换行统一成 `\n`（不带 `\r`）；
 *     · **每个非空文本行都包进 `<p>`** —— 不再依赖 wpautop 事后补，
 *       这样"编辑器里看到的"与"前台输出的"结构一致；
 *     · **空行一律写成 `<p>&nbsp;</p>` 并保留**（连续空行也照留）；
 *     · 只去掉正文**首尾**的空段落（两端空行没有意义）；
 *     · 剥掉**内联排版样式**（行高/字号/字族/颜色/背景/外边距/内边距）；
 *     · 保留其它属性（如对齐用的 `text-align`、图片的 `src/alt`、短代码文本）。
 *
 *   为什么"包 `<p>`"这一步很关键：不包的话，纯文本内容在 TinyMCE 里
 *   会被它自己包成 `<p>`、在 WangEditor 里又被包一次，来回切就翻倍。
 *
 * ⚠️ 短代码（`[fn]…[/fn]`、`<!--more-->`）在这套规则里是**普通文本**，
 *    原样保留 —— 不做任何转义或清洗。
 */
(function () {
  'use strict';

  /** 空行的规范写法（见文件头说明：只有 `&nbsp;` 才真的占一行高） */
  var CANON_EMPTY = '<p>&nbsp;</p>';

  /** 会被剥掉的**内联排版**属性（其余 style 保留，如 text-align） */
  var STRIP_STYLE_PROPS = [
    'line-height', 'font-size', 'font-family', 'font',
    'color', 'background', 'background-color',
    'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
    'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
    'text-indent', 'letter-spacing', 'word-spacing'
  ];

  /** 块级标签：规范化时认得它们，不会把内容再包一层 <p> */
  var BLOCK_TAGS = /^(P|DIV|H1|H2|H3|H4|H5|H6|BLOCKQUOTE|PRE|UL|OL|LI|TABLE|THEAD|TBODY|TR|TD|TH|FIGURE|FIGCAPTION|HR|SECTION|ARTICLE|HEADER|FOOTER|ASIDE|NAV|MAIN|ADDRESS|DL|DT|DD)$/;

  function stripStyleAttr(style) {
    var kept = String(style || '').split(';').filter(function (one) {
      var prop = one.split(':')[0].trim().toLowerCase();
      if (!prop) { return false; }
      return STRIP_STYLE_PROPS.indexOf(prop) < 0;
    });
    return kept.join(';');
  }

  /**
   * 真正空（没有文字、也没有任何真实子元素）的块。
   *
   * ⚠️ 判定要**保守**：只有 `<br>` 与空白算空，出现任何其它元素（`<img>`、`<span>`、
   *    脚注标记…）一律视为有内容 —— 宁可漏判（前台照样是空行），也不能误删行内内容。
   */
  function isEmptyBlock(el) {
    if (!el || el.nodeType !== 1) { return false; }
    var kids = el.childNodes;
    for (var i = 0; i < kids.length; i++) {
      var n = kids[i];
      if (n.nodeType === 1 && n.tagName !== 'BR') { return false; }
      if (n.nodeType === 3 && n.nodeValue.replace(/[\s\u00a0\u200b\ufeff]/g, '') !== '') { return false; }
    }
    return true;
  }

  /** 一段文本是不是"空行"（只有空白或 &nbsp;） */
  function isBlankText(s) {
    return String(s == null ? '' : s)
      .replace(/&nbsp;|&#160;|&#xa0;/gi, '')
      .replace(/<[^>]*>/g, '')
      .replace(/[\s\u00a0\u200b\ufeff]/g, '') === '';
  }

  /**
   * 规范化一段内容。
   * @param {string} html 编辑器给出的 HTML 或纯文本
   * @returns {string} 规范形
   */
  function normalize(html) {
    if (html === null || html === undefined) { return ''; }
    var s = String(html);

    // ① 换行统一（CRLF / CR → LF）
    s = s.replace(/\r\n?/g, '\n');

    // ② 若是**纯文本**（不含任何标签），按行拆段：
    //    非空行 → 一个 <p>；空行 → 一个 <p>&nbsp;</p>（**保留空行**）。
    //
    //    ⚠️ 包完**不能直接 return** —— 后面还有"去掉块间裸换行"要跑。
    if (!/<[a-zA-Z!/][^>]*>/.test(s)) {
      var lines = s.split('\n');
      while (lines.length && isBlankText(lines[0])) { lines.shift(); }
      while (lines.length && isBlankText(lines[lines.length - 1])) { lines.pop(); }
      s = lines.map(function (line) {
        var t = line.replace(/^[ \t\u3000]+|[ \t\u3000]+$/g, '');
        if (isBlankText(t)) { return CANON_EMPTY; }
        return '<p>' + t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</p>';
      }).join('\n');
      // 继续往下走 DOM 清理（不 return）
    }

    // ③ 走 DOM 规范化
    var root;
    try {
      root = document.createElement('div');
      root.innerHTML = s;
    } catch (e) {
      return s;   // 解析失败就原样返回，宁可不动也不要弄坏
    }

    // ④ 剥内联排版样式；顺带清掉空的 class/style
    var all = root.querySelectorAll('*');
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      if (el.hasAttribute('style')) {
        var kept = stripStyleAttr(el.getAttribute('style'));
        if (kept) { el.setAttribute('style', kept); }
        else { el.removeAttribute('style'); }
      }
      // class 只保留主题自己的（编辑器产生的临时类名不带过来）
      if (el.hasAttribute('class')) {
        var cls = (el.getAttribute('class') || '').trim();
        if (cls === '' || /^w-e-|^data-slate|^slate-/.test(cls)) {
          el.removeAttribute('class');
        }
      }
      // 编辑器内部用的属性一律不带进正文
      var attrs = el.attributes ? Array.prototype.slice.call(el.attributes) : [];
      for (var a = 0; a < attrs.length; a++) {
        var nm = attrs[a].name || '';
        if (/^(data-slate|data-w-e)/.test(nm) || nm === 'contenteditable' ||
            nm === 'spellcheck' || nm === 'draggable') {
          el.removeAttribute(nm);
        }
      }
    }

    // ⑤ div / section 之类统一成 <p>（引用、标题、列表、表格保持原样）
    var wrappers = root.querySelectorAll('div,section,article,header,footer,aside,nav,main,address,dl,dt,dd');
    for (var w = wrappers.length - 1; w >= 0; w--) {
      var win = wrappers[w];
      var p = document.createElement('p');
      while (win.firstChild) { p.appendChild(win.firstChild); }
      if (p.firstChild) { win.parentNode.insertBefore(p, win); }
      win.parentNode.removeChild(win);
    }

    // ⑥ **空行规范化**：真正空的段落（含只有 <br> / &nbsp; / 空白的）
    //    一律改写成 `<p>&nbsp;</p>` —— **保留，不删除**。
    var ps = root.querySelectorAll('p');
    for (var k = 0; k < ps.length; k++) {
      var pe = ps[k];
      if (isEmptyBlock(pe)) {
        var blank = document.createElement('p');
        blank.innerHTML = '&nbsp;';
        pe.parentNode.replaceChild(blank, pe);
      }
    }

    // ⑦ 去掉正文**首尾**的空段落（两端空行没有意义），
    //    并删掉块与块之间的裸换行（TinyMCE 会把它们当成空行）
    trimOuterEmpty(root);
    var out = root.innerHTML
      .replace(/>\s*\n\s*</g, '><')   // 标签之间的空白
      .replace(/\n{3,}/g, '\n\n')     // 连续 3 个以上换行 → 2 个
      .trim();

    return out;
  }

  function trimOuterEmpty(root) {
    var first = root.firstChild;
    while (first) {
      if (first.nodeType === 3 && isBlankText(first.nodeValue)) {
        var n1 = first.nextSibling;
        root.removeChild(first);
        first = n1;
        continue;
      }
      if (first.nodeType === 1 && first.tagName === 'P' && isEmptyBlock(first)) {
        var n2 = first.nextSibling;
        root.removeChild(first);
        first = n2;
        continue;
      }
      break;
    }
    var last = root.lastChild;
    while (last) {
      if (last.nodeType === 3 && isBlankText(last.nodeValue)) {
        var p1 = last.previousSibling;
        root.removeChild(last);
        last = p1;
        continue;
      }
      if (last.nodeType === 1 && last.tagName === 'P' && isEmptyBlock(last)) {
        var p2 = last.previousSibling;
        root.removeChild(last);
        last = p2;
        continue;
      }
      break;
    }
  }

  /** 判断内容是否"看起来是空的"（规范化后没有任何可见字符） */
  function isEmpty(html) {
    var t = normalize(html)
      .replace(/<[^>]*>/g, '')
      .replace(/&nbsp;/g, '')
      .replace(/[\s\u00a0\u200b\ufeff]/g, '');
    return t === '';
  }

  /**
   * 纯文本 → 段落 HTML（新规范）。
   *
   * **1 个回车 = 一个分段（`<p>`）；空行 = 一个空行（`<p>&nbsp;</p>`）** ——
   * 粘贴时「文档里的一个回车变一个分段、两个回车变一个空行」就是这条。
   * 两端多余的空行丢掉。
   *
   * @param {string} text 剪贴板里的纯文本
   * @returns {string} HTML
   */
  function textToParagraphs(text) {
    var lines = String(text == null ? '' : text).replace(/\r\n?/g, '\n').split('\n');
    while (lines.length && isBlankText(lines[0])) { lines.shift(); }
    while (lines.length && isBlankText(lines[lines.length - 1])) { lines.pop(); }
    return lines.map(function (line) {
      var t = line.replace(/^[ \t\u3000]+|[ \t\u3000]+$/g, '');
      if (isBlankText(t)) { return CANON_EMPTY; }
      return '<p>' + t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</p>';
    }).join('');
  }

  window.YBH_Content = {
    normalize: normalize,
    isEmpty: isEmpty,
    textToParagraphs: textToParagraphs,
    CANON_EMPTY: CANON_EMPTY,
    STRIP_STYLE_PROPS: STRIP_STYLE_PROPS
  };
})();
