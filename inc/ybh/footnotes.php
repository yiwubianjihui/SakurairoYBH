<?php
/**
 * YBH · 脚注（`[fn]注释文字[/fn]`）
 *
 * 需求：为编辑器添加脚注功能（任务清单第 6 项）。
 * 方案：**不装插件**，主题自建一个短代码 + 文末注释列表；编辑器侧由
 *       `js/ybh-editor.js` 提供按钮与「可视化」显示（见该文件头部说明）。
 *
 * ---------------------------------------------------------------
 * 写作语法（内联式，写作最顺手）
 *
 *     ……正文引用某结论[fn]此处放注释文字，可含 <a href="…">链接</a>。[/fn]，后文继续。
 *
 *   · 编号**自动生成**（按正文出现顺序 1、2、3…），不需要手写；
 *   · 脚注文字建议写在同一行内（换行会被 HTML 折叠成空格）；
 *   · 脚注里再写 `[fn]` 不会嵌套，按普通文本处理（只做单层）。
 *
 *  ⚠️ 手写时**必须成对**。若漏掉 `[/fn]`，WordPress 会把 `[fn]` 当成「自闭合
 *     短代码」调用一次本处理器，此时内容为空 ⇒ 什么都不输出，看上去就是
 *     标记凭空消失、后面的文字照旧（实测确认）。所以请尽量用编辑器工具栏的
 *     脚注按钮插入 —— 它保证生成成对的 `[fn]…[/fn]`。
 *
 * ---------------------------------------------------------------
 * 渲染管线（三步，顺序有讲究）
 *
 *   1. `the_content` prio **1**  —— 清空本轮收集器（必须早于一切）
 *   2. 短代码 `[fn]` 由 `do_shortcode`（`the_content` prio **11**）触发
 *      —— 晚于 `wpautop`(10)，避免注释文字里的换行被重复包段
 *   3. `the_content` prio **12** —— 把文末 `<section class="ybh-footnotes">`
 *      追加到正文末尾（12 > 11，此时所有上标都已渲染完）
 *
 *   摘要/归档页不会出现残字：`[fn]` 是**已注册的短代码**，`wp_trim_excerpt()`
 *   里的 `strip_shortcodes()` 会连同内容一起剥掉（实测见 T31 验收）。
 *
 * ---------------------------------------------------------------
 * 与 T32 的约定
 *
 *   存量文章里那 4 处 Gutenberg `core/footnotes` 块，迁移目标就是本语法：
 *   `[fn]注释文字[/fn]`。迁移后渲染结果与本模块完全一致。
 *
 * ---------------------------------------------------------------
 * 样式：`css/ybh.css` 第 12 节（`.ybh-fnref` / `.ybh-footnotes` / `:target` 高亮）
 *      编辑器内的可视化样式在 `css/editor-style.css`（`.ybh-fn-chip`）。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 本轮 `the_content` 渲染中收集到的脚注文字（按出现顺序）。
 * 每次渲染前由 `ybh_fn_reset()` 清空，渲染完由 `ybh_fn_append()` 取走。
 */
$GLOBALS['ybh_fn_items'] = array();

/**
 * 1) 渲染前清空收集器。
 *
 * 优先级 1：必须早于 `wpautop`(10) / `do_shortcode`(11) / 我们的追加(12)。
 * 用函数而不是直接赋值，是为了让「同一页渲染多篇文章」时互不串味。
 */
add_filter('the_content', 'ybh_fn_reset', 1);
function ybh_fn_reset($content)
{
    $GLOBALS['ybh_fn_items'] = array();
    return $content;
}

/**
 * 把一个脚注渲染成正文里的上标锚点，并登记进本轮收集器。
 *
 * @param string $text 脚注文字（可含 HTML）
 * @return string `<sup class="ybh-fnref">…</sup>`
 */
function ybh_fn_render_ref($text)
{
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    $items   = isset($GLOBALS['ybh_fn_items']) ? (array) $GLOBALS['ybh_fn_items'] : array();
    $items[] = $text;
    $n       = count($items);
    $GLOBALS['ybh_fn_items'] = $items;

    return sprintf(
        '<sup class="ybh-fnref" id="fnref-%1$d"><a href="#fn-%1$d" role="doc-noteref"'
        . ' aria-label="%2$s">%1$d</a></sup>',
        $n,
        esc_attr(sprintf('跳转到第 %d 条注释', $n))
    );
}

/**
 * 2) 短代码本体：`[fn]注释文字[/fn]` → 上标锚点。
 *
 * 用注册短代码（而不是自己在 `the_content` 里正则替换）的好处：
 *   · `strip_shortcodes()` 会自动把它从摘要里剥掉，归档页不留残字；
 *   · 在任何 `do_shortcode()` 生效的地方（小工具、页面构建器）都能用。
 */
add_shortcode('fn', 'ybh_fn_shortcode');
function ybh_fn_shortcode($atts, $content = '')
{
    return ybh_fn_render_ref($content);
}

/**
 * 2.5) 兜底：把残留在正文里的编辑器可视化标记 `<span class="ybh-fn-chip">`
 *      也当成脚注渲染。
 *
 * 正常情况下 `js/ybh-editor.js` 会在保存前把它换回 `[fn]…[/fn]`（`GetContent`），
 * 但万一日后编辑器换版、或有人直接往数据库里塞了可视化 HTML，正文里的脚注
 * 也不该凭空消失 —— 这一层保证「最坏情况也只是样式标记没被消化」。
 *
 * 优先级 11：晚于 `do_shortcode`(11，同优先级按注册顺序，本文件后加载)，
 * 早于追加列表的 12。
 */
add_filter('the_content', 'ybh_fn_rescue_chips', 11);
function ybh_fn_rescue_chips($content)
{
    if (strpos($content, 'ybh-fn-chip') === false) {
        return $content;
    }
    return preg_replace_callback(
        '#<span[^>]*class="[^"]*\bybh-fn-chip\b[^"]*"[^>]*>(.*?)</span>#s',
        function ($m) {
            return ybh_fn_render_ref($m[1]);
        },
        $content
    );
}

/**
 * 3) 文末追加注释列表（只有真的出现过脚注才输出）。
 *
 * 结构对齐 DPUB-ARIA：`role="doc-endnotes"` / `doc-backlink`，
 * 屏幕阅读器能把上标和注释正确关联。
 */
add_filter('the_content', 'ybh_fn_append', 12);
function ybh_fn_append($content)
{
    $items = isset($GLOBALS['ybh_fn_items']) ? (array) $GLOBALS['ybh_fn_items'] : array();
    $GLOBALS['ybh_fn_items'] = array();   // 取走即清空，避免重复追加

    if (empty($items)) {
        return $content;
    }

    $out  = "\n" . '<section class="ybh-footnotes" role="doc-endnotes">' . "\n";
    $out .= '<h2 class="ybh-fn-title">注释</h2>' . "\n";
    $out .= '<ol class="ybh-fn-list">' . "\n";
    foreach (array_values($items) as $i => $text) {
        $n = $i + 1;
        $out .= sprintf(
            '<li id="fn-%1$d" class="ybh-fn-item">%2$s'
            . ' <a class="ybh-fn-back" href="#fnref-%1$d" role="doc-backlink"'
            . ' aria-label="%3$s">↩</a></li>' . "\n",
            $n,
            $text,
            esc_attr(sprintf('返回第 %d 处正文', $n))
        );
    }
    $out .= '</ol>' . "\n</section>\n";

    return $content . $out;
}

/**
 * 4) 摘要双保险。
 *
 * `wp_trim_excerpt()` 里的 `strip_shortcodes()` 已能剥掉 `[fn]…[/fn]`，
 * 但那一步只在「没有手写摘要」时走；作者若手写摘要并粘贴了 `[fn]`，
 * 或第三方插件用 `get_the_excerpt` 的其它路径，就可能漏网。
 * 这里对摘要再做一道正则清洗（不依赖短代码注册状态）。
 */
add_filter('get_the_excerpt', 'ybh_fn_strip_from_excerpt', 9);
add_filter('the_excerpt', 'ybh_fn_strip_from_excerpt', 9);
function ybh_fn_strip_from_excerpt($text)
{
    return preg_replace('#\[fn\](.*?)\[/fn\]#s', '', (string) $text);
}
