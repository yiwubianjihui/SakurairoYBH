<?php
/**
 * YBH · 翻译工作室（T66j）—— 主站内的翻译工作台
 *
 * ===================================================================
 * 为什么从"静态站"搬到主站
 * ===================================================================
 *   原来工作台是 `i18n.yibianhui.cn` 上的静态站点，靠跨域调 REST，于是要处理
 *   CORS、共用 cookie、nonce 这些麻烦事（也确实踩过）。站长要求"不必继续用静态站，
 *   做一个功能更全面、更精美的页面"，于是搬进主站：
 *     · **同源**：登录状态、nonce、表单提交全是 WordPress 原生那一套，不再有跨域问题；
 *     · **服务端渲染**：列表/筛选/分页不依赖 JS 也能用，首屏快、可分享链接（参数在 URL 上）；
 *     · **能力更全**：筛选/搜索/分页、逐条与批量审核、撤销、文章标题与正文、统计与完成度、
 *       投票、我的提案、按提案人过滤。
 *
 *   路径：`/i18n/`（与 `/write/` 同一套路：`template_redirect` 接管 404，不改 rewrite、不建页面）。
 *
 * ===================================================================
 * 权限
 * ===================================================================
 *   · 未登录：可以浏览（只读）；
 *   · 登录用户（`edit_posts`，本站默认角色是 contributor）：可提交提案、投票；
 *   · 管理员（`manage_options`）：可批准 / 驳回 / 撤销、批量处理。
 *   **前端隐藏只是体验，真正的权限由这里和 REST 端点两边各自校验。**
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_STUDIO_SLUG')) {
    define('YBH_STUDIO_SLUG', 'i18n');
}

/** 工作室地址 */
function ybh_studio_url($args = array())
{
    $base = home_url('/' . YBH_STUDIO_SLUG . '/');
    return $args ? add_query_arg($args, $base) : $base;
}

/**
 * 工作室的「目标语言」参数（T69）。
 *
 * ⚠️ 为什么不能继续用 `?lang=`（站长反馈的 bug 根因）：
 *   `lang` 是**全站语言参数** —— `ybh_i18n_boot()` 会把它写进 cookie，
 *   于是"在工作台把目标语言切成英文"会**连带把整站界面切成英文**；
 *   回到主站再切回中文，cookie 变了，而工作台 URL 上的 `lang=en` 优先级更高，
 *   一进工作台又变成英文 —— 表现就是"切语言后串站、来回来回跳"。
 *
 * 现在工作室用**私有参数 `tl`**（translation language）：
 *   · 只决定"我在翻译哪种语言"，绝不改全站语言 / cookie；
 *   · 旧的 `?lang=xx` 链接仍然接受（兼容已分享出去的地址与"帮助本地化"入口）。
 */
function ybh_studio_lang_param()
{
    return 'tl';
}

/**
 * 取工作室当前的目标语言。
 *
 * 优先 `tl`（新），其次 `lang`（旧链接兼容），都合法时回落站点当前语言。
 */
function ybh_studio_current_lang()
{
    foreach (array(ybh_studio_lang_param(), 'lang') as $k) {
        if (!isset($_GET[$k])) {
            continue;
        }
        $v = sanitize_text_field(wp_unslash((string) $_GET[$k]));
        if (ybh_language_valid($v)) {
            return $v;
        }
    }
    return function_exists('ybh_current_language') ? ybh_current_language() : 'zh-Hans';
}

/* ---------------------------------------------------------------------------
 * 表单处理（admin_post，带 nonce）—— 同源，不必绕 REST
 * ------------------------------------------------------------------------- */
add_action('admin_post_ybh_studio', 'ybh_studio_handle');
function ybh_studio_handle()
{
    if (!is_user_logged_in()) {
        wp_safe_redirect(wp_login_url(ybh_studio_url()));
        exit;
    }
    $back = wp_get_referer() ?: ybh_studio_url();
    $act = isset($_POST['ybh_act']) ? sanitize_key(wp_unslash((string) $_POST['ybh_act'])) : '';
    $nonce = isset($_POST['_ybh_nonce']) ? (string) wp_unslash($_POST['_ybh_nonce']) : '';
    if (!wp_verify_nonce($nonce, 'ybh_studio')) {
        wp_safe_redirect(add_query_arg('ybh_msg', 'err-nonce', $back));
        exit;
    }

    $u = wp_get_current_user();
    global $wpdb;
    $t = ybh_i18n_proposals_table();

    switch ($act) {
        /* 提交提案（界面文案 或 文章标题/正文） */
        case 'propose':
            $lang = sanitize_text_field(wp_unslash((string) ($_POST['lang'] ?? '')));
            $text = trim(wp_kses_post(wp_unslash((string) ($_POST['proposal'] ?? ''))));
            $msgid = (string) wp_unslash((string) ($_POST['msgid'] ?? ''));
            $obj_type = (($_POST['obj_type'] ?? 'string') === 'post') ? 'post' : 'string';
            $obj_id = (int) ($_POST['obj_id'] ?? 0);
            $field = function_exists('ybh_i18n_field_valid') && ybh_i18n_field_valid((string) ($_POST['field'] ?? ''))
                ? (string) $_POST['field'] : '';
            $ok = true;
            if ($text === '' || !ybh_language_valid($lang)) { $ok = false; }
            if ($obj_type === 'post' && (!$obj_id || $field === '' || !get_post($obj_id))) { $ok = false; }
            if ($msgid === '' && $obj_type === 'post') { $msgid = get_the_title($obj_id); }
            if (!$ok) {
                wp_safe_redirect(add_query_arg('ybh_msg', 'err-input', $back));
                exit;
            }
            $wpdb->insert($t, array(
                'msgid' => $msgid, 'msgid_hash' => ybh_i18n_msgid_hash($msgid), 'lang' => $lang,
                'proposal' => $text, 'author_id' => (int) $u->ID, 'author_name' => (string) $u->display_name,
                'created' => current_time('mysql'), 'updated' => current_time('mysql'),
                'status' => 'pending', 'votes' => '[]',
                'obj_type' => $obj_type, 'obj_id' => $obj_id, 'field' => $field,
            ), array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'));
            wp_safe_redirect(add_query_arg('ybh_msg', 'proposed', $back) . '#p' . (int) $wpdb->insert_id);
            exit;

        /* 投票 */
        case 'vote':
            $id = (int) ($_POST['id'] ?? 0);
            $row = $wpdb->get_row($wpdb->prepare("SELECT votes FROM {$t} WHERE id = %d", $id), ARRAY_A);
            if ($row) {
                $votes = json_decode((string) $row['votes'], true);
                $votes = is_array($votes) ? array_map('intval', $votes) : array();
                $uid = (int) $u->ID;
                $votes = in_array($uid, $votes, true) ? array_values(array_diff($votes, array($uid))) : array_merge($votes, array($uid));
                $wpdb->update($t, array('votes' => wp_json_encode(array_values($votes)), 'updated' => current_time('mysql')),
                    array('id' => $id), array('%s', '%s'), array('%d'));
            }
            wp_safe_redirect(add_query_arg('ybh_msg', 'voted', $back));
            exit;

        /* 审核（单条） */
        case 'review':
        case 'bulk':
            if (!current_user_can('manage_options')) {
                wp_safe_redirect(add_query_arg('ybh_msg', 'err-cap', $back));
                exit;
            }
            $action = sanitize_key(wp_unslash((string) ($_POST['review_action'] ?? '')));
            $ids = $act === 'bulk'
                ? array_slice(array_map('intval', (array) ($_POST['ids'] ?? array())), 0, 300)
                : array((int) ($_POST['id'] ?? 0));
            $done = 0;
            foreach ($ids as $id) {
                if (!$id) { continue; }
                $res = ybh_i18n_review_proposal($id, $action, '', (int) $u->ID);
                if (!is_wp_error($res)) { $done++; }
            }
            wp_safe_redirect(add_query_arg('ybh_msg', 'reviewed-' . $done, $back));
            exit;
    }
    wp_safe_redirect($back);
    exit;
}

/* ---------------------------------------------------------------------------
 * 路由：`/i18n/`
 * ------------------------------------------------------------------------- */
add_action('template_redirect', 'ybh_studio_route', 0);
function ybh_studio_route()
{
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }
    if (ybh_studio_current_slug() !== YBH_STUDIO_SLUG) {
        return;
    }
    global $wp_query;
    if ($wp_query) {
        $wp_query->is_404 = false;
    }
    status_header(200);
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    if (!defined('DONOTCACHEOBJECT')) { define('DONOTCACHEOBJECT', true); }
    nocache_headers();

    /*
     * 表单兜底脚本：主题的 pjax 会劫持表单提交（实测把地址拼成
     * `/i18n/[object HTMLInputElement]` → 404）。这段脚本在捕获阶段拦下提交，
     * 改走浏览器原生 POST。必须在 get_header() 之前 enqueue 才会进 <head>。
     */
    wp_enqueue_script('ybh-studio', get_template_directory_uri() . '/js/ybh-studio.js',
        array(), defined('YBH_VERSION') ? YBH_VERSION : null, true);

    /*
     * T68b：AJAX 片段端点 —— `?partial=pane` 只输出右栏 HTML（词条详情 + 提案）。
     * 前端（js/ybh-studio.js）拦截左栏词条点击，fetch 这个片段就地替换，
     * 词条切换不再整页刷新；URL 照旧 pushState（刷新/分享语义不变）。
     */
    if (isset($_GET['partial']) && sanitize_key(wp_unslash((string) $_GET['partial'])) === 'pane') {
        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        echo ybh_studio_partial_pane();
        exit;
    }

    get_header();
    echo '<main id="main" class="site-main ybh-st-main" role="main">' . ybh_studio_render() . '</main>';
    get_footer();
    exit;
}

/** 请求路径（去斜杠、去查询串） */
function ybh_studio_current_slug()
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return trim($path, '/');
}

/* ---------------------------------------------------------------------------
 * 渲染
 * ------------------------------------------------------------------------- */
function ybh_studio_render()
{
    $langs = ybh_languages();
    // T69：目标语言走私有参数 `tl`（旧的 `lang` 仍兼容），不再与全站语言互相干扰
    $lang = ybh_studio_current_lang();
    $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash((string) $_GET['tab'])) : 'strings';
    if (!in_array($tab, array('strings', 'articles', 'mine', 'review'), true)) { $tab = 'strings'; }
    $filter = isset($_GET['f']) ? sanitize_key(wp_unslash((string) $_GET['f'])) : 'todo';
    if (!in_array($filter, array('todo', 'done', 'all', 'mine'), true)) { $filter = 'todo'; }
    $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $per = 30;
    // Crowdin 式左右分栏：左边选词条，右边看它的全部提案与输入框。选中项走 `?unit=`（服务端渲染，无需 JS）
    $unit = isset($_GET['unit']) ? preg_replace('~[^a-f0-9]~i', '', (string) wp_unslash($_GET['unit'])) : '';
    $is_admin = current_user_can('manage_options');
    $can_propose = is_user_logged_in() && current_user_can('edit_posts');
    $me = is_user_logged_in() ? (string) wp_get_current_user()->display_name : '';

    $approved = ybh_i18n_overlay_get($lang);
    $auto = function_exists('ybh_i18n_auto_adopted') ? ybh_i18n_auto_adopted($lang) : array();
    $entries = ybh_studio_string_entries();          // [msgid, refs…]（来自主题源码扫描结果，缓存）
    $props = ybh_studio_proposals($lang, 600);       // 该语言的全部提案
    $by_mid = array();
    $by_obj = array();
    foreach ($props as $p) {
        if ($p['obj_type'] === 'post') {
            $by_obj[$p['obj_id'] . '|' . $p['field']][] = $p;
        } else {
            $by_msgid[$p['msgid']][] = $p;
        }
    }
    $by_mid = isset($by_msgid) ? $by_msgid : array();

    $done = 0;
    foreach ($entries as $e) { if (!empty($approved[$e['msgid']])) { $done++; } }
    $total = count($entries);
    $pct = $total ? round($done / $total * 100) : 0;
    $pending = array_values(array_filter($props, function ($p) { return $p['status'] === 'pending'; }));
    // ⚠️ 闭包里必须 `use ($me)`：PHP 的箭头/匿名函数不会自动继承外部变量
    //    （第一版漏了，页面直接 Warning + 后续 fatal）
    $mine = array_values(array_filter($props, function ($p) use ($me) { return $p['author'] === $me; }));

    ob_start();
    ?>
    <div class="ybh-st" id="ybh-st">

      <header class="ybh-st__head">
        <div class="ybh-st__brand">
          <span class="ybh-st__logo">YBH</span>
          <div>
            <h1>翻译工作室</h1>
            <p>界面文案与文章都可以在这里提提案，管理员批准后**立刻对全站生效**。</p>
          </div>
        </div>
        <div class="ybh-st__head-right">
          <?php if ($me !== '') : ?>
            <span class="ybh-st__user">
              <b><?php echo esc_html($me); ?></b>
              <?php echo $is_admin ? '<em>管理员</em>' : '<em>译者</em>'; ?>
            </span>
            <?php if ($is_admin) : ?>
              <a class="ybh-st__btn ghost" href="<?php echo esc_url(admin_url('tools.php?page=ybh-i18n-proposals')); ?>">后台审核</a>
            <?php endif; ?>
          <?php else : ?>
            <a class="ybh-st__btn primary" href="<?php echo esc_url(wp_login_url(ybh_studio_url(array('tl' => $lang)))); ?>">登录后参与</a>
          <?php endif; ?>
        </div>
      </header>

      <section class="ybh-st__overview">
        <div class="ybh-st__prog">
          <div class="ybh-st__prog-head">
            <span><?php echo esc_html(ybh_language_label($lang)); ?> 界面文案完成度</span>
            <b><?php echo (int) $done; ?>/<?php echo (int) $total; ?> · <?php echo (int) $pct; ?>%</b>
          </div>
          <div class="ybh-st__bar"><span style="width:<?php echo (int) $pct; ?>%"></span></div>
        </div>
        <ul class="ybh-st__stats">
          <li><b><?php echo count($pending); ?></b><span>待审提案</span></li>
          <li><b><?php echo count($props); ?></b><span>本语言提案</span></li>
          <li><b><?php echo count($mine); ?></b><span>我提过的</span></li>
          <li><b><?php echo (int) ybh_studio_article_count(); ?></b><span>文章/页面</span></li>
        </ul>
      </section>

      <nav class="ybh-st__tabs">
        <?php
        $tabs = array(
            'strings' => array('界面文案', $total - $done),
            'articles' => array('文章', 0),
            'mine' => array('我的提案', count($mine)),
        );
        if ($is_admin) { $tabs['review'] = array('待审', count($pending)); }
        foreach ($tabs as $k => $v) {
            printf(
                '<a class="ybh-st__tab%s" href="%s">%s%s</a>',
                $tab === $k ? ' is-on' : '',
                esc_url(ybh_studio_url(array('tl' => $lang, 'tab' => $k, 'f' => $filter, 'q' => $q))),
                esc_html($v[0]),
                $v[1] ? ' <b>' . (int) $v[1] . '</b>' : ''
            );
        }
        ?>
        <span class="ybh-st__spacer"></span>
        <span class="ybh-st__langs">
          <?php foreach ($langs as $code => $info) : ?>
            <a class="ybh-st__lang<?php echo $code === $lang ? ' is-on' : ''; ?>"
               href="<?php echo esc_url(ybh_studio_url(array('tl' => $code, 'tab' => $tab, 'f' => $filter, 'q' => $q))); ?>"
               lang="<?php echo esc_attr($code); ?>"><?php echo esc_html($info['native']); ?></a>
          <?php endforeach; ?>
        </span>
      </nav>

      <?php
      $msg = isset($_GET['ybh_msg']) ? sanitize_text_field(wp_unslash((string) $_GET['ybh_msg'])) : '';
      $msg_map = array(
          'proposed' => array('提案已提交，等管理员批准。', 'ok'),
          'voted' => array('已记录你的投票。', 'ok'),
          'err-input' => array('内容为空或参数不对，没有提交。', 'err'),
          'err-nonce' => array('页面已过期，请重试。', 'err'),
          'err-cap' => array('只有管理员可以审核。', 'err'),
      );
      if (strpos($msg, 'reviewed-') === 0) {
          $msg_map[$msg] = array('已处理 ' . (int) substr($msg, 9) . ' 条。', 'ok');
      }
      if ($msg !== '' && isset($msg_map[$msg])) {
          printf('<p class="ybh-st__msg %s">%s</p>', esc_attr($msg_map[$msg][1]), esc_html($msg_map[$msg][0]));
      }
      ?>

      <?php if ($tab === 'strings') : ?>
        <?php echo ybh_studio_view_strings($entries, $approved, $auto, $by_mid, $lang, $filter, $q, $page, $per, $can_propose, $me, $unit, $is_admin); ?>
      <?php elseif ($tab === 'articles') : ?>
        <?php
        // T68：`?post=<ID>` = 单篇的分段翻译视图；否则是文章列表
        $ybh_single = (int) ($_GET['post'] ?? 0);
        echo $ybh_single > 0
            ? ybh_studio_view_article($ybh_single, $lang, $can_propose, $is_admin)
            : ybh_studio_view_articles($lang, $by_obj, $can_propose, $page, $q);
        ?>
      <?php elseif ($tab === 'mine') : ?>
        <?php echo ybh_studio_view_list($mine, '你还没有提过提案。'); ?>
      <?php else : ?>
        <?php echo ybh_studio_view_review($pending, $lang); ?>
      <?php endif; ?>

    </div>
    <?php
    return (string) ob_get_clean();
}

/** 提案列表（该语言）；$obj_id > 0 时只取该文章/页面的提案（T68 分段视图用） */
function ybh_studio_proposals($lang, $limit = 400, $obj_id = 0)
{
    global $wpdb;
    $t = ybh_i18n_proposals_table();
    if ($obj_id > 0) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE lang = %s AND obj_type = 'post' AND obj_id = %d ORDER BY id DESC LIMIT %d",
            $lang, $obj_id, $limit), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE lang = %s ORDER BY id DESC LIMIT %d", $lang, $limit), ARRAY_A);
    }
    $out = array();
    foreach ((array) $rows as $r) {
        $v = json_decode((string) $r['votes'], true);
        $out[] = array(
            'id' => (int) $r['id'],
            'msgid' => (string) $r['msgid'],
            'proposal' => (string) $r['proposal'],
            'author' => $r['author_name'] !== '' ? (string) $r['author_name'] : ('#' . (int) $r['author_id']),
            'status' => (string) $r['status'],
            'votes' => is_array($v) ? count($v) : 0,
            'created' => (string) $r['created'],
            'note' => (string) $r['note'],
            'obj_type' => (string) $r['obj_type'],
            'obj_id' => (int) $r['obj_id'],
            'field' => (string) $r['field'],
        );
    }
    return $out;
}

/**
 * 界面文案词条：**复用导出的词条表**（工作台导出时生成的 JSON），
 * 没有就现场扫一遍并缓存 —— 这样主题新加文案后不必改这里。
 */
function ybh_studio_string_entries()
{
    $cache = get_transient('ybh_studio_entries');
    if (is_array($cache) && $cache) {
        return $cache;
    }
    $file = ybh_i18n_overlay_dir(false) . '/entries.json';
    $rows = array();
    if (is_file($file)) {
        $j = json_decode((string) @file_get_contents($file), true);
        if (is_array($j)) {
            foreach ($j as $mid => $refs) {
                $rows[] = array('msgid' => (string) $mid, 'refs' => (array) $refs);
            }
        }
    }
    if (!$rows) {
        // 兜底：至少把内置译文的键列出来（T68：跟内置表实际有的语言走，不再写死四种）
        $map = ybh_i18n_strings();
        foreach (array_keys($map) as $l) {
            foreach ((array) ($map[$l] ?? array()) as $mid => $_) {
                $rows[$mid] = array('msgid' => (string) $mid, 'refs' => array());
            }
        }
        $rows = array_values($rows);
    }
    usort($rows, function ($a, $b) { return strcmp($a['msgid'], $b['msgid']); });
    set_transient('ybh_studio_entries', $rows, HOUR_IN_SECONDS);
    return $rows;
}

/** 文章/页面（用于统计与文章页） */
function ybh_studio_article_count()
{
    $c = get_transient('ybh_studio_art_count');
    if ($c !== false) { return (int) $c; }
    $n = (int) wp_count_posts('post')->publish + 0;
    $pages = wp_count_posts('page');
    $n += (int) $pages->publish;
    set_transient('ybh_studio_art_count', $n, HOUR_IN_SECONDS);
    return $n;
}

/* ---------------- 视图：界面文案（Crowdin 式左右分栏） ----------------
 * 左半屏：词条列表（搜索 / 筛选 / 分页，点一条选中）
 * 右半屏：选中词条的原文、**当前生效的译文**（人工批准 or 自动采用）、**全部提案**（票数/时间/状态）
 *         以及输入框（提交提案）与管理员的批准/驳回/撤销
 * 全部走 URL 参数 + 表单，**不依赖 JS**；小屏时右栏落到下面。
 */
/** 词条筛选后的列表（T68b 抽出：完整视图与 AJAX partial 共用） */
function ybh_studio_string_list($entries, $approved, $auto, $by_mid, $lang, $filter, $q, $me)
{
    $list = array();
    foreach ($entries as $e) {
        $mid = $e['msgid'];
        $cur = isset($approved[$mid]) ? (string) $approved[$mid] : '';
        $auto_text = ($cur === '' && isset($auto[$mid])) ? (string) $auto[$mid] : '';
        $eff = $cur !== '' ? $cur : $auto_text;
        $ps = isset($by_mid[$mid]) ? $by_mid[$mid] : array();
        if ($filter === 'todo' && $eff !== '') { continue; }
        if ($filter === 'done' && $eff === '') { continue; }
        if ($filter === 'mine') {
            $hit = false;
            foreach ($ps as $p) { if ($p['author'] === $me) { $hit = true; } }
            if (!$hit) { continue; }
        }
        if ($q !== '') {
            if (mb_stripos($mid . ' ' . $eff, $q) === false) { continue; }
        }
        $list[] = array('e' => $e, 'cur' => $cur, 'auto' => $auto_text, 'eff' => $eff, 'ps' => $ps);
    }
    return $list;
}

/** 从列表挑出选中项：unit hash 优先，否则第一条（T68b 抽出，partial 共用） */
function ybh_studio_string_pick($list, $unit, $page, $per)
{
    $pages = max(1, (int) ceil(count($list) / $per));
    $page = min($page, $pages);
    $slice = array_slice($list, ($page - 1) * $per, $per);
    $sel = null;
    foreach ($list as $row) {
        if ($unit !== '' && md5($row['e']['msgid']) === $unit) { $sel = $row; break; }
    }
    if (!$sel) {
        foreach ($slice as $row) { $sel = $row; break; }
    }
    return array($slice, $pages, $page, $sel);
}

function ybh_studio_view_strings($entries, $approved, $auto, $by_mid, $lang, $filter, $q, $page, $per, $can_propose, $me, $unit = '', $is_admin = false)
{
    $list = ybh_studio_string_list($entries, $approved, $auto, $by_mid, $lang, $filter, $q, $me);
    list($slice, $pages, $page, $sel) = ybh_studio_string_pick($list, $unit, $page, $per);

    $base_args = array('tl' => $lang, 'tab' => 'strings', 'f' => $filter, 'q' => $q);

    ob_start();
    ?>
    <form class="ybh-st__toolbar" method="get" action="<?php echo esc_url(home_url('/' . YBH_STUDIO_SLUG . '/')); ?>">
      <input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>" />
      <input type="hidden" name="tab" value="strings" />
      <span class="ybh-st__chips">
        <?php
        $labels = array('todo' => '待翻译', 'done' => '已翻译', 'all' => '全部', 'mine' => '我提过的');
        foreach ($labels as $k => $lab) {
            printf('<a class="ybh-st__chip%s" href="%s">%s</a>', $filter === $k ? ' is-on' : '',
                esc_url(ybh_studio_url(array_merge($base_args, array('f' => $k)))), esc_html($lab));
        }
        ?>
      </span>
      <input class="ybh-st__search" type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="搜索原文或译文…" />
      <button class="ybh-st__btn" type="submit">搜索</button>
      <span class="ybh-st__count"><?php echo count($list); ?> 条 · <?php echo (int) $page; ?>/<?php echo (int) $pages; ?> 页</span>
    </form>

    <div class="ybh-st__split">
      <!-- 左：词条列表 -->
      <aside class="ybh-st__pane-left">
        <div class="ybh-st__pane-head">词条 <span><?php echo count($slice); ?> / <?php echo count($list); ?></span></div>
        <ul class="ybh-st__slist">
          <?php if (!$slice) : ?>
            <li class="ybh-st__empty">没有匹配的词条。</li>
          <?php endif; ?>
          <?php foreach ($slice as $row) :
              $mid = $row['e']['msgid'];
              $hash = md5($mid);
              $is_sel = ($sel && $sel['e']['msgid'] === $mid);
              $n_ps = count($row['ps']);
              ?>
            <li class="<?php echo $is_sel ? 'is-sel' : ''; ?>">
              <a href="<?php echo esc_url(ybh_studio_url(array_merge($base_args, array('p' => $page, 'unit' => $hash)))); ?>#unit">
                <span class="t"><?php echo esc_html(mb_substr($mid, 0, 90)); ?></span>
                <span class="m">
                  <?php if ($row['cur'] !== '') : ?>
                    <em class="ok">已批准</em>
                  <?php elseif ($row['auto'] !== '') : ?>
                    <em class="auto">自动采用</em>
                  <?php else : ?>
                    <em class="none">未翻译</em>
                  <?php endif; ?>
                  <?php if ($n_ps) : ?><em class="ps"><?php echo (int) $n_ps; ?> 提案</em><?php endif; ?>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($pages > 1) : ?>
          <div class="ybh-st__pager">
            <?php if ($page > 1) : ?>
              <a class="ybh-st__btn" href="<?php echo esc_url(ybh_studio_url(array_merge($base_args, array('p' => $page - 1)))); ?>">上一页</a>
            <?php endif; ?>
            <?php if ($page < $pages) : ?>
              <a class="ybh-st__btn" href="<?php echo esc_url(ybh_studio_url(array_merge($base_args, array('p' => $page + 1)))); ?>">下一页</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </aside>

      <!-- 右：选中词条的详情与提案 -->
      <section class="ybh-st__pane-right" id="unit">
        <?php echo ybh_studio_string_pane($sel, $lang, $can_propose, $is_admin); ?>
      </section>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * 右栏：选中词条的原文、当前生效译文、全部提案、输入框（T68b 抽出）。
 * 完整视图与 AJAX partial（?partial=pane）共用 —— 词条切换从此不用整页刷新。
 */
function ybh_studio_string_pane($sel, $lang, $can_propose, $is_admin)
{
    ob_start();
    ?>
        <?php if (!$sel) : ?>
          <p class="ybh-st__empty">左边选一条词条。</p>
        <?php else :
            $mid = $sel['e']['msgid'];
            $ps = $sel['ps'];
            // 按规则把"会生效的那条"排到最前并标出来（票多优先；并列取更晚）
            usort($ps, function ($a, $b) {
                if ($a['votes'] !== $b['votes']) { return $b['votes'] - $a['votes']; }
                return $b['id'] - $a['id'];
            });
            $winner = $ps ? $ps[0] : null;
            ?>
          <div class="ybh-st__detail">
            <div class="ybh-st__src">
              <code><?php echo esc_html($mid); ?></code>
              <?php if (!empty($sel['e']['refs'])) : ?>
                <span class="ybh-st__ref"><?php echo esc_html(implode(' · ', array_slice($sel['e']['refs'], 0, 3))); ?></span>
              <?php endif; ?>
            </div>

            <div class="ybh-st__field">
              <label>当前生效的译文</label>
              <?php if ($sel['cur'] !== '') : ?>
                <div class="val"><span class="ok"><?php echo esc_html($sel['cur']); ?></span>
                  <em class="tag ok">人工批准</em></div>
              <?php elseif ($sel['auto'] !== '') : ?>
                <div class="val"><span class="auto"><?php echo esc_html($sel['auto']); ?></span>
                  <em class="tag auto">自动采用（票数最高）</em></div>
              <?php else : ?>
                <div class="val"><span class="none">（还没有译文，下面是全部提案）</span></div>
              <?php endif; ?>
            </div>
          </div>

          <h3 class="ybh-st__h3">全部提案 <span><?php echo count($ps); ?> 条</span>
            <em class="ybh-st__rule">规则：票数最高者生效；票数相同取**时间更晚**的一条</em></h3>
          <?php if (!$ps) : ?>
            <p class="ybh-st__empty">还没有人提提案 —— 你可以在下面第一个提交。</p>
          <?php else : ?>
            <ul class="ybh-st__plist">
              <?php foreach ($ps as $i => $p) :
                  $st = array('pending' => '待审', 'approved' => '已批准', 'rejected' => '已驳回');
                  ?>
                <li class="<?php echo esc_attr($p['status']); ?><?php echo ($winner && $p['id'] === $winner['id'] && $p['status'] !== 'approved') ? ' is-lead' : ''; ?>">
                  <div class="head">
                    <b><?php echo esc_html($p['author']); ?></b>
                    <span class="time"><?php echo esc_html($p['created']); ?></span>
                    <span class="votes">👍 <?php echo (int) $p['votes']; ?></span>
                    <span class="st"><?php echo esc_html($st[$p['status']] ?? $p['status']); ?></span>
                    <?php if ($winner && $p['id'] === $winner['id'] && $p['status'] === 'pending') : ?>
                      <em class="lead">当前会生效</em>
                    <?php endif; ?>
                  </div>
                  <div class="body"><?php echo esc_html($p['proposal']); ?></div>
                  <div class="acts">
                    <?php if (is_user_logged_in()) : ?>
                      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="inline">
                        <input type="hidden" name="action" value="ybh_studio" />
                        <input type="hidden" name="ybh_act" value="vote" />
                        <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>" />
                        <?php wp_nonce_field('ybh_studio', '_ybh_nonce'); ?>
                        <button class="ybh-st__mini" type="submit">👍 投票</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($is_admin) : ?>
                      <?php
                      $acts = array();
                      if ($p['status'] === 'pending') { $acts = array('approve' => array('批准', 'ok'), 'reject' => array('驳回', 'no')); }
                      elseif ($p['status'] === 'approved') { $acts = array('revoke' => array('撤销', 'ghost')); }
                      foreach ($acts as $a => $info) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="inline">
                          <input type="hidden" name="action" value="ybh_studio" />
                          <input type="hidden" name="ybh_act" value="review" />
                          <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>" />
                          <input type="hidden" name="review_action" value="<?php echo esc_attr($a); ?>" />
                          <?php wp_nonce_field('ybh_studio', '_ybh_nonce'); ?>
                          <button class="ybh-st__mini <?php echo esc_attr($info[1]); ?>" type="submit"><?php echo esc_html($info[0]); ?></button>
                        </form>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <?php if ($can_propose) : ?>
            <div class="ybh-st__field">
              <label for="ybh-st-new">提出你的译文</label>
              <form class="ybh-st__act col" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ybh_studio" />
                <input type="hidden" name="ybh_act" value="propose" />
                <input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>" />
                <input type="hidden" name="obj_type" value="string" />
                <input type="hidden" name="msgid" value="<?php echo esc_attr($mid); ?>" />
                <?php wp_nonce_field('ybh_studio', '_ybh_nonce'); ?>
                <textarea class="ybh-st__input" id="ybh-st-new" name="proposal" rows="3" placeholder="写下你的译文提案…"></textarea>
                <button class="ybh-st__btn primary" type="submit">提交提案</button>
              </form>
            </div>
          <?php else : ?>
            <p class="ybh-st__hint">登录后即可提交提案。</p>
          <?php endif; ?>
        <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

/** 一条提案的展示 + 投票 + 审核按钮 */
function ybh_studio_proposal_list($ps)
{
    if (!$ps) { return ''; }
    $is_admin = current_user_can('manage_options');
    ob_start();
    ?>
    <ul class="ybh-st__props">
      <?php foreach (array_slice($ps, 0, 6) as $p) :
          $st = array('pending' => '待审', 'approved' => '已批准', 'rejected' => '已驳回');
          $cls = $p['status'];
          ?>
        <li class="<?php echo esc_attr($cls); ?>">
          <span class="who"><?php echo esc_html($p['author']); ?></span>
          <span class="txt"><?php echo esc_html($p['proposal']); ?></span>
          <span class="st"><?php echo esc_html($st[$p['status']] ?? $p['status']); ?></span>
          <?php if (is_user_logged_in()) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="inline">
              <input type="hidden" name="action" value="ybh_studio" />
              <input type="hidden" name="ybh_act" value="vote" />
              <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>" />
              <?php wp_nonce_field('ybh_studio', '_ybh_nonce'); ?>
              <button class="ybh-st__mini" type="submit">👍 <?php echo (int) $p['votes']; ?></button>
            </form>
          <?php else : ?>
            <span class="ybh-st__mini static">👍 <?php echo (int) $p['votes']; ?></span>
          <?php endif; ?>
          <?php if ($is_admin) : ?>
            <?php
            $acts = array();
            if ($p['status'] === 'pending') { $acts = array('approve' => array('批准', 'ok'), 'reject' => array('驳回', 'no')); }
            elseif ($p['status'] === 'approved') { $acts = array('revoke' => array('撤销', 'ghost')); }
            foreach ($acts as $a => $info) : ?>
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="inline">
                <input type="hidden" name="action" value="ybh_studio" />
                <input type="hidden" name="ybh_act" value="review" />
                <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>" />
                <input type="hidden" name="review_action" value="<?php echo esc_attr($a); ?>" />
                <?php wp_nonce_field('ybh_studio', '_ybh_nonce'); ?>
                <button class="ybh-st__mini <?php echo esc_attr($info[1]); ?>" type="submit"><?php echo esc_html($info[0]); ?></button>
              </form>
            <?php endforeach; ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php
    return (string) ob_get_clean();
}

/**
 * AJAX 片段：右栏（词条详情 + 全部提案 + 输入框）。
 * 参数与完整页一致：tl（目标语言，旧 lang 兼容）/ f / q / p / unit。
 */
function ybh_studio_partial_pane()
{
    $lang = ybh_studio_current_lang();
    $filter = isset($_GET['f']) ? sanitize_key(wp_unslash((string) $_GET['f'])) : 'todo';
    if (!in_array($filter, array('todo', 'done', 'all', 'mine'), true)) { $filter = 'todo'; }
    $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash((string) $_GET['q'])) : '';
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $unit = isset($_GET['unit']) ? preg_replace('~[^a-f0-9]~i', '', (string) wp_unslash($_GET['unit'])) : '';
    $per = 30;
    $is_admin = current_user_can('manage_options');
    $can_propose = is_user_logged_in() && current_user_can('edit_posts');
    $me = is_user_logged_in() ? (string) wp_get_current_user()->display_name : '';

    $approved = ybh_i18n_overlay_get($lang);
    $auto = function_exists('ybh_i18n_auto_adopted') ? ybh_i18n_auto_adopted($lang) : array();
    $entries = ybh_studio_string_entries();
    $props = ybh_studio_proposals($lang, 600);
    $by_mid = array();
    foreach ($props as $p) {
        if ($p['obj_type'] !== 'post') {
            $by_mid[$p['msgid']][] = $p;
        }
    }

    $list = ybh_studio_string_list($entries, $approved, $auto, $by_mid, $lang, $filter, $q, $me);
    list($slice, $pages, $page, $sel) = ybh_studio_string_pick($list, $unit, $page, $per);

    return (string) ybh_studio_string_pane($sel, $lang, $can_propose, $is_admin);
}

/** 视图：文章 */
function ybh_studio_view_articles($lang, $by_obj, $can_propose, $page, $q)
{
    $per = 15;
    $query = new WP_Query(array(
        'post_type' => array('post', 'page'),
        'post_status' => 'publish',
        'posts_per_page' => $per,
        'paged' => $page,
        's' => $q,
        'orderby' => 'modified',
        'order' => 'DESC',
    ));
    ob_start();
    ?>
    <form class="ybh-st__toolbar" method="get" action="<?php echo esc_url(home_url('/' . YBH_STUDIO_SLUG . '/')); ?>">
      <input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>" />
      <input type="hidden" name="tab" value="articles" />
      <input class="ybh-st__search" type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="搜索文章标题…" />
      <button class="ybh-st__btn" type="submit">搜索</button>
      <span class="ybh-st__count">共 <?php echo (int) $query->found_posts; ?> 篇 · <?php echo (int) max(1, $query->max_num_pages); ?> 页</span>
    </form>
    <p class="ybh-st__hint">文章**默认显示原文**；这里的提案被批准后，读者在文章页切换语言即可看到，并会**署上提案者**的名字（译者）。</p>
    <div class="ybh-st__rows">
      <?php if (!$query->posts) : ?>
        <p class="ybh-st__empty">没有匹配的文章。</p>
      <?php endif; ?>
      <?php foreach ($query->posts as $p) :
          $t_title = (string) get_post_meta($p->ID, ybh_i18n_meta_key($lang, 'title'), true);
          $t_body = (string) get_post_meta($p->ID, ybh_i18n_meta_key($lang, 'content'), true);
          $who = (string) get_post_meta($p->ID, ybh_i18n_meta_key($lang, 'translator'), true);
          $excerpt = mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($p->post_content)))), 0, 200);
          // T68：源语言 = 这篇文章的原文语言。当前工作室语言 == 源语言 ⇒ 没有"翻译"可做
          $orig = function_exists('ybh_i18n_post_orig_lang') ? ybh_i18n_post_orig_lang($p->ID) : '';
          $is_source_side = ($orig !== '' && $orig === $lang);
          ?>
        <article class="ybh-st__card article">
          <div class="ybh-st__src">
            <b><?php echo esc_html(get_the_title($p)); ?></b>
            <span class="ybh-st__ref">#<?php echo (int) $p->ID; ?> · <?php echo esc_html($p->post_type); ?> · <?php echo esc_html($p->post_modified); ?></span>
          </div>
          <p class="ybh-st__exc"><?php echo esc_html($excerpt); ?></p>
          <?php if ($is_source_side) : ?>
            <div class="ybh-st__cur"><span class="none">这篇文章的原文就是 <?php echo esc_html(ybh_language_label($orig)); ?> —— 请选其它语言来翻译它。</span></div>
          <?php else : ?>
          <div class="ybh-st__cur">
            标题：<?php echo $t_title !== '' ? '<span class="ok">' . esc_html($t_title) . '</span>' : '<span class="none">未翻译</span>'; ?>
            　正文：<?php echo $t_body !== '' ? '<span class="ok">已翻译（' . (int) mb_strlen(wp_strip_all_tags($t_body)) . ' 字）</span>' : '<span class="none">未翻译</span>'; ?>
            <?php if ($who !== '') : ?><span class="who">译者：<?php echo esc_html($who); ?></span><?php endif; ?>
          </div>
          <?php endif; ?>
          <?php /* 入口链接**始终**给出：source-side 时进去也能看到提示并从那里切语言（T68 修） */ ?>
          <a class="ybh-st__btn" href="<?php echo esc_url(ybh_studio_url(array('tl' => $lang, 'tab' => 'articles', 'post' => $p->ID))); ?>">按段落翻译 →</a>
          <?php
          $title_props = $by_obj[$p->ID . '|title'] ?? array();
          $body_props = $by_obj[$p->ID . '|content'] ?? array();
          echo ybh_studio_proposal_list(array_merge($title_props, $body_props));
          ?>
          <?php if ($can_propose && !$is_source_side) : ?>
            <?php foreach (array(array('title', '翻译标题…', 1), array('content', '翻译正文（可写 HTML）…', 3)) as $f) : ?>
              <form class="ybh-st__act col" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ybh_studio" />
                <input type="hidden" name="ybh_act" value="propose" />
                <input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>" />
                <input type="hidden" name="obj_type" value="post" />
                <input type="hidden" name="obj_id" value="<?php echo (int) $p->ID; ?>" />
                <input type="hidden" name="field" value="<?php echo esc_attr($f[0]); ?>" />
                <input type="hidden" name="msgid" value="<?php echo esc_attr($f[0] === 'title' ? get_the_title($p) : $excerpt); ?>" />
                <?php wp_nonce_field('ybh_studio', '_ybh_nonce'); ?>
                <textarea class="ybh-st__input" name="proposal" rows="<?php echo (int) $f[2]; ?>" placeholder="<?php echo esc_attr($f[1]); ?>"></textarea>
                <button class="ybh-st__btn" type="submit">提交<?php echo $f[0] === 'title' ? '标题' : '正文'; ?>提案</button>
              </form>
            <?php endforeach; ?>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if ($query->max_num_pages > 1) : ?>
      <nav class="ybh-st__pager">
        <?php if ($page > 1) : ?><a class="ybh-st__btn" href="<?php echo esc_url(ybh_studio_url(array('tl' => $lang, 'tab' => 'articles', 'q' => $q, 'p' => $page - 1))); ?>">上一页</a><?php endif; ?>
        <?php if ($page < $query->max_num_pages) : ?><a class="ybh-st__btn" href="<?php echo esc_url(ybh_studio_url(array('tl' => $lang, 'tab' => 'articles', 'q' => $q, 'p' => $page + 1))); ?>">下一页</a><?php endif; ?>
      </nav>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

/**
 * 视图：单篇文章的分段翻译（T68）。
 *
 * 左脑是"逐段"：每段显示原文预览（按 HTML 渲染）、已批准的译文预览、
 * 待审提案，以及一个带格式按钮（粗体/斜体/链接）的提案输入框；
 * 尾部保留"整篇"模式（标题 + 正文一次翻）作为备选。
 * 生效规则见 i18n-proposals.php 的分段说明：全部段落就绪才拼成整篇译文。
 */
function ybh_studio_view_article($post_id, $lang, $can_propose, $is_admin = false)
{
    $p = get_post((int) $post_id);
    if (!$p || !in_array($p->post_type, array('post', 'page'), true) || 'publish' !== $p->post_status) {
        return '<p class="ybh-st__empty">文章不存在或未发布。</p>';
    }
    $post_id = (int) $p->ID;
    $orig = function_exists('ybh_i18n_post_orig_lang') ? ybh_i18n_post_orig_lang($post_id) : '';
    $is_source_side = ($orig !== '' && $orig === $lang);
    $back = ybh_studio_url(array('tl' => $lang, 'tab' => 'articles'));
    $segments = function_exists('ybh_i18n_split_paragraphs') ? ybh_i18n_split_paragraphs((string) $p->post_content) : array();
    $props = ybh_studio_proposals($lang, 400, $post_id);
    $by_field = array();
    foreach ($props as $pr) { $by_field[$pr['field']][] = $pr; }

    $t_title = (string) get_post_meta($post_id, ybh_i18n_meta_key($lang, 'title'), true);
    $t_body  = (string) get_post_meta($post_id, ybh_i18n_meta_key($lang, 'content'), true);
    $who     = (string) get_post_meta($post_id, ybh_i18n_meta_key($lang, 'translator'), true);
    $done = 0;
    foreach ($segments as $i => $_) {
        if ((string) get_post_meta($post_id, ybh_i18n_para_meta_key($lang, $i), true) !== '') { $done++; }
    }
    $pct = $segments ? round($done / count($segments) * 100) : 0;

    ob_start();
    ?>
    <p><a class="ybh-st__btn ghost" href="<?php echo esc_url($back); ?>">← 返回文章列表</a></p>
    <header class="ybh-st__art-head">
      <h2><?php echo esc_html(get_the_title($p)); ?></h2>
      <p class="ybh-st__ref">#<?php echo (int) $post_id; ?> · <?php echo esc_html($p->post_type); ?>
        · 原文语言：<?php echo esc_html(ybh_language_label($orig !== '' ? $orig : ybh_default_language())); ?>
        · 目标语言：<?php echo esc_html(ybh_language_label($lang)); ?>
        <?php if ($who !== '') : ?>· 译者：<?php echo esc_html($who); ?><?php endif; ?></p>
      <div class="ybh-st__prog">
        <div class="ybh-st__prog-head"><span>分段进度（段落/标题/引用各算一段）</span>
          <b><?php echo (int) $done; ?>/<?php echo (int) count($segments); ?> · <?php echo (int) $pct; ?>%</b></div>
        <div class="ybh-st__bar"><span style="width:<?php echo (int) $pct; ?>%"></span></div>
      </div>
      <p class="ybh-st__hint">每段批准后先存着；**全部段落都批准**时才会拼成整篇译文对外生效。列表、图片、脚注等结构永远保持原样。</p>
      <p class="ybh-st__langs">
        <?php foreach (ybh_languages() as $ybh_c => $ybh_i) :
            $ybh_on = ($ybh_c === $lang);
            // 源语言不可作为目标（原文就是它），标成禁用态
            $ybh_dis = ($orig !== '' && $ybh_c === $orig && $ybh_c !== $lang);
        ?>
          <a class="ybh-st__lang<?php echo $ybh_on ? ' is-on' : ''; ?>" aria-current="<?php echo $ybh_on ? 'true' : 'false'; ?>"
             href="<?php echo esc_url(ybh_studio_url(array('tl' => $ybh_c, 'tab' => 'articles', 'post' => $post_id))); ?>"
             <?php echo $ybh_dis ? ' style="opacity:.4;" title="这篇文章的原文就是它"' : ''; ?>><?php echo esc_html($ybh_i['native']); ?></a>
        <?php endforeach; ?>
      </p>
    </header>

    <?php if ($is_source_side) : ?>
      <p class="ybh-st__empty">这篇文章的原文就是 <?php echo esc_html(ybh_language_label($orig)); ?> —— 用上面的语言胶囊切换目标语言即可翻译（段落预览照常显示）。</p>
    <?php endif; ?>
    <div class="ybh-st__paras">
      <?php foreach ($segments as $i => $seg) :
          $approved_para = (string) get_post_meta($post_id, ybh_i18n_para_meta_key($lang, $i), true);
          $para_props = $by_field['para-' . $i] ?? array();
          $plain = trim(mb_substr(preg_replace('/\s+/u', ' ', wp_strip_all_tags($seg)), 0, 160));
          ?>
        <article class="ybh-st__card para">
          <div class="ybh-st__src">
            <span class="ybh-st__ref">第 <?php echo (int) ($i + 1); ?> 段</span>
            <?php echo $approved_para !== '' ? '<span class="ok">已批准</span>' : ''; ?>
          </div>
          <div class="ybh-st__preview"><?php echo wp_kses_post($seg); ?></div>
          <?php if ($approved_para !== '') : ?>
            <div class="ybh-st__cur"><span class="ok">当前译文：</span><?php echo wp_kses_post($approved_para); ?></div>
          <?php endif; ?>
          <?php echo ybh_studio_proposal_list($para_props); ?>
          <?php if ($can_propose && !$is_source_side) : ?>
            <form class="ybh-st__act col" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="ybh_studio" />
              <input type="hidden" name="ybh_act" value="propose" />
              <input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>" />
              <input type="hidden" name="obj_type" value="post" />
              <input type="hidden" name="obj_id" value="<?php echo (int) $post_id; ?>" />
              <input type="hidden" name="field" value="para-<?php echo (int) $i; ?>" />
              <input type="hidden" name="msgid" value="<?php echo esc_attr($plain); ?>" />
              <?php wp_nonce_field('ybh_studio', '_ybh_nonce'); ?>
              <div class="ybh-st__fmt-row">
                <button type="button" class="ybh-st__fmt" data-fmt="<b>|</b>" title="粗体"><b>B</b></button>
                <button type="button" class="ybh-st__fmt" data-fmt="<i>|</i>" title="斜体"><i>I</i></button>
                <button type="button" class="ybh-st__fmt" data-fmt="<a href=&quot;https://&quot;>|</a>" title="链接">🔗</button>
                <span class="ybh-st__ref">选中文本后点按钮包裹 HTML 标记</span>
              </div>
              <textarea class="ybh-st__input" name="proposal" rows="3"
                placeholder="翻译第 <?php echo (int) ($i + 1); ?> 段（保留必要的 HTML 标签；已批准译文会先预览，全部段落齐了才生效）…"><?php
                echo esc_html($approved_para !== '' ? $approved_para : '');
              ?></textarea>
              <button class="ybh-st__btn" type="submit">提交这一段</button>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if (!$segments) : ?>
        <p class="ybh-st__empty">这篇文章没有可拆的段落（可能是纯区块/短代码结构），请用下面的整篇模式。</p>
      <?php endif; ?>
    </div>

    <h3 class="ybh-st__art-sub">整篇模式（备选：一次翻标题与正文）</h3>
    <article class="ybh-st__card article">
      <div class="ybh-st__cur">
        标题：<?php echo $t_title !== '' ? '<span class="ok">' . esc_html($t_title) . '</span>' : '<span class="none">未翻译</span>'; ?>
        　正文：<?php echo $t_body !== '' ? '<span class="ok">已翻译（' . (int) mb_strlen(wp_strip_all_tags($t_body)) . ' 字）</span>' : '<span class="none">未翻译</span>'; ?>
        <?php if ($who !== '') : ?><span class="who">译者：<?php echo esc_html($who); ?></span><?php endif; ?>
      </div>
      <?php echo ybh_studio_proposal_list(array_merge($by_field['title'] ?? array(), $by_field['content'] ?? array())); ?>
      <?php if ($can_propose && !$is_source_side) : ?>
        <?php foreach (array(array('title', '翻译标题…', 1, get_the_title($p)), array('content', '翻译正文（可写 HTML）…', 3, mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($p->post_content)))), 0, 200))) as $f) : ?>
          <form class="ybh-st__act col" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ybh_studio" />
            <input type="hidden" name="ybh_act" value="propose" />
            <input type="hidden" name="lang" value="<?php echo esc_attr($lang); ?>" />
            <input type="hidden" name="obj_type" value="post" />
            <input type="hidden" name="obj_id" value="<?php echo (int) $post_id; ?>" />
            <input type="hidden" name="field" value="<?php echo esc_attr($f[0]); ?>" />
            <input type="hidden" name="msgid" value="<?php echo esc_attr($f[3]); ?>" />
            <?php wp_nonce_field('ybh_studio', '_ybh_nonce'); ?>
            <textarea class="ybh-st__input" name="proposal" rows="<?php echo (int) $f[2]; ?>" placeholder="<?php echo esc_attr($f[1]); ?>"></textarea>
            <button class="ybh-st__btn" type="submit">提交<?php echo $f[0] === 'title' ? '标题' : '正文'; ?>提案</button>
          </form>
        <?php endforeach; ?>
      <?php endif; ?>
    </article>
    <?php
    return (string) ob_get_clean();
}

/** 视图：我的提案 */
function ybh_studio_view_list($items, $empty)
{
    ob_start();
    ?>
    <div class="ybh-st__rows">
      <?php if (!$items) : ?><p class="ybh-st__empty"><?php echo esc_html($empty); ?></p><?php endif; ?>
      <?php foreach ($items as $p) : ?>
        <article class="ybh-st__card">
          <div class="ybh-st__src"><code><?php echo esc_html(mb_substr($p['msgid'], 0, 160)); ?></code>
            <span class="ybh-st__ref"><?php echo esc_html($p['created']); ?></span></div>
          <div class="ybh-st__cur"><span class="ok"><?php echo esc_html($p['proposal']); ?></span></div>
          <div class="ybh-st__meta">状态：<?php echo esc_html(array('pending' => '待审', 'approved' => '已批准', 'rejected' => '已驳回')[$p['status']] ?? $p['status']); ?>
            · 👍 <?php echo (int) $p['votes']; ?><?php echo $p['note'] !== '' ? ' · ' . esc_html($p['note']) : ''; ?></div>
        </article>
      <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/** 视图：待审（管理员，支持批量） */
function ybh_studio_view_review($pending, $lang)
{
    ob_start();
    ?>
    <?php
    /*
     * 表单结构说明（这里踩过坑）：
     *   复选框与「批量批准」放**同一个表单**，但卡片里每条的「批准」按钮**必须另起表单** ——
     *   早先把它放在批量表单里，点单条批准走的是批量动作、而 `ids[]` 是空的，
     *   于是提示"已处理 0 条"（验收时抓到）。
     *   又不能嵌套表单，所以：批量按钮用 `form="ybh-st-bulk"` 关联到外面的空表单 ✅ HTML5 允许。
     */
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ybh-st-bulk">
      <input type="hidden" name="action" value="ybh_studio" />
      <input type="hidden" name="ybh_act" value="bulk" />
      <?php wp_nonce_field('ybh_studio', '_ybh_nonce'); ?>
    </form>
    <div class="ybh-st__toolbar">
      <span class="ybh-st__count">待审 <?php echo count($pending); ?> 条</span>
      <button class="ybh-st__btn" type="button" id="ybh-st-selall">全选本页</button>
      <button class="ybh-st__btn ok" type="submit" form="ybh-st-bulk" name="review_action" value="approve">批量批准</button>
      <button class="ybh-st__btn no" type="submit" form="ybh-st-bulk" name="review_action" value="reject">批量驳回</button>
    </div>
    <p class="ybh-st__hint">批准后**立刻对全站生效**：界面文案写入正式译文并重建语言包；文章写入标题/正文并把提案者署名为译者。可随时撤销。</p>
    <div class="ybh-st__rows">
      <?php if (!$pending) : ?><p class="ybh-st__empty">没有待审提案。</p><?php endif; ?>
      <?php foreach ($pending as $p) : ?>
        <article class="ybh-st__card review">
          <div class="ybh-st__src">
            <label class="ybh-st__pick"><input type="checkbox" form="ybh-st-bulk" name="ids[]" value="<?php echo (int) $p['id']; ?>" /> 选中</label>
            <span class="ybh-st__tag"><?php echo $p['obj_type'] === 'post' ? '文章 #' . (int) $p['obj_id'] . ' · ' . esc_html($p['field']) : '界面文案'; ?></span>
            <code><?php echo esc_html(mb_substr($p['msgid'], 0, 140)); ?></code>
          </div>
          <div class="ybh-st__cur"><span class="ok"><?php echo esc_html($p['proposal']); ?></span></div>
          <div class="ybh-st__meta">提案人 <b><?php echo esc_html($p['author']); ?></b> · <?php echo esc_html($p['created']); ?> · 👍 <?php echo (int) $p['votes']; ?></div>
          <div class="ybh-st__act">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="ybh_studio" />
              <input type="hidden" name="ybh_act" value="review" />
              <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>" />
              <input type="hidden" name="review_action" value="approve" />
              <?php wp_nonce_field('ybh_studio', '_ybh_nonce'); ?>
              <button class="ybh-st__btn ok" type="submit">批准</button>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="ybh_studio" />
              <input type="hidden" name="ybh_act" value="review" />
              <input type="hidden" name="id" value="<?php echo (int) $p['id']; ?>" />
              <input type="hidden" name="review_action" value="reject" />
              <?php wp_nonce_field('ybh_studio', '_ybh_nonce'); ?>
              <button class="ybh-st__btn no" type="submit">驳回</button>
            </form>
          </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php
    return (string) ob_get_clean();
}
