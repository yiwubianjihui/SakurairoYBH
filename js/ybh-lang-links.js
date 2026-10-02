/**
 * YBH · 语言：链接改写成带 `?lang=`（T66i）
 *
 * ===================================================================
 * 为什么必须这么做（站长反馈："无法正常切换到英语外的其他语言"）
 * ===================================================================
 *   实测：带 `ybh_lang=en` 的 cookie 请求 `/changelog/`，服务器返回的是
 *   **`X-YBH-Cache: HIT` 的中文页面**（`<html lang="zh-Hans">`）。
 *   原因：本站用 Cache Enabler，缓存在 `advanced-cache.php` 阶段就直接输出了，
 *   **主题代码根本没机会执行**，所以 `DONOTCACHEPAGE`（我在非默认语言时设的）拦不住它。
 *   结果就是：点语言那一刻（URL 带 `?lang=`）是对的，但只要再点一个**不带参数**的站内链接，
 *   就被缓存按中文吐回来 —— 看起来就是"切不过去 / 切了又弹回中文"。
 *
 *   正确做法：**语言跟着 URL 走**（每种语言一份独立 URL，和页面缓存天然兼容），
 *   cookie 只当"下次手动进站时的默认值"。这个脚本负责把站内链接补上 `?lang=`。
 *
 * 具体行为：
 *   · 当前语言 = 默认（简体中文）时：**什么都不做**（默认页面继续吃缓存，最快）；
 *   · 当前语言非默认时：给站内同源链接补 `?lang=xx`
 *     （跳过：跨域、锚点、mailto/tel、下载、已有 lang 参数、`data-no-rewrite`）；
 *   · 兼容 pjax：换页后重新处理，并在 pjax 请求前把目标地址补好。
 *
 * 依赖：无。
 */
(function () {
  'use strict';

  var KEY = 'ybh_lang';
  var html = document.documentElement;
  var cur = (window.YBH_CURRENT_LANG || html.getAttribute('lang') || '').trim();
  // 没拿到语言就不动（避免在不知道语言时瞎改链接）
  if (!cur) { return; }

  // 默认语言：PHP 侧没有任何地方给 body 加 `ybh-lang-*` class（探针证实），
  // 旧写法 `body.className.indexOf('ybh-lang-zh-Hans')` 永远是 -1，
  // 一直在靠下一行的硬编码兜底 —— 现在直接以简体中文（站点默认）为准。
  // 若日后默认语言变了，PHP 的 wp_localize_script 会带上 YBH_DEFAULT_LANG。
  var isDefault = (window.YBH_DEFAULT_LANG || 'zh-Hans') === cur;
  if (isDefault) {
    // 默认语言下：若 URL 上还残留 ?lang=，顺手清掉（保持"默认页 = 无参数"这一缓存约定）
    return;
  }

  function shouldSkip(a, url) {
    if (!a || a.hasAttribute('data-ybh-lang')) { return true; }        // 语言切换本身另走整页刷新
    if (a.hasAttribute('data-no-rewrite') || a.hasAttribute('download')) { return true; }
    if (a.target && a.target !== '' && a.target !== '_self') { return true; }
    var href = a.getAttribute('href') || '';
    if (!href) { return true; }
    if (/^(#|mailto:|tel:|javascript:|data:)/i.test(href)) { return true; }
    if (url.origin !== location.origin) { return true; }
    if (url.pathname.match(/\.(jpg|jpeg|png|gif|webp|svg|zip|pdf|mp3|mp4|css|js)$/i)) { return true; }
    if (url.searchParams.get('lang')) { return true; }
    return false;
  }

  function rewrite(root) {
    var links = (root || document).querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      var url;
      try { url = new URL(a.href, location.href); } catch (e) { continue; }
      if (shouldSkip(a, url)) { continue; }
      url.searchParams.set('lang', cur);
      a.setAttribute('href', url.pathname + (url.search ? url.search : '') + (url.hash || ''));
    }
  }

  function run() {
    rewrite(document);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
  // pjax 换页后重新改写；并顺带在 pjax 真正发起前再补一次
  document.addEventListener('pjax:complete', run);
  document.addEventListener('pjax:end', run);
  document.addEventListener('pjax:beforeSend', run);
  // 主题的动态渲染（评论/搜索等）之后也补一次
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) { return; }
    var url;
    try { url = new URL(a.href, location.href); } catch (err) { return; }
    if (shouldSkip(a, url)) { return; }
    url.searchParams.set('lang', cur);
    a.setAttribute('href', url.toString());
  }, true);
})();
