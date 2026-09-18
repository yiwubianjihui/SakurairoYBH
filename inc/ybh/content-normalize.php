<?php
/**
 * YBH · 把「内容规范化」接到 TinyMCE 上（与 WangEditor 用同一套）
 *
 * ===================================================================
 * 为什么需要
 * ===================================================================
 *   用户反馈「TinyMCE 与 WangEditor 切换时会多出许多回车空行」以及
 *   「使用编辑器编辑后，页间距变得很小」。
 *
 *   规范化逻辑已经写在 `js/ybh-content.js`（`window.YBH_Content.normalize`），
 *   WangEditor 面板在**进出两个方向**都调它。但只做一边不够 ——
 *   TinyMCE 那一侧如果不同样归约，内容从 WangEditor 出来是干净的、
 *   经 TinyMCE 存一次又变脏，来回切几次照样累积。
 *
 * ⇒ 本文件把同一份 `ybh-content.js` 也挂进 TinyMCE 的编辑页（页面级），
 *   并通过 `ybh_para` 插件已有的钩子做两件事：
 *     · **载入后**（SetContent 完成）规范化一次；
 *     · **取内容时**（GetContent）规范化一次。
 *
 *   `ybh_para` 已经挂了 GetContent（清空段落），这里不重复挂 ——
 *   只补「载入后规范化」，并确保 `ybh-content.js` 在编辑器页可用。
 *
 * ===================================================================
 * 关闭方式：把 YBH_CONTENT_NORMALIZE 定义为 false。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_CONTENT_NORMALIZE')) {
    define('YBH_CONTENT_NORMALIZE', true);
}

/* 编辑页（含经典编辑器）加载规范化脚本 —— 两个编辑器共用 */
add_action('admin_enqueue_scripts', function () {
    if (!YBH_CONTENT_NORMALIZE) {
        return;
    }
    if (!function_exists('ybh_wangeditor_screen') || !ybh_wangeditor_screen()) {
        return;
    }

    wp_enqueue_script(
        'ybh-content',
        get_template_directory_uri() . '/js/ybh-content.js',
        array(),
        function_exists('ybh_asset_ver') ? ybh_asset_ver('js/ybh-content.js') : YBH_VERSION,
        true
    );

    // 让 WangEditor 面板脚本依赖它（保证 normalize 一定先可用）
    wp_script_add_data('ybh-wangeditor-panel', 'group', 1);
}, 5);
