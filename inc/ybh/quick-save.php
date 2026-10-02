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

/* ---------------------------------------------------------------------------
 * 前台编辑器保存端点（T64）
 *
 * 与上面那个「后台就地保存」的区别：
 *   · 后台那个**刻意不碰状态**（它只是 Ctrl+S 的就地保存）；
 *   · 前台编辑器要能「保存草稿 / 提交审核 / 发布」，所以这里**显式接受状态**，
 *     但用白名单 + 能力分流把住口子：没有 `publish_posts` 的账号永远只能是
 *     `draft` 或 `pending`，请求 `publish` 一律降级为 `pending`（与站点既有
 *     `inc/ybh/contributor-edit.php` 的意图一致：投稿者不能直接发布）。
 *   · `post_id = 0` 表示新建：先不建空文章（标题与正文都空就拒绝），
 *     避免用户一进编辑器就攒出一堆空草稿。
 *
 * 入参与上面那条一致：**原样传 `$_POST`**（不做 wp_unslash/wp_slash），
 * 与 `wp-admin/post.php` 的官方保存路径保持同一套转义语义。
 * ------------------------------------------------------------------------- */
add_action('wp_ajax_ybh_front_save', 'ybh_front_save_handler');
function ybh_front_save_handler()
{
    check_ajax_referer('ybh_front_save', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => '登录状态已失效，请重新登录后再保存。'), 401);
    }

    $post_id     = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $want        = isset($_POST['status']) ? sanitize_key((string) $_POST['status']) : 'draft';
    $can_publish = current_user_can('publish_posts');

    $allowed = array('draft', 'pending');
    if ($can_publish) {
        $allowed[] = 'publish';
    }
    $asked = $want;
    if (!in_array($want, $allowed, true)) {
        // 投稿者点了「发布」→ 降级为提交审核（而不是报错，体验更顺）
        $want = $can_publish ? 'draft' : 'pending';
    }

    $data = array();
    if (isset($_POST['post_title'])) {
        $data['post_title'] = (string) $_POST['post_title'];
    }
    if (isset($_POST['post_content'])) {
        $data['post_content'] = (string) $_POST['post_content'];
    }
    if (isset($_POST['post_excerpt'])) {
        $data['post_excerpt'] = (string) $_POST['post_excerpt'];
    }

    $created = false;

    if ($post_id <= 0) {
        /* ---------- 新建 ---------- */
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => '你的账号还没有写作权限。'), 403);
        }
        /*
         * 「空」的判定不能只看 `wp_strip_all_tags()`：编辑器里一个空格段落
         * 存下来是 `<p>&nbsp;</p>`，剥掉标签后还剩一个 `&nbsp;` 实体，
         * 于是"什么都没写"也会被当成有内容、建出一堆空草稿（校验时实测踩到）。
         * 这里先把实体解码、把不换行空格与全角空格也当空白，再 trim。
         */
        $plain = function ($html) {
            $t = wp_strip_all_tags((string) $html);
            $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $t = str_replace(array("\xC2\xA0", "\xE3\x80\x80"), ' ', $t);   // nbsp / 全角空格
            return trim($t);
        };
        $has_title = '' !== $plain(isset($data['post_title']) ? $data['post_title'] : '');
        $has_body  = '' !== $plain(isset($data['post_content']) ? $data['post_content'] : '');
        if (!$has_title && !$has_body) {
            wp_send_json_error(array('message' => '还没有内容可保存 —— 写点什么再试。'), 400);
        }

        $data['post_type']   = 'post';
        $data['post_status'] = $want;
        $data['post_author'] = get_current_user_id();

        $new = wp_insert_post($data, true);
        if (is_wp_error($new)) {
            wp_send_json_error(array('message' => $new->get_error_message() ? $new->get_error_message() : '创建文章失败'), 500);
        }
        $post_id = (int) $new;
        $created = true;
    } else {
        /* ---------- 更新已有 ---------- */
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(array('message' => '文章不存在（可能已被删除）'), 404);
        }
        if ('trash' === $post->post_status) {
            wp_send_json_error(array('message' => '这篇文章在回收站里，暂不保存'), 409);
        }
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => '你当前没有编辑这篇文章的权限'), 403);
        }

        /*
         * 已发布的文章 + 没有发布权 ⇒ 改动后退回「待审核」。
         * ⚠️ 不能顺着前端传的 `draft` 走：那会把一篇已发布文章直接下线成草稿
         * （前台立刻 404），这不是"改个错别字"的作者想要的。
         */
        if ('publish' === $post->post_status && !$can_publish) {
            $want = 'pending';
        }

        $data['ID'] = $post_id;
        if ($want !== $post->post_status) {
            $data['post_status'] = $want;
        }

        $res = wp_update_post($data, true);
        if (is_wp_error($res)) {
            wp_send_json_error(array('message' => $res->get_error_message() ? $res->get_error_message() : '保存失败'), 500);
        }
    }

    $fresh = get_post($post_id);
    $status = $fresh ? $fresh->post_status : $want;

    /*
     * T65：文章语言（地区字形）。字段出现才写 —— 没带这个字段的调用路径
     * （例如后台 Ctrl+S 就地保存）不会把已设的语言清空。
     */
    if (isset($_POST['ybh_lang']) && function_exists('ybh_post_language_save')) {
        ybh_post_language_save($post_id, wp_unslash((string) $_POST['ybh_lang']));
        $fresh = get_post($post_id);   // 语言不影响 post 字段，这里只是保持一致
    }

    /*
     * 分类与标签（前台写作页）。
     * 权限一律按 WordPress 自己的能力走，不另造一套：
     *   · `post_tag` 的分配能力是 `edit_posts`   —— 投稿者**有**；
     *   · `category` 的分配能力是 `manage_categories` —— 投稿者**没有**（服务端也挡一道，
     *     前端不显示只是体验，这里才是裁决）。
     * 同样只在该字段出现时处理；标签支持中英文逗号、顿号、分号分隔。
     */
    if (isset($_POST['ybh_tags'])) {
        $tax = get_taxonomy('post_tag');
        if ($tax && current_user_can($tax->cap->assign_terms)) {
            $raw = wp_unslash((string) $_POST['ybh_tags']);
            $names = array_values(array_filter(
                array_map('trim', preg_split('~[,，、;；]+~u', $raw)),
                function ($s) { return '' !== $s; }
            ));
            wp_set_post_tags($post_id, $names, false);
        }
    }
    if (isset($_POST['ybh_cats'])) {
        $tax = get_taxonomy('category');
        if ($tax && current_user_can($tax->cap->assign_terms)) {
            $ids = array_values(array_filter(array_map('intval', (array) $_POST['ybh_cats'])));
            // T68：分类**单选** —— 前端已是 radio；这里只取第一个，防其它提交路径多选
            $ids = array_slice($ids, 0, 1);
            if ($ids) {
                wp_set_post_categories($post_id, $ids, false);
            }
        }
    }

    /*
     * 多余空行：**保存时检查**（站长要求"提醒 + 一键清理"，不自动改）。
     *   · 每次保存都回一个 `blank_count`（被两个真实段落包夹的空段数）供编辑器提示；
     *   · 带 `ybh_clean=1` 时按**同一判据**清理已存内容，并把清理后的 HTML 回传，
     *     编辑器据此就地更新（用户不用手动刷新）。
     * 判据与 T58 迁移脚本完全一致 —— 见 inc/ybh/blank-line-guard.php 的说明。
     */
    $clean_removed = 0;
    if (isset($_POST['ybh_clean']) && '1' === (string) $_POST['ybh_clean'] && function_exists('ybh_blank_clean')) {
        $cur = get_post($post_id);
        if ($cur) {
            $cleaned = ybh_blank_clean((string) $cur->post_content);
            if ($cleaned['removed'] > 0) {
                global $wpdb;
                // 直接改库：这里只是"删掉被包夹的空段"，走 wp_update_post 会再次触发
                // 一整条保存链路（修订/钩子），而我们要清理的正是那条链路留下的产物。
                $wpdb->update($wpdb->posts, array('post_content' => $cleaned['html']), array('ID' => $post_id));
                clean_post_cache($post_id);
                $clean_removed = (int) $cleaned['removed'];
                $fresh = get_post($post_id);
            }
        }
    }

    $blank = function_exists('ybh_blank_scan')
        ? ybh_blank_scan($fresh ? (string) $fresh->post_content : '')
        : array('count' => 0, 'kept' => 0);

    // 让其它模块（缓存清理、通知、统计）能感知"前台保存"这件事
    do_action('ybh_front_saved', $post_id, $fresh, $created);

    if ('publish' === $status) {
        $message = $created ? '已发布' : '已更新并发布';
    } elseif ('pending' === $status) {
        $message = ('publish' === $asked && !$can_publish)
            ? '已提交审核（你的账号不能直接发布，需编辑审核通过）'
            : '已提交审核';
    } else {
        $message = $created ? '已保存为草稿' : '已保存';
    }
    if ($clean_removed > 0) {
        $message = '已清理 ' . $clean_removed . ' 处多余空行';
    }

    wp_send_json_success(array(
        'id'       => (int) $post_id,
        'status'   => $status,
        'message'  => $message,
        'modified' => $fresh ? $fresh->post_modified : '',
        'editUrl'  => function_exists('ybh_front_editor_url') ? ybh_front_editor_url($post_id) : '',
        'viewUrl'  => ('publish' === $status) ? (string) get_permalink($post_id) : '',
        'created'  => $created,
        // 多余空行检查结果（编辑器据此决定要不要显示「一键清理」）
        'blank_count' => (int) $blank['count'],
        'blank_kept'  => (int) $blank['kept'],
        'cleaned'     => $clean_removed,
        'html'        => ($clean_removed > 0 && $fresh) ? (string) $fresh->post_content : '',
    ));
}
