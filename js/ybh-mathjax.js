/**
 * YBH · MathJax 的 pjax 补课 + 字体本地化（T68 / T68b）
 *
 * ===================================================================
 * 一、pjax 补课（实测出来的缺口）
 * ===================================================================
 *   主题上游自带 MathJax：app.js 在**页面初始加载**时检测正文里的公式定界符
 *   （$…$、\(…\)、\[…\]、\begin{…}），有就加载 js/4247.js 并排版 ——
 *   直开一篇数学文章，实测 4 个公式全部渲染成功。
 *
 *   但本站是 **pjax**：换页只替换 `#page`，app.js 的那段检测**不会重跑**。实测：
 *     · 从别的页面 pjax 点进数学文章 → MathJax 已在内存里（不卸载文档）但没人调
 *       typesetPromise ⇒ 公式以原始 TeX 文本显示；
 *     · 会话第一页没有公式（MathJax 没被加载过）⇒ 之后 pjax 进数学文章连库都没有。
 *   这里补：换页后重新排版；库不在场时按需懒加载。
 *
 * ===================================================================
 * 二、公式字体本地化（站长反馈「公式要 TeX 字体」）
 * ===================================================================
 *   MathJax CHTML 输出的字体族是 **MathJax TeX**（论文里那个 Computer Modern
 *   风格），字形文件按配置 `chtml.fontURL` 从 jsdelivr CDN 拉 —— 国内拿不到时
 *   **静默回退系统衬线体**，公式于是"不像 TeX"（CDP 实测：computed font-family
 *   是页面正文的 Sarasa UI SC，document.fonts 里一个 MathJax 字体都没有）。
 *
 *   整套 TeX 字体（23 个 .woff，392K）已托管在主题 `fonts/mathjax/woff-v2/`。
 *   让它真正生效的两道保险（都实测有效，双管齐下）：
 *     ① **排版前改配置**：轮询 `MathJax.config.chtml.fontURL`，一旦出现且不是
 *        本站路径就改写 —— 上游加载 MathJax 库要经过网络往返，轮询必定赶在
 *        它注入 @font-face 之前；
 *     ② **事后兜底**：MathJax 排版时把 @font-face 注入
 *        `<style id="MJX-CHTML-styles">`，MutationObserver 盯住它，一出现就把
 *        任何主机下的 `…/chtml/fonts/woff-v2/` 前缀改写为本站目录。
 */
(function () {
  'use strict';

  var LOCAL_FONT_URL = window.YBH_MJ_FONT_URL || '';

  /* ---------- ① 排版前改配置 ---------- */

  function watchFontUrl() {
    if (!LOCAL_FONT_URL) { return; }
    var tries = 0;
    var t = setInterval(function () {
      tries++;
      var M = window.MathJax;
      if (M && M.config && M.config.chtml && M.config.chtml.fontURL
          && M.config.chtml.fontURL !== LOCAL_FONT_URL) {
        M.config.chtml.fontURL = LOCAL_FONT_URL;
        clearInterval(t);
      }
      if (tries > 240) { clearInterval(t); }   // ~12s 后放弃（CDN 场景也不会更久了）
    }, 50);
  }

  /* ---------- ② 样式表事后兜底 ---------- */

  function patchFontStyles() {
    if (!LOCAL_FONT_URL) { return; }
    var s = document.querySelector('style#MJX-CHTML-styles');
    if (!s) { return; }
    var t = s.textContent;
    if (t.indexOf('/chtml/fonts/woff-v2/') < 0) { return; }
    var patched = t.replace(/(["(])((?:https?:)?\/\/[^)"']*\/chtml\/fonts\/woff-v2\/)/g,
      function (m, q) { return q + LOCAL_FONT_URL + '/'; });
    if (patched !== t) {
      s.textContent = patched;
    }
  }

  if (LOCAL_FONT_URL && 'MutationObserver' in window) {
    var mo = new MutationObserver(function () { patchFontStyles(); });
    var startWatch = function () {
      watchFontUrl();
      if (document.head) {
        mo.observe(document.head, { childList: true, subtree: true, characterData: true });
        patchFontStyles();   // 样式表可能已经在了
      }
    };
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', startWatch);
    } else {
      startWatch();
    }
  }

  /* ---------- pjax 补课 ---------- */

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
    if (!window.MathJax) {
      window.MathJax = {
        tex: { inlineMath: [['$', '$'], ['\\(', '\\)']] },
        startup: { typeset: false },
        chtml: { fontURL: LOCAL_FONT_URL, mathmlSpacing: true }
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
        // 排版前最后再钉一次 fontURL（覆盖懒加载路径：配置是我们刚写的，也可能已被上游覆盖）
        if (LOCAL_FONT_URL && window.MathJax.config && window.MathJax.config.chtml) {
          window.MathJax.config.chtml.fontURL = LOCAL_FONT_URL;
        }
        var p = window.MathJax.typesetPromise();
        if (p && typeof p.then === 'function') {
          p.then(function () { patchFontStyles(); }).catch(function () {});
        }
      } catch (e) {}
      setTimeout(patchFontStyles, 200);
    });
  }

  // pjax 换页后补排版；MathJax 若走懒加载，就绪可能晚于事件，多补两次兜底
  document.addEventListener('pjax:complete', function () { setTimeout(go, 250); });
  document.addEventListener('pjax:end', function () { setTimeout(go, 250); setTimeout(go, 1800); });
})();
