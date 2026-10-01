/**
 * YBH · 翻译工作室的表单兜底（T66j）
 *
 * ===================================================================
 * 为什么需要这一段
 * ===================================================================
 *   实测：工作室里点「提交提案」，浏览器跳到了
 *   `/i18n/[object%20HTMLInputElement]`（404）—— 是**主题的 pjax 劫持了表单提交**，
 *   拼出来的地址是坏的（pjax 的 exclude 只认 `a[data-no-pjax]`，不认表单）。
 *   表现就是"点了提交没反应/页面 404、提案没提交"。
 *
 *   修法：在**捕获阶段**拦下 submit（`stopImmediatePropagation` 让主题的处理器收不到），
 *   然后用 `HTMLFormElement.prototype.submit.call(form)` 走浏览器的**原生提交** ——
 *   原生提交不触发 submit 事件，所以不会再被任何脚本二次拦截。
 *
 *   只处理 `.ybh-st` 里的表单，站点别的表单（搜索、评论）不受影响。
 */
(function () {
  'use strict';

  function nativeSubmit(form) {
    var orig = HTMLFormElement.prototype.submit;
    orig.call(form);
  }

  function onSubmit(e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') { return; }
    if (!form.closest || !form.closest('.ybh-st')) { return; }
    // 让主题/插件的提交拦截收不到这个事件
    e.stopImmediatePropagation();
    e.preventDefault();
    nativeSubmit(form);
  }

  // 捕获阶段（第三个参数 true）：比主题绑在冒泡阶段的处理器更早拿到事件
  document.addEventListener('submit', onSubmit, true);

  // 给工作室里的表单加上标记（万一以后主题改成认这个属性，也能直接生效）
  function mark() {
    var forms = document.querySelectorAll('.ybh-st form');
    for (var i = 0; i < forms.length; i++) {
      forms[i].setAttribute('data-no-pjax', '');
      forms[i].setAttribute('data-no-ajax', '');
    }
    // 「全选本页」：复选框用了 form="ybh-st-bulk" 关联到外部表单，不能用 form.elements 找
    var selall = document.getElementById('ybh-st-selall');
    if (selall && !selall.getAttribute('data-bound')) {
      selall.setAttribute('data-bound', '1');
      selall.addEventListener('click', function () {
        var boxes = document.querySelectorAll('.ybh-st input[form="ybh-st-bulk"]');
        for (var j = 0; j < boxes.length; j++) { boxes[j].checked = true; }
      });
    }
    bindFmtButtons();
  }

  /*
   * T68 · 分段翻译的格式按钮：把选中文本用 HTML 标记包起来。
   * `data-fmt` 的值形如 `<b>|</b>`，`|` 是选中内容的位置；没有选中时插到光标处。
   * 纯 DOM 操作（selectionStart/End + setRangeText），不依赖任何编辑器。
   */
  function bindFmtButtons() {
    var btns = document.querySelectorAll('.ybh-st__fmt');
    for (var i = 0; i < btns.length; i++) {
      if (btns[i].getAttribute('data-fmt-bound') === '1') { continue; }
      btns[i].setAttribute('data-fmt-bound', '1');
      btns[i].addEventListener('click', function () {
        var tpl = this.getAttribute('data-fmt') || '|';
        var bar = this.closest ? this.closest('.ybh-st__fmt-row') : null;
        var wrap = bar ? bar.parentElement : null;
        var ta = wrap ? wrap.querySelector('textarea[name="proposal"]') : null;
        if (!ta) { return; }
        var at = tpl.indexOf('|');
        var pre = at >= 0 ? tpl.slice(0, at) : tpl;
        var post = at >= 0 ? tpl.slice(at + 1) : '';
        var s = ta.selectionStart, e = ta.selectionEnd;
        var sel = ta.value.slice(s, e);
        ta.setRangeText(pre + sel + post, s, e, 'end');
        ta.focus();
        // 把光标/选区放回包好的内容上
        if (sel !== '') {
          ta.setSelectionRange(s + pre.length, s + pre.length + sel.length);
        } else {
          ta.setSelectionRange(s + pre.length, s + pre.length);
        }
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mark);
  } else {
    mark();
  }
  // pjax 换页回来（工作室页面本身不被 pjax 替换，但保险起见重绑一次）
  document.addEventListener('pjax:complete', mark);
  document.addEventListener('pjax:end', mark);
})();
