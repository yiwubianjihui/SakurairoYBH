<?php
/**
 * YBH · MathJax 的 pjax 补课（T68）
 *
 * ===================================================================
 * 背景事实（全部实测）
 * ===================================================================
 *   主题上游自带 MathJax 支持（`iro_opt('enable_theme_mathjax')`，本站**开着**）：
 *   app.js 在页面初始加载时检测正文里的公式定界符，有就动态加载 js/4247.js
 *   （MathJax 3.2.2 本地包）并排版。**直开一篇含公式的文章完全正常**（实测 4 个
 *   公式全部渲染成 mjx-container）。
 *
 *   缺口在 pjax：换页只替换 `#page`，app.js 的检测不会重跑 ——
 *     · 从别的页面 pjax 点进数学文章：MathJax 库还在内存里，但没人调
 *       `typesetPromise` ⇒ 公式以原始 TeX 文本显示；
 *     · 会话第一页没有公式（库从未加载）⇒ 之后 pjax 进数学文章连库都没有。
 *
 * 本模块只做一件事：挂一支很小的脚本（js/ybh-mathjax.js），在 pjax 换页后
 * 重新排版 / 按需懒加载。主题选项关着时一个字节都不下。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', function () {
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }
    // 与上游同一开关；选项关着 = 站长不要公式排版，这里也不补课
    if (!function_exists('iro_opt') || !iro_opt('enable_theme_mathjax', true)) {
        return;
    }
    wp_enqueue_script(
        'ybh-mathjax',
        get_template_directory_uri() . '/js/ybh-mathjax.js',
        array(),
        defined('YBH_VERSION') ? YBH_VERSION : null,
        true
    );
}, 20);
