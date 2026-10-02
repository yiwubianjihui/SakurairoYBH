/**
 * YBH · 语言切换浮层（T66b2）
 *
 * ===================================================================
 * 为什么需要这个文件（两个都来自站长反馈）
 * ===================================================================
 * 1) **语言链接必须整页刷新**。本站是 pjax：只替换 `#page` / `title` /
 *    `.footer-content` / `#app-js-before`。**顶部菜单不在替换范围内** ——
 *    所以点语言之后会出现"页面内容换了、菜单还是旧语言"（站长报的 bug）。
 *    这里给浮层里所有语言链接打上 `data-no-pjax`，让它们走完整导航。
 *    （PHP 侧也已经写了 `data-no-pjax`，这里是双保险：万一哪个模板漏了。）
 *
 * 2) 浮层开关。导航胶囊容器是 `overflow: hidden`，浮层放在里面会被裁掉，
 *    所以浮层由 PHP 输出在页脚、这里只负责开关与关闭行为。
 *
 * 依赖：无（不依赖 jQuery 与主题的 pjax 实现）。pjax 之后事件会重绑定。
 */
(function () {
  'use strict';

  function byId(id) { return document.getElementById(id); }

  function readCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  /**
   * 页面语言与 cookie 不一致时，**跳一次**到带 `?lang=` 的地址。
   *
   * 为什么需要（站长反馈"无法正常切换到英语外的其他语言"的真因）：
   *   本站用 Cache Enabler，缓存在 `advanced-cache.php` 阶段就输出，**主题代码还没跑** ——
   *   实测带 `ybh_lang=en` 的 cookie 请求 `/changelog/`，拿到的是 `X-YBH-Cache: HIT` 的**中文**页面。
   *   服务端的 `DONOTCACHEPAGE` / 重定向都拦不住已经生成的缓存，但**缓存的页面里带着这段 JS**，
   *   所以在这里纠正一次最可靠：URL 上带了语言之后，后续每个语言都有自己独立的 URL，
   *   与页面缓存天然兼容（非默认语言本来就不缓存）。
   *
   * 只在"确实不一致 + URL 上还没有 lang 参数"时跳，并且每个会话最多跳一次，杜绝死循环。
   */
  function reconcileLanguage() {
    var pageLang = (window.YBH_CURRENT_LANG || document.documentElement.getAttribute('lang') || '').trim();
    var cookieLang = readCookie('ybh_lang');
    if (!cookieLang || !pageLang || cookieLang === pageLang) { return; }
    var url;
    try { url = new URL(location.href); } catch (e) { return; }
    if (url.searchParams.get('lang')) { return; }
    var guard = 'ybh_lang_fixed';
    try {
      if (sessionStorage.getItem(guard) === cookieLang) { return; }
      sessionStorage.setItem(guard, cookieLang);
    } catch (e) {}
    url.searchParams.set('lang', cookieLang);
    location.replace(url.toString());
  }

  function markNoPjax(root) {
    var links = (root || document).querySelectorAll('a[data-ybh-lang]');
    for (var i = 0; i < links.length; i++) {
      links[i].setAttribute('data-no-pjax', '');
    }
  }

  function init() {
    var btn = byId('ybh-lang-pill-btn');
    var pop = byId('ybh-lang-pop');

    // 无论浮层在不在，都先把语言链接标成"不走 pjax"
    markNoPjax(document);
    if (!btn || !pop) { return; }
    if (btn.getAttribute('data-ybh-lang-init') === '1') { return; }  // 幂等
    btn.setAttribute('data-ybh-lang-init', '1');

    /*
     * T68：浮层锚定到**按钮下方**（此前 CSS 里写死 top:76px; right:24px，
     * 是"永远停在屏幕右上角"，按钮在别处时浮层和按钮就分家了 —— 站长反馈）。
     *
     * 浮层仍由 PHP 输出在页脚（不能放进 `.nav-search-wrapper`，会被它的
     * overflow:hidden 裁掉），但显示时用按钮的位置算出 top/right：
     *   top   = 按钮下缘 + 8px
     *   right = 视口右缘 − 按钮右缘
     * 移动端（≤640px）CSS 是底部弹层，此时清掉内联样式、交还给 CSS。
     * CSS 里的 top/right 值保留作"无 JS 兜底"。
     */
    var MOBILE_QUERY = '(max-width: 640px)';
    var mql = window.matchMedia ? window.matchMedia(MOBILE_QUERY) : null;

    function isMobile() { return mql ? mql.matches : (window.innerWidth <= 640); }

    function positionPop() {
      if (isMobile()) {               // 移动端底部弹层：清内联样式，交给 CSS
        pop.style.top = '';
        pop.style.right = '';
        pop.style.left = '';
        return;
      }
      var rect = btn.getBoundingClientRect();
      if (!rect || (!rect.width && !rect.height)) { return; }
      pop.style.top = Math.max(8, Math.round(rect.bottom + 8)) + 'px';
      pop.style.right = Math.max(8, Math.round(window.innerWidth - rect.right)) + 'px';
      pop.style.left = '';
    }

    function close() {
      pop.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
    }
    function open() {
      positionPop();
      pop.hidden = false;
      btn.setAttribute('aria-expanded', 'true');
      var first = pop.querySelector('a');
      if (first && first.focus) { first.focus(); }
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (pop.hidden) { open(); } else { close(); }
    });

    // 点浮层外部关闭
    document.addEventListener('click', function (e) {
      if (pop.hidden) { return; }
      if (pop.contains(e.target) || btn.contains(e.target)) { return; }
      close();
    });

    // Esc 关闭
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !pop.hidden) { close(); }
    });

    // 浮层开着时窗口变化 → 重新锚定（滚动/缩放都不会和按钮分家）
    window.addEventListener('resize', function () {
      if (!pop.hidden) { positionPop(); }
    }, { passive: true });
    window.addEventListener('scroll', function () {
      if (!pop.hidden) { positionPop(); }
    }, { passive: true });

    // 点语言链接：立刻整页跳转（保险起见自己再走一次 location，避免被 pjax 拦）
    pop.addEventListener('click', function (e) {
      var a = e.target.closest ? e.target.closest('a[data-ybh-lang]') : null;
      if (!a) { return; }
      a.setAttribute('data-no-pjax', '');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  // pjax 换页后重新绑定 + 重新打标记
  document.addEventListener('pjax:complete', init);
  document.addEventListener('pjax:end', init);

  // 语言自愈：缓存的页面可能是别的语言，这里纠正一次（见函数注释）
  reconcileLanguage();
})();
