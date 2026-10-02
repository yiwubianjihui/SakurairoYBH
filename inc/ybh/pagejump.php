<?php
/**
 * YBH · 分页「跳至某页」输入框
 *
 * ---------------------------------------------------------------
 * 背景
 *
 *   站点文章已有 8 页，`paginate_links` 只列首尾与当前页附近 ——
 *   想跳到中间某页只能一页页点（用户反馈）。
 *
 *   表单标记由 `index.php` 输出（`.ybh-pagejump`），本模块只负责交互：
 *     · 回车即跳（在 input 上按 Enter 不需要先点按钮）；
 *     · **越界夹取**到 [1, total]，而不是弹错误或跳 404
 *       —— 输入 999 时用户想要的是"最后一页"，不是一句报错；
 *     · 第 1 页走**站根**（`/page/1/` 会 301 回根），并且第 1 页地址
 *       不带 `#main`，与分页链接「只在 ≥2 页加锚点」的规则保持一致；
 *       ≥2 页带上 `#main`，落到文章列表而不是页面顶端（与翻页手感一致）。
 *
 *   没有 JS 时表单本身可用（就是普通的 GET 提交），只是不夹取、不补锚点 ——
 *   属于"降级但不坏"。
 *
 * ---------------------------------------------------------------
 * 关闭方式：把 YBH_PAGEJUMP 定义为 false。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_PAGEJUMP')) {
    define('YBH_PAGEJUMP', true);
}

/*
 * 统一分页器本体在 `tpl/pagination.php`（T62），由 bootstrap 第 13 节加载 ——
 * 所以这里**不再** require，避免两处加载点各自漂移。
 * 本文件只负责跳页框的交互（事件委托 / URL 生成 / 越界夹取）。
 */

add_action('wp_footer', 'ybh_pagejump_script', 98);
function ybh_pagejump_script()
{
    if (!YBH_PAGEJUMP) {
        return;
    }
    // 只在有分页的归档/首页需要
    if (!is_home() && !is_archive() && !is_search()) {
        return;
    }
    ?>
<script id="ybh-pagejump">
/*
 * 分页「跳至某页」。详见 inc/ybh/pagejump.php 顶部说明。
 *
 * ⚠️ T62 起改为**事件委托**绑在 document 上（并加了一次性哨兵防止重复绑定）：
 *   本脚本由 wp_footer 输出，位置在 pjax 的替换范围（#page / .footer-content /
 *   #app-js-before / title）**之外** —— pjax 换页后不会重跑它，而旧写法用
 *   document.querySelector 抓元素 + 直接 addEventListener，元素一被换掉监听器
 *   就一起没了。结果是「凡是通过 pjax 到达的页面，跳页框都是死的」。
 *
 * ⚠️ URL 由 PHP 给出的 data-pattern（含 %#%）生成，**不再硬拼 /page/N/** ——
 *   搜索页的形态是 ?paged=N&s=…，硬拼会丢查询串。
 */
(function () {
  'use strict';

  // 幂等：同一文档里若已经绑过（例如脚本被重复输出），直接返回
  if (window.__ybhPagejumpBound) { return; }
  window.__ybhPagejumpBound = true;

  var SEL = '.ybh-pagejump';

  function pick(target, sel) {
    return (target && target.closest) ? target.closest(sel) : null;
  }

  function go(form) {
    var input = form.querySelector('.ybh-pagejump-input');
    if (!input) { return; }

    var total  = parseInt(form.getAttribute('data-total'), 10) || 1;
    var home   = form.getAttribute('data-home') || '/';
    var pattern = form.getAttribute('data-pattern') || '';
    var anchor = form.getAttribute('data-anchor') || '';

    var n = parseInt(input.value, 10);
    if (isNaN(n) || n < 1) { n = 1; }
    if (n > total) { n = total; }

    // 回第一页：站根（/page/1/ 会 301 回根），且不带锚点
    if (n === 1) {
      window.location.href = home;
      return;
    }

    var url;
    if (pattern && pattern.indexOf('%#%') !== -1) {
      url = pattern.replace('%#%', String(n));       // 上下文相关，搜索页也对
    } else {
      url = home.replace(/\/+$/, '') + '/page/' + n + '/';  // 兜底：老形态
    }
    if (anchor && url.indexOf('#') === -1) { url += anchor; }

    window.location.href = url;
  }

  // 回车即跳（捕获阶段，确保在主题/插件之前拿到）
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.keyCode !== 13) { return; }
    var input = pick(e.target, SEL + ' .ybh-pagejump-input');
    if (!input) { return; }
    var form = pick(input, SEL);
    if (!form) { return; }
    e.preventDefault();
    go(form);
  }, true);

  // 越界时实时纠正显示值，让用户立刻看到会被夹到哪（失焦不跳，避免误触）
  document.addEventListener('blur', function (e) {
    var input = pick(e.target, SEL + ' .ybh-pagejump-input');
    if (!input) { return; }
    var form = pick(input, SEL);
    if (!form) { return; }
    var total = parseInt(form.getAttribute('data-total'), 10) || 1;
    var n = parseInt(input.value, 10);
    if (isNaN(n)) { return; }
    if (n < 1) { input.value = 1; }
    else if (n > total) { input.value = total; }
  }, true);

  // 提交（点「跳转」按钮或无 JS 时的普通 GET 由浏览器自己走）
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.matches || !form.matches(SEL)) { return; }
    e.preventDefault();
    go(form);
  }, true);
})();
</script>
    <?php
}
