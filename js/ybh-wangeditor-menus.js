/**
 * YBH · WangEditor 自定义菜单（补齐 TinyMCE 的功能）
 *
 * ===================================================================
 * 为什么要自己写这几个菜单
 * ===================================================================
 *
 * 站点原来的 TinyMCE 工具栏有这些**本主题特有**的功能，WangEditor 内置菜单里没有：
 *
 *   ybhFootnote    脚注（正文里存 `[fn]…[/fn]`，前台由主题转成上标角标）
 *   ybhSup/sub     上标 / 下标（与「脚注角标」刻意区分：这是纯排版标记）
 *   ybhIndent      段首缩进（给段落加 .ybh-indent，只缩第一行）
 *   ybhFindReplace 查找 / 替换
 *   ybhCleanParas  清空段落（段距忽然变大时用）
 *   ybhCharmap     特殊字符
 *   ybhPasteText   粘贴为纯文本
 *   ybhRuby        注音（<ruby>汉字<rt>读音</rt></ruby>）
 *   ybhMore        插入「更多」分隔符 <!--more-->
 *
 * 不补这些，作者从 TinyMCE 切过来就会发现"少了一半功能"，那这个附加编辑器就没意义了。
 *
 * ===================================================================
 * ★ 为什么弹窗不直接用 WangEditor 的 showModal
 * ===================================================================
 *
 * 试过 `showModal: true` + `getModalContentElem()`（这是它 dist 里对内置 link/image
 * 菜单用的写法），但实测点了按钮弹窗**不出现**，而且这种内部 API 出问题时不报错、
 * 只静默失效，很难查。
 *
 * 所以改成**自己在面板里渲染弹层**（`.ybh-wam-overlay`）：
 *   · 完全可控，不依赖内部实现；
 *   · 出问题一眼能看出来（DOM 在不在）；
 *   · 以后 WangEditor 升级也不会把弹窗弄没。
 * 代价是要自己写一点弹层的定位与关闭逻辑 —— 值。
 *
 * ===================================================================
 * 编辑数据怎么改
 * ===================================================================
 *   一律走编辑器自己的 API（insertText / setHtml / SlateTransforms），
 *   **不直接改 DOM** —— 直接改 DOM 会与它的数据模型脱节，撤销栈也会错乱。
 */
(function () {
  'use strict';

  if (typeof window.wangEditor === 'undefined') {
    return;
  }

  var W = window.wangEditor;
  var Boot = W.Boot;
  var SlateTransforms = W.SlateTransforms;
  var SlateEditor = W.SlateEditor;
  var SlateNode = W.SlateNode;
  var SlateElement = W.SlateElement;
  var DomEditor = W.DomEditor;

  if (!Boot || !SlateTransforms) {
    return;
  }

  /* ================================================================
   * 弹层（自己实现，见文件头说明）
   * ================================================================ */

  var overlay = null;

  function closeOverlay() {
    if (overlay && overlay.parentNode) {
      overlay.parentNode.removeChild(overlay);
    }
    overlay = null;
  }

  /**
   * 弹出一个自管理的弹层。
   * @param {object} editor  WangEditor 实例（用于回到编辑面）
   * @param {object} opts    { title, desc, fields:[{name,label,value,placeholder,multiline}],
   *                           buttons:[{name,text,primary,onClick(api)}] }
   * @param {function} [onReady] 弹层挂上后的回调（用来聚焦输入框）
   */
  function openOverlay(editor, opts, onReady) {
    closeOverlay();

    overlay = document.createElement('div');
    overlay.className = 'ybh-wam-overlay';

    var box = document.createElement('div');
    box.className = 'ybh-wam-box';

    var head = document.createElement('div');
    head.className = 'ybh-wam-head';
    head.innerHTML = '<strong>' + (opts.title || '') + '</strong>';
    var x = document.createElement('button');
    x.type = 'button';
    x.className = 'ybh-wam-x';
    x.setAttribute('aria-label', '关闭');
    x.innerHTML = '&times;';
    x.addEventListener('click', closeOverlay);
    head.appendChild(x);
    box.appendChild(head);

    var body = document.createElement('div');
    body.className = 'ybh-wam-body';

    if (opts.desc) {
      var d = document.createElement('p');
      d.className = 'ybh-wam__desc';
      d.textContent = opts.desc;
      body.appendChild(d);
    }

    (opts.fields || []).forEach(function (f) {
      var row = document.createElement('div');
      row.className = 'ybh-wam__row';
      if (f.label) {
        var lb = document.createElement('label');
        lb.textContent = f.label;
        row.appendChild(lb);
      }
      var inp = document.createElement(f.multiline ? 'textarea' : 'input');
      inp.className = 'ybh-wam__input';
      inp.value = f.value || '';
      inp.placeholder = f.placeholder || '';
      inp.setAttribute('data-ybh-wam', f.name);
      if (f.multiline) { inp.rows = 3; }
      row.appendChild(inp);
      body.appendChild(row);
    });

    if (opts.grid) {
      var g = document.createElement('div');
      g.className = 'ybh-wam__charmap';
      opts.grid.forEach(function (c) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'ybh-wam__char';
        b.textContent = c;
        b.addEventListener('click', function () {
          insertHtml(editor, c);
          closeOverlay();
        });
        g.appendChild(b);
      });
      body.appendChild(g);
    }

    box.appendChild(body);

    var bar = document.createElement('div');
    bar.className = 'ybh-wam__bar';
    var api = {
      val: function (name) {
        var el = box.querySelector('[data-ybh-wam="' + name + '"]');
        return el ? el.value : '';
      },
      close: closeOverlay,
      editor: editor
    };
    (opts.buttons || []).forEach(function (b) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'ybh-wam__btn' + (b.primary ? ' is-primary' : '');
      btn.textContent = b.text;
      btn.addEventListener('click', function () { b.onClick(api); });
      bar.appendChild(btn);
    });
    box.appendChild(bar);

    overlay.appendChild(box);

    // 点遮罩空白处关闭
    overlay.addEventListener('mousedown', function (e) {
      if (e.target === overlay) { closeOverlay(); }
    });
    // Esc 关闭（只关弹层，不关整个面板）
    overlay.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.stopPropagation();
        closeOverlay();
      }
    }, true);

    document.body.appendChild(overlay);
    if (onReady) { setTimeout(function () { onReady(box); }, 40); }
  }

  /* ================================================================
   * 通用工具
   * ================================================================ */

  function insertHtml(editor, html) {
    try {
      editor.restoreSelection();
      editor.insertText(html);   // insertText 接受 HTML 片段
      editor.focus();
    } catch (e) {
      // 兜底：直接在光标处插入（极少数情况下 restoreSelection 会抛）
      try { editor.insertText(html); } catch (e2) { /* 忽略 */ }
    }
  }

  function selectedText(editor) {
    try {
      return editor.selection ? (editor.selection.getSelectionText() || '') : '';
    } catch (e) {
      return '';
    }
  }

  function wrapSelection(editor, openTag, closeTag) {
    var txt = selectedText(editor);
    insertHtml(editor, openTag + txt + closeTag);
  }

  function eachSelectedParagraph(editor, fn) {
    try {
      SlateTransforms.setNodes(editor, fn(), {
        match: function (n) {
          return SlateElement.isElement(n) && DomEditor.checkNodeType(n, 'paragraph');
        }
      });
      return true;
    } catch (e) {
      return false;
    }
  }

  /** 当前光标所在段落 */
  function currentParagraph(editor) {
    try {
      var path = editor.selection.anchor.path;
      for (var i = path.length - 1; i >= 0; i--) {
        var n = SlateNode.get(editor, path.slice(0, i + 1));
        if (SlateElement.isElement(n) && DomEditor.checkNodeType(n, 'paragraph')) {
          return n;
        }
      }
    } catch (e) { /* 忽略 */ }
    return null;
  }

  /* 图标（内联 SVG，避免再引一套图标字体） */
  var ICON = {
    footnote: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M4 3h16v2H4zm0 4h10v2H4zm0 4h16v2H4zm0 4h10v2H4zm0 4h16v2H4z"/><path fill="currentColor" d="M17 10h2v8h-2z"/></svg>',
    sup: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M3 16l4-10h2l4 10H11l-1-3H6l-1 3zm3-5h3L7.5 8z"/><path fill="currentColor" d="M16 4h5v2l-3 4h3v2h-5V9l3-4h-3z"/></svg>',
    sub: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M3 12l4-10h2l4 10H11l-1-3H6l-1 3zm3-5h3L7.5 5z"/><path fill="currentColor" d="M16 14h5v2l-3 4h3v2h-5v-2l3-4h-3z"/></svg>',
    indent: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M3 5h18v2H3zm0 6h10v2H3zm0 6h10v2H3zm0 4h18v2H3z"/><path fill="currentColor" d="M16 9l4 3-4 3z"/></svg>',
    find: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M10 2a8 8 0 105.3 14l4.4 4.4 1.4-1.4-4.4-4.4A8 8 0 0010 2zm0 2a6 6 0 110 12 6 6 0 010-12z"/></svg>',
    clean: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M3 5h18v2H3zm0 6h11v2H3zm0 6h11v2H3z"/><path fill="currentColor" d="M18 11l3 3-1.5 1.5L18 14l-1.5 1.5L15 14z" opacity=".6"/></svg>',
    ruby: '<svg viewBox="0 0 24 24" width="16" height="16"><text x="12" y="8" font-size="7" text-anchor="middle" fill="currentColor">kana</text><text x="12" y="20" font-size="12" text-anchor="middle" fill="currentColor">&#28450;</text></svg>',
    charmap: '<svg viewBox="0 0 24 24" width="16" height="16"><text x="12" y="18" font-size="16" text-anchor="middle" fill="currentColor">&#167;</text></svg>',
    pastetext: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M9 2h6v2H9zM7 4h10v2H7z"/><path fill="currentColor" d="M5 6h14v16H5zm3 4v2h8v-2zm0 4v2h8v-2zm0 4v2h5v-2z" opacity=".85"/></svg>',
    more: '<svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M3 11h18v2H3z"/><path fill="currentColor" d="M8 4h8v2H8zm0 14h8v2H8z" opacity=".5"/></svg>'
  };

  /* ================================================================
   * 1) 脚注 —— 正文里存 [fn]…[/fn]
   * ================================================================ */

  Boot.registerMenu({
    key: 'ybhFootnote',
    factory: function () {
      return {
        title: '脚注',
        iconSvg: ICON.footnote,
        tag: 'button',
        isActive: function () { return false; },
        isDisabled: function () { return false; },
        getValue: function () { return ''; },
        getModalPositionNode: function () { return null; },
        getModalContentElem: function () { return null; },
        getPanelContentElem: function () { return null; },
        exec: function (editor) {
          var sel = selectedText(editor);
          openOverlay(editor, {
            title: '插入脚注',
            desc: '脚注会以 [fn]…[/fn] 存进正文，前台自动编号并显示为上标角标。',
            fields: [{
              name: 'text', label: '脚注内容', multiline: true, value: sel,
              placeholder: '例如：参见 2026 年 9 月的那篇说明。'
            }],
            buttons: [
              { name: 'cancel', text: '取消', onClick: function (a) { a.close(); } },
              {
                name: 'ok', text: '插入', primary: true, onClick: function (a) {
                  var t = (a.val('text') || '').trim() || '脚注内容';
                  insertHtml(editor, '[fn]' + t + '[/fn]');
                  a.close();
                }
              }
            ]
          }, function (box) {
            var el = box.querySelector('[data-ybh-wam="text"]');
            if (el) { el.focus(); }
          });
        }
      };
    }
  });

  /* ================================================================
   * 2) 上标 / 下标
   * ================================================================ */

  Boot.registerMenu({
    key: 'ybhSup',
    factory: function () {
      return {
        title: '上标',
        iconSvg: ICON.sup,
        tag: 'button',
        isActive: function () { return false; },
        isDisabled: function () { return false; },
        getValue: function () { return ''; },
        getModalPositionNode: function () { return null; },
        getModalContentElem: function () { return null; },
        getPanelContentElem: function () { return null; },
        exec: function (editor) { wrapSelection(editor, '<sup>', '</sup>'); }
      };
    }
  });

  Boot.registerMenu({
    key: 'ybhSub',
    factory: function () {
      return {
        title: '下标',
        iconSvg: ICON.sub,
        tag: 'button',
        isActive: function () { return false; },
        isDisabled: function () { return false; },
        getValue: function () { return ''; },
        getModalPositionNode: function () { return null; },
        getModalContentElem: function () { return null; },
        getPanelContentElem: function () { return null; },
        exec: function (editor) { wrapSelection(editor, '<sub>', '</sub>'); }
      };
    }
  });

  /* ================================================================
   * 3) 段首缩进（给段落加/去 .ybh-indent）
   * ================================================================ */

  Boot.registerMenu({
    key: 'ybhIndent',
    factory: function () {
      return {
        title: '段首缩进（只缩第一行，可反复切换）',
        iconSvg: ICON.indent,
        tag: 'button',
        isDisabled: function () { return false; },
        getValue: function () { return ''; },
        getModalPositionNode: function () { return null; },
        getModalContentElem: function () { return null; },
        getPanelContentElem: function () { return null; },
        exec: function (editor) {
          var cur = currentParagraph(editor);
          var next = !(cur && cur.ybhIndent);
          eachSelectedParagraph(editor, function () { return { ybhIndent: next }; });
          editor.focus();
        },
        isActive: function (editor) {
          var cur = currentParagraph(editor);
          return !!(cur && cur.ybhIndent);
        }
      };
    }
  });

  /* ================================================================
   * 4) 查找 / 替换
   * ================================================================ */

  Boot.registerMenu({
    key: 'ybhFindReplace',
    factory: function () {
      return {
        title: '查找 / 替换',
        iconSvg: ICON.find,
        tag: 'button',
        isActive: function () { return false; },
        isDisabled: function () { return false; },
        getValue: function () { return ''; },
        getModalPositionNode: function () { return null; },
        getModalContentElem: function () { return null; },
        getPanelContentElem: function () { return null; },
        exec: function (editor) {
          var lastIndex = 0;

          openOverlay(editor, {
            title: '查找 / 替换',
            desc: '「查找下一个」会滚动到并选中该处。替换只作用于当前选中项；「全部替换」会先问一次。',
            fields: [
              { name: 'find', label: '查找', placeholder: '要查找的文字' },
              { name: 'repl', label: '替换为', placeholder: '留空表示只查找' }
            ],
            buttons: [
              {
                name: 'find', text: '查找下一个', onClick: function (a) {
                  var needle = a.val('find');
                  if (!needle) { return; }
                  var text = '';
                  try { text = editor.getText() || ''; } catch (e) { text = ''; }
                  var idx = text.indexOf(needle, lastIndex);
                  if (idx < 0) { idx = text.indexOf(needle); }
                  if (idx < 0) { window.alert('没有找到「' + needle + '」'); return; }
                  lastIndex = idx + needle.length;
                  editor.focus();
                  selectTextInEditor(editor, needle, idx);
                }
              },
              {
                name: 'replace', text: '替换', primary: true, onClick: function (a) {
                  var needle = a.val('find');
                  var repl = a.val('repl');
                  if (!needle) { return; }
                  // 用编辑器自己的 API 替换当前选中内容
                  try {
                    var sel = selectedText(editor);
                    if (sel === needle) {
                      editor.restoreSelection();
                      editor.insertText(repl);
                    } else {
                      var text = editor.getText() || '';
                      var idx = text.indexOf(needle);
                      if (idx < 0) { window.alert('没有找到「' + needle + '」'); return; }
                      editor.focus();
                      selectTextInEditor(editor, needle, idx);
                      setTimeout(function () {
                        editor.restoreSelection();
                        editor.insertText(repl);
                        editor.focus();
                      }, 40);
                    }
                  } catch (e) { /* 忽略 */ }
                }
              },
              {
                name: 'all', text: '全部替换', onClick: function (a) {
                  var needle = a.val('find');
                  var repl = a.val('repl');
                  if (!needle) { return; }
                  var html = '';
                  try { html = editor.getHtml() || ''; } catch (e) { return; }
                  var count = 0;
                  // 只替换标签之间的文本，避开属性与标签名
                  var outHtml = html.replace(/>([^<]*)</g, function (m, txt) {
                    if (txt.indexOf(needle) < 0) { return m; }
                    var parts = txt.split(needle);
                    count += parts.length - 1;
                    return '>' + parts.join(repl) + '<';
                  });
                  if (count === 0) { window.alert('没有找到「' + needle + '」'); return; }
                  if (window.confirm('共找到 ' + count + ' 处，全部替换为「' + repl + '」？')) {
                    editor.setHtml(outHtml);
                    editor.focus();
                  }
                }
              },
              { name: 'close', text: '关闭', onClick: function (a) { a.close(); } }
            ]
          }, function (box) {
            var el = box.querySelector('[data-ybh-wam="find"]');
            if (el) { el.focus(); }
          });
        }
      };
    }
  });

  /**
   * 在编辑面里按"纯文本第 idx 个字符"定位并选中。
   * **只用于给作者看见并选中**，不用于改数据（改数据一律走 editor.insertText）。
   */
  function selectTextInEditor(editor, needle, idx) {
    try {
      var root = editor.getEditableContainer();
      if (!root) { return; }
      var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
      var count = 0, node;
      while ((node = walker.nextNode())) {
        var len = node.nodeValue.length;
        if (count + len > idx) {
          var start = idx - count;
          var rng = document.createRange();
          rng.setStart(node, start);
          rng.setEnd(node, Math.min(len, start + needle.length));
          var sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(rng);
          if (node.parentElement && node.parentElement.scrollIntoView) {
            node.parentElement.scrollIntoView({ block: 'center', behavior: 'smooth' });
          }
          return;
        }
        count += len;
      }
    } catch (e) { /* 忽略 */ }
  }

  /* ================================================================
   * 5) 清空段落
   * ================================================================ */

  Boot.registerMenu({
    key: 'ybhCleanParas',
    factory: function () {
      return {
        title: '清理空段落（段距突然变大的时候用）',
        iconSvg: ICON.clean,
        tag: 'button',
        isActive: function () { return false; },
        isDisabled: function () { return false; },
        getValue: function () { return ''; },
        getModalPositionNode: function () { return null; },
        getModalContentElem: function () { return null; },
        getPanelContentElem: function () { return null; },
        exec: function (editor) {
          var html = '';
          try { html = editor.getHtml() || ''; } catch (e) { return; }
          var n = 0;
          var re = /<p(?:\s[^>]*)?>(?:\s|&nbsp;|\u00a0|\u200b|\ufeff)*<\/p>/gi;
          var prev = null;
          while (prev !== html) {
            prev = html;
            html = html.replace(re, function () { n++; return ''; });
          }
          var msg = n === 0 ? '没有发现空段落' : ('已清理 ' + n + ' 个空段落');
          if (n > 0) {
            editor.setHtml(html);
            editor.focus();
          }
          if (window.YBH_Editor && window.YBH_Editor.toast) {
            window.YBH_Editor.toast(msg);
          } else {
            window.alert(msg);
          }
        }
      };
    }
  });

  /* ================================================================
   * 6) 注音 <ruby>汉字<rt>读音</rt></ruby>
   * ================================================================ */

  Boot.registerMenu({
    key: 'ybhRuby',
    factory: function () {
      return {
        title: '注音（给选中的汉字加读音）',
        iconSvg: ICON.ruby,
        tag: 'button',
        isActive: function () { return false; },
        isDisabled: function () { return false; },
        getValue: function () { return ''; },
        getModalPositionNode: function () { return null; },
        getModalContentElem: function () { return null; },
        getPanelContentElem: function () { return null; },
        exec: function (editor) {
          var sel = selectedText(editor);
          openOverlay(editor, {
            title: '注音',
            desc: '先选中要注音的汉字再点这个按钮，读音会显示在汉字上方。',
            fields: [
              { name: 'base', label: '汉字', value: sel, placeholder: '要注音的汉字' },
              { name: 'rt', label: '读音', placeholder: '例如：かんじ / kàn zì' }
            ],
            buttons: [
              { name: 'cancel', text: '取消', onClick: function (a) { a.close(); } },
              {
                name: 'ok', text: '插入', primary: true, onClick: function (a) {
                  var base = (a.val('base') || '').trim();
                  var rt = (a.val('rt') || '').trim();
                  if (!base || !rt) { window.alert('汉字和读音都要填。'); return; }
                  insertHtml(editor, '<ruby>' + base + '<rt>' + rt + '</rt></ruby>');
                  a.close();
                }
              }
            ]
          }, function (box) {
            var el = box.querySelector('[data-ybh-wam="rt"]');
            if (el) { el.focus(); }
          });
        }
      };
    }
  });

  /* ================================================================
   * 7) 特殊字符
   * ================================================================ */

  Boot.registerMenu({
    key: 'ybhCharmap',
    factory: function () {
      var CHARS = [
        '—', '–', '…', '·', '×', '÷', '±', '≈', '≠', '≤', '≥', '∞',
        '←', '→', '↑', '↓', '↔', '⇒', '⇔', '↺', '↻',
        '「', '」', '『', '』', '《', '》', '〈', '〉', '【', '】', '〔', '〕',
        '“', '”', '‘', '’', '¥', '€', '£', '§', '¶', '†', '‡', '※', '°', '℃',
        '①', '②', '③', '④', '⑤', '⑥', '⑦', '⑧', '⑨', '⑩',
        '★', '☆', '●', '○', '◆', '◇', '■', '□', '▲', '△', '♥', '♪', '✓', '✗'
      ];
      return {
        title: '特殊字符',
        iconSvg: ICON.charmap,
        tag: 'button',
        isActive: function () { return false; },
        isDisabled: function () { return false; },
        getValue: function () { return ''; },
        getModalPositionNode: function () { return null; },
        getModalContentElem: function () { return null; },
        getPanelContentElem: function () { return null; },
        exec: function (editor) {
          openOverlay(editor, {
            title: '特殊字符',
            desc: '点一下即插入到光标处。',
            grid: CHARS,
            buttons: [{ name: 'close', text: '关闭', onClick: function (a) { a.close(); } }]
          });
        }
      };
    }
  });

  /* ================================================================
   * 8) 粘贴为纯文本
   * ================================================================ */

  Boot.registerMenu({
    key: 'ybhPasteText',
    factory: function () {
      return {
        title: '粘贴为纯文本（去掉来源的字体、颜色、链接等）',
        iconSvg: ICON.pastetext,
        tag: 'button',
        isActive: function () { return false; },
        isDisabled: function () { return false; },
        getValue: function () { return ''; },
        getModalPositionNode: function () { return null; },
        getModalContentElem: function () { return null; },
        getPanelContentElem: function () { return null; },
        exec: function (editor) {
          if (!navigator.clipboard || !navigator.clipboard.readText) {
            window.alert('这个浏览器不允许直接读剪贴板。\n请改用 Ctrl+Shift+V 粘贴为纯文本。');
            return;
          }
          navigator.clipboard.readText().then(function (txt) {
            if (!txt) { return; }
            // 按空行切段；段内单换行保留为 <br>
            var blocks = txt.replace(/\r\n/g, '\n').split(/\n{2,}/);
            var html = blocks.map(function (b) {
              return '<p>' + b.split('\n').join('<br>') + '</p>';
            }).join('');
            insertHtml(editor, html);
            editor.focus();
          }).catch(function () {
            window.alert('读取剪贴板被拒绝了。\n请改用 Ctrl+Shift+V 粘贴为纯文本。');
          });
        }
      };
    }
  });

  /* ================================================================
   * 9) 更多分隔符 <!--more-->
   * ================================================================ */

  Boot.registerMenu({
    key: 'ybhMore',
    factory: function () {
      return {
        title: '插入「更多」分隔符（首页只显示分隔符之前的内容）',
        iconSvg: ICON.more,
        tag: 'button',
        isActive: function () { return false; },
        isDisabled: function () { return false; },
        getValue: function () { return ''; },
        getModalPositionNode: function () { return null; },
        getModalContentElem: function () { return null; },
        getPanelContentElem: function () { return null; },
        exec: function (editor) {
          insertHtml(editor, '<!--more-->');
          editor.focus();
        }
      };
    }
  });

})();
