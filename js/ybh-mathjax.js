/**
 * YBH · MathJax 的 pjax 补课（T68）
 *
 * ===================================================================
 * 为什么需要这个文件（实测出来的缺口）
 * ===================================================================
 *   主题上游自带 MathJax：app.js 在**页面初始加载**时检测正文里的公式定界符
 *   （$…$、\(…\)、\[…\]、\begin{…}），有就加载 js/4247.js 并排版 ——
 *   直开一篇数学文章，实测 4 个公式全部渲染成功。
 *
 *   但本站是 **pjax**：换页只替换 `#page`，app.js 的那段检测**不会重跑**。实测：
 *     · 从别的页面 pjax 点进数学文章 → MathJax 已在内存里（不卸载文档）但没人调
 *       typesetPromise ⇒ 公式以原始 TeX 文本显示；
 *     · 会话第一页没有公式（MathJax 没被加载过）⇒ 之后 pjax 进数学文章连库都没有。
 *   这个脚本补的就是这两条：换页后重新排版；库不在场时按需懒加载。
 *
 * 触发条件都从严：主题选项开着（window._iro.theme_mathjax）+ 新页面真有公式
 * + 尚未排版过，避免每页空跑。
 */
(function () {
  'use strict';

  var DELIM = /(?:\$\$[\s\S]*?\$\$|\$[^$\n]+?\$|\\\([\s\S]*?\\\)|\\\[[\s\S]*?\\\]|\\begin\{[a-zA-Z*]+\}[\s\S]*?\\end\{[a-zA-Z*]+\})/;

  function optionOn() {
    return !window._iro || window._iro.theme_mathjax !== false;
  }

  function contentRoot() {
    return document.querySelector('article div.entry-content')
      || document.querySelector('.entry-content')
      || null;
  }

  function hasMath() {
    if (!optionOn()) { return false; }
    if (document.getElementsByTagName('math').length > 0) { return true; }
    var root = contentRoot();
    if (!root) { return false; }
    if (root.querySelector('mjx-container')) { return false; }   // 这页已经排过
    return DELIM.test(root.textContent || '');
  }

  var loading = false;

  function ensureLoaded(cb) {
    if (window.MathJax && typeof window.MathJax.typesetPromise === 'function') { cb(); return; }
    if (loading) { return; }
    loading = true;
    // MathJax 3 在启动时读 window.MathJax 的配置；上游可能已写好部分配置，别覆盖，合并即可
    if (!window.MathJax) {
      window.MathJax = {
        tex: { inlineMath: [['$', '$'], ['\\(', '\\)']] },
        startup: { typeset: false },
        chtml: { fontURL: 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/output/chtml/fonts/woff-v2', mathmlSpacing: true }
      };
    }
    var s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-chtml.js';
    s.async = true;
    s.onload = function () { loading = false; cb(); };
    s.onerror = function () { loading = false; };   // CDN 不可达就保持原文（可读，只是没排版）
    document.head.appendChild(s);
  }

  function go() {
    if (!hasMath()) { return; }
    ensureLoaded(function () {
      try {
        var p = window.MathJax.typesetPromise();
        if (p && typeof p.catch === 'function') { p.catch(function () {}); }
      } catch (e) {}
    });
  }

  // pjax 换页后补排版；MathJax 若走懒加载，就绪可能晚于事件，多补两次兜底
  document.addEventListener('pjax:complete', function () { setTimeout(go, 250); });
  document.addEventListener('pjax:end', function () { setTimeout(go, 250); setTimeout(go, 1800); });
})();
