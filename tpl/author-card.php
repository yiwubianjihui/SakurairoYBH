<?php
/**
 * YBH · 作者信息卡（T62b）
 *
 * 用在作者归档页（`author.php`）头部：头像 / 显示名 / 昵称 / 作品数 /
 * 个人网站 / 社交链接 / 完整简介，外加一段 `Person` 结构化数据。
 *
 * ===================================================================
 * ⚠️ 为什么必须保留 `.author_info` / `.avatar` / `.author-center` / `.description`
 * ===================================================================
 *   主题核心 `style.css:1641-1710` 针对这几个选择器写了版式：
 *     · `.author_info { float:left; height:110px; max-width:70% }`
 *     · `.avatar::after` 用 `data-post-count` 属性画「作品数」小胶囊
 *     · `.description { max-height:20px; overflow:hidden }`（把简介裁成一行）
 *   结构一改，观感立刻崩。所以这里**沿用同一套 DOM**，只在外面加
 *   `ybh-author-card` 这一个新类，把新版式写在 `css/ybh.css` 的 T62 段里
 *   （`body.author .author_info.ybh-author-card …` 覆盖，不改 core）。
 *   简介被裁成一行的问题也在那一节里用 `max-height:none` 解掉。
 *
 * ===================================================================
 * SEO
 * ===================================================================
 *   作者页原本整页没有 `<h1>`（只有站头那个装饰性的 `YBH`），标题也缺作者名。
 *   这里把作者名做成 `<h1>`，并输出 `Person` JSON-LD（只写**真实存在**的字段，
 *   不编造）—— `sameAs` 收集个人网站与社交链接，供搜索引擎串联同一作者。
 *   注意：**不在本页输出 `rel="author"`**（那是指向作者页的链接该带的属性，
 *   自指没有意义；文章页里的 `rel="author"` 由 `inc/post_metas.php` 负责）。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once get_template_directory() . '/inc/ybh/author-profile.php';

if (!function_exists('ybh_render_author_card')) {
    /**
     * 渲染作者信息卡。
     *
     * @param int $uid 用户 ID。
     * @return string 已转义的 HTML；用户不存在时返回空串。
     */
    function ybh_render_author_card($uid)
    {
        $p = ybh_author_profile($uid);
        if (empty($p)) {
            return '';
        }

        $name  = (string) $p['display_name'];
        $nick  = (string) $p['nickname'];
        $bio   = (string) $p['bio'];
        $site  = (string) $p['site'];
        $count = (int) $p['post_count'];
        $links = array();

        // 个人网站也进 sameAs（去重交给同一个数组）
        if ('' !== $site) {
            $links[] = $site;
        }
        $social = (array) $p['social'];
        foreach ($social as $one) {
            if (!empty($one['url'])) {
                $links[] = (string) $one['url'];
            }
        }
        $links = array_values(array_unique(array_filter($links)));

        $out  = '<div class="author_info ybh-author-card">';

        // 头像：保留 `data-post-count`，core 的 `.avatar::after` 胶囊依赖它
        $out .= '<div class="avatar" data-post-count="' . $count . '">' . $p['avatar_html'] . '</div>';

        $out .= '<div class="author-center">';
        $out .= '<h1 class="author-name ybh-author-card__name">' . esc_html($name) . '</h1>';

        if ('' !== $nick && $nick !== $name) {
            $out .= '<p class="ybh-author-card__nick">'
                . sprintf(esc_html(function_exists('ybh_t') ? ybh_t('昵称：%s') : '昵称：%s'), esc_html($nick)) . '</p>';
        }

        $out .= '<p class="ybh-author-card__meta">'
            . sprintf(esc_html(function_exists('ybh_t') ? ybh_t('%d 篇作品') : '%d 篇作品'), $count) . '</p>';

        if ('' !== $site) {
            $out .= '<p class="ybh-author-card__site"><i class="fa-solid fa-link" aria-hidden="true"></i> '
                . '<a href="' . esc_url($site) . '" rel="me noopener" target="_blank">'
                . esc_html(preg_replace('#^https?://#i', '', untrailingslashit($site))) . '</a></p>';
        }

        if (!empty($social)) {
            $out .= '<ul class="ybh-author-social">';
            foreach ($social as $one) {
                $url  = isset($one['url']) ? (string) $one['url'] : '';
                $text = isset($one['text']) ? (string) $one['text'] : '';
                if ('' === $url && '' === $text) {
                    continue;   // 既没地址也没文字
                }
                $label = isset($one['label']) && '' !== $one['label']
                    ? (string) $one['label']
                    : esc_html(function_exists('ybh_t') ? ybh_t('链接') : '链接');
                $icon = isset($one['icon']) && '' !== $one['icon'] ? (string) $one['icon'] : '';

                if ('' === $url) {
                    // T61：微信号 / QQ 号这类**没有主页**的项 —— 渲染成纯文本标签，
                    // 不做成链接（否则就是一个点不动的死链）。
                    $out .= '<li><span class="ybh-author-social__text" title="' . esc_attr($label) . '">';
                    if ('' !== $icon) {
                        $out .= '<i class="' . esc_attr($icon) . '" aria-hidden="true"></i>';
                    }
                    $out .= '<span>' . esc_html($label) . '</span>';
                    $out .= '<b>' . esc_html($text) . '</b>';
                    $out .= '</span></li>';
                    continue;
                }

                $out .= '<li><a href="' . esc_url($url) . '" rel="me noopener" target="_blank">';
                if ('' !== $icon) {
                    $out .= '<i class="' . esc_attr($icon) . '" aria-hidden="true"></i>';
                }
                $out .= '<span>' . esc_html($label) . '</span></a></li>';
            }
            $out .= '</ul>';
        }

        // 简介：保留 `.description`（core 会裁成一行，T62 的 CSS 段已解掉）
        $out .= '<div class="description ybh-author-card__bio">';
        $out .= ('' !== $bio)
            ? wp_kses_post(nl2br($bio))
            : esc_html(function_exists('ybh_t') ? ybh_t('还没有填写个人简介。') : '还没有填写个人简介。');
        $out .= '</div>';

        $out .= '</div>';   // .author-center
        $out .= '</div>';   // .author_info

        // ---- 结构化数据：只写有值的字段，不编造 ----
        $person = array(
            '@context' => 'https://schema.org',
            '@type'    => 'Person',
            'name'     => $name,
            'url'      => (string) $p['url'],
        );
        if (!empty($p['avatar_url'])) {
            $person['image'] = (string) $p['avatar_url'];
        }
        if ('' !== $bio) {
            $person['description'] = wp_strip_all_tags($bio);
        }
        if (!empty($links)) {
            $person['sameAs'] = $links;
            $person['mainEntityOfPage'] = (string) $p['url'];
        }
        $out .= '<script type="application/ld+json">'
            . wp_json_encode($person, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '</script>';

        return $out;
    }
}
