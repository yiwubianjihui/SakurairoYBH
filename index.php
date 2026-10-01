<?php
get_header();

// T33 第 5 项：首页行动按钮（投稿 / 加入 / 全部文章 / 赞助）
//  ① ybh_render_home_cta()        内容最顶端的一排按钮卡片（各尺寸通用）
//  ② ybh_render_mobile_actions()  手机端常驻吸底操作条（≥861px 由 CSS 隐藏）——
//     因为封面是整屏高，上面那排在手机上落在折线之下。
//  两者都只在首页输出（函数内部已用 is_home()/is_front_page() 判定）。
ybh_render_home_cta();
ybh_render_mobile_actions();

// 获取组件顺序数据
$component_order = iro_opt('homepage_components',[]) ? iro_opt('homepage_components',[]) : array();

// 按顺序动态渲染组件
foreach ($component_order as $component) {
    switch ($component) {
        // 静态页面
        case 'static_page':
            $static_page_id = iro_opt("static_page_id");
            if ($static_page_id && ($static_page = get_post($static_page_id))) :
                ?>
                <section class="custom-static-section">
                    <h1 class="main-title static-page-title">
                        <?php if (!(strpos(get_the_title($static_page), "_")===0)){
                            # '_'开头的静态页面不显示标题
                         echo esc_html(get_the_title($static_page));
                        }
                         ?>
                    </h1>
                    <div class="static-page-content">
                        <?php echo apply_filters('the_content', $static_page->post_content); ?>
                    </div>
                </section>
            <?php
            endif;
            break;
            
        // 特色区域
        case 'exhibition':
                get_template_part('exhibition');
            break;

        // 文章列表
        case 'primary':
            // YBH：主页标签行（文章数最多的前 20 个 + 「显示更多」折叠）
            ybh_render_home_tag_row();
            ?>
            <div id="primary" class="content-area">
                <main id="main" class="site-main" role="main">
                    <h1 class="main-title posts-area-title">
                        <i class="<?php echo esc_attr(iro_opt('post_area_icon', 'fa-regular fa-bookmark')); ?>" aria-hidden="true"></i>
                        <?php echo esc_html(iro_opt('post_area_title', '文章列表')); ?>
                    </h1>

                    <?php if (have_posts()) : ?>
                        <?php if (is_home() && !is_front_page()) : ?>
                            <header class="archive-header">
                                <h1 class="page-title screen-reader-text"><?php single_post_title(); ?></h1>
                            </header>
                        <?php endif; ?>

                        <?php get_template_part('tpl/content', 'thumb'); ?>

                    <?php else : ?>
                        <?php get_template_part('tpl/content', 'none'); ?>
                    <?php endif; ?>
                </main>

                <?php if (iro_opt('pagenav_style') == 'ajax') : ?>
                    <div id="pagination"><?php next_posts_link(__(' Previous', 'sakurairo')); ?></div>
                    <div id="add_post">
                        <span id="add_post_time" style="visibility: hidden;" 
                            title="<?php echo esc_attr(iro_opt('page_auto_load', '')); ?>">
                        </span>
                    </div>
                <?php else : ?>
                    <?php
                    /*
                     * T62：分页统一到 ybh_render_pagination()（实现见 tpl/pagination.php）。
                     *
                     * 这里原来是一整段内联实现（paginate_links + 手写 `#main` 锚点补丁 +
                     * 跳页表单）。函数化之后主页、作者页、归档页、搜索页共用同一份实现，
                     * 观感与 URL 形态保持不变：
                     *   · 首页仍是 `/page/2/#main`（锚点只在 ≥2 页加，理由见函数文件头）；
                     *   · 跳页框的 URL 由 PHP 给出 `data-pattern`，不再硬拼 `/page/N/`
                     *     —— 那样在搜索页（`?paged=N&s=…`）是坏的；
                     *   · 跳页输入框补上了 `name="paged"`：没有 JS 时表单也能真正跳转
                     *     （老实现没有 name，所谓"降级可用"是假的）。
                     */
                    echo ybh_render_pagination(array(
                        'total'   => (int) $wp_query->max_num_pages,
                        'current' => max(1, (int) get_query_var('paged')),
                        'label'   => __('文章分页', 'sakurairo'),
                        'context' => 'home',
                    ));
                    ?>
                <?php endif; ?>
            </div>
            <?php
            break;
    }
}

if (!in_array('primary', $component_order)) : //是否需要提供虚假的首页标记，解决当文章不显示时封面丢失，兼容前端js
    ?>
        <main id="main" class="site-main">
            <h1 class="main-title posts-area-title" style="display:none;">
            </h1>
        </main>
<?php
endif;

get_footer();
?>