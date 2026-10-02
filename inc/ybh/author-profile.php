<?php
/**
 * YBH · 作者信息的唯一数据源（T62b）
 *
 * ===================================================================
 * 为什么要有这个文件
 * ===================================================================
 *   作者信息原本散在三个地方各取各的：
 *     · 作者页 `author.php`        —— `get_the_author_meta()` 直接取
 *     · 前台资料页 `inc/ybh/profile.php` —— 自己一套字段
 *     · 搜索「搜人」`inc/ybh/user-search.php` —— 又是一套字段名
 *   三处各写一遍，迟早会漂移（一边加字段、另一边忘了）。这里把「一个用户的
 *   公开信息」收敛成一次查询、一份结构，上面三处都读它。
 *
 * ===================================================================
 * 与「能改的字段」的边界
 * ===================================================================
 *   本文件**只读**。写（改昵称/简介/网站/头像）仍然只发生在
 *   `inc/ybh/profile.php` 的表单处理器里 —— 一处写、多处读。
 *
 *   目前**公开**展示的字段：显示名、昵称、简介、个人网站、头像、作品数、角色。
 *   社交链接走 `ybh_author_social_links` 过滤器（由资料页的字段注册表提供），
 *   没有装/没有填时就是一个空数组 —— 渲染端据此整段不输出，不留空壳。
 *
 *   邮箱**永不**出现在这里：本站前台不公开邮箱（见 profile.php 的说明）。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ybh_author_profile')) {
    /**
     * 取一个用户的公开信息。
     *
     * @param int $uid 用户 ID。
     * @return array 结构见下方 `$profile`；用户不存在时返回空数组。
     *               键名是**契约**：`inc/ybh/user-search.php` 与
     *               `tpl/author-card.php`、`tpl/user-card.php` 都按这套键取值。
     */
    function ybh_author_profile($uid)
    {
        static $cache = array();

        $uid = (int) $uid;
        if ($uid <= 0) {
            return array();
        }
        if (isset($cache[$uid])) {
            return $cache[$uid];
        }

        $user = get_userdata($uid);
        if (!$user) {
            return $cache[$uid] = array();
        }

        $display = (string) $user->display_name;
        $nick    = (string) get_the_author_meta('nickname', $uid);
        $bio     = (string) get_the_author_meta('description', $uid);
        $site    = (string) get_the_author_meta('user_url', $uid);

        /**
         * 过滤：该用户的社交链接。
         *
         * 由资料页的字段注册表（T61）挂上；每项形如
         * `array( 'label' => 'GitHub', 'url' => 'https://…', 'icon' => 'fa-brands fa-github' )`。
         * 没有数据时必须返回空数组 —— 渲染端会整段不输出。
         *
         * @param array $links 社交链接数组。
         * @param int   $uid   用户 ID。
         */
        $social = apply_filters('ybh_author_social_links', array(), $uid);

        return $cache[$uid] = array(
            'id'           => $uid,
            'display_name' => $display,
            // 昵称原样返回（**不与显示名去重**）：是否展示"昵称"由渲染端决定，
            // 数据层不替调用方做取舍。
            'nickname'     => $nick,
            'bio'          => $bio,
            'url'          => (string) get_author_posts_url($uid),
            'site'         => $site,
            'avatar_url'   => (string) get_avatar_url($uid, array('size' => 192)),
            'avatar_html'  => (string) get_avatar($uid, 192),
            'post_count'   => (int) count_user_posts($uid, 'post', true),
            'roles'        => (array) $user->roles,
            'social'       => is_array($social) ? $social : array(),
        );
    }
}
