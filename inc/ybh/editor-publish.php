<?php
/**
 * YBH · 编辑器「发布」端点（WangEditor 面板里的「发布」按钮）
 *
 * ===================================================================
 * 需求
 * ===================================================================
 *   用户要求「为 WangEditor 增加同款发布功能」——
 *   编辑器面板里已经有了「保存文章」（走就地保存，只存内容、不改状态），
 *   还缺一个**直接发布**的按钮。
 *
 * ===================================================================
 * 与「保存文章」的分工
 * ===================================================================
 *   · `ybh_quick_save`（已有）—— 只改标题/正文/摘要，**不碰 post_status**。
 *     这是刻意的：它要让"按 Ctrl+S 随手存一下"绝对安全，
 *     不会把草稿意外发出去。所以它不能兼任发布。
 *   · `ybh_publish`（本文件）—— 明确地把文章**置为已发布**。
 *     两个动作分开，作者点哪个心里有数。
 *
 * ===================================================================
 * ★ 权限：沿用 WordPress 自己的判断，不另造一套
 * ===================================================================
 *   · 发布**已发布过的**文章 → `publish_posts`（或 `edit_published_posts`）；
 *   · 首次把草稿发出去      → `publish_posts`；
 *   · 另外始终要求 `edit_post`（能不能编辑这一篇）。
 *
 *   ⚠️ 本站有 `inc/ybh/contributor-edit.php`：投稿者**没有** `publish_posts`，
 *      而且那边会把 `publish` 强制回落到 `pending`。所以投稿者点「发布」时
 *      这里会**先被 `current_user_can('publish_posts')` 挡住**，回一句清楚的提示，
 *      而不是让他提交后又被静默改成待审（那样更让人困惑）。
 *
 * ===================================================================
 * 关闭方式：把 YBH_WANG_PUBLISH 定义为 false。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_WANG_PUBLISH')) {
    define('YBH_WANG_PUBLISH', true);
}

add_action('wp_ajax_ybh_publish', 'ybh_publish_handler');
function ybh_publish_handler()
{
    if (!YBH_WANG_PUBLISH) {
        wp_send_json_error(array('message' => '发布功能已关闭。'), 403);
    }

    check_ajax_referer('ybh_wangeditor_publish', 'nonce');

    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    if (!$post_id) {
        wp_send_json_error(array('message' => '缺少文章 ID。'), 400);
    }

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(array('message' => '文章不存在。'), 404);
    }

    // ① 能不能编辑这一篇
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => '你没有编辑这篇的权限。'), 403);
    }

    // ② 能不能发布 —— 这是与"保存草稿"最关键的区别
    if (!current_user_can('publish_posts')) {
        wp_send_json_error(array(
            'message' => '你的账号没有发布权限，请先「保存文章」，再由编辑审核发布。',
            'code'    => 'cannot_publish',
        ), 403);
    }

    // 回收站里的不能发
    if ('trash' === $post->post_status) {
        wp_send_json_error(array('message' => '这篇在回收站里，先恢复再发布。'), 400);
    }

    // 标题/正文/摘要一起带上，避免"发布了但内容是旧的"
    $data = array('ID' => $post_id);

    if (isset($_POST['post_title'])) {
        $data['post_title'] = wp_strip_all_tags(wp_unslash($_POST['post_title']));
    }
    if (isset($_POST['post_content'])) {
        // 走 WP 官方管线：KSES、修订都会照常处理
        $data['post_content'] = wp_unslash($_POST['post_content']);
    }
    if (isset($_POST['post_excerpt'])) {
        $data['post_excerpt'] = wp_unslash($_POST['post_excerpt']);
    }

    // 标题为空时不发布 —— 空标题的文章发出去很难看，也让作者以为出错了
    $title = isset($data['post_title']) ? trim($data['post_title']) : trim(get_the_title($post_id));
    if ('' === $title) {
        wp_send_json_error(array('message' => '标题是空的，先填个标题再发布。'), 400);
    }

    // 内容是空的同样拦一下（正文为空的文章发出去没有意义）
    $content = isset($data['post_content']) ? $data['post_content'] : $post->post_content;
    if ('' === trim(wp_strip_all_tags($content))) {
        wp_send_json_error(array('message' => '正文是空的，先写点内容再发布。'), 400);
    }

    $data['post_status'] = 'publish';

    // 首次发布时补上发布时间（草稿的 post_date 可能是创建时间）
    if ('publish' !== $post->post_status) {
        $data['edit_date'] = true;
    }

    $result = wp_update_post($data, true);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()), 500);
    }

    $fresh = get_post($post_id);

    wp_send_json_success(array(
        'id'      => $post_id,
        'status'  => $fresh->post_status,
        'link'    => get_permalink($post_id),
        'human'   => human_time_diff(strtotime($fresh->post_date), current_time('timestamp')) . '前',
        'message' => '已发布',
    ));
}

/* ---------------------------------------------------------------------------
 * 把发布所需的 nonce 一起交给面板脚本
 *
 * ⚠️ 优先级 25：必须晚于 wangeditor.php 的入队（优先级 20），
 * 否则 wp_localize_script 静默失效（这个坑踩过一次，见 editor-default.php 注释）。
 * ------------------------------------------------------------------------- */
add_action('admin_enqueue_scripts', function () {
    if (!YBH_WANG_PUBLISH) {
        return;
    }
    if (!function_exists('ybh_wangeditor_screen') || !ybh_wangeditor_screen()) {
        return;
    }
    if (!wp_script_is('ybh-wangeditor-panel', 'enqueued')) {
        return;
    }
    wp_localize_script('ybh-wangeditor-panel', 'YBH_WANG_PUBLISH_CFG', array(
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('ybh_wangeditor_publish'),
        'canPublish' => current_user_can('publish_posts') ? 1 : 0,
    ));
}, 26);
