<?php
/**
 * YBH · 主页标签行
 *
 * 展示文章数最多的标签（默认前 20 个），纯文字超链接 + 前置标签图标；
 * 其余标签折叠在「显示更多」之后 —— 用 checkbox + CSS 展开，不依赖 JavaScript。
 *
 * 挂载点：index.php 的 primary 组件（见该文件 case 'primary'）。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('ybh_render_home_tag_row')) {

    /**
     * @param int $visible 默认展示数量
     * @param int $max     最多取多少个（防止标签极多时渲染过量）
     */
    function ybh_render_home_tag_row($visible = 30, $max = 160)
    {
        $tags = get_tags(array(
            'orderby'    => 'count',
            'order'      => 'DESC',
            'number'     => $max,
            'hide_empty' => true,
        ));

        if (is_wp_error($tags) || empty($tags)) {
            return;
        }

        $shown = array_slice($tags, 0, $visible);
        $rest  = array_slice($tags, $visible);
        $uid   = 'ybh-tag-toggle';
        ?>
        <section class="ybh-tag-row" aria-label="标签">
            <?php if (!empty($rest)) : ?>
                <input type="checkbox" id="<?php echo esc_attr($uid); ?>" class="ybh-tag-toggle" hidden>
            <?php endif; ?>

            <span class="ybh-tag-row-icon" aria-hidden="true">
                <i class="fa-solid fa-tags"></i>
            </span>

            <span class="ybh-tag-list">
                <?php foreach ($shown as $tag) : ?>
                    <a class="ybh-tag" href="<?php echo esc_url(get_tag_link($tag->term_id)); ?>"
                       title="<?php echo esc_attr($tag->name . '（' . $tag->count . ' 篇）'); ?>"><?php
                        echo esc_html($tag->name);
                    ?></a>
                <?php endforeach; ?>
                <?php foreach ($rest as $tag) : ?>
                    <a class="ybh-tag ybh-tag-extra" href="<?php echo esc_url(get_tag_link($tag->term_id)); ?>"
                       title="<?php echo esc_attr($tag->name . '（' . $tag->count . ' 篇）'); ?>"><?php
                        echo esc_html($tag->name);
                    ?></a>
                <?php endforeach; ?>
                <?php if (!empty($rest)) : ?>
                    <?php /* 「显示更多」放在列表内部末尾，跟随最后一个标签流动，
                             而不是独立 flex item 飘到行右侧（那样与标签流脱节）。
                             checkbox 在列表外，用 for 关联，展开仍由纯 CSS 完成。 */ ?>
                    <label for="<?php echo esc_attr($uid); ?>" class="ybh-tag-more">
                        <span class="ybh-tag-more-open">显示更多<i class="fa-solid fa-angle-down"></i></span>
                        <span class="ybh-tag-more-close">收起<i class="fa-solid fa-angle-up"></i></span>
                    </label>
                <?php endif; ?>
            </span>
        </section>
        <?php
    }
}
