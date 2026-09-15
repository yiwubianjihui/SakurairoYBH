<?php
/**
 * YBH · 投稿者改稿 + 二次审核
 *
 * 需求（本轮）：
 *   「给所有 Contributor 修改自己作品的权限，但需要再次提交审核。」
 *
 * ---------------------------------------------------------------
 * 一、权限：只补一条 `edit_published_posts`
 *
 *   WordPress 判断「能不能编辑某篇文章」时（`map_meta_cap()` 的 `edit_post` 分支）：
 *     · 作者是本人 → 要求 `edit_posts`            （投稿者有）
 *     · 作者是别人 → 要求 `edit_others_posts`     （投稿者没有）
 *     · 文章已发布 → 再要求 `edit_published_posts`（投稿者没有 ← **就缺这一条**）
 *
 *   所以**只补这一条**就够了：投稿者因此能改自己已发布的文章，
 *   而「改别人的」仍然被 `edit_others_posts` 挡着。
 *
 *   ⚠️ 不要图省事去改角色本身的能力表：那会影响整个站点、也会被插件或
 *      WordPress 更新覆盖。也不要顺手补 `edit_others_posts` —— 那就等于给了
 *      改别人文章的权限，与需求完全相反。
 *   ⚠️ 同样**不要**补 `publish_posts`：投稿者一旦能直接发布，
 *     整套"先审后发"就失效了。
 *
 *   想收回：把 YBH_ALLOW_CONTRIBUTOR_EDIT_PUBLISHED 定义为 false（或注释掉本段）。
 *
 * ---------------------------------------------------------------
 * 二、二次审核：改完自动退回「待审核」
 *
 *   在 `wp_insert_post_data` 里把 post_status 由 publish 改成 pending。
 *
 *   为什么用这个过滤器而不是 `save_post`：
 *     `wp_insert_post_data` 在**写库之前**改，一次写入到位；
 *     用 `save_post` 还得再 `wp_update_post()` 一次 —— 多一次写入、多一条修订版，
 *     而且中间那一瞬间文章已经是「带着未审内容且处于已发布状态」，
 *     等于把没审过的内容先放出去了。
 *
 *   ⚠️ **代价（必须让作者知道）**：文章退回 pending 之后**前台就看不到了**，
 *   要等编辑/管理员审核通过才重新上线。这是"读者只会看到审过的版本"的必然代价。
 *   所以保存后会弹一条明确的后台提示，不让作者一头雾水（见第三节）。
 *
 *   ⚠️ 换行符 / 编码：本文件与主题其它文件一样，UTF-8 无 BOM、LF。
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 * 开关
 * ------------------------------------------------------------------------- */
if (!defined('YBH_ALLOW_CONTRIBUTOR_EDIT_PUBLISHED')) {
    define('YBH_ALLOW_CONTRIBUTOR_EDIT_PUBLISHED', true);
}

/** 当前用户是不是「投稿者」（只看角色，不看能力 —— 编辑/管理员另有角色） */
function ybh_is_contributor($user = null)
{
    if (!$user instanceof WP_User) {
        $user = wp_get_current_user();
    }
    if (!$user || !$user->exists()) {
        return false;
    }
    return in_array('contributor', (array) $user->roles, true);
}

/* ---------------------------------------------------------------------------
 * 1) 补 edit_published_posts
 * ------------------------------------------------------------------------- */
add_filter('user_has_cap', function ($allcaps, $caps, $args, $user = null) {
    if (!YBH_ALLOW_CONTRIBUTOR_EDIT_PUBLISHED) {
        return $allcaps;
    }
    if (!in_array('edit_published_posts', (array) $caps, true)) {
        return $allcaps;
    }
    // 第 4 个参数才是 WP_User（$args[0] 是能力名，别拿它去 get_userdata）
    if (ybh_is_contributor($user)) {
        $allcaps['edit_published_posts'] = true;
    }
    return $allcaps;
}, 10, 4);

/* ---------------------------------------------------------------------------
 * 2) 投稿者改已发布的文章 → 退回待审
 *
 *   守卫一个都不能少，否则会误伤（要么把管理员的正常编辑也退回了，
 *   要么在自动保存时把文章踢下线）。
 * ------------------------------------------------------------------------- */
add_filter('wp_insert_post_data', function ($data, $postarr) {
    if (!YBH_ALLOW_CONTRIBUTOR_EDIT_PUBLISHED) {
        return $data;
    }

    // 自动保存：动作频繁且不带真实编辑意图，绝不能在这里动状态
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return $data;
    }

    // 只处理文章。页面/附件/自定义类型不归这条规则管
    if (empty($data['post_type']) || 'post' !== $data['post_type']) {
        return $data;
    }

    $id = !empty($postarr['ID']) ? (int) $postarr['ID'] : 0;
    if ($id <= 0) {
        return $data;   // 新建的文章本来就走"待审核"，不用管
    }
    if (wp_is_post_revision($id)) {
        return $data;
    }

    $user = wp_get_current_user();
    if (!ybh_is_contributor($user)) {
        return $data;   // 编辑/管理员改稿不该被退回
    }

    $old = get_post($id);
    if (!$old instanceof WP_Post) {
        return $data;
    }
    // 只处理「原本已发布」的文章；草稿/待审本来就在审核流程里
    if ('publish' !== $old->post_status) {
        return $data;
    }
    // 必须是作者本人（配合 edit_others_posts 缺失，这里其实已经是必然，但显式写清楚）
    if ((int) $old->post_author !== (int) $user->ID) {
        return $data;
    }
    // 作者自己选了"存草稿"之类：尊重他的选择，不强行改成待审
    if ('publish' !== $data['post_status']) {
        return $data;
    }

    $data['post_status'] = 'pending';

    // 记一笔，保存跳转回来后弹提示（见第 3 节）。
    // 用 transient 而不是 query 参数：post.php 保存后会 302，query 参数容易被中间环节丢掉。
    set_transient('ybh_contrib_resent_' . (int) $user->ID, $id, 120);

    return $data;
}, 10, 2);

/* ---------------------------------------------------------------------------
 * 3) 保存后的明确提示
 *
 *   不提这一句，作者会以为"我明明改好了，怎么文章没了" —— 这比不给他改更糟。
 * ------------------------------------------------------------------------- */
add_action('admin_notices', function () {
    if (!ybh_is_contributor()) {
        return;
    }
    $user_id = get_current_user_id();
    $post_id = (int) get_transient('ybh_contrib_resent_' . $user_id);
    if (!$post_id) {
        return;
    }
    delete_transient('ybh_contrib_resent_' . $user_id);

    $link = get_edit_post_link($post_id);
    printf(
        '<div class="notice notice-warning is-dismissible"><p><strong>修改已提交审核。</strong>'
        . '稿件已回到「待审核」状态，<strong>审核通过前这篇文章会暂时从站点下线</strong>'
        . '（这样才能保证读者看到的都是审过的版本）。编辑通过后会自动重新上线。'
        . '%s</p></div>',
        $link ? ' <a href="' . esc_url($link) . '">继续修改</a>' : ''
    );
});

/* ---------------------------------------------------------------------------
 * 4) 编辑器里的「先说明白」提示
 *
 *   作者点「更新」之前就该知道这条规则，而不是按下去才发现文章下线了。
 *   只在「编辑已发布的文章 + 当前用户是投稿者」时显示。
 * ------------------------------------------------------------------------- */
add_action('post_submitbox_misc_actions', function () {
    $post = get_post();
    if (!$post instanceof WP_Post || 'post' !== $post->post_type) {
        return;
    }
    if ('publish' !== $post->post_status) {
        return;
    }
    if (!ybh_is_contributor() || (int) $post->post_author !== get_current_user_id()) {
        return;
    }
    echo '<div class="misc-pub-section" style="color:#996800">'
        . '<span class="dashicons dashicons-warning" style="color:#996800"></span> '
        . '<strong>更新后会重新进入审核</strong><br>'
        . '<span style="font-size:12px;line-height:1.6">'
        . '审核通过前，这篇文章会暂时从站点下线。改错别字也一样 —— 这是为了保证'
        . '读者看到的都是审过的版本。</span></div>';
});
