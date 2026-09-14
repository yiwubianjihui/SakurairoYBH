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
            'url'     => ybh_cta_page_url('all-articles', '/all-articles/'),
            'icon'    => 'fa-solid fa-book-open',
            'label'   => '全部文章',
            'sub'     => '按时序与分类浏览',
            'primary' => false,
        ),
        // T34：随机文章 —— 直接复用主题既有的 /?random_post=1（template_redirect 302）。
        // 不用 JS，中键/新窗口打开也能用；爬虫看到的是 302 不会索引。
        array(
            'url'     => add_query_arg('random_post', '1', home_url('/')),
            'icon'    => 'fa-solid fa-shuffle',
            'label'   => '随机文章',
            'sub'     => '让命运替你挑一篇',
            'primary' => false,
        ),
        // T34：换个封面 —— 复用主题既有的骰子按钮 #bg-next（换封面逻辑在主题打包 JS 里，
        // 与其重写一份不如点它一下）。所以这一项不是链接，而是"触发器"。
        array(
            'trigger' => 'bg-next',
            'icon'    => 'fa-solid fa-dice',
            'label'   => '换个封面',
            'sub'     => '首页封面随机换一张',
            'primary' => false,
        ),
        array(
            'url'     => ybh_cta_page_url('join-ybh', '/join-ybh/'),
            'icon'    => 'fa-solid fa-user-plus',
            'label'   => '加入 YBH',
            'sub'     => '成为义务编辑会一员',
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

    // 掉链的按钮直接剔除（宁可少一个，也不给读者一个 404）；触发器项不参与该判断
    $items = array_values(array_filter($items, function ($it) {
        return !empty($it['trigger']) || !empty($it['url']);
    }));

    if (!$items) {
        return;
    }
    ?>
    <section class="ybh-home-cta" aria-label="快捷入口">
        <div class="ybh-cta-inner">
            <?php foreach ($items as $it) : ?>
                <?php if (!empty($it['trigger'])) : ?>
                    <button type="button"
                            class="ybh-cta-btn<?php echo $it['primary'] ? ' ybh-cta-btn--primary' : ''; ?>"
                            data-ybh-trigger="<?php echo esc_attr($it['trigger']); ?>">
                        <i class="<?php echo esc_attr($it['icon']); ?>" aria-hidden="true"></i>
                        <span class="ybh-cta-label"><?php echo esc_html($it['label']); ?></span>
                        <?php if (!empty($it['sub'])) : ?>
                            <span class="ybh-cta-sub"><?php echo esc_html($it['sub']); ?></span>
                        <?php endif; ?>
                    </button>
                <?php else : ?>
                    <a class="ybh-cta-btn<?php echo $it['primary'] ? ' ybh-cta-btn--primary' : ''; ?>"
                       href="<?php echo esc_url($it['url']); ?>">
                        <i class="<?php echo esc_attr($it['icon']); ?>" aria-hidden="true"></i>
                        <span class="ybh-cta-label"><?php echo esc_html($it['label']); ?></span>
                        <?php if (!empty($it['sub'])) : ?>
                            <span class="ybh-cta-sub"><?php echo esc_html($it['sub']); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php ybh_render_cta_trigger_script(); ?>
    </section>
    <?php
}

/**
 * 触发器按钮（如「换个封面」）的行为。
 *
 * 做法：找到目标元素（#bg-next）并点它一下 —— 换封面的逻辑在主题打包的 app.js 里，
 * 重写一份既会重复也会随主题升级失效，这里只做"代为点击"。
 * 目标不存在（非首页/封面关闭）时给出明确提示，而不是静默无反应。
 * 用事件委托绑定，避免脚本执行时站台还没输出的时序问题。
 */
function ybh_render_cta_trigger_script()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
    <script>
    (function () {
      document.addEventListener('click', function (e) {
        var el = e.target;
        var btn = (el && el.closest) ? el.closest('[data-ybh-trigger]') : null;
        if (!btn) { return; }
        e.preventDefault();
        var id = btn.getAttribute('data-ybh-trigger');
        var target = document.getElementById(id);
        if (!target) {
          // 封面在非首页/被关掉时不渲染该按钮，明确说一句比什么都不发生好
          btn.classList.add('is-failed');
          setTimeout(function () { btn.classList.remove('is-failed'); }, 1200);
          return;
        }
        btn.classList.add('is-active');
        setTimeout(function () { btn.classList.remove('is-active'); }, 400);
        target.click();
      });
    })();
    </script>
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
