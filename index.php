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
                    <nav class="traditional-pagination">
                        <?php
                        /*
                         * YBH：给「第 2 页及以后」的页码链接补一个 `#main` 锚点。
                         *
                         * 起因（用户反馈）：点页码后浏览器总是回到页面**最顶端**，
                         * 而首页最上面是一整屏封面，于是每翻一页都要再往下滚一大段，很费事。
                         * 带上 `#main`（就是文章列表那个 <main>）之后，浏览器直接停在列表开头。
                         *
                         * ⚠️ **只在 ≥2 页加，第 1 页不加**。
                         * 第一版是把锚点挂在 `base` 上（一行搞定），结果 page 1 的链接变成
                         * `/page/1/#main`，而 `/page/1/` 会 **301 跳到 `/`** ——
                         * 浏览器会把 `#main` **一起带到重定向后的地址**上，
                         * 于是从第 2 页点「1」或左箭头"回首页"时，直接落在文章列表而不是页面顶端（用户反馈）。
                         * 只在 ≥2 页加，回首页就仍是正常的从顶部开始。
                         *
                         * 实现：先让 paginate_links 正常生成，再回头给链接补锚点 ——
                         * 这样不必跟 `base`/`format` 那套占位符较劲，也不用关心
                         * 站点用的是 `/page/2/` 还是 `?paged=2` 两种 URL 形态（正则两种都认）。
                         */
                        $ybh_links = paginate_links(array(
                            'base' => str_replace(999999999, '%#%', esc_url(get_pagenum_link(999999999))),
                            'format' => '?paged=%#%',
                            'current' => max(1, get_query_var('paged')),
                            'total' => $wp_query->max_num_pages,
                            'prev_text' => '<i class="fa-solid fa-angle-left"></i>',
                            'next_text' => '<i class="fa-solid fa-angle-right"></i>'
                        ));

                        echo preg_replace_callback(
                            '/href="([^"]*(?:\/page\/(\d+)\/|\bpaged=(\d+))[^"]*)"/',
                            function ($m) {
                                $num = (isset($m[2]) && '' !== $m[2]) ? (int) $m[2] : (int) ($m[3] ?? 0);
                                return 'href="' . $m[1] . ($num >= 2 ? '#main' : '') . '"';
                            },
                            (string) $ybh_links
                        );

                        /*
                         * YBH：页码跳转框（用户反馈「分页功能增加输入页码功能」）。
                         *
                         * 为什么需要：文章已有 8 页，而页码条只列首尾与当前页附近，
                         * 想跳到中间某页只能一页页点。这里给一个直接输入页码的入口。
                         *
                         * 交互对齐原生习惯：回车提交；越界时夹到 [1, total]，
                         * 由 JS 处理（没有 JS 时表单本身也能提交，只是不夹取）。
                         *
                         * ⚠️ 「第 1 页」的地址是**站根**（`/page/1/` 会 301 回根），
                         * 所以表单 action 用 `get_pagenum_link(1)` 而不是拼 `/page/%d/`；
                         * 也**不带 #main** —— 与上面分页链接「只在 ≥2 页加锚点」的规则一致。
                         */
                        $ybh_total = (int) $wp_query->max_num_pages;
                        $ybh_cur   = max(1, (int) get_query_var('paged'));
                        if ($ybh_total > 1) :
                            $ybh_home = get_pagenum_link(1);
                            ?>
                            <form class="ybh-pagejump"
                                  action="<?php echo esc_url($ybh_home); ?>"
                                  data-total="<?php echo (int) $ybh_total; ?>"
                                  data-home="<?php echo esc_url($ybh_home); ?>">
                                <label class="ybh-pagejump-label" for="ybh-pagejump-input">
                                    <?php esc_html_e('跳至', 'sakurairo'); ?>
                                </label>
                                <input id="ybh-pagejump-input" class="ybh-pagejump-input"
                                       type="number" inputmode="numeric" min="1"
                                       max="<?php echo (int) $ybh_total; ?>"
                                       value="<?php echo (int) $ybh_cur; ?>"
                                       aria-label="<?php esc_attr_e('输入页码后回车跳转', 'sakurairo'); ?>">
                                <span class="ybh-pagejump-total">/&nbsp;<?php echo (int) $ybh_total; ?></span>
                                <button type="submit" class="ybh-pagejump-go">
                                    <?php esc_html_e('跳转', 'sakurairo'); ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </nav>
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