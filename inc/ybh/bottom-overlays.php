<?php
/**
 * YBH · 底部浮层仲裁（T60）
 *
 * ===================================================================
 * 问题
 * ===================================================================
 *   首访时页面上可能**同时**出现四个贴底元素：
 *     · Cookie 同意横幅   `.ybh-consent`        z-index 99999
 *     · PWA 安装提示条    `.ybh-pwa-bar`        z-index 9999
 *     · 控制台引导气泡    `#ybh-console-hint`   z-index 200
 *     · 手机吸底操作条    `.ybh-mobile-actions` z-index 60
 *   z-index 高者压住低者，实测（1440×900，CDP）：
 *     桌面首访：横幅 × PWA 条重叠 **46,620 px²**、横幅 × 引导气泡 **1,520.9 px²**；
 *     手机端更糟：27,282 / 22,616 / 22,766 / 10,140 px² 四处重叠。
 *   用户看到的就是"一堆东西叠在一起"，而且低优先级的那个按钮根本点不到。
 *
 * ===================================================================
 * 做法
 * ===================================================================
 *   优先级：**Cookie 同意 > PWA 条 > 控制台气泡 > 吸底操作条**。
 *     · 同一时刻只让**最高优先级**的那条留在原位；
 *     · 比它低的两条（PWA / 气泡）直接不显示 —— 它们本来就是"可以下次再说"的东西；
 *     · 吸底操作条**不隐藏**（它是站点的投稿入口，藏掉会丢转化），也**不再被抬升**。
 *
 *   T67c（2026-09-30）：站长要求**回退"避让"方案** —— 原先用一条 CSS 变量
 *   `--ybh-bottom-lift` 把常驻的吸底条抬到浮层之上，观感上像在"躲"。
 *   现在：
 *     · Cookie 同意改成了**覆盖整屏的模态弹窗**（见 inc/ybh/cookie-banner.php），
 *       它开着时吸底条本来就在遮罩下面点不到，CSS 里直接隐藏即可，不需要任何抬升；
 *     · PWA 提示条由 CSS 让它**自己站到吸底条上方**（不与常驻入口抢位置）。
 *   于是 JS 这边只剩一件事：**判定当前谁在显示**，并把结果写成 `<html>` 上的
 *   `ybh-ov-*` 类。位置与显隐仍然全部交给 CSS。
 *
 *   为什么不像旧代码那样直接 `display:none` 吸底条：旧写法
 *   `body:has(#ybh-consent:not([hidden])) .ybh-mobile-actions{display:none}`
 *   在首访时把「我要投稿」一起藏掉了 —— 那是站点最想要的点击。
 *   （模态化之后这一条不再有代价：弹窗本来就盖住了整屏。）
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_footer', 'ybh_bottom_overlays_arbiter', 101);   // 晚于 banner(99) 与气泡(100)
function ybh_bottom_overlays_arbiter()
{
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }
    ?>
    <script id="ybh-bottom-overlays">
    /*
     * 底部浮层仲裁。详见 inc/ybh/bottom-overlays.php 顶部说明。
     * 顺序 = 优先级：先出现的先占位，后面的被压。
     */
    (function () {
      'use strict';
      var h = document.documentElement;
      var LAYERS = [
        { key: 'consent', cls: 'ybh-ov-consent', sel: '#ybh-consent' },
        { key: 'pwa',     cls: 'ybh-ov-pwa',     sel: '.ybh-pwa-bar' },
        { key: 'hint',    cls: 'ybh-ov-hint',    sel: '#ybh-console-hint' }
      ];

      function isVisible(el) {
        if (!el || el.hasAttribute('hidden')) { return false; }
        var cs = window.getComputedStyle(el);
        if (cs.display === 'none' || cs.visibility === 'hidden') { return false; }
        if (parseFloat(cs.opacity || '1') < 0.05) { return false; }
        var r = el.getBoundingClientRect();
        return r.width > 2 && r.height > 2;
      }

      function recalc() {
        var owner = null, ownerEl = null;
        for (var i = 0; i < LAYERS.length; i++) {
          var l = LAYERS[i];
          var el = document.querySelector(l.sel);
          var on = isVisible(el);
          h.classList.toggle(l.cls, on);
          if (on && !owner) { owner = l; ownerEl = el; }
        }
        h.classList.toggle('ybh-ov-any', !!owner);

        /*
         * T67c：**不再计算抬升量**（原来在这里算 `--ybh-bottom-lift`）。
         * 吸底操作条固定在自己的位置上；需要让位的只是"提示型"浮层，
         * 而它们要么不显示（见 CSS 的 `html.ybh-ov-*` 规则），要么自己站到吸底条上方。
         */
        h.style.removeProperty('--ybh-bottom-lift');

        // 通知其它脚本（控制台气泡据此决定"现在是不是轮到我"）
        try {
          window.dispatchEvent(new CustomEvent('ybh:overlaychange', {
            detail: { owner: owner ? owner.key : null }
          }));
        } catch (e) {}
      }

      var t = null;
      function schedule() { if (t) { clearTimeout(t); } t = setTimeout(recalc, 60); }

      // 首屏、尺寸变化、滚动（控制台按钮是滚动后才淡入的）都要重算
      window.addEventListener('resize', schedule, { passive: true });
      window.addEventListener('scroll', schedule, { passive: true });
      window.addEventListener('load', schedule);
      document.addEventListener('DOMContentLoaded', schedule);
      document.addEventListener('click', schedule, true);
      document.addEventListener('keydown', schedule, true);

      // 浮层自身的显隐/尺寸变化：用 MutationObserver 盯住它们，避免到处埋回调
      if (window.MutationObserver) {
        var mo = new MutationObserver(schedule);
        var attach = function () {
          for (var i = 0; i < LAYERS.length; i++) {
            var el = document.querySelector(LAYERS[i].sel);
            if (el) { mo.observe(el, { attributes: true, attributeFilter: ['hidden', 'class', 'style'] }); }
          }
        };
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', attach); }
        else { attach(); }
      }

      // 首访时几个浮层是错开时间出现的（横幅立即、气泡 1.6s、PWA 2.5s），
      // 所以除了事件驱动，再补几次定时重算，保证最终状态收敛。
      [0, 300, 1200, 2000, 3000, 5000].forEach(function (ms) { setTimeout(schedule, ms); });

      window.YBHOverlays = { refresh: recalc, layers: LAYERS };
      recalc();
    })();
    </script>
    <?php
}
