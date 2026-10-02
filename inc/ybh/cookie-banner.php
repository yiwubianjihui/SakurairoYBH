<?php
/**
 * YBH · 轻量 Cookie 同意横幅（替代 WPConsent）
 *
 * 为什么自建：WPConsent 带着后台管理、远程服务扫描、使用统计上报等一整套东西，
 * 而我们需要的只是「告知 + 取得同意 + 记住选择 + 给脚本一个判断依据」。
 * 本实现只有一个 PHP 文件 + 一小段内联 CSS/JS，不额外发请求、不连任何外部服务。
 *
 * 结构：
 *   底部横幅（首次访问）→ [接受全部] / [仅必要] / [自定义]
 *   自定义面板 → 必要(锁定) / 统计 / 营销 三个开关 + 保存
 *   选择存 localStorage；同时写一个同名 cookie，便于以后做服务端判断。
 *
 * 给脚本用：
 *   <script type="text/plain" data-ybh-consent="analytics" src="…"></script>
 *   —— 只有用户同意「统计」后，这段才会被真正插入页面（见下方 JS 的激活逻辑）。
 *   JS 侧也可直接判断： if (window.YBHConsent.has('analytics')) { … }
 *
 * 放设置入口（任意页面/文章里贴）：
 *   [ybh_cookie_settings]            文字链接
 *   [ybh_cookie_settings text="Cookie 设置"]
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 同意类别：键 => [名称, 说明]。necessary 恒为真且不可关闭。 */
function ybh_consent_categories()
{
    return array(
        'necessary' => array(
            'label' => '必要',
            'desc'  => '登录状态、评论、Cookie 偏好本身。关掉网站就无法正常工作。',
            'locked' => true,
        ),
        'analytics' => array(
            'label' => '统计',
            'desc'  => '匿名统计访问量，帮助我们了解哪些文章更受欢迎。',
            'locked' => false,
        ),
        'marketing' => array(
            'label' => '营销',
            'desc'  => '用于展示更相关的推广内容。本站目前尚未使用。',
            'locked' => false,
        ),
    );
}

/** Cookie / 隐私政策页（优先用站点已建的 /cookie-policy/） */
function ybh_consent_policy_url()
{
    foreach (get_pages(array('number' => 30)) as $p) {
        if (strtolower($p->post_name) === 'cookie-policy') {
            return get_permalink($p->ID);
        }
    }
    $pp = (int) get_option('wp_page_for_privacy_policy');
    return $pp ? get_permalink($pp) : home_url('/');
}

/* ---------------------------------------------------------------------------
 * 只在前端、且非后台/非 feed 时输出
 * ------------------------------------------------------------------------- */
add_action('wp_footer', 'ybh_render_cookie_banner', 99);
function ybh_render_cookie_banner()
{
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }
    $cats   = ybh_consent_categories();
    $policy = ybh_consent_policy_url();
    $key    = 'ybh_consent_v1';
    ?>
    <?php
    /*
     * T60：无障碍与"不逼人表态"三处改进（都在下面的标记与脚本里）：
     *   · 根容器 `role="dialog"` + `aria-label`，并把面板切换做成可感知的状态
     *     （自定义按钮带 `aria-expanded`/`aria-controls`，切面板时把焦点送进去）；
     *   · 多一个「稍后再说」（`data-act="later"`）：**不记录任何同意**，只本次会话不再打扰
     *     （存 sessionStorage）—— 不再迫使访客在没读完之前二选一；
     *   · 弹出示延迟到 1.2 秒或首次滚动 120px，让首屏先渲染完。
     */
    ?>
    <div id="ybh-consent" class="ybh-consent" role="dialog" aria-modal="true"
         aria-label="<?php echo esc_attr(ybh_t('Cookie 同意')); ?>" aria-live="polite" hidden>
        <?php
        /*
         * T67c：**从贴底横幅改成模态弹窗**（站长要求）。
         *   · 根容器自身就是遮罩层（`inset:0` + 半透明底），卡片居中；点遮罩空白处 = 「稍后再说」；
         *   · 既然是模态，补上焦点圈定（Tab 在卡片内循环）与滚动锁 —— 原来是非模态横幅，
         *     故意不做陷阱，现在语义变了，做法也要跟着变；
         *   · Esc 行为不变（自定义面板→退回；卡片上→稍后再说）。
         * T66：文案全部过 `ybh_t()`（英文/日文有译文，缺译回退中文）。
         */
        ?>
        <!-- 卡片 -->
        <div class="ybh-consent__bar" data-panel="bar">
            <div class="ybh-consent__text">
                <strong><i class="fa-solid fa-cookie-bite" aria-hidden="true"></i><?php ybh_e('我们使用 Cookie'); ?></strong>
                <span><?php ybh_e('必要的 Cookie 用于登录与评论；其余用于统计与推广，可由你决定是否允许。详见'); ?>
                    <a href="<?php echo esc_url($policy); ?>"><?php ybh_e('Cookie 政策'); ?></a>。</span>
            </div>
            <div class="ybh-consent__actions">
                <button type="button" class="ybh-consent__btn ybh-consent__btn--ghost" data-act="later"><?php ybh_e('稍后再说'); ?></button>
                <button type="button" class="ybh-consent__btn ybh-consent__btn--ghost" data-act="custom"
                        aria-expanded="false" aria-controls="ybh-consent-custom"><?php ybh_e('自定义'); ?></button>
                <button type="button" class="ybh-consent__btn ybh-consent__btn--ghost" data-act="reject"><?php ybh_e('仅必要'); ?></button>
                <button type="button" class="ybh-consent__btn ybh-consent__btn--primary" data-act="accept"><?php ybh_e('接受全部'); ?></button>
            </div>
        </div>

        <!-- 自定义面板 -->
        <div class="ybh-consent__bar ybh-consent__panel" id="ybh-consent-custom" data-panel="custom" hidden>
            <div class="ybh-consent__text">
                <strong><i class="fa-solid fa-sliders" aria-hidden="true"></i><?php ybh_e('Cookie 偏好'); ?></strong>
            </div>
            <ul class="ybh-consent__list">
                <?php foreach ($cats as $k => $c) : ?>
                    <li>
                        <label class="ybh-consent__item">
                            <input type="checkbox" data-cat="<?php echo esc_attr($k); ?>"
                                <?php echo $c['locked'] ? 'checked disabled' : ''; ?>>
                            <span class="ybh-consent__item-text">
                                <b><?php echo esc_html(ybh_t($c['label'])); ?><?php
                                    echo $c['locked'] ? esc_html(ybh_t('（必需）')) : ''; ?></b>
                                <em><?php echo esc_html(ybh_t($c['desc'])); ?></em>
                            </span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="ybh-consent__actions">
                <button type="button" class="ybh-consent__btn ybh-consent__btn--ghost" data-act="back"><?php ybh_e('返回'); ?></button>
                <button type="button" class="ybh-consent__btn ybh-consent__btn--primary" data-act="save"><?php ybh_e('保存选择'); ?></button>
            </div>
        </div>
    </div>
    <script>
    (function () {
      var KEY = <?php echo json_encode($key); ?>;
      var CATS = <?php echo json_encode(array_keys($cats)); ?>;
      var root = document.getElementById('ybh-consent');
      if (!root) return;

      function read() {
        try {
          var raw = localStorage.getItem(KEY);
          if (raw) return JSON.parse(raw);
        } catch (e) {}
        return null;
      }
      function write(v) {
        try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {}
        // 同时写 cookie（1 年），便于以后做服务端判断
        try {
          document.cookie = KEY + '=' + encodeURIComponent(JSON.stringify(v)) +
            ';path=/;max-age=31536000;SameSite=Lax' +
            (location.protocol === 'https:' ? ';Secure' : '');   // T60：https 下补 Secure
        } catch (e) {}
        apply(v);
      }
      // 把 data-ybh-consent="cat" 的 script 按同意情况真正插入
      function apply(v) {
        var list = document.querySelectorAll('script[type="text/plain"][data-ybh-consent]');
        for (var i = 0; i < list.length; i++) {
          var el = list[i], cat = el.getAttribute('data-ybh-consent');
          if (cat === 'necessary' || (v && v[cat])) {
            var s = document.createElement('script');
            for (var a = 0; a < el.attributes.length; a++) {
              var at = el.attributes[a];
              if (at.name !== 'type' && at.name !== 'data-ybh-consent') s.setAttribute(at.name, at.value);
            }
            s.text = el.textContent;
            el.parentNode.insertBefore(s, el.nextSibling);
            el.parentNode.removeChild(el);
          }
        }
        document.documentElement.setAttribute('data-ybh-consent',
          v ? CATS.filter(function (c) { return v[c]; }).join(',') : '');
      }
      /*
       * 焦点管理（T67c）：改成**模态**之后要做焦点圈定。
       * 原来是非模态横幅，故意不做陷阱（锁住焦点会挡住页面其它内容）；
       * 现在是覆盖整屏的弹窗，不圈定反而会让 Tab 跑到遮罩后面的页面上。
       * 关闭时仍把焦点还给打开前的元素。
       */
      var lastFocus = null;
      function focusables() {
        var sel = 'button:not([disabled]), a[href], input:not([disabled]), [tabindex]:not([tabindex="-1"])';
        var list = [];
        root.querySelectorAll('[data-panel]').forEach(function (panel) {
          if (panel.hidden) { return; }
          panel.querySelectorAll(sel).forEach(function (el) {
            if (el.offsetParent !== null || el === document.activeElement) { list.push(el); }
          });
        });
        return list;
      }
      function trapTab(ev) {
        var list = focusables();
        if (!list.length) { return; }
        var first = list[0], last = list[list.length - 1];
        var active = document.activeElement;
        if (ev.shiftKey && (active === first || !root.contains(active))) {
          ev.preventDefault(); last.focus();
        } else if (!ev.shiftKey && active === last) {
          ev.preventDefault(); first.focus();
        }
      }
      function focusFirst(panelEl) {
        if (!panelEl) { return; }
        var first = panelEl.querySelector('button, a[href], input:not([disabled])');
        if (first && first.focus) {
          try { first.focus({ preventScroll: true }); } catch (e) { first.focus(); }
        }
      }
      /* 模态期间锁住背景滚动，否则滚轮会带着遮罩后面的页面跑 */
      function lockScroll(on) {
        try { document.documentElement.classList.toggle('ybh-consent-lock', !!on); } catch (e) {}
      }
      function refreshOverlays() {
        if (window.YBHOverlays && window.YBHOverlays.refresh) { window.YBHOverlays.refresh(); }
      }
      function show(panel) {
        if (root.hidden) { lastFocus = document.activeElement; }
        root.hidden = false;
        lockScroll(true);
        var customBtn = root.querySelector('[data-act="custom"]');
        if (customBtn) { customBtn.setAttribute('aria-expanded', panel === 'custom' ? 'true' : 'false'); }
        var bars = root.querySelectorAll('[data-panel]');
        var shown = null;
        for (var i = 0; i < bars.length; i++) {
          var on = bars[i].getAttribute('data-panel') === panel;
          bars[i].hidden = !on;
          if (on) { shown = bars[i]; }
        }
        focusFirst(shown);      // 模态：打开就把焦点送进卡片（原来只在自定义面板时送）
        refreshOverlays();
      }
      function hide(restoreFocus) {
        root.hidden = true;
        lockScroll(false);
        var customBtn = root.querySelector('[data-act="custom"]');
        if (customBtn) { customBtn.setAttribute('aria-expanded', 'false'); }
        if (restoreFocus !== false && lastFocus && lastFocus.focus) {
          try { lastFocus.focus({ preventScroll: true }); } catch (e) {}
        }
        refreshOverlays();
      }

      /* 「稍后再说」：本次会话先不打扰，**不写任何同意记录**（下次访问还会问） */
      var SNOOZE = KEY + '_snooze';
      function snoozed() {
        try { return sessionStorage.getItem(SNOOZE) === '1'; } catch (e) { return false; }
      }
      function snooze() {
        try { sessionStorage.setItem(SNOOZE, '1'); } catch (e) {}
        hide(false);
      }

      root.addEventListener('click', function (ev) {
        // 点遮罩空白处 = 「稍后再说」（同样是"不表态"，不写同意记录）
        if (ev.target === root) { snooze(); return; }
        var btn = ev.target.closest ? ev.target.closest('[data-act]') : null;
        if (!btn) return;
        var act = btn.getAttribute('data-act');
        if (act === 'accept') {
          var all = { necessary: true };
          CATS.forEach(function (c) { all[c] = true; });
          write(all); hide();
        } else if (act === 'reject') {
          var min = { necessary: true };
          CATS.forEach(function (c) { min[c] = false; });
          min.necessary = true;
          write(min); hide();
        } else if (act === 'custom') {
          var cur = read() || {};
          root.querySelectorAll('input[data-cat]').forEach(function (i) {
            if (i.disabled) return;
            i.checked = !!cur[i.getAttribute('data-cat')];
          });
          show('custom');
        } else if (act === 'save') {
          var v = { necessary: true };
          root.querySelectorAll('input[data-cat]').forEach(function (i) {
            v[i.getAttribute('data-cat')] = i.disabled ? true : i.checked;
          });
          write(v); hide();
        } else if (act === 'back') {
          show('bar');
        } else if (act === 'later') {
          snooze();                       // T60：本次会话不再打扰，但不记录同意
        }
      });

      /* Esc：自定义面板里 → 退回横幅；卡片上 → 等同「稍后再说」 */
      document.addEventListener('keydown', function (ev) {
        if (root.hidden) { return; }
        if (ev.key === 'Tab' || ev.keyCode === 9) { trapTab(ev); return; }
        if (ev.key !== 'Escape' && ev.keyCode !== 27) { return; }
        var customOn = false;
        root.querySelectorAll('[data-panel]').forEach(function (b) {
          if (b.getAttribute('data-panel') === 'custom' && !b.hidden) { customOn = true; }
        });
        if (customOn) { show('bar'); } else { snooze(); }
      });

      // 对外 API
      window.YBHConsent = {
        has: function (c) { var v = read(); return c === 'necessary' ? true : !!(v && v[c]); },
        all: read,
        open: function () { show('bar'); },
        reset: function () {
          // T60：原来只清 localStorage，服务端那个同名 cookie 还在 ⇒ 状态可能不一致
          try { localStorage.removeItem(KEY); } catch (e) {}
          try { sessionStorage.removeItem(SNOOZE); } catch (e) {}
          try { document.cookie = KEY + '=;path=/;max-age=0;SameSite=Lax'; } catch (e) {}
          show('bar');
        }
      };

      var saved = read();
      apply(saved);              // 已同意 → 立即激活对应脚本

      /*
       * T60：未选择时**延迟**弹（1.2 秒或首次滚动超过 120px，谁先到算谁），
       * 让首屏先渲染完；本次会话点过「稍后再说」就不再弹。
       */
      if (!saved && !snoozed()) {
        var armed = false;
        var onScroll = function () { if (window.scrollY > 120) { arm(); } };
        function arm() {
          if (armed) { return; }
          armed = true;
          window.removeEventListener('scroll', onScroll, true);
          show('bar');
        }
        window.addEventListener('scroll', onScroll, { passive: true, capture: true });
        setTimeout(arm, 1200);
      }
    })();
    </script>
    <?php
}

/* ---------------------------------------------------------------------------
 * [ybh_cookie_settings] —— 在隐私政策页/页脚放一个「Cookie 设置」入口
 * ------------------------------------------------------------------------- */
add_shortcode('ybh_cookie_settings', function ($atts) {
    $a = shortcode_atts(array('text' => ''), $atts, 'ybh_cookie_settings');
    // T66：默认文案过 ybh_t()（英文/日文有译文）；站长显式传 text="…" 时按原样用
    $label = ($a['text'] !== '') ? (string) $a['text'] : ybh_t('Cookie 设置');
    return '<a href="#" class="ybh-consent-link" onclick="window.YBHConsent&&window.YBHConsent.open();return false;">'
        . esc_html($label) . '</a>';
});
