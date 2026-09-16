<?php
/**
 * YBH · 预加载遮罩（#preload）的退场时机优化
 *
 * ---------------------------------------------------------------
 * 问题：首次进入（冷缓存）时明显"加载很慢"
 *
 *   主题的 `#preload` 是一层**全屏白色遮罩**（z-index 999），盖住整页。
 *   `js/app.js` 里撤掉它的条件是：
 *
 *       window.addEventListener('load', n, { once: true })   // 所有资源加载完
 *       setTimeout(n, 3000)                                   // 或最多等 3 秒
 *
 *   也就是说 **`load` 事件没来之前，用户看到的就是一片白**。
 *   而 `load` 要等**全部子资源**（含十几张封面图、字体、图标）下载完 ——
 *   冷访问 + 慢网下就是好几秒；再加上退场那段
 *   `filter: blur(0 → 100px)` 的**整屏模糊动画**（`preload_blur`，本站 500ms），
 *   体感更慢。
 *
 *   实测（无头 Edge 等 load 完成）：主站 ~135 秒才结束，
 *   而同服务器的纯静态小游戏站只要 3 秒 —— 差距不在图本身，
 *   而在"白屏一直盖着、且要等所有资源"。
 *
 * ---------------------------------------------------------------
 * 做法：页面**可用**就把遮罩撤掉，不等最后一张图
 *
 *   · 等到 `DOMContentLoaded`（DOM 就绪、样式与首屏结构都在了）；
 *   · 再看**首屏内的图片**是否就绪（用 `getBoundingClientRect` 判断在视口内，
 *     只等这些，不等页面下方那十几张）；
 *   · 兜底：DOM 就绪后最多再等 `YBH_PRELOAD_MAX_WAIT` 毫秒，无论图好没好都撤。
 *
 *   撤的时候必须**同时**把 `documentElement.style.overflowY` 置为 `unset`
 *   —— 主题 CSS 里有 `html { overflow-y: hidden }` 配合遮罩锁滚动，
 *   只删遮罩不解锁的话页面会**滚不动**（App 端此前踩过同一个坑）。
 *
 *   站长若把 `preload_animation` 关掉，本模块自然不生效（页面上没有 #preload）。
 */

if (!defined('ABSPATH')) {
    exit;
}

/** DOM 就绪后最多再等多久（毫秒）。太大等于没优化，太小会让首屏图闪一下。 */
if (!defined('YBH_PRELOAD_MAX_WAIT')) {
    define('YBH_PRELOAD_MAX_WAIT', 900);
}

add_action('wp_footer', 'ybh_preload_early_dismiss', 99);
function ybh_preload_early_dismiss()
{
    // 遮罩是主题选项控制的；关掉时页面上没有 #preload，脚本自己会立刻退出
    ?>
<script id="ybh-preload-tune">
/*
 * 让 #preload 遮罩在「页面可用」时就撤掉，而不是等 window.load（所有资源）。
 * 详见 inc/ybh/preload-tune.php 顶部说明。
 */
(function () {
  'use strict';

  var MAX_WAIT = <?php echo (int) YBH_PRELOAD_MAX_WAIT; ?>;
  var pre = document.getElementById('preload');
  if (!pre) { return; }

  var done = false;

  function dismiss() {
    if (done) { return; }
    done = true;
    // 关键：只删遮罩不解锁滚动的话，页面会滚不动（主题 CSS 里 html{overflow-y:hidden}）
    document.documentElement.style.overflowY = 'unset';
    try {
      pre.animate(
        [{ opacity: 1 }, { opacity: 0 }],
        { duration: 180, fill: 'forwards', easing: 'ease' }
      ).onfinish = function () { pre.remove(); };
      // 动画没跑起来（老浏览器/被禁）也不能把遮罩留在页面上
      setTimeout(function () { if (pre.parentNode) { pre.remove(); } }, 400);
    } catch (e) {
      pre.remove();
    }
    pre.classList.add('hide');
    pre.classList.remove('show');
  }

  /** 首屏内的图片是否都就绪（只算视口内的，页面下方那些不等） */
  function aboveFoldReady() {
    var imgs = document.images, vh = window.innerHeight || 800;
    for (var i = 0; i < imgs.length; i++) {
      var img = imgs[i];
      if (!img.getAttribute('src')) { continue; }
      var r;
      try { r = img.getBoundingClientRect(); } catch (e) { continue; }
      if (r.bottom < 0 || r.top > vh) { continue; }       // 不在首屏，不管
      if (!img.complete) { return false; }
    }
    return true;
  }

  function start() {
    var t0 = Date.now();
    (function poll() {
      if (done) { return; }
      if (aboveFoldReady() || Date.now() - t0 >= MAX_WAIT) { dismiss(); return; }
      setTimeout(poll, 60);
    })();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();
</script>
    <?php
}
