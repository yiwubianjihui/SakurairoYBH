<?php
/**
 * YBH · 经典编辑器「就地保存」AJAX 端点（任务清单第 3 项）
 *
 * 为什么需要它：
 *   WordPress 经典编辑器里，Ctrl+S 的行为在两个标签页下完全不同 ——
 *   · 文本标签：wp-admin/js/post.js 绑了 Ctrl+S → wp.autosave.server.triggerSave()，
 *     而 autosave **只对草稿生效**，已发布文章上等于什么都没发生；
 *   · 可视化标签：没有任何处理器，Ctrl+S 直接落到浏览器的"另存为"。
 *   作者体感就是「按了没反应」或「莫名其妙退出编辑」。更糟的是：**静默失败**，
 *   作者根本不知道内容有没有存上。
 *
 * 本端点提供一条确定、就地、可反馈的保存路径：
 *   · 只写编辑器直接编辑的三个字段（标题/正文/摘要），
 *     **不碰** post_status / post_author / post_date / 分类 / 标签 —— 因此不会触发
 *     WP 的任务流跳转逻辑（这正是"跳回列表页"的根源）；
 *   · 唯一例外：`auto-draft`（刚打开的新文章）顺手转成 `draft`，
 *     与 WP 自带 autosave 的行为一致 —— 否则新文章的内容在"草稿"里看不见；
 *   · 交给 `wp_update_post()` 走 WP 官方管线：权限、KSES、修订（revision）都照常。
 *     入参不做 wp_unslash/wp_slash 处理 —— **与 wp-admin/post.php 的官方保存路径完全一致**
 *     （那条路径也是把 $_POST 原样交给 edit_post()），避免自己引入转义差异。
 *
 * 安全：nonce + `edit_post` 能力校验 + 拒绝回收站文章；只对已登录用户开放。
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_ybh_quick_save', 'ybh_quick_save_handler');
function ybh_quick_save_handler()
{
    check_ajax_referer('ybh_quick_save', 'nonce');

    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    if ($post_id <= 0) {
        wp_send_json_error(array('message' => '缺少文章 ID'), 400);
    }

    $post = get_post($post_id);
    if (!$post) {
        wp_send_json_error(array('message' => '文章不存在（可能已被删除）'), 404);
    }
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => '你当前没有编辑这篇文章的权限'), 403);
    }
    if ('trash' === $post->post_status) {
        wp_send_json_error(array('message' => '这篇文章在回收站里，暂不保存'), 409);
    }

    // 只取编辑器真正在编辑的字段；与官方保存路径一致，原样传递（不自行 slashes 处理）
    $data = array('ID' => $post_id);

    if (isset($_POST['post_title'])) {
        $data['post_title'] = (string) $_POST['post_title'];
    }
    if (isset($_POST['post_content'])) {
        $data['post_content'] = (string) $_POST['post_content'];
    }
    if (isset($_POST['post_excerpt'])) {
        $data['post_excerpt'] = (string) $_POST['post_excerpt'];
    }

    // 新文章（auto-draft）→ 草稿：与 WP 自带 autosave 一致，避免内容"存了但看不到"
    $status_changed = false;
    if ('auto-draft' === $post->post_status) {
        $data['post_status'] = 'draft';
        $status_changed = true;
    }

    if (count($data) < 2) {
        wp_send_json_error(array('message' => '没有可保存的内容'), 400);
    }

    $result = wp_update_post($data, true);   // 第二个参数 true：失败返回 WP_Error
    if (is_wp_error($result)) {
        wp_send_json_error(array(
            'message' => $result->get_error_message() ? $result->get_error_message() : '保存失败',
        ), 500);
    }

    $fresh = get_post($post_id);

    // 触发器：让其它模块（如统计、缓存清理）能感知"就地保存"这件事
    do_action('ybh_quick_saved', $post_id, $fresh);

    wp_send_json_success(array(
        'id'        => (int) $post_id,
        'modified'  => $fresh ? $fresh->post_modified : '',
        'human'     => date_i18n('H:i:s'),
        'status'    => $fresh ? $fresh->post_status : '',
        'new_post'  => $status_changed,
        'title_len' => mb_strlen((string) $fresh->post_title),
        'content_len' => mb_strlen((string) $fresh->post_content),
        'message'   => $status_changed ? '已保存为新草稿' : '已保存',
    ));
}
