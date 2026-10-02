<?php
/**
 * YBH · 全站统一分页器（T62）
 *
 * ===================================================================
 * 为什么要统一
 * ===================================================================
 *   本站原本有**四套**分页器，同一件「翻页」的事在四个页面长得不一样：
 *     · 主页     `index.php`   → `nav.traditional-pagination` + 跳页框，URL `/page/2/#main`
 *     · 作者页   `author.php`  → `nav.navigator` + `paginate_links()`，URL `/author/system/page/2/`
 *     · 归档页   `archive.php` → 只有上一页/下一页两个箭头
 *     · 搜索页   `search.php`  → `the_posts_pagination()`，URL `?paged=2&s=…`
 *   用户反馈「作者页用了另一种分页器」。这里把主页那套（样式最新、带跳页框）
 *   抽成唯一实现，四处共用。
 *
 * ===================================================================
 * 三个必须知道的坑（照着做，别再踩）
 * ===================================================================
 *   1) **`base` 与 `format` 用 WordPress 自己的默认**。
 *      `paginate_links()` 不传 `base` 时会自己算
 *      `str_replace(999999999, '%#%', get_pagenum_link(999999999))` —— 而
 *      `get_pagenum_link()` 是**认当前上下文**的：它会把 `?s=…`、分类、作者
 *      路径都带上，并自动去掉 `paged`。自己拼 `/page/%#%/` 会在搜索页丢掉
 *      `s`、在多语言/子目录站上丢掉前缀。只有确实要覆盖时才传 `base`。
 *   2) **`#main` 锚点只在 ≥2 页加**。
 *      第 1 页的地址是站根（`/page/1/` 会 301 回 `/`），浏览器会把锚点**带到
 *      重定向后的地址**上 —— 于是「回第 1 页」会直接跳到文章列表而不是页面
 *      顶端。这是写在 `index.php` 原注释里的真实教训，此处用同一套正则保留：
 *      先让 `paginate_links()` 正常生成，再回头给 `href` 补锚点。
 *   3) **跳页框不能假设 URL 形态**。
 *      老实现是 `home + '/page/N/'` 硬拼，在搜索页（`?paged=N&s=…`）是坏的。
 *      现在由 PHP 给出带 `%#%` 占位符的 `data-pattern`，JS 只做替换；
 *      同时表单里有 `name="paged"` 与隐藏参数 —— **没有 JS 时就是一次普通 GET，
 *      功能不退化成坏的**（老实现的输入框没有 `name`，所谓"降级可用"是假的）。
 *
 * ===================================================================
 * 用法
 * ===================================================================
 *   echo ybh_render_pagination( array(
 *       'total'   => (int) $wp_query->max_num_pages,
 *       'current' => max( 1, (int) get_query_var( 'paged' ) ),
 *       'label'   => __( '文章分页', 'sakurairo' ),
 *       'hidden'  => array( 's' => get_search_query() ),   // 只给"无 JS 降级"用
 *   ) );
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ybh_pagination_base')) {
    /**
     * 当前上下文的页码 URL 模板（含 `%#%` 占位符）。
     *
     * 直接用 `paginate_links()` 自己的默认算法，保证与 WordPress 各处一致。
     */
    function ybh_pagination_base()
    {
        return str_replace(999999999, '%#%', esc_url_raw(get_pagenum_link(999999999)));
    }
}

if (!function_exists('ybh_render_pagination')) {
    /**
     * 渲染统一分页器。
     *
     * @param array $args {
     *     @type int    $total    总页数（必需）。< 2 时整块不输出。
     *     @type int    $current  当前页，默认取 `paged` 查询变量。
     *     @type string $base     页码 URL 模板；留空用当前上下文默认（**推荐留空**）。
     *     @type string $format   与 `paginate_links()` 同名参数，默认 `?paged=%#%`。
     *     @type string $anchor   锚点，默认 `#main`；传空串表示不加锚点。
     *     @type array  $add_args 交给 `paginate_links()` 追加的查询参数（一般不需要）。
     *     @type array  $hidden   跳页表单里的隐藏字段（供无 JS 降级保留上下文）。
     *     @type string $label    `nav` 的无障碍名称与可视隐藏标题。
     *     @type bool   $jump     是否输出跳页框，默认 true。
     *     @type int    $jump_min 总页数 **大于** 该值才输出跳页框，默认 1（即 ≥2 页就有）。
     *     @type string $context  仅用于调试：会以 HTML 注释写在输出里。
     * }
     * @return string 已转义的 HTML；总页数不足时返回空串。
     */
    function ybh_render_pagination(array $args = array())
    {
        $a = array_merge(array(
            'total'    => 0,
            'current'  => max(1, (int) get_query_var('paged')),
            'base'     => '',
            'format'   => '?paged=%#%',
            'anchor'   => '#main',
            'add_args' => array(),
            'hidden'   => array(),
            'label'    => function_exists('ybh_t') ? ybh_t('文章分页') : __('文章分页', 'sakurairo'),
            'jump'     => true,
            'jump_min' => 1,
            'context'  => '',
        ), $args);

        $total = (int) $a['total'];
        if ($total < 2) {
            return '';
        }

        $base = ('' !== (string) $a['base']) ? (string) $a['base'] : ybh_pagination_base();
        if (false === strpos($base, '%#%')) {
            // 没有占位符就没法生成页码链接 —— 宁可退回 WordPress 默认，也不要输出坏链接
            $base = ybh_pagination_base();
        }
        $current = max(1, min($total, (int) $a['current']));
        $anchor  = (string) $a['anchor'];

        $links = paginate_links(array(
            'base'      => $base,
            'format'    => (string) $a['format'],
            'current'   => $current,
            'total'     => $total,
            'add_args'  => (array) $a['add_args'],
            'type'      => 'plain',
            'prev_text' => '<i class="fa-solid fa-angle-left"></i>',
            'next_text' => '<i class="fa-solid fa-angle-right"></i>',
        ));

        /*
         * 补 `#main` 锚点（见文件头坑 2）。正则同时认两种 URL 形态：
         *   /page/(\d+)/   与   paged=(\d+)
         * 只给 ≥2 页补，第 1 页保持"从页面顶端开始"。
         */
        if ('' !== $anchor) {
            $links = preg_replace_callback(
                '/href="([^"]*(?:\/page\/(\d+)\/|\bpaged=(\d+))[^"]*)"/',
                function ($m) use ($anchor) {
                    $num = (isset($m[2]) && '' !== $m[2]) ? (int) $m[2] : (int) (isset($m[3]) ? $m[3] : 0);
                    return 'href="' . $m[1] . ($num >= 2 ? $anchor : '') . '"';
                },
                (string) $links
            );
        }

        $out = '';
        if ('' !== (string) $a['context']) {
            $out .= "\n<!-- ybh-pagination: " . esc_html((string) $a['context']) . " total={$total} current={$current} -->\n";
        }

        $out .= '<nav class="traditional-pagination ybh-pagination" aria-label="' . esc_attr($a['label']) . '">';
        $out .= $links;

        if ($a['jump'] && $total > (int) $a['jump_min']) {
            /*
             * 第 1 页地址：`get_pagenum_link(1)` 是当前归档的"根"，
             * 表单 action 指向它 ⇒ 无 JS 提交时也不会 301 到别的路径。
             */
            $first = get_pagenum_link(1);

            $out .= '<form class="ybh-pagejump" method="get" action="' . esc_url($first) . '"'
                . ' data-total="' . (int) $total . '"'
                . ' data-home="' . esc_url($first) . '"'
                . ' data-pattern="' . esc_attr($base) . '"'
                . ' data-anchor="' . esc_attr($anchor) . '">';

            // 无 JS 降级：把上下文参数带过去（JS 路径不用它们，位置无害）
            foreach ((array) $a['hidden'] as $k => $v) {
                if ('' === (string) $k) {
                    continue;
                }
                foreach ((array) $v as $one) {
                    $out .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($one) . '" />';
                }
            }

            $out .= '<label class="ybh-pagejump-label" for="ybh-pagejump-input">'
                . esc_html(function_exists('ybh_t') ? ybh_t('跳至') : '跳至') . '</label>';
            // name="paged" 是关键：没有它，原生提交不带任何页码参数（老实现的 bug）
            $out .= '<input id="ybh-pagejump-input" class="ybh-pagejump-input" type="number"'
                . ' inputmode="numeric" name="paged" min="1" max="' . (int) $total . '"'
                . ' value="' . (int) $current . '"'
                . ' aria-label="' . esc_attr(function_exists('ybh_t') ? ybh_t('输入页码后回车跳转') : '输入页码后回车跳转') . '" />';
            $out .= '<span class="ybh-pagejump-total">/&nbsp;' . (int) $total . '</span>';
            $out .= '<button type="submit" class="ybh-pagejump-go">'
                . esc_html(function_exists('ybh_t') ? ybh_t('跳转') : '跳转') . '</button>';
            $out .= '</form>';
        }

        $out .= '</nav>';

        return $out;
    }
}
