<?php
/**
 * YBH · 反馈功能（T68b）
 *
 * ===================================================================
 * 这是什么
 * ===================================================================
 *   `/feedback/` 前台反馈页：游客与登录用户都能提交（网站问题 / 文章纠错 /
 *   功能建议 / 其它），提交的内容存为**自定义文章类型** `ybh_feedback`，
 *   站长在后台「仪表盘 → 反馈」里看。不发邮件（后台列表 + 红点计数更可靠）。
 *
 * ===================================================================
 * 防滥用（游客可写 ⇒ 必须设防）
 * ===================================================================
 *   · nonce + Honeypot 暗字段（机器人填了就拒）；
 *   · 频率限制：同一 IP（登录用户按用户）**10 分钟一条**，超了退回提示；
 *   · 内容与联系方式长度上限；正文 strip_tags；
 *   · 自定义文章类型不 public、不进搜索/REST 前台，游客前台永远读不到。
 *
 * ===================================================================
 * 入口
 * ===================================================================
 *   · 顶部导航追加「反馈」（顶级项，游客可见；`ybh_feedback_in_nav` 可关）；
 *   · 短代码 `[ybh_feedback]` 也可以贴到任何页面。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_FEEDBACK_SLUG')) {
    define('YBH_FEEDBACK_SLUG', 'feedback');
}

/* ---------------------------------------------------------------------------
 * 存储容器：私有自定义文章类型
 * ------------------------------------------------------------------------- */
add_action('init', function () {
    register_post_type('ybh_feedback', array(
        'labels' => array(
            'name'          => '反馈',
            'singular_name' => '反馈',
            'add_new_item'  => '新反馈',
        ),
        'public'              => false,          // 前台完全不可见
        'exclude_from_search' => true,
        'show_ui'             => true,           // 后台可见
        'show_in_menu'        => true,
        'show_in_rest'        => false,
        'menu_icon'           => 'dashicons-feedback',
        'menu_position'       => 26,
        'supports'            => array('title', 'editor', 'meta'),
        'capability_type'     => 'post',
    ));
});

/** 后台列表加"类型 / 联系方式 / 来源页"三列，并给未处理的上底色 */
add_filter('manage_ybh_feedback_posts_columns', function ($cols) {
    $cols['ybh_fb_kind'] = '类型';
    $cols['ybh_fb_contact'] = '联系方式';
    $cols['ybh_fb_page'] = '来源页';
    return $cols;
});
add_action('manage_ybh_feedback_posts_custom_column', function ($col, $post_id) {
    if ($col === 'ybh_fb_kind') {
        $kinds = ybh_feedback_kinds();
        $v = (string) get_post_meta($post_id, '_ybh_fb_kind', true);
        echo esc_html($kinds[$v] ?? $v);
    } elseif ($col === 'ybh_fb_contact') {
        echo esc_html((string) get_post_meta($post_id, '_ybh_fb_contact', true));
    } elseif ($col === 'ybh_fb_page') {
        $u = (string) get_post_meta($post_id, '_ybh_fb_page', true);
        echo $u !== '' ? '<a href="' . esc_url($u) . '" target="_blank" rel="noopener">' . esc_html(wp_basename((string) parse_url($u, PHP_URL_PATH) ?: $u)) . '</a>' : '—';
    }
}, 10, 2);

/** 反馈类型（键名即契约，前台下拉与后台列共用） */
function ybh_feedback_kinds()
{
    return apply_filters('ybh_feedback_kinds', array(
        'bug'     => '网站问题',
        'typo'    => '文章纠错',
        'idea'    => '功能建议',
        'other'   => '其它',
    ));
}

/* ---------------------------------------------------------------------------
 * 前台页面：`/feedback/`（不建页面、不改 rewrite，同 /write/ 的套路）
 * ------------------------------------------------------------------------- */
add_action('template_redirect', 'ybh_feedback_route', 0);
function ybh_feedback_route()
{
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $path = trim((string) wp_parse_url($uri, PHP_URL_PATH), '/');
    if ($path !== YBH_FEEDBACK_SLUG) {
        return;
    }
    global $wp_query;
    if ($wp_query) { $wp_query->is_404 = false; }
    status_header(200);
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    nocache_headers();

    get_header();
    echo '<main id="main" class="site-main ybh-fb-main" role="main">' . ybh_feedback_render() . '</main>';
    get_footer();
    exit;
}

/** 表单页（含提交结果提示） */
function ybh_feedback_render()
{
    $msg = isset($_GET['ybh_fb']) ? sanitize_key(wp_unslash((string) $_GET['ybh_fb'])) : '';
    $msg_map = array(
        'ok'        => array('反馈已收到，谢谢！站长会尽快看到它。', true),
        'rate'      => array('你刚提交过一条，请稍等几分钟再发下一条。', false),
        'empty'     => array('内容不能为空。', false),
        'nonce'     => array('页面已过期，请刷新后重试。', false),
        'long'      => array('内容太长了（上限 5000 字）。', false),
        'fail'      => array('提交失败，请稍后再试。', false),
    );
    $notice = '';
    if ($msg !== '' && isset($msg_map[$msg])) {
        $notice = '<p class="ybh-fb__msg ' . ($msg_map[$msg][1] ? 'ok' : 'err') . '">' . esc_html($msg_map[$msg][0]) . '</p>';
    }

    // 带上"从哪来"：站内链接进来的（?from=…）预填来源页
    $from = isset($_GET['from']) ? esc_url_raw(wp_unslash((string) $_GET['from'])) : '';
    $me = wp_get_current_user();

    ob_start();
    ?>
    <div class="ybh-fb">
      <h1 class="ybh-fb__title"><?php echo esc_html(ybh_t('反馈')); ?></h1>
      <p class="ybh-fb__desc"><?php echo esc_html(ybh_t('发现问题、想纠错、或有想法，都写在这里。不需要登录；留联系方式的话我们能回复你。')); ?></p>
      <?php echo $notice; ?>
      <form class="ybh-fb__form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="ybh_feedback_send" />
        <?php wp_nonce_field('ybh_feedback_send', 'ybh_fb_nonce'); ?>
        <?php /* Honeypot：人不会看到也不会填 */ ?>
        <input type="text" name="ybh_fb_hp" value="" style="position:absolute;left:-9999px;" tabindex="-1" autocomplete="off" aria-hidden="true" />

        <div class="ybh-fb__row">
          <label for="ybh-fb-kind"><?php echo esc_html(ybh_t('类型')); ?></label>
          <select id="ybh-fb-kind" name="ybh_fb_kind">
            <?php foreach (ybh_feedback_kinds() as $k => $label) : ?>
              <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="ybh-fb__row">
          <label for="ybh-fb-contact"><?php echo esc_html(ybh_t('联系方式（选填）')); ?></label>
          <input type="text" id="ybh-fb-contact" name="ybh_fb_contact" maxlength="120"
                 placeholder="<?php echo esc_attr(ybh_t('邮箱 / QQ / 微信，愿意被回复就填')); ?>"
                 value="<?php echo esc_attr($me->exists() ? (string) $me->display_name : ''); ?>" />
        </div>

        <div class="ybh-fb__row">
          <label for="ybh-fb-body"><?php echo esc_html(ybh_t('内容')); ?></label>
          <textarea id="ybh-fb-body" name="ybh_fb_body" rows="7" maxlength="5000" required
                    placeholder="<?php echo esc_attr(ybh_t('越具体越好：哪个页面、什么操作、期望什么')); ?>"></textarea>
        </div>

        <?php if ($from !== '') : ?>
          <p class="ybh-fb__hint"><?php echo esc_html(ybh_t('将附上来源页面')); ?>：<code><?php echo esc_html($from); ?></code></p>
          <input type="hidden" name="ybh_fb_page" value="<?php echo esc_attr($from); ?>" />
        <?php endif; ?>

        <button type="submit" class="ybh-fb__btn"><?php echo esc_html(ybh_t('提交反馈')); ?></button>
      </form>
      <?php
      /*
       * pjax 对表单提交的劫持（翻译工作室踩过：地址被拼成 [object HTMLInputElement]）。
       * 同一套修法：捕获阶段拦下 submit、stopImmediatePropagation，再走浏览器原生提交。
       */
      ?>
      <script>
        (function () {
          var f = document.querySelector('.ybh-fb__form');
          if (!f) { return; }
          f.addEventListener('submit', function (e) {
            e.stopImmediatePropagation();
            e.preventDefault();
            HTMLFormElement.prototype.submit.call(f);
          }, true);
        })();
      </script>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** 反馈页地址 */
function ybh_feedback_url()
{
    return home_url('/' . YBH_FEEDBACK_SLUG . '/');
}

/** 提交处理（PRG：处理完重定向回表单页带结果码） */
add_action('admin_post_nopriv_ybh_feedback_send', 'ybh_feedback_handle');
add_action('admin_post_ybh_feedback_send', 'ybh_feedback_handle');
function ybh_feedback_handle()
{
    $back = ybh_feedback_url();
    // Honeypot：被填了 = 机器人，静默"成功"
    if (trim((string) wp_unslash($_POST['ybh_fb_hp'] ?? '')) !== '') {
        wp_safe_redirect(add_query_arg('ybh_fb', 'ok', $back));
        exit;
    }
    if (!isset($_POST['ybh_fb_nonce']) || !wp_verify_nonce((string) $_POST['ybh_fb_nonce'], 'ybh_feedback_send')) {
        wp_safe_redirect(add_query_arg('ybh_fb', 'nonce', $back));
        exit;
    }

    $body = trim(wp_strip_all_tags(wp_unslash((string) ($_POST['ybh_fb_body'] ?? ''))));
    $contact = trim(wp_strip_all_tags(wp_unslash((string) ($_POST['ybh_fb_contact'] ?? ''))));
    $kind = sanitize_key(wp_unslash((string) ($_POST['ybh_fb_kind'] ?? 'other')));
    $page = esc_url_raw(wp_unslash((string) ($_POST['ybh_fb_page'] ?? '')));
    if (!isset(ybh_feedback_kinds()[$kind])) { $kind = 'other'; }

    if ($body === '') {
        wp_safe_redirect(add_query_arg('ybh_fb', 'empty', $back));
        exit;
    }
    if (mb_strlen($body) > 5000 || mb_strlen($contact) > 120) {
        wp_safe_redirect(add_query_arg('ybh_fb', 'long', $back));
        exit;
    }

    // 频率限制：登录用户按用户，游客按 IP，10 分钟一条
    $who = is_user_logged_in() ? 'u' . get_current_user_id()
        : 'ip' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    $lock = 'ybh_fb_rl_' . $who;
    if (get_transient($lock)) {
        wp_safe_redirect(add_query_arg('ybh_fb', 'rate', $back));
        exit;
    }
    set_transient($lock, 1, 10 * MINUTE_IN_SECONDS);

    $uid = is_user_logged_in() ? get_current_user_id() : 0;
    $title = mb_substr($body, 0, 60);
    $id = wp_insert_post(array(
        'post_type'    => 'ybh_feedback',
        'post_title'   => $title,
        'post_content' => $body,
        'post_status'  => 'pending',           // 未处理
        'post_author'  => $uid,
    ), true);
    if (is_wp_error($id)) {
        wp_safe_redirect(add_query_arg('ybh_fb', 'fail', $back));
        exit;
    }
    update_post_meta($id, '_ybh_fb_kind', $kind);
    update_post_meta($id, '_ybh_fb_contact', $contact);
    update_post_meta($id, '_ybh_fb_page', $page);
    update_post_meta($id, '_ybh_fb_ip', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    wp_safe_redirect(add_query_arg('ybh_fb', 'ok', $back));
    exit;
}

/* ---------------------------------------------------------------------------
 * 后台：仪表盘「一眼看到未处理数」
 * ------------------------------------------------------------------------- */
add_action('wp_dashboard_setup', function () {
    if (!current_user_can('edit_others_posts')) { return; }
    wp_add_dashboard_widget('ybh_feedback_widget', '反馈', function () {
        $pending = (int) wp_count_posts('ybh_feedback')->pending;
        $total = (int) wp_count_posts('ybh_feedback')->pending + (int) wp_count_posts('ybh_feedback')->private;
        echo $pending > 0
            ? '<p style="margin:0 0 6px;"><b style="color:#d63638;">' . (int) $pending . '</b> 条反馈待处理。</p>'
            : '<p style="margin:0 0 6px;">暂无待处理的反馈。</p>';
        echo '<p style="margin:0;"><a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=ybh_feedback')) . '">查看反馈</a></p>';
    });
});

/* ---------------------------------------------------------------------------
 * 入口（T69 改版）：**右上角图标按钮**，不再占顶部菜单的一项
 *
 * 站长要求：「反馈按钮改到界面右上角，仅显示图标」。
 *   · 菜单里那项默认**关闭**（想恢复：`add_filter('ybh_feedback_in_nav','__return_true')`）；
 *   · 改为在 header 右上角（语言胶囊 / 用户头像旁）输出一个 33×33 圆钮，
 *     只用图标（对话气泡），文字进 aria-label / title，与旁边语言钮视觉一致。
 * ------------------------------------------------------------------------- */

/** 右上角反馈图标（header.php 调用；返回已转义的 HTML） */
function ybh_feedback_icon_button()
{
    if (is_admin() || !function_exists('ybh_feedback_url')) {
        return '';
    }
    if (!apply_filters('ybh_feedback_show_icon', true)) {
        return '';
    }
    $label = function_exists('ybh_t') ? ybh_t('反馈') : '反馈';
    return '<a class="ybh-topbtn ybh-feedback-btn" href="' . esc_url(ybh_feedback_url()) . '"'
        . ' title="' . esc_attr($label) . '" aria-label="' . esc_attr($label) . '">'
        . '<i class="fa-solid fa-comment-dots" aria-hidden="true"></i>'
        . '<span class="screen-reader-text">' . esc_html($label) . '</span>'
        . '</a>';
}

add_filter('wp_nav_menu_objects', 'ybh_feedback_nav_item', 31, 2);
function ybh_feedback_nav_item($items, $args = null)
{
    if (is_admin() || !function_exists('ybh_feedback_url')) {
        return $items;
    }
    $loc = is_object($args) && isset($args->theme_location) ? (string) $args->theme_location : '';
    if ($loc !== '' && $loc !== 'primary') {
        return $items;
    }
    // T69：默认不再往菜单里插（改走右上角图标）
    if (!apply_filters('ybh_feedback_in_nav', false)) {
        return $items;
    }
    foreach ((array) $items as $it) {
        if (!empty($it->ybh_is_feedback)) { return $items; }
    }
    $new = new stdClass();
    $new->ID = 0;
    $new->menu_item_parent = 0;
    $new->title = function_exists('ybh_t') ? ybh_t('反馈') : '反馈';
    $new->url = ybh_feedback_url();
    $new->type = 'custom';
    $new->object = 'custom';
    $new->db_id = 0;
    $new->classes = array('ybh-feedback-link');
    $new->menu_order = PHP_INT_MAX;
    $new->current = false;
    $new->current_item_ancestor = false;
    $new->current_item_parent = false;
    $new->ybh_is_feedback = true;
    $items[] = $new;
    return $items;
}
