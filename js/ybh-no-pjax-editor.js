/**
 * YBH · 让「去写作页」的链接绕过 pjax（T66）
 *
 * ===================================================================
 * 为什么需要这一支
 * ===================================================================
 *   本站启用了 pjax：换页时只替换 `#page` / `title` / `.footer-content` /
 *   `#app-js-before`，**不重新执行 wp_footer 里的脚本**。
 *   而写作页 `/write/` 的编辑器是**靠脚本现场搭起来**的（WangEditor 要挂到容器上）。
 *   于是"从主站经 pjax 点进写作页"会出现：页面 HTML 换成了写作页的样子，
 *   但控制器这一轮不会重跑 ⇒ 看到一个**空的编辑器**（没有工具栏、没有正文区）。
 *   实测确认过这个现象（`data-ybh-fe-init: null`、`slate: false`）。
 *
 *   修法：主题的 pjax 认 `data-no-pjax`（站内登录链接就是这个用法），
 *   **被标了这个属性的链接走整页加载**。这里把所有指向 `/write/` 的链接自动标上，
 *   包括 pjax 换页之后新出现的链接（否则首页第一次换页后又失效）。
 *
 *   ⚠️ 只标站内、只标 `/write/`，不碰任何其它链接的 pjax 行为。
 */
(function () {
  'use strict';

  var MARK = 'data-no-pjax';

  function isEditorLink(a) {
    if (!a || a.hasAttribute(MARK)) { return false; }
    var href = a.getAttribute('href') || '';
    if (!href) { return false; }
    // 只认本站路径里的 /write/（含 /write/?post=123），不碰外链
    try {
      var u = new URL(a.href, window.location.href);
      if (u.host !== window.location.host) { return false; }
      return u.pathname === '/write/' || u.pathname.indexOf('/write/') === 0;
    } catch (e) {
      return href.indexOf('/write/') >= 0 && href.indexOf('//') !== 0;
    }
  }

  function markAll() {
    var links = document.querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) {
      if (isEditorLink(links[i])) { links[i].setAttribute(MARK, ''); }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', markAll);
  } else {
    markAll();
  }
  // pjax 换页后，新页面里的链接同样要标
  document.addEventListener('pjax:complete', markAll);
  // 极端情况：链接由其它脚本后插入
  setTimeout(markAll, 1500);
})();
