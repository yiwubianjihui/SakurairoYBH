<?php
/**
 * YBH · 右下角控制台的「引导气泡」（任务清单：给控制台加引导，因为用户可能看不到）
 *
 * 背景：#changskin 是主题右下角的一个小圆按钮，点开是控制台（换主题色 / 日夜 /
 *       紧凑模式）。它只有图标、没有文字，而且默认是 scale(0) 隐藏、要滚动一段
 *       距离才淡入（主题 js/app.js 在 scrollY>20 时才显示）。访客基本注意不到，
 *       站长自己也反馈"可能看不到"。
 *
 * 做法：首次访问时（滚动出现控制台之后）在按钮旁边弹一个气泡 + 给按钮加一圈
 *       呼吸光环，说明它是干什么的；用户点一下气泡就直接把控制台打开，
 *       点别处/滚动/按键/超时都会收起。**只提示一次**（localStorage 记名），
 *       不打扰老访客；尊重 prefers-reduced-motion 与 html.ybh-lite（不呼吸）。
 *
 * 为什么用独立脚本 + 事件委托：见 bootstrap.php 里紧凑按钮那段注释 ——
 * footer.php 先调 wp_footer() 再输出 .skin-menu，脚本执行时按钮还不在 DOM 里。
 * 这里挂在 wp_footer prio 100（在主题输出控制台之后、也在紧凑按钮脚本 prio 99 之后），
 * 并且用 getElementById 在 DOMContentLoaded 后再查一次，双保险。
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_footer', 'ybh_console_hint', 100);
function ybh_console_hint()
{
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }
    ?>
    <div class="ybh-console-hint" id="ybh-console-hint" role="status" hidden>
        <span class="ybh-console-hint__arrow" aria-hidden="true"></span>
        <div class="ybh-console-hint__body">
            <strong class="ybh-console-hint__title">这里可以换配色</strong>
            <span class="ybh-console-hint__text">点右下角这个按钮：换主题色、切换紧凑模式、日夜模式</span>
            <button type="button" class="ybh-console-hint__close" aria-label="知道了，不再提示">知道了</button>
        </div>
    </div>
    <script>
    (function () {
      var KEY = 'ybh_console_hint_v1';   // 改版本号可让全部访客再看一次
      var h = document.documentElement;

      function seen() {
        try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; }
      }
      function markSeen() {
        try { localStorage.setItem(KEY, '1'); } catch (e) {}
      }

      function run() {
        var box = document.getElementById('ybh-console-hint');
        var btn = document.getElementById('changskin');
        if (!box || !btn || seen()) { return; }

        var timer = null;
        var shownAt = 0;
        var waits = 0;

        function cleanup() {
          document.removeEventListener('click', onAny, true);
          document.removeEventListener('keydown', onAny, true);
          window.removeEventListener('scroll', hide, true);
        }
        function hide() {
          if (timer) { clearTimeout(timer); timer = null; }
          box.hidden = true;
          h.classList.remove('ybh-console-hint-on');
          cleanup();
        }
        /*
         * T60：**只有真的被看到才算"提示过了"**。
         * 旧实现里任何一次外部点击（包括点在 Cookie 横幅上的那一下）都会 markSeen()，
         * 于是首访用户其实没读到气泡，却再也不会看到它 —— 白提示一次。
         * 现在：点「知道了」、或显示满 2.5 秒后消失 → 记名；更早被点掉 → 不记名，下次还会出现。
         */
        function dismiss(explicit) {
          if (explicit || (shownAt && Date.now() - shownAt > 2500)) { markSeen(); }
          hide();
        }
        function onAny(e) {
          // 点在气泡内部不当作"点别处"
          if (box.contains(e.target)) { return; }
          dismiss(false);
        }

        /* 有更高优先级的底部浮层（Cookie 横幅 / PWA 条）在场时让路 —— 见
           inc/ybh/bottom-overlays.php。等它们消失后再弹，不把提示叠在别人身上。 */
        function suppressed() {
          var c = document.documentElement.classList;
          return c.contains('ybh-ov-consent') || c.contains('ybh-ov-pwa');
        }

        function tryShow() {
          if (seen() || !box.hidden) { return; }
          if (suppressed()) { return; }
          box.hidden = false;
          h.classList.add('ybh-console-hint-on');
          shownAt = Date.now();
          document.addEventListener('click', onAny, true);
          document.addEventListener('keydown', onAny, true);
          window.addEventListener('scroll', hide, true);
          timer = setTimeout(function () { dismiss(true); }, 12000);   // 12 秒后自动收起（视为已看到）
          if (window.YBHOverlays && window.YBHOverlays.refresh) { window.YBHOverlays.refresh(); }
        }

        // 浮层状态变化时重新判断：该让路就让路，该出场就出场（最多重试 20 次，别长期挂定时器）
        window.addEventListener('ybh:overlaychange', function () {
          if (suppressed()) {
            if (!box.hidden) { hide(); }        // 让路但**不记名**，下次还能看到
            return;
          }
          if (box.hidden && !seen() && waits < 20) {
            waits++;
            setTimeout(tryShow, 400);
          }
        });

        // 等控制台自己淡入（主题在 scrollY>20 才显示），再弹提示
        setTimeout(tryShow, 1600);

        box.addEventListener('click', function (e) {
          if (e.target && e.target.classList && e.target.classList.contains('ybh-console-hint__close')) {
            dismiss(true);                       // 明确点了「知道了」→ 记名
            return;
          }
          // 点气泡主体：顺手把控制台打开，让用户立刻看到它长什么样
          try { btn.click(); } catch (err) {}
          dismiss(true);
        });
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
      } else {
        run();
      }
    })();
    </script>
    <?php
}
