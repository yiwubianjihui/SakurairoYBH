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
    <div id="ybh-consent" class="ybh-consent" hidden>
        <!-- 横幅 -->
        <div class="ybh-consent__bar" data-panel="bar">
            <div class="ybh-consent__text">
                <strong>我们使用 Cookie</strong>
                <span>必要的 Cookie 用于登录与评论；其余用于统计与推广，可由你决定是否允许。详见
                    <a href="<?php echo esc_url($policy); ?>">Cookie 政策</a>。</span>
            </div>
            <div class="ybh-consent__actions">
                <button type="button" class="ybh-consent__btn ybh-consent__btn--ghost" data-act="custom">自定义</button>
                <button type="button" class="ybh-consent__btn ybh-consent__btn--ghost" data-act="reject">仅必要</button>
                <button type="button" class="ybh-consent__btn ybh-consent__btn--primary" data-act="accept">接受全部</button>
            </div>
        </div>

        <!-- 自定义面板 -->
        <div class="ybh-consent__bar ybh-consent__panel" data-panel="custom" hidden>
            <div class="ybh-consent__text">
                <strong>Cookie 偏好</strong>
            </div>
            <ul class="ybh-consent__list">
                <?php foreach ($cats as $k => $c) : ?>
                    <li>
                        <label class="ybh-consent__item">
                            <input type="checkbox" data-cat="<?php echo esc_attr($k); ?>"
                                <?php echo $c['locked'] ? 'checked disabled' : ''; ?>>
                            <span class="ybh-consent__item-text">
                                <b><?php echo esc_html($c['label']); ?><?php
                                    echo $c['locked'] ? '（必需）' : ''; ?></b>
                                <em><?php echo esc_html($c['desc']); ?></em>
                            </span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="ybh-consent__actions">
                <button type="button" class="ybh-consent__btn ybh-consent__btn--ghost" data-act="back">返回</button>
                <button type="button" class="ybh-consent__btn ybh-consent__btn--primary" data-act="save">保存选择</button>
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
            ';path=/;max-age=31536000;SameSite=Lax';
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
      function show(panel) {
        root.hidden = false;
        var bars = root.querySelectorAll('[data-panel]');
        for (var i = 0; i < bars.length; i++) bars[i].hidden = bars[i].getAttribute('data-panel') !== panel;
      }
      function hide() { root.hidden = true; }

      root.addEventListener('click', function (ev) {
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
        }
      });

      // 对外 API
      window.YBHConsent = {
        has: function (c) { var v = read(); return c === 'necessary' ? true : !!(v && v[c]); },
        all: read,
        open: function () { show('bar'); },
        reset: function () { try { localStorage.removeItem(KEY); } catch (e) {} show('bar'); }
      };

      var saved = read();
      apply(saved);              // 已同意 → 立即激活对应脚本
      if (!saved) show('bar');   // 未选择 → 弹横幅
    })();
    </script>
    <?php
}

/* ---------------------------------------------------------------------------
 * [ybh_cookie_settings] —— 在隐私政策页/页脚放一个「Cookie 设置」入口
 * ------------------------------------------------------------------------- */
add_shortcode('ybh_cookie_settings', function ($atts) {
    $a = shortcode_atts(array('text' => 'Cookie 设置'), $atts, 'ybh_cookie_settings');
    return '<a href="#" class="ybh-consent-link" onclick="window.YBHConsent&&window.YBHConsent.open();return false;">'
        . esc_html($a['text']) . '</a>';
});
