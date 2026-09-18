/**
 * YBH · 编辑器内容的**统一规范化**（TinyMCE 与 WangEditor 共用同一套）
 *
 * ===================================================================
 * 解决什么
 * ===================================================================
 *
 * 用户反馈两条：
 *   ① 「使用编辑器编辑后，页间距变得很小」
 *   ② 「TinyMCE 与 WangEditor 切换时会多出许多回车空行」
 *
 * 两条其实是**同一个根因的两种表现**：内容在两个编辑器之间往返时，
 * 没有任何一方做"归约"，于是各自按自己的习惯改写，越改越乱。
 *
 * 具体观察到的三种脏数据：
 *   · **纯文本 + CRLF 空行**（从「文本」标签页写、或纯文本粘贴进来的）——
 *     数据库里没有 `<p>`，靠前台 `wpautop` 事后补；补出来的段落里
 *     多余的空行会变成 `<p>&nbsp;</p>`，就是"多出来的回车空行"；
 *   · **内联排版样式**（`style="line-height:…; margin:0; font-size:…"`）——
 *     内联样式优先于主题 CSS，`margin:0` 一进去**段间距就没了**
 *     （这正是"页间距变得很小"）；
 *   · `<p><br></p>` / `<p>&nbsp;</p>` / 连续 3 个以上换行 —— 空段落。
 *
 * ===================================================================
 * 做法：定义一份"规范形"，两个编辑器在**进出边界**都过一遍
 * ===================================================================
 *
 *   规范形（canonical form）：
 *     · 换行统一成 `\n`（不带 `\r`）；
 *     · **每个非空文本行都包进 `<p>`** —— 不再依赖 wpautop 事后补，
 *       这样"编辑器里看到的"与"前台输出的"结构一致；
 *     · 空行折叠：连续 3 个以上换行 → 2 个；空段落一律删掉；
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

  /** 真正空（没有文字、也没有有意义子元素）的块 */
  function isEmptyBlock(el) {
    var kids = el.children;
    for (var i = 0; i < kids.length; i++) {
      var t = kids[i].tagName;
      if (/^(IMG|VIDEO|AUDIO|IFRAME|HR|TABLE|FIGURE|SOURCE|SVG|CANVAS|INPUT|BUTTON)$/.test(t)) {
        return false;
      }
      if (!isEmptyBlock(kids[i])) { return false; }
    }
    return (el.textContent || '').replace(/[\s\u00a0\u200b\ufeff]/g, '') === '';
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

    // ② 若是**纯文本**（不含任何标签），先把每个非空行包成 <p>。
    //    这样后面就不必依赖 wpautop 事后补段落，结构在库里就是确定的。
    //
    //    ⚠️ 包完**不能直接 return** —— 后面还有"删空段落 / 去掉块间裸换行"要跑。
    //    第一版就是在这里提前 return 了，结果：纯文本内容第一次规范化只被包了 <p>、
    //    空段落没被删；再规范化一次才删掉 ⇒ **不幂等**（实测 3643 → 3519），
    //    而且库里会留下空段落（正是用户反馈的"多出回车空行"）。
    if (!/<[a-zA-Z!/][^>]*>/.test(s)) {
      s = s.split(/\n+/)
        .map(function (line) { return line.trim(); })
        .filter(function (line) { return line !== ''; })
        .map(function (line) { return '<p>' + line + '</p>'; })
        .join('\n');
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

    // ⑥ 删空段落（只删真正空的 p；标题/pre/blockquote 即使空也留着 ——
    //    那是作者刚插入还没写内容的）
    var ps = root.querySelectorAll('p');
    for (var k = ps.length - 1; k >= 0; k--) {
      var pe = ps[k];
      // 只有 <br> 的段落也算空
      var onlyBr = pe.children.length === 0 ||
        (function () {
          for (var c = 0; c < pe.children.length; c++) {
            if (pe.children[c].tagName !== 'BR') { return false; }
          }
          return true;
        })();
      if (onlyBr && (pe.textContent || '').replace(/[\s\u00a0\u200b\ufeff]/g, '') === '') {
        pe.parentNode.removeChild(pe);
        continue;
      }
      if (isEmptyBlock(pe)) { pe.parentNode.removeChild(pe); }
    }

    // ⑦ 删掉块与块之间的裸换行（TinyMCE 会把它们当成空行）
    var out = root.innerHTML
      .replace(/>\s*\n\s*</g, '><')   // 标签之间的空白
      .replace(/\n{3,}/g, '\n\n')     // 连续 3 个以上换行 → 2 个
      .trim();

    return out;
  }

  /** 判断内容是否"看起来是空的"（规范化后没有任何可见字符） */
  function isEmpty(html) {
    var t = normalize(html)
      .replace(/<[^>]*>/g, '')
      .replace(/&nbsp;/g, '')
      .replace(/[\s\u00a0\u200b\ufeff]/g, '');
    return t === '';
  }

  window.YBH_Content = {
    normalize: normalize,
    isEmpty: isEmpty,
    STRIP_STYLE_PROPS: STRIP_STYLE_PROPS
  };
})();
