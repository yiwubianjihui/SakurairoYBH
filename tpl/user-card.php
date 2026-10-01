<?php
/**
 * YBH · 用户卡片（T60 渲染层）
 *
 * 入口只有一个：`ybh_render_user_card( array $u ): string`
 * 数据来自 `inc/ybh/user-search.php` 的 `ybh_user_search_item()`。
 *
 * -------------------------------------------------------------------
 * 为什么单独一个 tpl 文件
 * -------------------------------------------------------------------
 *   · 搜人结果、作者页、将来的"推荐作者"都要用同一张卡 —— 一处改，处处变；
 *   · 下游任务（CSS / 短代码）依赖的契约就是这个文件路径 + `ybh-user-card` 这个 class。
 *
 * 容器结构（后续 CSS 任务针对这些 class 写样式，本文件**不含任何 CSS**）：
 *
 *   <article class="ybh-user-card">
 *     <a class="ybh-user-card__avatar">…</a>
 *     <div class="ybh-user-card__main">
 *       <h3 class="ybh-user-card__name">…</h3>
 *       <p  class="ybh-user-card__bio">…</p>
 *       <p  class="ybh-user-card__meta">…</p>
 *     </div>
 *   </article>
 *
 * -------------------------------------------------------------------
 * 三条纪律
 * -------------------------------------------------------------------
 *   1. 🔴 **绝不输出邮箱**。`$u` 里根本没有 `user_email` 这个键（数据层不取），
 *      这里也不做任何"字段遍历输出"的花活 —— 只输出下面写明的白名单字段。
 *      将来有人往 item 里加字段，也必须显式加进来才会显示，不会因为数组变了就漏出去。
 *   2. 所有文本 `esc_html`、URL `esc_url`、属性 `esc_attr`。
 *      bio 在数据层只做了截断、没转义，转义**只在这里发生一次**（避免 `&amp;amp;`）。
 *   3. `post_count` 显示成「N 篇作品」，不是「N 篇文章」—— 本站把投稿也叫"作品"。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ybh_render_user_card')) {
    /**
     * 渲染一张用户卡。
     *
     * @param array $u 见 `ybh_user_search_item()`：
     *                 id / display_name / nickname / avatar_url / bio / url / post_count / roles
     * @return string 已转义的 HTML；数据明显不对时返回空串（宁可少一张卡，不要半张坏卡）
     */
    function ybh_render_user_card(array $u): string
    {
        $id = isset($u['id']) ? (int) $u['id'] : 0;
        if ($id <= 0) {
            return '';
        }

        $name = isset($u['display_name']) ? (string) $u['display_name'] : '';
        if ($name === '' && isset($u['nickname'])) {
            $name = (string) $u['nickname'];
        }
        if ($name === '') {
            $name = '匿名作者';
        }

        $url = isset($u['url']) ? (string) $u['url'] : '';
        if ($url === '') {
            // author.php 一定在，兜一条，免得整张卡没有可点的目标
            $url = (string) get_author_posts_url($id);
        }

        $avatar = isset($u['avatar_url']) ? (string) $u['avatar_url'] : '';
        if ($avatar === '') {
            // 本站头像已被 inc/ybh/avatar.php 接管；这里只是数据层没给值时兜底
            $avatar = (string) get_avatar_url($id, array('size' => 96));
        }

        $bio       = isset($u['bio']) ? (string) $u['bio'] : '';
        $postCount = isset($u['post_count']) ? (int) $u['post_count'] : 0;

        // 社交链接：T61 现在必然返回空数组 ⇒ 下面整段不输出（不留空壳节点）
        $social = function_exists('ybh_user_search_social') ? ybh_user_search_social($id) : array();

        ob_start();
        ?>
<article class="ybh-user-card" data-user-id="<?php echo esc_attr((string) $id); ?>">
    <a class="ybh-user-card__avatar" href="<?php echo esc_url($url); ?>" title="<?php echo esc_attr($name); ?>">
        <img src="<?php echo esc_url($avatar); ?>" alt="<?php echo esc_attr($name); ?>" width="96" height="96" loading="lazy" decoding="async" />
    </a>
    <div class="ybh-user-card__main">
        <h3 class="ybh-user-card__name">
            <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($name); ?></a>
        </h3>
        <?php if ($bio !== '') : ?>
            <p class="ybh-user-card__bio"><?php echo esc_html($bio); ?></p>
        <?php endif; ?>
        <p class="ybh-user-card__meta">
            <span class="ybh-user-card__posts"><?php echo esc_html($postCount . ' 篇作品'); ?></span>
            <?php if ($social) : ?>
                <span class="ybh-user-card__social">
                    <?php foreach ($social as $link) : ?>
                        <?php
                        if (!is_array($link)) {
                            continue;
                        }
                        $linkUrl = isset($link['url']) ? (string) $link['url'] : '';
                        $linkTxt = isset($link['text']) ? (string) $link['text'] : '';
                        if ($linkUrl === '' && $linkTxt === '') {
                            continue; // 既没有地址也没有文字，跳过
                        }
                        $label = isset($link['label']) ? (string) $link['label'] : '链接';
                        // icon 是可选的 FontAwesome class，只能放进 class 属性 ⇒ 用 esc_attr
                        $icon = isset($link['icon']) ? (string) $link['icon'] : '';
                        if ($linkUrl === '') :
                            // T61：微信号 / QQ 号这类**没有主页**的项 —— 渲染成纯文本标签，
                            // 不做成链接（否则就是一个点不动的死链）。作者页信息卡同理。
                            ?>
                        <span class="ybh-user-card__social-link is-text" title="<?php echo esc_attr($label); ?>">
                            <?php if ($icon !== '') : ?><i class="<?php echo esc_attr($icon); ?>" aria-hidden="true"></i><?php endif; ?>
                            <span class="ybh-user-card__social-label"><?php echo esc_html($label); ?></span>
                            <span class="ybh-user-card__social-value"><?php echo esc_html($linkTxt); ?></span>
                        </span>
                            <?php
                            continue;
                        endif;
                        ?>
                        <a class="ybh-user-card__social-link" href="<?php echo esc_url($linkUrl); ?>"
                           rel="nofollow noopener" target="_blank" title="<?php echo esc_attr($label); ?>">
                            <?php if ($icon !== '') : ?><i class="<?php echo esc_attr($icon); ?>" aria-hidden="true"></i><?php endif; ?>
                            <span class="ybh-user-card__social-label"><?php echo esc_html($label); ?></span>
                        </a>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        </p>
    </div>
</article>
        <?php
        /**
         * 过滤单张用户卡的 HTML。
         *
         * ⚠️ 拿到的 `$u` 里没有 `user_email`（数据层就不取），
         * 所以这里再怎么拼也不会漏邮箱；但也不要自己去查邮箱拼进来。
         *
         * @param string $html
         * @param array  $u
         * @param array  $social
         */
        return (string) apply_filters('ybh_user_card_html', (string) ob_get_clean(), $u, $social);
    }
}
