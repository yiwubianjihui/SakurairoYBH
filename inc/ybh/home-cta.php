<?php
/**
 * YBH · 主页行动按钮（任务清单第 5 项）
 *
 * 需求原话：「在主页增加更明显的按钮，特别是对手机端」。
 *
 * 问题：站点最关键的四个入口（我要投稿 / 加入 YBH / 全部文章 / 请给我们钱）
 *       此前只存在于顶栏菜单里，而顶栏在手机上折叠成汉堡菜单 ——
 *       读者与学生作者基本看不到，更不会主动去翻。
 *
 * 做法：在首页内容最顶端（封面之下、展台与文章列表之上）渲染一排按钮卡片。
 *       桌面一行四个；平板两列；手机两列并放大点击区、主按钮（投稿）通栏。
 *       样式在 css/ybh.css 第 14 节。
 *
 * 链接策略：**按 slug 现查**（get_page_by_path / get_permalink），
 *       页面被改名或换模板都不用改代码；页面不存在时退回约定的路径，
 *       真找不到就跳过该按钮 —— 绝不输出死链。
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 取一个页面的固定链接（按 slug 查，找不到用兜底路径）。
 *
 * @param string $slug     页面别名
 * @param string $fallback 页面不存在时使用的绝对路径
 * @return string
 */
function ybh_cta_page_url($slug, $fallback)
{
    $page = get_page_by_path($slug);
    if ($page && 'publish' === $page->post_status) {
        return get_permalink($page);
    }
    return home_url($fallback);
}

/**
 * 取一篇文章的固定链接（「请给我们钱」是文章而不是页面）。
 *
 * @param string $slug
 * @param string $fallback
 * @return string
 */
function ybh_cta_post_url($slug, $fallback)
{
    $post = get_page_by_path($slug, OBJECT, 'post');
    if ($post && 'publish' === $post->post_status) {
        return get_permalink($post);
    }
    return home_url($fallback);
}

/**
 * 渲染首页行动按钮。
 * 只在首页/静态首页输出（本函数虽从 index.php 调用，但 index.php 也是
 * 无专用模板时的兜底模板，加一道判断避免在归档页冒出来）。
 */
function ybh_render_home_cta()
{
    if (!is_home() && !is_front_page()) {
        return;
    }

    $items = array(
        array(
            'url'     => ybh_cta_page_url('submit', '/submit/'),
            'icon'    => 'fa-solid fa-pen-nib',
            'label'   => '我要投稿',
            'sub'     => '把你的文字交给我们',
            'primary' => true,
        ),
        array(
            'url'     => ybh_cta_page_url('join-ybh', '/join-ybh/'),
            'icon'    => 'fa-solid fa-user-plus',
            'label'   => '加入 YBH',
            'sub'     => '成为义务编辑会一员',
            'primary' => false,
        ),
        array(
            'url'     => ybh_cta_page_url('all-articles', '/all-articles/'),
            'icon'    => 'fa-solid fa-book-open',
            'label'   => '全部文章',
            'sub'     => '按时序与分类浏览',
            'primary' => false,
        ),
        array(
            'url'     => ybh_cta_post_url('tip', '/tip/'),
            'icon'    => 'fa-solid fa-heart',
            'label'   => '请给我们钱',
            'sub'     => '支持我们继续做下去',
            'primary' => false,
        ),
    );

    // 掉链的按钮直接剔除（宁可少一个，也不给读者一个 404）
    $items = array_values(array_filter($items, function ($it) {
        return !empty($it['url']);
    }));

    if (!$items) {
        return;
    }
    ?>
    <section class="ybh-home-cta" aria-label="快捷入口">
        <div class="ybh-cta-inner">
            <?php foreach ($items as $it) : ?>
                <a class="ybh-cta-btn<?php echo $it['primary'] ? ' ybh-cta-btn--primary' : ''; ?>"
                   href="<?php echo esc_url($it['url']); ?>">
                    <i class="<?php echo esc_attr($it['icon']); ?>" aria-hidden="true"></i>
                    <span class="ybh-cta-label"><?php echo esc_html($it['label']); ?></span>
                    <?php if (!empty($it['sub'])) : ?>
                        <span class="ybh-cta-sub"><?php echo esc_html($it['sub']); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

/**
 * 手机端吸底操作条（T33 第 5 项的第二半）。
 *
 * 为什么还要一条：首页封面是整屏高，上面那排按钮落在折线之下，
 * 手机用户第一屏看不到；而顶栏在手机上又是汉堡菜单。这里给三个最关键的
 * 入口做常驻吸底，≥861px 由 CSS 隐藏（桌面顶栏完整可见，不需要）。
 * 样式与避让规则见 css/ybh.css 第 14.1 节。
 */
function ybh_render_mobile_actions()
{
    if (!is_home() && !is_front_page()) {
        return;
    }

    $items = array(
        array(
            'url'     => ybh_cta_page_url('submit', '/submit/'),
            'icon'    => 'fa-solid fa-pen-nib',
            'label'   => '投稿',
            'primary' => true,
        ),
        array(
            'url'     => ybh_cta_page_url('all-articles', '/all-articles/'),
            'icon'    => 'fa-solid fa-book-open',
            'label'   => '全部文章',
            'primary' => false,
        ),
        array(
            'url'     => ybh_cta_page_url('join-ybh', '/join-ybh/'),
            'icon'    => 'fa-solid fa-user-plus',
            'label'   => '加入',
            'primary' => false,
        ),
    );

    $items = array_values(array_filter($items, function ($it) {
        return !empty($it['url']);
    }));

    if (!$items) {
        return;
    }
    ?>
    <nav class="ybh-mobile-actions" aria-label="快捷操作">
        <?php foreach ($items as $it) : ?>
            <a class="ybh-ma-btn<?php echo $it['primary'] ? ' ybh-ma-btn--primary' : ''; ?>"
               href="<?php echo esc_url($it['url']); ?>">
                <i class="<?php echo esc_attr($it['icon']); ?>" aria-hidden="true"></i>
                <span><?php echo esc_html($it['label']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}
