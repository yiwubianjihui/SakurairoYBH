<?php
/**
 * YBH · 前端减重（与具体插件无关的通用项）
 *
 * ---------------------------------------------------------------
 * 目前只有一项：**匿名访客不再加载 dashicons**。
 *
 * 为什么可以摘：
 *   · dashicons 是 WordPress **后台**的图标字体（`wp-includes/css/dashicons.min.css`，约 35 KB）；
 *   · 本站前端模板与主题 JS/CSS 都没用到它 —— 2026-09-22 实测首页 HTML 里除了样式表自己的
 *     handle 名（`dashicons-css`）之外，**没有任何 `dashicons-*` 图标类**；
 *   · 真正用到它的都在后台：`inc/ybh/admin.php`、`admin-simplify.php`、`changelog.php`、
 *     `contributor-edit.php`，以及 Kirki 定制器 —— 那些请求 `is_admin()` 为真，本模块直接跳过。
 *
 * 为什么限定「未登录」：
 *   登录用户的页面顶部有管理工具栏，工具栏的图标用的就是 dashicons。匿名访客没有工具栏。
 *
 * 与页面缓存的关系：整页缓存的受益者正是匿名访客，所以这条省下来的是**主要流量**的那一份。
 *
 * ---------------------------------------------------------------
 * 关闭方式：把 YBH_FRONTEND_WEIGHT 定义为 false（或删掉本文件在 bootstrap.php 的 require）。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_FRONTEND_WEIGHT')) {
    define('YBH_FRONTEND_WEIGHT', true);
}

/**
 * 优先级 999：等所有插件都入队完再摘，避免被后面的 enqueue 又加回来。
 */
add_action('wp_enqueue_scripts', 'ybh_frontend_drop_dashicons', 999);
function ybh_frontend_drop_dashicons()
{
    if (!YBH_FRONTEND_WEIGHT) {
        return;
    }

    // 后台、定制器、登录页都要用 dashicons
    if (is_admin() || is_user_logged_in()) {
        return;
    }

    wp_dequeue_style('dashicons');
}

/**
 * 9.7c-4) pjax 之后按内容去重内联 <style>
 *
 * 实测（2026-09-24）：站内每次 pjax 导航都会把目标页的内联 <style> 追加进来且
 * **从不清理**，`<style>` 数量随导航 6 → 7 → 8 …（整页刷新回 6）。
 * 重复块内容完全相同（同规则同值），渲染结果不变，但会持续占用内存与解析。
 * 这里只删「内容完全相同的后出现者」，不碰任何不同的样式块。
 */
add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }
    ?>
<script>
(function () {
  function ybhDedupeStyle() {
    var list = document.querySelectorAll('style'), seen = {}, removed = 0;
    for (var i = 0; i < list.length; i++) {
      var t = list[i], k = t.textContent || '';
      if (!k) { continue; }
      if (seen[k]) { if (t.parentNode) { t.parentNode.removeChild(t); removed++; } }
      else { seen[k] = 1; }
    }
    if (removed && window.console && console.log) { console.log('[YBH] 去重内联 style：' + removed + ' 个'); }
  }
  document.addEventListener('pjax:complete', function () { setTimeout(ybhDedupeStyle, 80); });
  if (document.readyState !== 'loading') { ybhDedupeStyle(); }
  else { document.addEventListener('DOMContentLoaded', ybhDedupeStyle); }
})();
</script>
    <?php
}, 99);