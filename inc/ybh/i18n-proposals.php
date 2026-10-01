<?php
/**
 * YBH · 多语言提案系统（T66d）—— 类 Crowdin 的"多提案 + 管理员批准"
 *
 * ===================================================================
 * 为什么放在主站（WordPress）而不是 i18n 工作台
 * ===================================================================
 *   工作台（i18n.yibianhui.cn）是**纯静态 SPA**，没有服务端；
 *   而"谁能提、谁能批、批了写哪儿"本来就该和账号体系在一起。所以：
 *     · 账号 —— 直接复用主站账号（JWT 插件提供 `/wp-json/jwt-auth/v1/token` 换 token）；
 *     · 存储 —— 主站自建两张逻辑（本表 + uploads 下的译文覆盖层）；
 *     · 审核 —— 主站后台「工具 → 翻译提案」；
 *     · 工作台只做前端，用下面的 REST 端点读写。
 *
 * ===================================================================
 * 数据流
 * ===================================================================
 *   提案（pending，可多条、可投票）
 *     → 管理员批准（approved）
 *        → ① 进 `uploads/ybh-i18n/<lang>.json`（本层 `ybh_t()` 用的覆盖层）
 *        → ② 重建 `languages/sakurairo-<locale>.l10n.php`（主题 gettext 用）
 *        → ③ 记下被覆盖的旧值（`prev`），随时可**撤销**
 *
 * ===================================================================
 * REST（命名空间 ybh-i18n/v1）
 * ===================================================================
 *   GET  /strings?lang=xx                 已批准的正式译文（公开）
 *   GET  /proposals?lang=&msgid=&status=  提案列表（公开，不暴露邮箱）
 *   POST /proposals                       {msgid,lang,proposal} 需登录
 *   POST /proposals/<id>/vote             投票/取消（需登录）
 *   POST /proposals/<id>/review           {action:approve|reject,note} 仅管理员
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 表名 */
function ybh_i18n_proposals_table()
{
    global $wpdb;
    return $wpdb->prefix . 'ybh_i18n_proposals';
}

/* ===========================================================================
 * 自动采用规则（站长定的）
 * ---------------------------------------------------------------------------
 *   **没有已批准译文时**：采用**票数最高**的提案；
 *   票数相同时：采用**时间更靠后**（id 更大）的那一条。
 *
 * 放在这里、而不是直接写进覆盖层，有两个好处：
 *   ① 覆盖层仍然是"人工批准过的"干净记录，自动采用只是**运行时解析**；
 *   ② 一旦有人把某条批准了，自动采用立刻让位（批准优先）。
 * 可以用 `add_filter('ybh_i18n_auto_adopt', '__return_false')` 整体关掉。
 * =========================================================================== */

/** 是否启用自动采用 */
function ybh_i18n_auto_adopt_enabled()
{
    return (bool) apply_filters('ybh_i18n_auto_adopt', true);
}

/**
 * 某语言"自动采用"的界面文案（msgid => 译文）。
 * 只包含**没有人工批准**的词条；结果用 transient 缓存，提案变动时清掉。
 */
function ybh_i18n_auto_adopted($lang)
{
    static $cache = array();
    if (!$lang || !ybh_language_valid($lang) || !ybh_i18n_auto_adopt_enabled()) {
        return array();
    }
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }
    $key = 'ybh_i18n_auto_' . md5((string) $lang);
    $hit = get_transient($key);
    if (is_array($hit)) {
        $cache[$lang] = $hit;
        return $hit;
    }
    global $wpdb;
    $t = ybh_i18n_proposals_table();
    /*
     * 排序即规则：票多优先（votes 存的是 JSON 数组，用长度近似不行 ——
     * 所以冗余一个 vote_count 列不划算，这里直接按 id 倒序取回后在 PHP 里选，
     * 只取 pending 且 obj_type=string 的行，量级很小）。
     */
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, msgid, proposal, votes, created FROM {$t}
         WHERE lang = %s AND status = 'pending' AND obj_type = 'string'
         ORDER BY id ASC", $lang), ARRAY_A);
    $best = array();
    foreach ((array) $rows as $r) {
        $v = json_decode((string) $r['votes'], true);
        $n = is_array($v) ? count($v) : 0;
        $mid = (string) $r['msgid'];
        if (!isset($best[$mid])) {
            $best[$mid] = array('votes' => $n, 'id' => (int) $r['id'], 'text' => (string) $r['proposal']);
            continue;
        }
        // 票多者胜；票同则 id 更大（更晚）者胜
        if ($n > $best[$mid]['votes'] || ($n === $best[$mid]['votes'] && (int) $r['id'] > $best[$mid]['id'])) {
            $best[$mid] = array('votes' => $n, 'id' => (int) $r['id'], 'text' => (string) $r['proposal']);
        }
    }
    $out = array();
    foreach ($best as $mid => $info) {
        $out[$mid] = $info['text'];
    }
    set_transient($key, $out, 10 * MINUTE_IN_SECONDS);
    $cache[$lang] = $out;
    return $out;
}

/** 提案/投票/审核后清掉自动采用缓存 */
function ybh_i18n_auto_adopt_flush($lang = '')
{
    if ($lang !== '' && ybh_language_valid($lang)) {
        delete_transient('ybh_i18n_auto_' . md5((string) $lang));
        return;
    }
    foreach (array_keys(ybh_languages()) as $l) {
        delete_transient('ybh_i18n_auto_' . md5((string) $l));
    }
}

/**
 * 文章/页面某字段的"有效译文"：
 *   有已批准 → 用它（may be '' 表示没有）；
 *   没有已批准 → 用该 (obj, field, lang) 下票数最高（并列取更晚）的提案。
 *
 * @return array ['text' => string, 'source' => 'approved'|'auto'|'', 'proposal_id' => int, 'author' => string]
 */
function ybh_i18n_effective_post_value($post_id, $lang, $field)
{
    $post_id = (int) $post_id;
    $approved = ybh_i18n_page_translation($post_id, $lang, $field);
    if ($approved !== '') {
        return array('text' => $approved, 'source' => 'approved', 'proposal_id' => 0,
            'author' => (string) get_post_meta($post_id, ybh_i18n_meta_key($lang, 'translator'), true));
    }
    if (!ybh_i18n_auto_adopt_enabled()) {
        return array('text' => '', 'source' => '', 'proposal_id' => 0, 'author' => '');
    }
    global $wpdb;
    $t = ybh_i18n_proposals_table();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, proposal, votes, author_name FROM {$t}
         WHERE obj_type = 'post' AND obj_id = %d AND field = %s AND lang = %s AND status = 'pending'
         ORDER BY id ASC", $post_id, $field, $lang), ARRAY_A);
    $win = null;
    foreach ((array) $rows as $r) {
        $v = json_decode((string) $r['votes'], true);
        $n = is_array($v) ? count($v) : 0;
        if ($win === null || $n > $win['votes'] || ($n === $win['votes'] && (int) $r['id'] > $win['id'])) {
            $win = array('votes' => $n, 'id' => (int) $r['id'], 'text' => (string) $r['proposal'], 'author' => (string) $r['author_name']);
        }
    }
    if (!$win) {
        return array('text' => '', 'source' => '', 'proposal_id' => 0, 'author' => '');
    }
    return array('text' => $win['text'], 'source' => 'auto', 'proposal_id' => $win['id'], 'author' => $win['author']);
}

/** 覆盖层目录（uploads/ybh-i18n） */
function ybh_i18n_overlay_dir($create = false)
{
    $up = wp_upload_dir();
    $dir = trailingslashit($up['basedir']) . 'ybh-i18n';
    if ($create && !is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    return $dir;
}

/** 某个语言的正式译文（覆盖层 JSON：msgid => 译文） */
function ybh_i18n_overlay_get($lang)
{
    $f = ybh_i18n_overlay_dir() . '/' . sanitize_key($lang) . '.json';
    if (!is_file($f)) {
        return array();
    }
    $j = json_decode((string) @file_get_contents($f), true);
    return is_array($j) ? $j : array();
}

/** 写入覆盖层（原子写：先写临时文件再 rename） */
function ybh_i18n_overlay_put($lang, array $map)
{
    $dir = ybh_i18n_overlay_dir(true);
    /*
     * 目录不可写时**早期就报清楚**：实际踩过一次 —— 目录是用 CLI（root）建的，
     * 而网站进程跑在 www 下，于是「批准」按钮一直报"写入失败"却看不出原因。
     * 这里把路径、属主、权限都写进日志。
     */
    if (!is_dir($dir) || !is_writable($dir)) {
        $st = ybh_i18n_overlay_status();
        error_log('[YBH i18n] 覆盖层目录不可写：' . $st['dir'] . '（owner=' . $st['owner'] . '）');
        return false;
    }
    $f = $dir . '/' . sanitize_key($lang) . '.json';
    ksort($map);
    $json = wp_json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $tmp = $f . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $json) === false) {
        error_log('[YBH i18n] 临时文件写入失败：' . $tmp);
        return false;
    }
    if (!@rename($tmp, $f)) {
        error_log('[YBH i18n] 覆盖层 rename 失败：' . $tmp . ' → ' . $f);
        @unlink($tmp);
        return false;
    }
    return true;
}

/** 覆盖层目录状态（后台提示与排错用） */
function ybh_i18n_overlay_status()
{
    $dir = ybh_i18n_overlay_dir(false);
    $owner = '?';
    if (is_dir($dir) && function_exists('posix_getpwuid') && @fileowner($dir) !== false) {
        $pw = posix_getpwuid((int) @fileowner($dir));
        if (!empty($pw['name'])) { $owner = $pw['name']; }
    }
    return array(
        'dir' => $dir,
        'exists' => is_dir($dir),
        'writable' => is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir)),
        'owner' => $owner,
    );
}

/**
 * 正式译文变动后清一次页面缓存。
 * 不这么做的话，批准只改数据库/文件，**页面上还是旧的**（读者要等缓存过期才看到）。
 */
function ybh_i18n_clear_caches()
{
    if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_complete_cache')) {
        Cache_Enabler::clear_complete_cache();
        return true;
    }
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
        return true;
    }
    return false;
}

/**
 * 把某个语言的正式译文重建为 WordPress 语言包（`.l10n.php`，WP 6.5+ 原生支持）。
 * 主题只用 `sakurairo` 这一个 textdomain，所以整份重建最省事也最不容易出错。
 */
function ybh_i18n_rebuild_l10n($lang)
{
    $langs = ybh_languages();
    if (!isset($langs[$lang])) {
        return false;
    }
    $locale = (string) $langs[$lang]['locale'];
    $map = ybh_i18n_overlay_get($lang);
    if (!$map) {
        // 没有译文就把文件删掉，避免留一份空包
        $f = get_template_directory() . '/languages/sakurairo-' . $locale . '.l10n.php';
        if (is_file($f)) { @unlink($f); }
        return true;
    }
    $messages = array();
    foreach ($map as $msgid => $msgstr) {
        if (is_string($msgid) && $msgid !== '' && is_string($msgstr) && $msgstr !== '') {
            $messages[$msgid] = $msgstr;
        }
    }
    $data = array(
        'domain' => 'sakurairo',
        'language' => $locale,
        'plural-forms' => 'nplurals=2; plural=n != 1;',
        'messages' => $messages,
    );
    $php = "<?php\n// YBH 多语言：由「工具 → 翻译提案」批准后自动生成，请勿手改。\n"
        . 'return ' . var_export($data, true) . ";\n";
    $dir = get_template_directory() . '/languages';
    if (!is_dir($dir)) { wp_mkdir_p($dir); }
    $f = $dir . '/sakurairo-' . $locale . '.l10n.php';
    return @file_put_contents($f, $php) !== false;
}

/* ---------------------------------------------------------------------------
 * 建表（幂等；用版本号控制，不必挂激活钩子）
 * ------------------------------------------------------------------------- */
add_action('admin_init', 'ybh_i18n_proposals_install', 5);
function ybh_i18n_proposals_install()
{
    // v2：加 obj_type / obj_id / field —— 提案不再只针对"界面文案"，
    //     也针对**文章/页面的标题与正文**（站长要求把文章本身纳入翻译流程）。
    $ver = '2';
    if (get_option('ybh_i18n_proposals_db') === $ver) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = ybh_i18n_proposals_table();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$t} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        msgid text NOT NULL,
        msgid_hash char(32) NOT NULL DEFAULT '',
        lang varchar(16) NOT NULL DEFAULT '',
        proposal longtext NOT NULL,
        author_id bigint(20) unsigned NOT NULL DEFAULT 0,
        author_name varchar(100) NOT NULL DEFAULT '',
        created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        status varchar(12) NOT NULL DEFAULT 'pending',
        votes text NOT NULL,
        reviewer_id bigint(20) unsigned NOT NULL DEFAULT 0,
        reviewed datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
        prev longtext NULL,
        note varchar(200) NOT NULL DEFAULT '',
        obj_type varchar(12) NOT NULL DEFAULT 'string',
        obj_id bigint(20) unsigned NOT NULL DEFAULT 0,
        field varchar(12) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY msgid_hash (msgid_hash),
        KEY lang_status (lang, status),
        KEY obj (obj_type, obj_id, field, lang),
        KEY author (author_name)
    ) {$charset};";
    dbDelta($sql);
    update_option('ybh_i18n_proposals_db', $ver, false);
}

/* ---------------------------------------------------------------------------
 * 公共小工具
 * ------------------------------------------------------------------------- */
function ybh_i18n_msgid_hash($msgid)
{
    return md5((string) $msgid);
}

/** 当前语言下某条文案的"正式译文"（覆盖层优先，其次内置表） */
function ybh_i18n_approved($lang, $msgid)
{
    $map = ybh_i18n_overlay_get($lang);
    return isset($map[$msgid]) ? (string) $map[$msgid] : '';
}

/* ===========================================================================
 * 非 REST 的"报到"端点（给工作台共用主站登录用）
 *
 * 为什么需要它：**WordPress REST 在请求里没有 `X-WP-Nonce` 时，会直接把当前用户
 * 当成未登录**（`rest_cookie_check_errors()` 的逻辑：没 nonce 就 `wp_set_current_user(0)`）。
 * 所以"用主站 cookie 认出人来"这件事，必须先在一个**普通请求**里完成 —— 普通请求
 * （也就是 admin-ajax）本来就是按 cookie 认证的，不需要 nonce。
 * 这里顺手把 REST 用的 nonce 一起发下去，工作台随后就能带着它调 REST 写入。
 * =========================================================================== */
add_action('wp_ajax_nopriv_ybh_i18n_ping', 'ybh_i18n_ajax_ping');
add_action('wp_ajax_ybh_i18n_ping', 'ybh_i18n_ajax_ping');
function ybh_i18n_ajax_ping()
{
    nocache_headers();
    $out = array('logged_in' => false);
    if (is_user_logged_in()) {
        $u = wp_get_current_user();
        $out = array(
            'logged_in' => true,
            'id' => (int) $u->ID,
            'name' => (string) $u->display_name,
            'can_review' => current_user_can('manage_options'),
            'can_propose' => current_user_can('edit_posts'),
            'nonce' => wp_create_nonce('wp_rest'),
        );
    }
    wp_send_json($out);   // 含 Content-Type: application/json 与 exit
}

/* ===========================================================================
 * REST
 * =========================================================================== */
add_action('rest_api_init', 'ybh_i18n_register_rest');
function ybh_i18n_register_rest()
{
    $ns = 'ybh-i18n/v1';

    register_rest_route($ns, '/strings', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'args' => array('lang' => array('required' => true)),
        'callback' => function (WP_REST_Request $r) {
            $lang = sanitize_text_field((string) $r['lang']);
            if (!ybh_language_valid($lang)) {
                return new WP_Error('ybh_bad_lang', '未知语言', array('status' => 400));
            }
            $map = ybh_i18n_overlay_get($lang);
            return array('lang' => $lang, 'count' => count($map), 'strings' => $map);
        },
    ));

    register_rest_route($ns, '/proposals', array(
        array(
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => 'ybh_i18n_rest_list',
        ),
        array(
            'methods' => 'POST',
            'permission_callback' => function () { return is_user_logged_in(); },
            'callback' => 'ybh_i18n_rest_create',
        ),
    ));

    register_rest_route($ns, '/proposals/(?P<id>\d+)/vote', array(
        'methods' => 'POST',
        'permission_callback' => function () { return is_user_logged_in(); },
        'callback' => 'ybh_i18n_rest_vote',
    ));

    register_rest_route($ns, '/proposals/(?P<id>\d+)/review', array(
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => 'ybh_i18n_rest_review',
    ));

    /*
     * 批量审核（站长要求工作台"只提供提案模式"，那审核得快 —— 一条条点太慢）。
     * 只允许管理员；一次最多 200 条。
     */
    register_rest_route($ns, '/proposals/bulk-review', array(
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function (WP_REST_Request $r) {
            $ids = (array) $r->get_param('ids');
            $action = (string) $r->get_param('action');
            $ids = array_slice(array_values(array_filter(array_map('intval', $ids))), 0, 200);
            if (!$ids) {
                return new WP_Error('ybh_no_ids', '没有选中提案', array('status' => 400));
            }
            $done = 0; $errs = array();
            foreach ($ids as $id) {
                $res = ybh_i18n_review_proposal($id, $action, '批量' . $action, get_current_user_id());
                if (is_wp_error($res)) { $errs[] = $id . ':' . $res->get_error_message(); } else { $done++; }
            }
            return array('ok' => true, 'action' => $action, 'done' => $done, 'errors' => $errs);
        },
    ));

    /*
     * 我是谁（**共用主站 cookie**，避免在工作台重复登录）。
     * 工作台与主站同属 yibianhui.cn（同站不同子域），浏览器会把主站登录 cookie
     * 带在同站请求里，所以这里直接 `is_user_logged_in()` 就能认出人来。
     * 前端用 `credentials: 'include'` 调它即可。
     */
    register_rest_route($ns, '/whoami', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => 'ybh_i18n_rest_whoami',
    ));

    /*
     * 文章列表（站长要求：**文章本身也要能翻译**）。
     * 返回每篇的标题/正文摘要 + 该语言下"已批准译文"的状态，供工作台列出并提提案。
     */
    register_rest_route($ns, '/articles', array(
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => 'ybh_i18n_rest_articles',
    ));
}

/** 允许跨域读工作台的来源（只有我们自己这两个域名） */
function ybh_i18n_allowed_origins()
{
    return apply_filters('ybh_i18n_allowed_origins', array(
        'https://i18n.yibianhui.cn',
        'https://www.yibianhui.cn',
    ));
}

/**
 * 「我是谁」——**专门用来让工作台共用主站登录**。
 *
 * 两个坑都在这里解决（都实测踩过）：
 *  ① `is_user_logged_in()` 在 REST 请求里通常是 false —— 因为 WP 的
 *     `rest_cookie_check_errors()` 规定"没有 `X-WP-Nonce` 就当作未登录"。
 *     工作台在别的子域上，第一次调用时手里还没有 nonce，于是永远认不出人。
 *     解法：这里**直接用 `wp_validate_auth_cookie()` 校验登录 cookie**（它是带签名的，
 *     由 WP 自己验证，安全性不降级），再 `wp_set_current_user()`。
 *  ② 不能无条件把 nonce 发给任意来源（否则等于给 CSRF 开后门）。
 *     所以只在**来源属于我们自己的域名**时才下发 nonce。
 */
function ybh_i18n_rest_whoami(WP_REST_Request $r)
{
    $uid = wp_validate_auth_cookie('', 'logged_in');
    if ($uid) {
        wp_set_current_user((int) $uid);
    }
    if (!is_user_logged_in()) {
        return array('logged_in' => false);
    }
    $u = wp_get_current_user();
    $origin = get_http_origin();
    $origin_ok = ($origin === '' || in_array($origin, ybh_i18n_allowed_origins(), true));
    return array(
        'logged_in' => true,
        'id' => (int) $u->ID,
        'name' => (string) $u->display_name,
        'can_review' => current_user_can('manage_options'),
        'can_propose' => current_user_can('edit_posts'),
        // 后续写请求要带 `X-WP-Nonce`（WP 的标准要求）；只发给可信来源
        'nonce' => $origin_ok ? wp_create_nonce('wp_rest') : '',
    );
}

/**
 * 只让自己的域名跨域调这套接口（其余来源去掉 CORS 头）。
 * 默认 WP 对任何来源都回显 ACAO + 允许凭据；对"能读 nonce 的接口"来说太宽。
 */
add_action('rest_pre_serve_request', function ($served, $result, $request, $server) {
    if (strpos((string) $request->get_route(), '/ybh-i18n/v1/') !== 0) {
        return $served;
    }
    $origin = get_http_origin();
    if ($origin !== '' && !in_array($origin, ybh_i18n_allowed_origins(), true)) {
        header_remove('Access-Control-Allow-Origin');
        header_remove('Access-Control-Allow-Credentials');
    }
    return $served;
}, 11, 4);
function ybh_i18n_rest_articles(WP_REST_Request $r)
{
    $lang = sanitize_text_field((string) $r->get_param('lang'));
    if (!ybh_language_valid($lang)) {
        return new WP_Error('ybh_bad_lang', '未知语言', array('status' => 400));
    }
    $q = sanitize_text_field((string) $r->get_param('q'));
    $page = max(1, (int) $r->get_param('page') ?: 1);
    $per = min(50, max(5, (int) $r->get_param('per_page') ?: 20));

    $args = array(
        'post_type' => array('post', 'page'),
        'post_status' => 'publish',
        'posts_per_page' => $per,
        'paged' => $page,
        'orderby' => 'modified',
        'order' => 'DESC',
        's' => $q,
        'no_found_rows' => false,
    );
    $query = new WP_Query($args);
    $out = array();
    foreach ($query->posts as $p) {
        $title = (string) get_post_meta($p->ID, ybh_i18n_meta_key($lang, 'title'), true);
        $body = (string) get_post_meta($p->ID, ybh_i18n_meta_key($lang, 'content'), true);
        $out[] = array(
            'id' => (int) $p->ID,
            'type' => $p->post_type,
            'title' => get_the_title($p),
            'excerpt' => mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($p->post_content)))), 0, 160),
            'modified' => $p->post_modified,
            'link' => get_permalink($p),
            'trans_title' => mb_substr($title, 0, 200),
            'trans_excerpt' => mb_substr(trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($body))), 0, 160),
            'has_title' => $title !== '',
            'has_body' => $body !== '',
            'translator' => (string) get_post_meta($p->ID, ybh_i18n_meta_key($lang, 'translator'), true),
            'orig_lang' => ybh_i18n_post_orig_lang($p->ID),
        );
    }
    return array(
        'lang' => $lang,
        'page' => $page,
        'per_page' => $per,
        'total' => (int) $query->found_posts,
        'pages' => (int) $query->max_num_pages,
        'articles' => $out,
    );
}

function ybh_i18n_rest_list(WP_REST_Request $r)
{
    global $wpdb;
    $t = ybh_i18n_proposals_table();
    $where = array('1=1');
    $args = array();
    if ($lang = (string) $r->get_param('lang')) {
        $where[] = 'lang = %s'; $args[] = sanitize_text_field($lang);
    }
    if ($msgid = (string) $r->get_param('msgid')) {
        $where[] = 'msgid_hash = %s'; $args[] = ybh_i18n_msgid_hash($msgid);
    }
    if ($obj = (string) $r->get_param('obj_type')) {
        if (in_array($obj, array('string', 'post'), true)) { $where[] = 'obj_type = %s'; $args[] = $obj; }
    }
    if ($oid = (int) $r->get_param('obj_id')) {
        $where[] = 'obj_id = %d'; $args[] = $oid;
    }
    if ($author = (string) $r->get_param('author')) {
        $where[] = 'author_name = %s'; $args[] = sanitize_text_field($author);
    }
    if ($st = (string) $r->get_param('status')) {
        if (in_array($st, array('pending', 'approved', 'rejected'), true)) {
            $where[] = 'status = %s'; $args[] = $st;
        }
    }
    $limit = min(500, max(1, (int) $r->get_param('limit') ?: 200));
    $sql = "SELECT id,msgid,lang,proposal,author_id,author_name,created,updated,status,votes,reviewed,note,prev,obj_type,obj_id,field
            FROM {$t} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . $limit;
    $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
    $out = array();
    foreach ((array) $rows as $row) {
        $v = json_decode((string) $row['votes'], true);
        $out[] = array(
            'id' => (int) $row['id'],
            'msgid' => $row['msgid'],
            'lang' => $row['lang'],
            'proposal' => $row['proposal'],
            'author' => $row['author_name'] !== '' ? $row['author_name'] : ('#' . (int) $row['author_id']),
            'author_id' => (int) $row['author_id'],
            'created' => $row['created'],
            'status' => $row['status'],
            'votes' => is_array($v) ? count($v) : 0,
            'voted' => is_array($v) && in_array(get_current_user_id(), array_map('intval', $v), true),
            'note' => $row['note'],
            'has_prev' => ($row['prev'] !== null && $row['prev'] !== ''),
            'obj_type' => (string) $row['obj_type'],
            'obj_id' => (int) $row['obj_id'],
            'field' => (string) $row['field'],
        );
    }
    return array('count' => count($out), 'proposals' => $out);
}

function ybh_i18n_rest_create(WP_REST_Request $r)
{
    global $wpdb;
    $msgid = (string) $r->get_param('msgid');
    $lang = sanitize_text_field((string) $r->get_param('lang'));
    $text = (string) $r->get_param('proposal');
    $obj_type = (string) $r->get_param('obj_type');
    $obj_type = in_array($obj_type, array('post', 'string'), true) ? $obj_type : 'string';
    $obj_id = (int) $r->get_param('obj_id');
    $field = (string) $r->get_param('field');
    $field = ybh_i18n_field_valid($field) ? $field : '';   // T68：也接受 para-<n>
    if ($obj_type === 'post') {
        $post = get_post($obj_id);
        if (!$post || !in_array($post->post_type, array('post', 'page'), true)) {
            return new WP_Error('ybh_bad_obj', '文章不存在', array('status' => 400));
        }
        if ($field === '') {
            return new WP_Error('ybh_bad_field', '缺少 field（title/content）', array('status' => 400));
        }
    }
    if ($msgid === '' || mb_strlen($msgid) > 20000) {
        return new WP_Error('ybh_bad_msgid', 'msgid 缺失或过长', array('status' => 400));
    }
    if (!ybh_language_valid($lang)) {
        return new WP_Error('ybh_bad_lang', '未知语言', array('status' => 400));
    }
    $text = trim(wp_kses_post($text));
    if ($text === '' || mb_strlen($text) > 20000) {
        return new WP_Error('ybh_bad_text', '译文为空或过长', array('status' => 400));
    }
    $u = wp_get_current_user();
    $now = current_time('mysql');
    $ok = $wpdb->insert(ybh_i18n_proposals_table(), array(
        'msgid' => $msgid,
        'msgid_hash' => ybh_i18n_msgid_hash($msgid),
        'lang' => $lang,
        'proposal' => $text,
        'author_id' => (int) $u->ID,
        'author_name' => (string) $u->display_name,
        'created' => $now,
        'updated' => $now,
        'status' => 'pending',
        'votes' => '[]',
        'obj_type' => $obj_type,
        'obj_id' => $obj_id,
        'field' => $field,
    ), array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'));
    if (!$ok) {
        return new WP_Error('ybh_db', '写入失败', array('status' => 500));
    }
    ybh_i18n_auto_adopt_flush($lang);   // 新提案可能改变"自动采用"结果
    return array('ok' => true, 'id' => (int) $wpdb->insert_id, 'status' => 'pending');
}

function ybh_i18n_rest_vote(WP_REST_Request $r)
{
    global $wpdb;
    $t = ybh_i18n_proposals_table();
    $id = (int) $r['id'];
    $row = $wpdb->get_row($wpdb->prepare("SELECT id, votes FROM {$t} WHERE id = %d", $id), ARRAY_A);
    if (!$row) {
        return new WP_Error('ybh_404', '提案不存在', array('status' => 404));
    }
    $uid = get_current_user_id();
    $votes = json_decode((string) $row['votes'], true);
    $votes = is_array($votes) ? array_map('intval', $votes) : array();
    if (in_array($uid, $votes, true)) {
        $votes = array_values(array_diff($votes, array($uid)));
        $now = false;
    } else {
        $votes[] = $uid;
        $now = true;
    }
    $wpdb->update($t, array('votes' => wp_json_encode(array_values($votes)), 'updated' => current_time('mysql')),
        array('id' => $id), array('%s', '%s'), array('%d'));
    ybh_i18n_auto_adopt_flush();   // 票数变了，"自动采用"可能换人
    return array('ok' => true, 'votes' => count($votes), 'voted' => $now);
}

/**
 * 管理员批准/驳回。批准时**真正生效**：写覆盖层 + 重建语言包，并记下旧值以便撤销。
 */
function ybh_i18n_rest_review(WP_REST_Request $r)
{
    $id = (int) $r['id'];
    $action = (string) $r->get_param('action');
    $note = mb_substr(sanitize_text_field((string) $r->get_param('note')), 0, 200);
    $res = ybh_i18n_review_proposal($id, $action, $note, get_current_user_id());
    if (is_wp_error($res)) {
        return $res;
    }
    return array('ok' => true, 'id' => $id, 'action' => $action, 'effect' => $res);
}

/* ===========================================================================
 * T68 · 分段翻译
 *
 * 长文一整篇塞进一个输入框很难翻好。这里把正文按**块级单元**（<p> / <h1–h6> /
 * <blockquote> 整块）切开，每一段可以单独提提案、单独批准。
 *
 * 存储与生效规则（刻意保守，保证读者看到的要么是原文、要么是**完整**译文）：
 *   · 第 n 段的译文存 post_meta `_ybh_i18n_{lang}_para{n}`；
 *   · 每次批准/撤销后重新拼装：**全部段落都有译文**时才把拼好的整篇写进
 *     `_ybh_i18n_{lang}`（走既有的显示与署名通道）；不完整时不动整篇 meta
 *     ——若此前拼出的整篇因撤销变得不完整，且能证明它确实是拼出来的，
 *     则撤下它（见 ybh_i18n_review_proposal 的 revoke 分支）；
 *   · 拼装 = 原文里第 n 个单元替换成译文，其余逐字节不动 ⇒ 结构（图片、列表、
 *     短代码、[fn] 脚注）永不丢失。
 * ========================================================================= */

/** 提案 field 是否合法：title / content / para-<n>（T68 分段） */
function ybh_i18n_field_valid($field)
{
    $field = (string) $field;
    if (in_array($field, array('title', 'content'), true)) { return true; }
    return (bool) preg_match('/^para-\d{1,6}$/', $field);
}

/** 分段单元的正则（切块与拼装必须用同一个，否则替换错位） */
function ybh_i18n_para_rx()
{
    // ⚠️ 回引必须是 \1：`(p|h[1-6])` 是本式里**唯一**的捕获组
    // （blockquote 两侧用的是非捕获组 `(?:…)`）。写成 \2 会让
    // preg_match_all / preg_replace_callback 直接编译失败
    // （"reference to non-existent subpattern"）⇒ 分段翻译静默失效、永远切出 0 段。
    return '~<blockquote(?:\s[^>]*)?>.*?</blockquote>|<(p|h[1-6])(?:\s[^>]*)?>.*?</\1>~isu';
}

/**
 * 把 HTML 按可翻译的块级单元切开。
 *
 * 只切顶层 `<p>` / `<h1–h6>` / `<blockquote>`（blockquote 整块算一段，
 * 其内部的 <p> 不再重复拆）。ul/ol/table/figure/pre 与裸文本不算单元
 * ——它们通常没有"逐段翻译"的意义，整篇模式处理。
 *
 * @param  string $html
 * @return string[] 以原文出现顺序编号的单元（保留原始 HTML）
 */
function ybh_i18n_split_paragraphs($html)
{
    $html = (string) $html;
    if ($html === '' || !preg_match_all(ybh_i18n_para_rx(), $html, $m)) {
        return array();
    }
    return array_map('strval', $m[0]);
}

/** 第 n 段译文的 meta 键 */
function ybh_i18n_para_meta_key($lang, $n)
{
    return ybh_i18n_meta_key($lang) . '_para' . (int) $n;
}

/**
 * 拼装：把原文里第 n 个单元换成第 n 段译文（没有译文的段保持原样）。
 *
 * @param  string   $source_html  原文 post_content
 * @param  string[] $translations 段号 => 译文 HTML（可稀疏）
 * @return string
 */
function ybh_i18n_compose_paragraphs($source_html, $translations)
{
    $translations = is_array($translations) ? $translations : array();
    if (!$translations) {
        return (string) $source_html;
    }
    $n = -1;
    $out = preg_replace_callback(ybh_i18n_para_rx(), function ($m) use (&$n, $translations) {
        $n++;
        if (!isset($translations[$n]) || (string) $translations[$n] === '') {
            return $m[0];               // 这段还没有译文 → 原样
        }
        $t = (string) $translations[$n];
        // 译文没带块标签就包一个 <p>（保证拼出来的结构合法）
        if (!preg_match('~^<(p|h[1-6]|blockquote)\b~i', trim($t))) {
            $t = '<p>' . trim($t) . '</p>';
        }
        return $t;
    }, (string) $source_html);
    return (string) $out;
}

/**
 * 收集当前各段的译文状态。
 *
 * @return array ['total' => int, 'done' => int, 'trans' => string[]（段号=>译文，稀疏）]
 */
function ybh_i18n_para_state($pid, $lang)
{
    $segments = ybh_i18n_split_paragraphs((string) get_post_field('post_content', (int) $pid));
    $trans = array();
    $done = 0;
    foreach ($segments as $i => $_) {
        $v = trim((string) get_post_meta((int) $pid, ybh_i18n_para_meta_key($lang, $i), true));
        if ($v !== '') {
            $trans[$i] = $v;
            $done++;
        }
    }
    return array('total' => count($segments), 'done' => $done, 'trans' => $trans);
}

/**
 * 段落批准后的**重新拼装**：全部段落就绪时写整篇 meta 并署名译者；否则不动整篇。
 *
 * @return array ['complete' => bool, 'total' => int, 'done' => int, 'composed' => bool]
 */
function ybh_i18n_para_recompose($pid, $lang, $who = '')
{
    $pid = (int) $pid;
    $st = ybh_i18n_para_state($pid, $lang);
    if (!$st['total']) {
        return array('complete' => false, 'total' => 0, 'done' => 0, 'composed' => false);
    }
    $full_key = ybh_i18n_meta_key($lang, 'content');
    if ($st['done'] === $st['total']) {
        $source = (string) get_post_field('post_content', $pid);
        $composed = ybh_i18n_compose_paragraphs($source, $st['trans']);
        $current = (string) get_post_meta($pid, $full_key, true);
        if ($composed !== $current) {
            update_post_meta($pid, $full_key, $composed);
        }
        if ($who !== '') {
            update_post_meta($pid, ybh_i18n_meta_key($lang, 'translator'), $who);
        }
        return array('complete' => true, 'total' => $st['total'], 'done' => $st['done'], 'composed' => true);
    }
    return array('complete' => false, 'total' => $st['total'], 'done' => $st['done'], 'composed' => false);
}

/**
 * 审核核心（REST 与后台表单共用）。
 *
 * @return array|WP_Error
 */
function ybh_i18n_review_proposal($id, $action, $note = '', $reviewer = 0)
{
    global $wpdb;
    if (!in_array($action, array('approve', 'reject', 'revoke'), true)) {
        return new WP_Error('ybh_bad_action', '未知操作', array('status' => 400));
    }
    $t = ybh_i18n_proposals_table();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", (int) $id), ARRAY_A);
    if (!$row) {
        return new WP_Error('ybh_404', '提案不存在', array('status' => 404));
    }
    $lang = (string) $row['lang'];
    $msgid = (string) $row['msgid'];
    $now = current_time('mysql');
    $effect = array('lang' => $lang, 'msgid_md5' => ybh_i18n_msgid_hash($msgid));

    if ($action === 'reject') {
        $wpdb->update($t, array('status' => 'rejected', 'reviewer_id' => (int) $reviewer,
            'reviewed' => $now, 'note' => $note), array('id' => (int) $id),
            array('%s', '%d', '%s', '%s'), array('%d'));
        $effect['status'] = 'rejected';
        return $effect;
    }

    /* ---------- 文章/页面：写 post meta（标题或正文），并把**提案者**署名为译者 ---------- */
    if (($row['obj_type'] ?? 'string') === 'post') {
        $pid = (int) $row['obj_id'];
        $field = (string) $row['field'];
        $post = get_post($pid);
        if (!$post) {
            return new WP_Error('ybh_404_post', '文章不存在', array('status' => 404));
        }

        /*
         * T68 · 分段提案（field = para-<n>）：写段落 meta，再重新拼装。
         * 与整篇模式的关键差异：整篇 meta 只在**全部段落就绪**时才被写/署名。
         */
        if (preg_match('/^para-(\d+)$/', $field, $pm)) {
            $n = (int) $pm[1];
            $total = count(ybh_i18n_split_paragraphs((string) $post->post_content));
            if ($n >= $total) {
                return new WP_Error('ybh_bad_para', '段落编号超出范围（共 ' . $total . ' 段）', array('status' => 400));
            }
            $key = ybh_i18n_para_meta_key($lang, $n);
            $old = (string) get_post_meta($pid, $key, true);

            if ($action === 'revoke') {
                // 撤销前先判断"整篇 meta 是否是按段拼出来的"：把撤销前的段落状态拼一遍，
                // 与整篇 meta 一致 ⇒ 是拼的（撤销后若不完整就要撤下整篇）；对不上 ⇒ 手动整篇翻译，不动。
                $pre = ybh_i18n_para_state($pid, $lang);
                $pre_trans = $pre['trans'];
                if ($old !== '' && $pre['done'] === $pre['total']) { $pre_trans[$n] = $old; }
                $was_composed = ($pre['done'] === $pre['total'] && $pre['total'] > 0
                    && (string) get_post_meta($pid, ybh_i18n_meta_key($lang, 'content'), true)
                        === ybh_i18n_compose_paragraphs((string) $post->post_content, $pre_trans));

                $prev = (string) $row['prev'];
                if ($prev === '') { delete_post_meta($pid, $key); } else { update_post_meta($pid, $key, $prev); }
                $st = ybh_i18n_para_recompose($pid, $lang);
                if ($was_composed && !$st['complete']) {
                    delete_post_meta($pid, ybh_i18n_meta_key($lang, 'content'));
                }
                ybh_i18n_clear_caches();
                $wpdb->update($t, array('status' => 'pending', 'reviewer_id' => 0,
                    'reviewed' => '0000-00-00 00:00:00', 'note' => $note), array('id' => (int) $id),
                    array('%s', '%d', '%s', '%s'), array('%d'));
                $effect['status'] = 'revoked';
                $effect['restored'] = $prev;
                $effect['para'] = array('n' => $n, 'total' => $st['total'], 'done' => $st['done']);
                return $effect;
            }

            // approve
            $val = wp_kses_post((string) $row['proposal']);
            update_post_meta($pid, $key, $val);
            $who = (string) $row['author_name'];
            if ($who === '') { $who = '#' . (int) $row['author_id']; }
            $st = ybh_i18n_para_recompose($pid, $lang, $who);   // 完整时写整篇 + 署名
            ybh_i18n_clear_caches();
            $wpdb->update($t, array('status' => 'approved', 'reviewer_id' => (int) $reviewer, 'reviewed' => $now,
                'note' => $note !== '' ? $note : '分段 ' . ($n + 1) . '/' . $st['total'],
                'prev' => $old), array('id' => (int) $id),
                array('%s', '%d', '%s', '%s', '%s'), array('%d'));
            $effect['status'] = 'approved';
            $effect['post'] = $pid;
            $effect['field'] = $field;
            $effect['para'] = array('n' => $n, 'total' => $st['total'], 'done' => $st['done'],
                'composed' => $st['composed']);
            $effect['prev'] = $old;
            return $effect;
        }

        $key = ybh_i18n_meta_key($lang, $field === 'title' ? 'title' : 'content');
        $old = (string) get_post_meta($pid, $key, true);

        if ($action === 'revoke') {
            $prev = (string) $row['prev'];
            if ($prev === '') { delete_post_meta($pid, $key); } else { update_post_meta($pid, $key, $prev); }
            $wpdb->update($t, array('status' => 'pending', 'reviewer_id' => 0,
                'reviewed' => '0000-00-00 00:00:00', 'note' => $note), array('id' => (int) $id),
                array('%s', '%d', '%s', '%s'), array('%d'));
            ybh_i18n_clear_caches();
            $effect['status'] = 'revoked';
            $effect['restored'] = $prev;
            return $effect;
        }

        $val = ($field === 'title')
            ? sanitize_text_field((string) $row['proposal'])
            : wp_kses_post((string) $row['proposal']);
        update_post_meta($pid, $key, $val);
        // 站长要求：**在其它语言状态下文章要显示译者** —— 译者就是提这条提案的人
        $who = (string) $row['author_name'];
        if ($who === '') { $who = '#' . (int) $row['author_id']; }
        if ($field === 'content') {
            update_post_meta($pid, ybh_i18n_meta_key($lang, 'translator'), $who);
        }
        ybh_i18n_clear_caches();
        $wpdb->update($t, array('status' => 'approved', 'reviewer_id' => (int) $reviewer, 'reviewed' => $now,
            'note' => $note, 'prev' => $old), array('id' => (int) $id),
            array('%s', '%d', '%s', '%s', '%s'), array('%d'));
        $effect['status'] = 'approved';
        $effect['post'] = $pid;
        $effect['field'] = $field;
        $effect['translator'] = $who;
        $effect['prev'] = $old;
        return $effect;
    }

    /* ---------- 界面文案：写覆盖层 ---------- */
    $map = ybh_i18n_overlay_get($lang);

    if ($action === 'revoke') {
        // 撤销这一次批准：把旧值放回去
        $prev = (string) $row['prev'];
        $prev = ($prev === '' && strpos((string) $row['prev'], 'NULL')) ? '' : $prev;
        if ($prev === '') {
            unset($map[$msgid]);
        } else {
            $map[$msgid] = $prev;
        }
        ybh_i18n_overlay_put($lang, $map);
        ybh_i18n_rebuild_l10n($lang);
        $wpdb->update($t, array('status' => 'pending', 'reviewer_id' => 0, 'reviewed' => '0000-00-00 00:00:00',
            'note' => $note), array('id' => (int) $id), array('%s', '%d', '%s', '%s'), array('%d'));
        $effect['status'] = 'revoked';
        $effect['restored'] = $prev;
        return $effect;
    }

    // approve：记下当前值作为"可撤销的旧值"，再写入
    $old = isset($map[$msgid]) ? (string) $map[$msgid] : '';
    $map[$msgid] = (string) $row['proposal'];
    if (!ybh_i18n_overlay_put($lang, $map)) {
        $st = ybh_i18n_overlay_status();
        return new WP_Error('ybh_fs',
            '写入正式译文失败：目录 ' . $st['dir'] . '（属主 ' . $st['owner'] . '，可写='
            . ($st['writable'] ? '是' : '否') . '）。请让网站进程对该目录有写权限。',
            array('status' => 500));
    }
    ybh_i18n_rebuild_l10n($lang);
    $wpdb->update($t, array('status' => 'approved', 'reviewer_id' => (int) $reviewer, 'reviewed' => $now,
        'note' => $note, 'prev' => $old), array('id' => (int) $id),
        array('%s', '%d', '%s', '%s', '%s'), array('%d'));
    $effect['status'] = 'approved';
    $effect['prev'] = $old;
    $effect['total'] = count($map);
    ybh_i18n_auto_adopt_flush($lang);
    return $effect;
}

/* ===========================================================================
 * 后台「工具 → 翻译提案」
 * =========================================================================== */
add_action('admin_menu', function () {
    add_management_page('翻译提案', '翻译提案', 'edit_posts', 'ybh-i18n-proposals', 'ybh_i18n_proposals_page');
});

function ybh_i18n_proposals_page()
{
    global $wpdb;
    $t = ybh_i18n_proposals_table();
    $can_review = current_user_can('manage_options');
    $filter = isset($_GET['st']) ? sanitize_text_field(wp_unslash((string) $_GET['st'])) : 'pending';

    // 处理审核动作（表单 + nonce）
    if (isset($_POST['ybh_i18n_action'], $_POST['pid']) && $can_review) {
        check_admin_referer('ybh_i18n_review_' . (int) $_POST['pid']);
        $res = ybh_i18n_review_proposal((int) $_POST['pid'], (string) $_POST['ybh_i18n_action'],
            isset($_POST['note']) ? sanitize_text_field(wp_unslash((string) $_POST['note'])) : '', get_current_user_id());
        $msg = is_wp_error($res) ? ('失败：' . $res->get_error_message()) : ('已完成：' . (string) ($res['status'] ?? ''));
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }

    $where = $filter !== 'all' ? $wpdb->prepare('WHERE status = %s', $filter) : '';
    $rows = $wpdb->get_results("SELECT * FROM {$t} {$where} ORDER BY id DESC LIMIT 200", ARRAY_A);
    $counts = array();
    foreach ((array) $wpdb->get_results("SELECT status, COUNT(*) c FROM {$t} GROUP BY status", ARRAY_A) as $c) {
        $counts[$c['status']] = (int) $c['c'];
    }

    echo '<div class="wrap"><h1>翻译提案</h1>';
    $st = ybh_i18n_overlay_status();
    if (!$st['writable']) {
        echo '<div class="notice notice-error"><p><b>正式译文目录不可写</b>，批准会失败（提案仍会保存）。<br />'
            . '目录：<code>' . esc_html($st['dir']) . '</code>，属主：<code>' . esc_html($st['owner']) . '</code>。'
            . '把该目录（或其上级 <code>uploads</code>）的属主/权限改成网站进程可写即可。</p></div>';
    }
    echo '<p>提案来自 i18n 工作台（<a href="https://i18n.yibianhui.cn/sakurairo.html" target="_blank" rel="noopener">i18n.yibianhui.cn</a>）。'
        . '批准后会立刻写入正式译文（<code>uploads/ybh-i18n/&lt;lang&gt;.json</code> + <code>languages/sakurairo-*.l10n.php</code>），可随时撤销。</p>';
    echo '<ul class="subsubsub">';
    foreach (array('pending' => '待审', 'approved' => '已批准', 'rejected' => '已驳回', 'all' => '全部') as $k => $label) {
        $n = $k === 'all' ? array_sum($counts) : ($counts[$k] ?? 0);
        $url = add_query_arg(array('page' => 'ybh-i18n-proposals', 'st' => $k), admin_url('tools.php'));
        echo '<li><a href="' . esc_url($url) . '"' . ($filter === $k ? ' class="current"' : '') . '>'
            . esc_html($label) . ' <span class="count">(' . (int) $n . ')</span></a> | </li>';
    }
    echo '</ul><table class="widefat striped"><thead><tr>'
        . '<th style="width:46px">ID</th><th style="width:70px">语言</th><th>msgid（原文）</th><th>提案译文</th>'
        . '<th style="width:110px">提案人</th><th style="width:70px">票</th><th style="width:150px">操作</th>'
        . '</tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="7">没有记录。</td></tr>';
    }
    foreach ((array) $rows as $r) {
        $votes = json_decode((string) $r['votes'], true);
        $vn = is_array($votes) ? count($votes) : 0;
        $st = array('pending' => '待审', 'approved' => '已批准', 'rejected' => '已驳回');
        echo '<tr>';
        echo '<td>' . (int) $r['id'] . '</td>';
        echo '<td>' . esc_html($r['lang']) . '</td>';
        echo '<td style="max-width:320px"><code>' . esc_html(mb_substr((string) $r['msgid'], 0, 160)) . '</code></td>';
        echo '<td><strong>' . esc_html(mb_substr((string) $r['proposal'], 0, 200)) . '</strong>'
            . ($r['status'] !== 'pending' ? '<br><em>' . esc_html($st[$r['status']] ?? $r['status']) . '</em>' : '') . '</td>';
        echo '<td>' . esc_html($r['author_name'] ?: ('#' . (int) $r['author_id'])) . '</td>';
        echo '<td>' . (int) $vn . '</td>';
        echo '<td>';
        if ($can_review) {
            foreach (array('approve' => '批准', 'reject' => '驳回', 'revoke' => '撤销') as $act => $label) {
                if ($act === 'revoke' && $r['status'] !== 'approved') { continue; }
                if ($act !== 'revoke' && $r['status'] === 'approved') { continue; }
                echo '<form method="post" style="display:inline">';
                wp_nonce_field('ybh_i18n_review_' . (int) $r['id']);
                echo '<input type="hidden" name="pid" value="' . (int) $r['id'] . '" />'
                    . '<input type="hidden" name="ybh_i18n_action" value="' . esc_attr($act) . '" />'
                    . '<button class="button button-small" type="submit">' . esc_html($label) . '</button></form> ';
            }
        } else {
            echo '—';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
