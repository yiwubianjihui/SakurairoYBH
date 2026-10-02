<?php
/**
 * YBH · 多余空行守卫（任务 A 余项：发布时检查 + 一键清理）
 *
 * ===================================================================
 * 要解决的问题
 * ===================================================================
 *   早前保存链路会把正文里的换行逐个变成段落，于是出现过「每一段之间都多一个空段」
 *   的历史文章（最严重的一篇 165 个段落里 82 个是空的）。数据已按 T58 清理过，
 *   但**新写的文章还可能再踩**：作者贴进来的文本、编辑器切换、插件改动都可能重新
 *   制造这种空段。站长的要求是：**发布时检查，提醒 + 一键清理**（不自动改）。
 *
 * ===================================================================
 * 判据（与 T58 迁移脚本**同一套**，不另发明）
 * ===================================================================
 *   只把「**被两个真实段落紧紧包夹**」的空段（`<p>&nbsp;</p>` / `&#160;` / 纯空白变体）
 *   算作多余。也就是说：
 *
 *       <p>甲</p> <p>&nbsp;</p> <p>乙</p>   → 中间那个算多余
 *       <p>甲</p> <p>&nbsp;</p> <p>&nbsp;</p> <p>乙</p> → **不算**（连续空档大概率是作者有意的分段）
 *
 *   另外**永不触碰**：`[fn]…[/fn]` 脚注内的内容、以及
 *   `<pre>/<code>/<table>/<blockquote>/<ul>/<ol>/<figure>/<dl>/<script>/<style>` 容器内部。
 *   只含 `<br>` 的段落也不算空段（那是作者刻意留的换行）。
 *
 *   之所以「宁可漏删」：T44 之后**空行本身是要保留的内容**，误删比漏删严重得多。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 保护容器（内部一律不动） */
function ybh_blank_protect_ranges($c)
{
    $out = array();
    if (preg_match_all('~<(pre|code|table|blockquote|ul|ol|figure|dl|script|style)\b[^>]*>.*?</\1>~isu', $c, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $h) {
            $out[] = array($h[1], $h[1] + strlen($h[0]));
        }
    }
    return $out;
}

/** 脚注范围（`[fn]…[/fn]`） */
function ybh_blank_fn_ranges($c)
{
    $out = array();
    if (preg_match_all('~\[fn\](.*?)\[/fn\]~isu', $c, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $h) {
            $out[] = array($h[1], $h[1] + strlen($h[0]));
        }
    }
    return $out;
}

function ybh_blank_in_ranges($pos, $ranges)
{
    foreach ($ranges as $r) {
        if ($pos >= $r[0] && $pos < $r[1]) {
            return true;
        }
    }
    return false;
}

/** 1 = 空白空段（候选）｜2 = 只含 <br>（保留）｜0 = 真实段落 */
function ybh_blank_inner_kind($inner)
{
    $t = str_ireplace(array('&nbsp;', '&#160;', '&#xa0;'), ' ', $inner);
    $t = str_replace("\xC2\xA0", ' ', $t);
    if (trim($t) === '') {
        return 1;
    }
    $t2 = preg_replace('~<br\s*/?>~i', ' ', $t);
    if (trim($t2) === '') {
        return 2;
    }
    return 0;
}

/** 覆盖原文的块序列：[kind, raw, offset] */
function ybh_blank_blocks($c)
{
    $out = array();
    $off = 0;
    $len = strlen($c);
    if (preg_match_all('~<p(?:\s[^>]*)?>.*?</p>~isu', $c, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $raw = $hit[0];
            $start = $hit[1];
            if ($start > $off) {
                $gap = substr($c, $off, $start - $off);
                $out[] = array((trim($gap) === '') ? 'W' : 'X', $gap, $off);
            }
            $inner = preg_replace('~^<p(?:\s[^>]*)?>~iu', '', $raw);
            $inner = preg_replace('~</p>$~iu', '', $inner);
            $ik = ybh_blank_inner_kind($inner);
            $out[] = array(($ik === 1) ? 'E' : (($ik === 2) ? 'E-BR' : 'R'), $raw, $start);
            $off = $start + strlen($raw);
        }
    }
    if ($off < $len) {
        $gap = substr($c, $off);
        $out[] = array((trim($gap) === '') ? 'W' : 'X', $gap, $off);
    }
    return $out;
}

/**
 * 扫描正文。
 *
 * @param string $content
 * @return array{count:int,kept:int,in_fn:int,in_prot:int,br_only:int,html:string}
 *   count  = 会被清掉的空段数（被两个真实段包夹的空段）
 *   kept   = 空段但**故意保留**（连续空档/开头结尾/相邻不是真实段）
 *   html   = 清理后的正文（count 为 0 时与原文一致）
 */
function ybh_blank_scan($content)
{
    $content = (string) $content;
    $res = array('count' => 0, 'kept' => 0, 'in_fn' => 0, 'in_prot' => 0, 'br_only' => 0, 'html' => $content);
    if ('' === $content || false === strpos($content, '<p')) {
        return $res;
    }

    $blocks = ybh_blank_blocks($content);
    $fnR    = ybh_blank_fn_ranges($content);
    $protR  = ybh_blank_protect_ranges($content);

    $solid = array();
    foreach ($blocks as $i => $b) {
        if ($b[0] !== 'W') {
            $solid[] = $i;
        }
    }
    $n = count($solid);
    $drop = array();
    for ($k = 0; $k < $n; $k++) {
        $i = $solid[$k];
        $kind = $blocks[$i][0];
        if ('E-BR' === $kind) {
            $res['br_only']++;
            continue;
        }
        if ('E' !== $kind) {
            continue;
        }
        $pos = $blocks[$i][2];
        if (ybh_blank_in_ranges($pos, $fnR)) {
            $res['in_fn']++;
            continue;
        }
        if (ybh_blank_in_ranges($pos, $protR)) {
            $res['in_prot']++;
            continue;
        }
        $prev = ($k > 0) ? $blocks[$solid[$k - 1]][0] : null;
        $next = ($k + 1 < $n) ? $blocks[$solid[$k + 1]][0] : null;
        if ($prev === 'R' && $next === 'R') {
            $drop[] = $i;
        } else {
            $res['kept']++;
        }
    }

    $res['count'] = count($drop);
    if ($drop) {
        $html = '';
        foreach ($blocks as $i => $b) {
            if (!in_array($i, $drop, true)) {
                $html .= $b[1];
            }
        }
        $res['html'] = $html;
    }
    return $res;
}

/**
 * 清理后的正文。
 *
 * @param string $content
 * @return array{html:string,removed:int,kept:int}
 */
function ybh_blank_clean($content)
{
    $scan = ybh_blank_scan($content);
    return array('html' => $scan['html'], 'removed' => $scan['count'], 'kept' => $scan['kept']);
}

/* ---------------------------------------------------------------------------
 * 后台：保存后若发现多余空行，在编辑页给一条**可一键清理**的提示
 *
 * 为什么用 transient + admin_notices 而不是直接改内容：站长的要求就是"提醒 + 一键清理"，
 * 不自动改。transient 只活 5 分钟，且只对**当前用户**可见（键里带 user id）。
 * ------------------------------------------------------------------------- */
add_action('save_post', function ($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!in_array($post->post_type, array('post', 'page'), true)) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    // 前台写作页保存时会自己把结果回给编辑器，不必再塞后台提示
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return;
    }
    $scan = ybh_blank_scan((string) $post->post_content);
    if ($scan['count'] > 0) {
        set_transient('ybh_blank_notice_' . get_current_user_id() . '_' . $post_id, (int) $scan['count'], 300);
    } else {
        delete_transient('ybh_blank_notice_' . get_current_user_id() . '_' . $post_id);
    }
}, 20, 3);

add_action('admin_notices', function () {
    if (!function_exists('get_current_screen')) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || 'post' !== $screen->base) {
        return;
    }
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : (isset($_GET['post_ID']) ? (int) $_GET['post_ID'] : 0);
    if ($post_id <= 0) {
        return;
    }
    $count = (int) get_transient('ybh_blank_notice_' . get_current_user_id() . '_' . $post_id);
    if ($count <= 0) {
        return;
    }
    $url = wp_nonce_url(
        admin_url('admin-post.php?action=ybh_blank_clean&post=' . $post_id),
        'ybh_blank_clean_' . $post_id
    );
    echo '<div class="notice notice-warning"><p><strong>检测到 ' . $count . ' 处多余空行</strong>'
        . '（被两个正文段落包夹的空段，通常是保存链路带进来的，不是你敲的）。'
        . '连续空档与脚注、代码块内部不受影响。</p>'
        . '<p><a class="button button-primary" href="' . esc_url($url) . '">一键清理这 ' . $count . ' 处空行</a></p></div>';
});

add_action('admin_post_ybh_blank_clean', function () {
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) {
        wp_die('没有权限。');
    }
    check_admin_referer('ybh_blank_clean_' . $post_id);

    $post = get_post($post_id);
    $clean = ybh_blank_clean((string) $post->post_content);
    if ($clean['removed'] > 0) {
        kses_remove_filters();   // 只把已经存过的内容做"删空段"，不需要再走一遍 KSES
        wp_update_post(array('ID' => $post_id, 'post_content' => $clean['html']));
        kses_init_filters();
    }
    delete_transient('ybh_blank_notice_' . get_current_user_id() . '_' . $post_id);

    wp_safe_redirect(add_query_arg('ybh_blank', $clean['removed'], get_edit_post_link($post_id, 'raw')));
    exit;
});
