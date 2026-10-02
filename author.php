<?php

get_header();

/*
 * T62b：作者个人信息卡。
 *
 * 数据源：`inc/ybh/author-profile.php`（与前台资料页、搜索「搜人」共用同一份，
 * 一处查询三处读，避免字段漂移）。渲染：`tpl/author-card.php`。
 *
 * ⚠️ 这里原先还有一段内联 <style>（给头像右下角画「作品数」胶囊，
 * 选择器 `.author_info .avatar::after`）。它已被删除，原因有二：
 *   1. 它由模板输出，pjax 每次换页都会**重复注入**一次；
 *   2. 全站样式应当集中在一处 —— 那段规则与原样保留的观感都已经并入
 *      `css/ybh.css` 的 T62 段（含深色模式）。
 */
require_once get_template_directory() . '/inc/ybh/author-profile.php';
require_once get_template_directory() . '/tpl/author-card.php';

echo ybh_render_author_card((int) get_the_author_meta('ID'));
?>
<div id="primary" class="content-area">
    <main id="main" class="site-main" role="main">

        <?php if (have_posts()) : ?>
            <?php get_template_part('tpl/content', 'thumb'); ?>
            <div class="clearer"></div>
        <?php else : ?>
            <?php get_template_part('tpl/content', 'none'); ?>
        <?php endif; ?>

    </main><!-- #main -->
    <?php if (iro_opt('pagenav_style') == 'ajax') : ?>
        <div id="pagination"><?php next_posts_link(__(' Previous', 'sakurairo')); ?></div>
        <div id="add_post"><span id="add_post_time" style="visibility: hidden;" title="<?php echo esc_attr(iro_opt('page_auto_load', '')); ?>"></span></div>
    <?php else : ?>
        <?php
        /*
         * T62：作者页原来用 nav.navigator + 自己一份 paginate_links（与主页不是一套，
         * 用户反馈「卡片大小与分页器都跟主页不一样」）。现在统一到
         * ybh_render_pagination()（实现见 tpl/pagination.php）。
         *
         * 作者页 URL 形如 /author/system/page/2/ —— 由 get_pagenum_link() 按当前
         * 上下文自动处理，所以 base 留空走默认；锚点保持默认 #main，落在作品列表开头。
         */
        echo ybh_render_pagination(array(
            'total'   => (int) $wp_query->max_num_pages,
            'current' => max(1, (int) get_query_var('paged')),
            'label'   => sprintf(__('%s 的作品分页', 'sakurairo'), get_the_author()),
            'context' => 'author',
        ));
        ?>
    <?php endif; ?>
</div><!-- #primary -->

<?php
get_footer();
?>
