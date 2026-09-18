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
 */
(function () {
  'use strict';

  var form = document.querySelector('.ybh-pagejump');
  if (!form) { return; }

  var input = form.querySelector('.ybh-pagejump-input');
  if (!input) { return; }

  var total = parseInt(form.getAttribute('data-total'), 10) || 1;
  var home  = form.getAttribute('data-home') || '/';

  function go() {
    var n = parseInt(input.value, 10);
    if (isNaN(n) || n < 1) { n = 1; }
    if (n > total) { n = total; }

    // 回第一页：站根（/page/1/ 会 301 回根），且不带锚点
    if (n === 1) {
      window.location.href = home;
      return;
    }

    // 其余页：在站根上拼 /page/N/，与分页链接同形；带 #main 落到文章列表
    var base = home.replace(/\/+$/, '');
    window.location.href = base + '/page/' + n + '/#main';
  }

  // 回车即跳；失焦不跳（避免点别处时误触）
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' || e.keyCode === 13) {
      e.preventDefault();
      go();
    }
  });

  // 越界时实时纠正显示值，让用户立刻看到会被夹到哪
  input.addEventListener('blur', function () {
    var n = parseInt(input.value, 10);
    if (isNaN(n)) { return; }
    if (n < 1) { input.value = 1; }
    else if (n > total) { input.value = total; }
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    go();
  });
})();
</script>
    <?php
}
