<?php
/**
 * YBH · 评论区：注册用户一律指向「作者页」，显示名用**当前**昵称（C 批）
 *
 * ===================================================================
 * 要解决的两个现象（站长反馈）
 * ===================================================================
 *   1. **点击评论里的用户进不去他的页面**；
 *   2. **部分评论还显示旧的显示名**（用户改了显示名，评论里还是老的）。
 *
 * 实测到的原因（线上数据，不是推测）：
 *   · 主题模板（`functions.php:692/698`、`inc/theme-plus.php:237/242`）用的是
 *     `comment_author_url()` —— 它读的是**评论里存的那一刻**填的网址。注册用户
 *     当年大多没填，于是 `href=""`（点不动）；填过的也多半是旧个人站，不是本站作者页。
 *   · 显示名用 `comment_author()` —— 读的同样是**评论表里存的字符串**。
 *     实测线上：存着 `Xixi` / `Alice`，而账号当前显示名是 `希希.` / `爱丽丝`。
 *
 * ===================================================================
 * 做法：只挂两个 WordPress 自带的过滤器，不动任何模板
 * ===================================================================
 *   · `get_comment_author_url`：评论若属于注册用户（`user_id > 0`），一律改指
 *     `get_author_posts_url()`；**访客评论原样不动**（他自己填的网址仍然算数）。
 *   · `get_comment_author`：注册用户一律改显**当前** `display_name`；访客原样。
 *
 * 为什么走过滤器而不是改模板：模板里 `comment_author()`、`comment_author_url()`、
 * `get_comment_author()`、以及头像 `alt` 全都读这两个函数 —— 一处挂上，全站（含后台
 * 评论列表）同时生效，也不必去改上游主题的模板文件。
 *
 * ⚠️ 副作用与配套：显示名变成"实时"的了，而页面有缓存 ⇒ 用户改完显示名，旧页面里
 *    可能还是老名字。所以下面同时挂了 `profile_update` → 清缓存（见文件末尾）。
 *
 * 关闭方式：`add_filter('ybh_comment_author_link', '__return_false');`
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/** 总开关（便于临时关闭比较效果） */
function ybh_comment_author_link_enabled()
{
    return (bool) apply_filters('ybh_comment_author_link', true);
}

/**
 * 注册用户的评论 → 指向本站作者页。
 */
add_filter('get_comment_author_url', function ($url, $comment_id = 0, $comment = null) {
    if (!ybh_comment_author_link_enabled() || !($comment instanceof WP_Comment)) {
        return $url;
    }
    $uid = (int) $comment->user_id;
    if ($uid <= 0) {
        return $url;   // 访客：保留他自己填的网址
    }
    if (!get_userdata($uid)) {
        return $url;   // 账号已注销：不要造一个死链
    }
    $author_url = get_author_posts_url($uid);
    return $author_url ? $author_url : $url;
}, 10, 3);

/**
 * 注册用户的评论 → 显示**当前**显示名。
 */
add_filter('get_comment_author', function ($author, $comment_id = 0, $comment = null) {
    if (!ybh_comment_author_link_enabled() || !($comment instanceof WP_Comment)) {
        return $author;
    }
    $uid = (int) $comment->user_id;
    if ($uid <= 0) {
        return $author;   // 访客：保留评论里填的昵称
    }
    $user = get_userdata($uid);
    if (!$user) {
        return $author;
    }
    $name = trim((string) $user->display_name);
    return ('' !== $name) ? $name : $author;
}, 10, 3);

/**
 * 显示名/昵称一变，评论区的渲染结果就变了 ⇒ 清一次页面缓存，避免"改了还是老名字"。
 *
 * 只清一次全量缓存（站点规模不大，这个代价可以接受），并且只在显示名真的变了时清。
 */
add_action('profile_update', function ($user_id, $old_user_data = null) {
    if (!ybh_comment_author_link_enabled()) {
        return;
    }
    $user = get_userdata($user_id);
    if (!$user) {
        return;
    }
    $old = ($old_user_data instanceof WP_User) ? (string) $old_user_data->display_name : '';
    if ($old !== '' && $old === (string) $user->display_name) {
        return;   // 显示名没变，不必清
    }
    if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_complete_cache')) {
        Cache_Enabler::clear_complete_cache();
    } elseif (function_exists('wp_cache_clean_cache')) {
        wp_cache_clean_cache();
    }
}, 10, 2);
