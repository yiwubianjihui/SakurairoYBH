<?php

/**
 * The template for displaying search results pages.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/#search-result
 *
 * @package Sakurairo
 */

get_header(); ?>
<section id="primary" class="content-area">
	<main id="main" class="site-main" role="main">
    
    <?php
    $paged = max(1, get_query_var('paged'));
    $search_query = get_search_query();
    $sticky_posts = get_option('sticky_posts');
    $show_pages_filter = true;

    // 默认勾选的选项
    $default_checked = array('post');
    if (iro_opt('search_for_shuoshuo')) $default_checked[] = 'shuoshuo';
    if (iro_opt('search_for_pages')) {
        if (iro_opt('only_admin_can_search_pages')) {
            //仅限管理员检索页面
            if (current_user_can('manage_options')) {
                $default_checked[] = 'page';
            } else {
                $show_pages_filter = false;
            }
        } else {
            $default_checked[] = 'page';
        }
    } else {
        $show_pages_filter = false;
    }

    /*
     * 获取当前查询参数中的 content_type。
     *
     * T63 修正：`content_type` 有**两种来源** —— 页内筛选器提交的是数组
     * （`content_type[]=post`），而 `applyFilter()` 那段 JS 拼的是逗号串
     * （`content_type=post,shuoshuo`）。原实现直接 `explode()` $_GET 里的值，
     * 一旦有人用原生提交（或 JS 失效）就是个数组 ⇒ PHP 8 上 TypeError 白屏。
     * 这里统一先归一成字符串再拆。
     */
    if (isset($_GET['content_type'])) {
        $raw_types = wp_unslash($_GET['content_type']);
        if (is_array($raw_types)) {
            $raw_types = implode(',', $raw_types);
        }
        $selected_types = array_values(array_filter(array_map('sanitize_key', explode(',', (string) $raw_types))));
    } else {
        $selected_types = $default_checked;
    }
    if (empty($selected_types)) {
        $selected_types = $default_checked;
    }

    /*
     * 「用户」是独立一路（T63）：
     *   · `user` 不是注册的 post type，不能进 `WP_Query` 的 post_type；
     *   · 主题 `functions.php` 的 `customize_query_functions()` 会在
     *     `pre_get_posts` 里无条件覆写搜索的 post_type ⇒ 塞进主查询也没用。
     * 所以这里把 `user` 单独摘出来，交给 `ybh_user_search()`（WP_User_Query）。
     */
    $search_users = in_array('user', $selected_types, true)
        && function_exists('ybh_user_search')
        && ybh_user_search_enabled();

    $content_types = $search_users
        ? array_values(array_diff($selected_types, array('user')))
        : $selected_types;

    // 只勾了「用户」：文章那一路整体跳过（否则会白跑一次查询还把文章也列出来）
    $only_users = ($search_users && empty($content_types));
    if (!$only_users && empty($content_types)) {
        $content_types = array('post');
    }

    // 搜索页标题
    if (!iro_opt('patternimg') || !get_random_bg_url()) : ?>
        <header class="page-header">
            <h1 class="page-title"><?php printf(esc_html__('Search result: %s', 'sakurairo'), '<span>' . esc_html($search_query) . '</span>'); ?></h1>
        </header><!-- .page-header -->
    <?php endif; ?>

    <?php
    $all_results_query = null;
    if (!$only_users) :
    $all_results_args = array(
        'post_type' => $content_types,
        'post_status' => 'publish',
        's' => $search_query,
        'posts_per_page' => -1,
        'orderby' => 'relevance',
        'order' => 'DESC',
    );

    if (iro_opt('only_admin_can_search_pages')) {
        // 只允许管理员检索页面
        if (!current_user_can('manage_options')) {
            // 不是管理员就移除page
            $all_results_args['post_type'] = array_diff($content_types, array('page'));
        }
    }

    $all_results_args['post__not_in'] = array_map('intval', explode(',', iro_opt('custom_exclude_search_results')));
    //排除自定义内容id

    $all_results_query = new WP_Query($all_results_args);
    endif; // !$only_users

    /*
     * T63：「搜人」取数。
     *   · 只勾「用户」→ 按 `paged` 分页取；
     *   · 与文章混选  → 只取第一页做预览（文章那一路已占用 `paged`，
     *     两套结果共用一个页码必然打架，所以这里不分页，只给"查看全部"的入口）。
     * ⚠️ 绝不能把用户结果并进上面的文章查询 —— 见文件顶部 T63 注释。
     */
    $user_search = null;
    if ($search_users) {
        $user_search = ybh_user_search($search_query, $only_users ? $paged : 1, 12);
    }

    if (iro_opt('search_filter')) : ?>
        <!-- 筛选器部分 -->
        <div id="filter-container">
            <div class="filter-count">
                <?php
                // T63：只勾「用户」时文章查询被整段跳过（$all_results_query = null），
                // 计数改由用户那一路提供，避免 null 解引用。
                echo $only_users
                    ? (int) $user_search['total']
                    : (int) $all_results_query->found_posts;
                ?>
                <?php echo __('results found', 'sakurairo'); ?>
            </div>

            <form id="search-filter-form" action="" method="GET">
                <?php if ($search_query) : ?>
                    <input type="hidden" name="s" value="<?php echo esc_attr($search_query); ?>">
                <?php endif; ?>

                <label>
                    <input type="checkbox" name="content_type[]" value="post" onchange="applyFilter()" <?php echo in_array('post', $content_types) ? 'checked' : ''; ?>> <?php echo __('Post', 'sakurairo'); ?>
                </label>

                <?php if (iro_opt('search_for_shuoshuo')) : ?>
                    <label>
                        <input type="checkbox" name="content_type[]" value="shuoshuo" onchange="applyFilter()" <?php echo in_array('shuoshuo', $content_types) ? 'checked' : ''; ?>> <?php echo __('shuoshuo', 'sakurairo'); ?>
                    </label>
                <?php endif; ?>

                <?php if ($show_pages_filter) : ?>
                    <label>
                        <input type="checkbox" name="content_type[]" value="page" onchange="applyFilter()" <?php echo in_array('page', $content_types) ? 'checked' : ''; ?>> <?php echo __('Page', 'sakurairo'); ?>
                    </label>
                <?php endif; ?>

                <?php /* T63：搜人。默认**不勾**，避免改变既有搜索行为；勾上与文章混选时只预览，单勾「用户」才分页。 */ ?>
                <?php if ($search_users) : ?>
                    <label>
                        <input type="checkbox" name="content_type[]" value="user" onchange="applyFilter()" <?php echo in_array('user', $selected_types) ? 'checked' : ''; ?>> <?php echo __('用户', 'sakurairo'); ?>
                    </label>
                <?php endif; ?>
            </form>

            <div id="filter-toggle" title="<?php echo __('If no option is selected, all results are retrieved by default', 'sakurairo'); ?>" onclick="applyFilter()">
            <a href="./" id="the_filter" style="color: white;"><i class="fas fa-filter"></i></a> <?php echo __('Click to filter', 'sakurairo'); ?></a>
        </div>
    </div>
    <?php endif; ?>

    <script>
    function applyFilter() {
        var filterForm = document.getElementById('search-filter-form');
        var checkboxes = filterForm.querySelectorAll('input[name="content_type[]"]');
        var selected = [];
        checkboxes.forEach(function (checkbox) {
            if (checkbox.checked) selected.push(checkbox.value);
        });

        var searchParams = new URLSearchParams(window.location.search);
        searchParams.set('content_type', selected.join(','));
        var newUrl = window.location.pathname + '?' + searchParams.toString();

        var the_filter = document.getElementById('the_filter');
        the_filter.href = newUrl;

        the_filter.click();
    }
    </script>

    <?php
    // 结果处理，排序，展示
    $all_results = [];
    if ($all_results_query && $all_results_query->have_posts()) :
        if (iro_opt('sticky_pinned_content')) {
            // 置顶文章是否在检索中也置顶
            $sticky_results = [];
            $non_sticky_results = [];
            
            while ($all_results_query->have_posts()) : $all_results_query->the_post();
                if (in_array(get_the_ID(), $sticky_posts)) {
                    $sticky_results[] = $post;
                } else {
                    $non_sticky_results[] = $post;
                }
            endwhile;
            
            $all_results = array_merge($sticky_results, $non_sticky_results);
        } else {
            while ($all_results_query->have_posts()) : $all_results_query->the_post();
                $all_results[] = $post;
            endwhile;
        }
    endif;
    wp_reset_postdata();

    // 内容分页
    /*
     * T63：每页篇数改用**站点设置**（本站 bootstrap 把 posts_per_page 固定为 12）。
     * 原来这里硬编码 10 —— 会出现「分页条按 10 算页数、全站却按 12 取」的错位。
     */
    $posts_per_page = max(1, (int) get_option('posts_per_page', 12));
    $total_results = count($all_results);
    $total_pages = (int) ceil($total_results / $posts_per_page);
    $current_page_results = array_slice($all_results, ($paged - 1) * $posts_per_page, $posts_per_page);

    // 输出当前页内容（T63：只勾「用户」时，文章这一路整体不出）
    if (!$only_users && !empty($current_page_results)) :
        foreach ($current_page_results as $post) :
            setup_postdata($post);
            get_template_part('tpl/content', 'thumbcard');
        endforeach;

        /*
         * T62：搜索页原来用 `the_posts_pagination()`（全站第四套分页器）。
         * 现在统一到 `ybh_render_pagination()`：与主页/作者页/归档页同一份实现，
         * 带跳页框，且 `nav` 有 aria-label。
         * `base` 留空 ⇒ 由 get_pagenum_link() 按当前上下文生成，自动保留 `s`
         * 与 `content_type`；`hidden` 只服务"无 JS 降级"的跳页表单。
         */
        if (function_exists('ybh_render_pagination')) {
            echo ybh_render_pagination(array(
                'total'   => $total_pages,
                'current' => $paged,
                'label'   => __('搜索结果分页', 'sakurairo'),
                'hidden'  => array(
                    's'            => $search_query,
                    'content_type' => implode(',', $selected_types),
                ),
                'context' => 'search',
            ));
        } else {
            the_posts_pagination(array(
                'total' => $total_pages,
                'current' => $paged,
                'format' => '?paged=%#%',
            ));
        }
    elseif (!$only_users) :
        ?>
        <div class="search-box" style="margin-top: 15px;">
            <!-- search start -->
            <form class="s-search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
                <input class="text-input" type="search" name="s" placeholder="<?php esc_attr_e('Search...', 'sakurairo'); ?>" required>
            </form>
            <!-- search end -->
        </div>
        <?php get_template_part('tpl/content', 'none'); ?>
    <?php
    endif;
    wp_reset_postdata();

    /*
     * T63：「搜人」结果区。
     *   · 只勾「用户」    → 完整列表 + 独立分页（用 $user_search['paged']，
     *                       与文章那一路的 `paged` 互不干扰）；
     *   · 与文章混选      → 只展示前若干位并给「查看全部用户」入口
     *                       （两套结果共用一个 `paged` 必然打架，所以不在这里分页）。
     * 用户卡渲染在 tpl/user-card.php，数据来自 inc/ybh/user-search.php。
     */
    if ($search_users && is_array($user_search)) : ?>
        <section class="ybh-user-results" aria-label="<?php esc_attr_e('用户搜索结果', 'sakurairo'); ?>">
            <h2 class="ybh-user-results__title">
                <?php
                printf(
                    /* translators: %s: 搜索关键词 */
                    esc_html__('用户：%s', 'sakurairo'),
                    '<span>' . esc_html($search_query) . '</span>'
                );
                ?>
                <span class="ybh-user-results__count">
                    <?php echo (int) $user_search['total']; ?><?php esc_html_e(' 位', 'sakurairo'); ?>
                </span>
            </h2>
            <?php
            if (!empty($user_search['items'])) {
                foreach ($user_search['items'] as $ybh_one_user) {
                    if (function_exists('ybh_render_user_card')) {
                        echo ybh_render_user_card($ybh_one_user);   // 内部已转义
                    }
                }
            } else {
                echo '<p class="ybh-user-results__empty">' . esc_html__('没有匹配的用户。', 'sakurairo') . '</p>';
            }

            if ($only_users && function_exists('ybh_render_pagination')) {
                echo ybh_render_pagination(array(
                    'total'   => (int) $user_search['pages'],
                    'current' => (int) $user_search['paged'],
                    'label'   => __('用户搜索结果分页', 'sakurairo'),
                    'hidden'  => array(
                        's'            => $search_query,
                        'content_type' => 'user',
                    ),
                    'context' => 'search-users',
                ));
            } elseif (!$only_users && (int) $user_search['total'] > count($user_search['items'])) {
                printf(
                    '<p class="ybh-user-results__more"><a href="%s">%s</a></p>',
                    esc_url(add_query_arg(array('s' => $search_query, 'content_type' => 'user'), home_url('/'))),
                    esc_html__('查看全部用户 →', 'sakurairo')
                );
            }
            ?>
        </section>
    <?php endif; ?>


	</main><!-- #main -->
</section><!-- #primary -->

<?php get_footer(); ?>
