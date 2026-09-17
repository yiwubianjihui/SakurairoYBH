<?php
/**
 * YBH · 首屏封面兜底 + `imgError` 提前定义
 *
 * 本文件解决两个**冷访问才会暴露**的问题（都由并行会话那轮「封面双变体 + 首屏去懒加载」引入）。
 *
 * ---------------------------------------------------------------
 * 问题一：`imgError is not defined`（控制台报错 ×4）
 *
 *   `tpl/content-thumbcard.php` 把**前 4 张**卡片图改成了「去懒加载」：
 *
 *       <img src="…" loading="eager" decoding="async" onerror="imgError(this)"/>
 *
 *   但 `imgError` 是 **`js/app.js` 里一个 webpack chunk 才挂上去的**全局函数
 *   （`2623:()=>{window.imgError=function(e,t){…}}`），而 app.js 位于**页面最末尾**。
 *   于是首屏那几张图在任何一次加载失败/被中断时触发 onerror，函数**还不存在** ⇒
 *   控制台报 `Uncaught ReferenceError: imgError is not defined`。
 *
 *   这里在 `<head>` 里**提前**放一个同名实现（与 app.js 那份行为一致：换成占位图、
 *   摘掉 onerror 免得反复触发）。app.js 之后覆盖成它自己那份，值相同、无害。
 *
 * ---------------------------------------------------------------
 * 问题二：首屏封面在**冷访问**时不出（第二次访问才出）
 *
 *   主题的首屏背景由 `js/app.js` 在运行时设置：`#centerbg` 的 `style.backgroundImage`。
 *   开发票时开了主题选项 `cache_cover`，那条链路是：
 *
 *       IndexedDB("sakurairo").cache.get("cover")  →  没命中就用 cover_api 的 URL
 *                                                  →  finally 里再 fetch 一次并写入缓存
 *
 *   实测（无头 Edge，每次全新 profile）：
 *     · 第一次访问：`#centerbg` 的 backgroundImage 始终是 `none`，**点「换封面」也不出**；
 *     · 清掉 IndexedDB 后刷新（= 走到第二次）：`coverBG_change` 正常触发，背景正常出现。
 *   也就是说**冷访问那一次，这条链路整体没跑通**，首屏就是一整屏空白。
 *
 *   本模块不去改 app.js（那是 webpack 压缩产物，手改会在下次构建时丢失），
 *   而是在页面底部放一段**兜底脚本**：
 *     · 正常情况：app.js 已经在 `#centerbg` 上设了背景 ⇒ 检测到就什么都不做；
 *     · 异常情况：等 `YBH_HERO_WAIT` 毫秒还没有背景 ⇒ 直接用 `cover_api`
 *       把背景设上，并补派发一次 `coverBG_change`，让主题自己的后续逻辑（如
 *       「文章特色图当背景」）仍能接管。
 *
 *   这样首屏**一定**有封面；app.js 修好后本兜底会自动变成一条不生效的空操作。
 *
 * ---------------------------------------------------------------
 * 关闭方式：把 YBH_HERO_FALLBACK 定义为 false。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_HERO_FALLBACK')) {
    define('YBH_HERO_FALLBACK', true);
}

/** 等多久还没背景就兜底（毫秒）。太大首屏白得久，太小会和 app.js 抢。 */
if (!defined('YBH_HERO_WAIT')) {
    define('YBH_HERO_WAIT', 1200);
}

/* ---------------------------------------------------------------------------
 * 1) `imgError` 提前定义（放 <head>，必须早于任何带 onerror 的图片）
 * ------------------------------------------------------------------------- */
add_action('wp_head', function () {
    ?>
<script id="ybh-img-error-early">
/*
 * 提前定义 window.imgError。
 * 必需原因见 inc/ybh/preload-tune.php 同目录的 hero-cover 模块顶部说明：
 * app.js 在最末尾才挂这个函数，而首屏那几张「去懒加载」的图在它之前就可能触发 onerror。
 * 已存在就不覆盖（app.js 之后仍会用自己那份覆盖回来）。
 */
if (typeof window.imgError !== 'function') {
  window.imgError = function (img) {
    if (!img || img.dataset.ybhErrDone) { return; }
    img.dataset.ybhErrDone = '1';
    img.onerror = null;                       // 摘掉，免得坏图反复触发
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
      '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="150" viewBox="0 0 200 150">' +
      '<rect width="200" height="150" fill="#f2f1ee"/>' +
      '<text x="50%" y="52%" text-anchor="middle" font-size="13" fill="#9a958e">图片走丢了</text>' +
      '</svg>');
  };
}
</script>
    <?php
}, 1);

/* ---------------------------------------------------------------------------
 * 2) 首屏封面兜底（放页面末尾，跟 app.js 同一批之后）
 * ------------------------------------------------------------------------- */
add_action('wp_footer', function () {
    if (!YBH_HERO_FALLBACK) {
        return;
    }
    // 只在首页需要：其它页面首屏不是那张全宽封面
    if (!is_home() && !is_front_page()) {
        return;
    }
    ?>
<script id="ybh-hero-cover-fallback">
/*
 * 首屏封面兜底。正常情况下 app.js 已经设好背景，这里什么都不做。
 * 详见 inc/ybh/hero-cover.php 顶部说明。
 */
(function () {
  'use strict';

  var WAIT = <?php echo (int) YBH_HERO_WAIT; ?>;
  var el = document.getElementById('centerbg');
  if (!el) { return; }

  var api = (window._iro && window._iro.cover_api) || '';
  var done = false;

  function hasBg() {
    var inline = el.style.backgroundImage || '';
    if (inline && inline !== 'none') { return true; }
    var cs = window.getComputedStyle(el).backgroundImage || '';
    return !!cs && cs !== 'none';
  }

  function fallback() {
    if (done || hasBg() || !api) { return; }
    done = true;
    var url = api + (api.indexOf('?') >= 0 ? '&' : '?') + Date.now();
    el.style.backgroundImage = 'url("' + url + '")';
    // 补一次主题自己的事件，让依赖它的逻辑（如文章特色图当背景）仍能接管
    try {
      document.dispatchEvent(new CustomEvent('coverBG_change', { detail: url }));
    } catch (e) { /* 老浏览器没有 CustomEvent 构造器：不影响背景已经设上 */ }
  }

  // app.js 是页面末尾的同步脚本，等它跑完再判断；用轮询而不是单次延时，
  // 是为了在慢网络下（封面 API 要 302 再取图）也能兜住。
  var t0 = Date.now();
  (function poll() {
    if (hasBg() || done) { return; }
    if (Date.now() - t0 >= WAIT) { fallback(); return; }
    setTimeout(poll, 120);
  })();

  // 双保险：整页加载完还没有，就直接补
  window.addEventListener('load', function () { setTimeout(fallback, 200); });
})();
</script>
    <?php
}, 99);
