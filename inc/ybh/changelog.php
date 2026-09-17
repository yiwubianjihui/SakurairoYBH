<?php
/**
 * SakurairoYBH · 更新日志页（changelog.php）
 *
 * ## 用法
 *
 * 新建一个页面，正文里写 `[ybh_changelog]`，即成「更新日志」页。
 * （本站已建好：`/changelog/`，见文件末尾的自动建页逻辑。）
 *
 * ## 页面由两块组成
 *
 * 1. **版本时间线** —— 数据来自下面的 `ybh_changelog_entries()`。
 *    这是**权威数据源**：版本号、日期、条目都由它决定，不依赖数据库，
 *    所以换主题版本、迁移站点都不会丢。要加一条就往下加一个数组元素。
 *    也可以用过滤器 `ybh_changelog_entries` 从别处追加（例如子主题）。
 *
 * 2. **最近站务动态** —— 自动拉取「工作日志」分类下最新的若干篇，
 *    标题 + 日期 + 链接。这一块**不需要任何人手工维护**：
 *    编辑部每周发的工作日志一发布，这里就会跟着变 —— 这就是「实时」的部分。
 *
 * 两块的分工：手写的负责「主题/功能改了什么」，自动的负责「站里最近在做什么」。
 *
 * ## 条目字段
 *
 *   version  版本号（没有版本号就写日期或留空，会显示成普通节点）
 *   date     日期 YYYY-MM-DD（决定排序；也用于「最后更新于」）
 *   title    这一版的一句话主题
 *   items[]  条目数组，每项 { type, scope, text }
 *            type  → feat 新增 / fix 修复 / perf 优化 / content 内容 / ops 运维
 *            scope → 可选，标签上的小前缀，如「后台」「阅读」「服务端」
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 更新日志数据源（倒序由渲染端负责，这里按时间正序写反而不好维护，故直接倒序写）。
 *
 * ⚠️ 新增条目请加在**最前面**（数组第一项 = 最新）。
 * 版本号与日期取自主题仓库的 git 提交记录，不是凭印象写的。
 */
function ybh_changelog_entries()
{
    $entries = array(

        array(
            'version' => '1.3.7',
            'date'    => '2026-09-15',
            'title'   => '投稿体验简化 · 更新日志上线',
            'items'   => array(
                array('type' => 'feat', 'scope' => '后台', 'text' => '投稿者 / 作者登录后台后**整块隐藏** WordPress 左侧菜单，改用顶部一条极简导航：写文章 / 我的文章 / 待审核 / 媒体库 / 资料 / 回到站点 / 退出。编辑与管理员不受影响。'),
                array('type' => 'feat', 'scope' => '后台', 'text' => '敲 `/wp-admin/` 的落点从「个人资料」改成「我的文章」——那里能看到自己各状态的稿子，也是发新稿的起点。'),
                array('type' => 'feat', 'scope' => '后台', 'text' => '管理条里的「编辑个人资料」以及 `wp-admin/profile.php` 都改指前台资料页，站内只剩一个资料入口。'),
                array('type' => 'feat', 'scope' => '站点', 'text' => '新增本更新日志页，并把主题与站点的历次更新整理成时间线。'),
                array('type' => 'content', 'scope' => '内容', 'text' => '为全部 **45 篇没有标签的文章**补齐标签（作者、连载作品、栏目、体裁），站内已无无标签文章。'),
                array('type' => 'ops', 'scope' => '服务端', 'text' => '服务器连续两次因磁盘读瓶颈失联的根因定位与处置：停掉闲置的邮件服务与容器底座、给 PHP-FPM 池加硬上限、把 WordPress 定时任务改为系统驱动、回收内核预留的 256 MB。详见站内公告。'),
            ),
        ),

        array(
            'version' => '1.3.6',
            'date'    => '2026-09-14',
            'title'   => '前台个人资料页',
            'items'   => array(
                array('type' => 'feat', 'scope' => '账号', 'text' => '新增前台「个人资料」页（`[ybh_profile]`）：头像上传、昵称与显示名、个人网站、个人简介、修改密码、我的投稿。'),
                array('type' => 'feat', 'scope' => '账号', 'text' => '顶部用户菜单的「个人资料」不再进 wp-admin，改指这个前台页 —— 改个头像不用再穿过整个后台。'),
            ),
        ),

        array(
            'version' => '1.3.5',
            'date'    => '2026-09-14',
            'title'   => '导航归类与主页快捷入口',
            'items'   => array(
                array('type' => 'feat', 'scope' => '导航', 'text' => '顶端菜单归类为 5 项，新增「随机文章」与「换封面」入口。'),
                array('type' => 'perf', 'scope' => '列表', 'text' => '文章卡片标题字号下调，一屏能看到更多条目。'),
                array('type' => 'feat', 'scope' => '后台', 'text' => '「默认启用紧凑模式」做成设置项，可在「YBH 魔改」里随时切换。'),
            ),
        ),

        array(
            'version' => '1.3.4',
            'date'    => '2026-09-14',
            'title'   => '编辑器增强六项',
            'items'   => array(
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '查找替换（`Ctrl+F` / `Ctrl+H`），按文本节点匹配，不会改坏链接与标签。'),
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '脚注预览改成与前台一致的圆形编号徽章，悬停展开、就地编辑。'),
                array('type' => 'fix', 'scope' => '编辑器', 'text' => '`Ctrl+S` 其实一直**没有保存**（已发布文章上等于什么都没发生）—— 现在会就地保存并给出明确提示。'),
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '工具栏新增「首行缩进」开关，存的是类名而非内联样式，前后台一致。'),
                array('type' => 'feat', 'scope' => '站点', 'text' => '主页加入更明显的行动按钮；新建「全部文章」「我要投稿」两个页面。'),
            ),
        ),

        array(
            'version' => '1.3.2',
            'date'    => '2026-09-14',
            'title'   => '签名栏排版',
            'items'   => array(
                array('type' => 'fix', 'scope' => '首页', 'text' => '首屏签名栏支持在标点后换行，窄屏不再被硬截断。'),
                array('type' => 'fix', 'scope' => '列表', 'text' => '文章卡片的浏览量只保留数字，去掉「热度」二字。'),
            ),
        ),

        array(
            'version' => '1.3.1',
            'date'    => '2026-09-13',
            'title'   => '紧凑模式 · 脚注 · 法务页面',
            'items'   => array(
                array('type' => 'feat', 'scope' => '列表', 'text' => '紧凑模式默认开启：桌面 3 列、窄屏 2 列。'),
                array('type' => 'feat', 'scope' => '阅读', 'text' => '正文支持 `[fn]注释[/fn]` 脚注，渲染成上标编号 + 文末注释列表。'),
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '解除编辑区 820px 宽度上限，把横向空间还给正文。'),
                array('type' => 'content', 'scope' => '法务', 'text' => '补齐隐私政策、用户协议、Cookie 政策三份文档，并在页脚加入口。'),
            ),
        ),

        array(
            'version' => '1.3.0',
            'date'    => '2026-09-13',
            'title'   => '头像全线自建',
            'items'   => array(
                array('type' => 'feat', 'scope' => '账号', 'text' => '自建头像端点与上传通道，支持用户上传自定义头像。'),
                array('type' => 'perf', 'scope' => '性能', 'text' => '头像不再向第三方（Cravatar / Gravatar）发起请求，全部走站内，也不再因为外部服务不可达而显示空白。'),
            ),
        ),

        array(
            'version' => '1.2.9',
            'date'    => '2026-09-12',
            'title'   => '标签区窄屏减量',
            'items'   => array(
                array('type' => 'fix', 'scope' => '首页', 'text' => '标签展示区在窄屏默认只显示前若干个，折叠点前移；展开后仍是全部标签，不丢内容。'),
            ),
        ),

        array(
            'version' => '1.2.7',
            'date'    => '2026-09-11',
            'title'   => '中文斜体与后台美化',
            'items'   => array(
                array('type' => 'feat', 'scope' => '阅读', 'text' => '中文斜体改用霞鹜文楷，不再用机械倾斜的伪斜体。'),
                array('type' => 'feat', 'scope' => '后台', 'text' => '管理后台统一到与前台一致的圆角、阴影与主题色；新增投稿快捷入口。'),
                array('type' => 'fix', 'scope' => '后台', 'text' => '控制台「紧凑」按钮点了没反应（监听器从未绑定）已修。'),
            ),
        ),

        array(
            'version' => '1.2.5',
            'date'    => '2026-09-11',
            'title'   => '主页精简与随机封面',
            'items'   => array(
                array('type' => 'feat', 'scope' => '首页', 'text' => '主页标签行上线，卡片精简。'),
                array('type' => 'perf', 'scope' => '性能', 'text' => '随机封面改走轻量端点，不再随机抽到几百 KB 的原图。'),
            ),
        ),

        array(
            'version' => '1.2.4',
            'date'    => '2026-09-10',
            'title'   => '改用经典编辑器',
            'items'   => array(
                array('type' => 'feat', 'scope' => '编辑器', 'text' => '全站改用经典编辑器并重做写作体验 —— 投稿的同学不必面对块编辑器。'),
            ),
        ),

        array(
            'version' => '1.2.2',
            'date'    => '2026-09-09',
            'title'   => '字体子集化',
            'items'   => array(
                array('type' => 'perf', 'scope' => '性能', 'text' => '中文字体做子集化，首页字体体积从约 21 MB 降到 0.84 MB。'),
                array('type' => 'fix', 'scope' => '账号', 'text' => '修复重置密码链接提示「已失效」的问题。'),
            ),
        ),

        array(
            'version' => '1.2.0',
            'date'    => '2026-09-08',
            'title'   => 'YBH 分叉首个正式版',
            'items'   => array(
                array('type' => 'feat', 'scope' => '站点', 'text' => '基于 Sakurairo 建立 YBH 自有分支，处理首批 5 项站点问题：展台遮挡、WebP 上传、动效细节、已访问菜单配色、分叉标识。'),
            ),
        ),
    );

    /**
     * 允许外部追加 / 覆盖条目（子主题、插件、后续版本）。
     *
     * @param array $entries 条目数组，最新的在最前。
     */
    return apply_filters('ybh_changelog_entries', $entries);
}

/** 条目类型的显示名。 */
function ybh_changelog_type_label($type)
{
    $map = array(
        'feat'    => '新增',
        'fix'     => '修复',
        'perf'    => '优化',
        'content' => '内容',
        'ops'     => '运维',
    );
    return isset($map[$type]) ? $map[$type] : '更新';
}

/* ---------------------------------------------------------------------------
 * 样式：只在含短代码的页面上加载
 * ------------------------------------------------------------------------- */

add_action('wp_enqueue_scripts', 'ybh_changelog_assets', 20);
function ybh_changelog_assets()
{
    if (is_admin() || !is_singular()) {
        return;
    }
    $post = get_queried_object();
    if (!$post instanceof WP_Post || !has_shortcode((string) $post->post_content, 'ybh_changelog')) {
        return;
    }
    wp_enqueue_style(
        'ybh-changelog',
        get_template_directory_uri() . '/css/ybh-changelog.css',
        array(),
        // 版本号跟文件修改时间走（见 bootstrap.php 的 ybh_asset_ver 注释）
        function_exists('ybh_asset_ver') ? ybh_asset_ver('css/ybh-changelog.css')
            : (defined('YBH_VERSION') ? YBH_VERSION : '1')
    );
}

/* ---------------------------------------------------------------------------
 * 短代码
 * ------------------------------------------------------------------------- */

add_shortcode('ybh_changelog', 'ybh_changelog_shortcode');
function ybh_changelog_shortcode($atts = array())
{
    $atts = shortcode_atts(array(
        'recent'    => 5,          // 自动「站务动态」条数，0 = 关闭
        'recent_cat' => '工作日志', // 取哪个分类的最近文章
    ), $atts, 'ybh_changelog');

    $entries = ybh_changelog_entries();
    if (!is_array($entries)) {
        $entries = array();
    }

    // 按日期倒序兜底排序（数据源里已是最新在前，这里防手工插入顺序不对）
    usort($entries, function ($a, $b) {
        return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
    });

    $latest = $entries ? (string) ($entries[0]['date'] ?? '') : '';

    ob_start();
    ?>
    <div class="ybh-cl">

      <header class="ybh-cl-head">
        <p class="ybh-cl-lead">
          这里记录 <strong>YBH 站点与主题</strong> 的每一次改动 ——
          修了什么、加了什么、为什么改。倒序排列，最新的在最前。
        </p>
        <?php if ($latest !== '') : ?>
          <p class="ybh-cl-stamp">
            <span class="dashicons dashicons-update"></span>
            最后更新：<time datetime="<?php echo esc_attr($latest); ?>"><?php echo esc_html($latest); ?></time>
          </p>
        <?php endif; ?>
      </header>

      <?php
      /* ---- 自动区块：最近的站务动态 ---- */
      $recent_n = (int) $atts['recent'];
      if ($recent_n > 0) :
          /*
           * 分类先用**名称**精确匹配，再退回 slug。
           * 中文分类的 slug 是 sanitize_title() 出来的编码串，直接按名称找更可靠；
           * 而英文分类（slug 干净）用名称找也一样命中。
           */
          $cat_name = (string) $atts['recent_cat'];
          $cat = get_term_by('name', $cat_name, 'category');
          if (!$cat) {
              $cat = get_category_by_slug(sanitize_title($cat_name));
          }
          $q = new WP_Query(array(
              'post_type'           => 'post',
              'posts_per_page'      => $recent_n,
              'ignore_sticky_posts' => true,
              'no_found_rows'       => true,
              'cat'                 => $cat ? (int) $cat->term_id : 0,
          ));
          if ($q->have_posts()) :
      ?>
      <section class="ybh-cl-recent">
        <h2 class="ybh-cl-h2">
          <span class="dashicons dashicons-megaphone"></span>
          最近站务动态
          <small>自动同步，无需维护</small>
        </h2>
        <ul class="ybh-cl-recent-list">
          <?php while ($q->have_posts()) : $q->the_post(); ?>
            <li>
              <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
              <time datetime="<?php echo esc_attr(get_the_date('c')); ?>"><?php echo esc_html(get_the_date('Y-m-d')); ?></time>
            </li>
          <?php endwhile; ?>
        </ul>
      </section>
      <?php
          endif;
          wp_reset_postdata();
      endif;
      ?>

      <?php /* ---- 手写区块：版本时间线 ---- */ ?>
      <section class="ybh-cl-timeline">
        <?php foreach ($entries as $e) :
            $ver   = (string) ($e['version'] ?? '');
            $date  = (string) ($e['date'] ?? '');
            $title = (string) ($e['title'] ?? '');
            $items = isset($e['items']) && is_array($e['items']) ? $e['items'] : array();
        ?>
          <article class="ybh-cl-node" id="v<?php echo esc_attr(str_replace('.', '-', $ver)); ?>">
            <div class="ybh-cl-dot" aria-hidden="true"></div>
            <div class="ybh-cl-card">
              <div class="ybh-cl-meta">
                <?php if ($ver !== '') : ?>
                  <span class="ybh-cl-ver">v<?php echo esc_html($ver); ?></span>
                <?php endif; ?>
                <?php if ($date !== '') : ?>
                  <time class="ybh-cl-date" datetime="<?php echo esc_attr($date); ?>"><?php echo esc_html($date); ?></time>
                <?php endif; ?>
                <?php if ($title !== '') : ?>
                  <span class="ybh-cl-title"><?php echo esc_html($title); ?></span>
                <?php endif; ?>
              </div>
              <?php if ($items) : ?>
                <ul class="ybh-cl-items">
                  <?php foreach ($items as $it) :
                      $type  = (string) ($it['type'] ?? 'feat');
                      $scope = (string) ($it['scope'] ?? '');
                      $text  = (string) ($it['text'] ?? '');
                      if ($text === '') {
                          continue;
                      }
                  ?>
                    <li class="ybh-cl-item is-<?php echo esc_attr($type); ?>">
                      <span class="ybh-cl-tag"><?php echo esc_html(ybh_changelog_type_label($type)); ?></span>
                      <?php if ($scope !== '') : ?>
                        <span class="ybh-cl-scope"><?php echo esc_html($scope); ?></span>
                      <?php endif; ?>
                      <?php
                      /*
                       * 条目文案里用了 `code` 风格的标记（反引号）来突出命令、选项名、类名。
                       * 这里把反引号转成 <code>，其余按纯文本转义 ——
                       * 既保留可读性，又不会让人能从这个数据源注入 HTML。
                       */
                      $safe = esc_html($text);
                      $safe = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $safe);
                      // 允许 **强调**（同样是先转义再补标签，安全）
                      $safe = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $safe);
                      echo $safe;
                      ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

      <footer class="ybh-cl-foot">
        <p>想追更具体的过程，可以看
          <a href="<?php echo esc_url(home_url('/category/工作日志/')); ?>">工作日志</a> 分类；
          完整代码改动在 <a href="https://github.com/yiwubianjihui/SakurairoYBH" target="_blank" rel="noopener">GitHub</a>。
        </p>
      </footer>

    </div>
    <?php
    return ob_get_clean();
}

/* ---------------------------------------------------------------------------
 * 自动建页
 *
 * 「更新日志」这类页面漏建了就是 404，而建页动作本身没有技术含量。
 * 这里在主题切换到本版本后检查一次：没有 slug 为 changelog 的页面就建一个，
 * 正文写短代码。**只建一次**（用 option 记版本号），不会反复打扰。
 * ------------------------------------------------------------------------- */

add_action('admin_init', 'ybh_changelog_maybe_create_page');
function ybh_changelog_maybe_create_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $stamp = 'ybh_changelog_page_ver';
    $want  = defined('YBH_VERSION') ? YBH_VERSION : '1';
    if (get_option($stamp) === $want) {
        return;
    }

    $existing = get_page_by_path('changelog');
    if (!$existing) {
        $id = wp_insert_post(array(
            'post_title'   => '更新日志',
            'post_name'    => 'changelog',
            'post_content' => '[ybh_changelog]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => get_current_user_id(),
            'comment_status' => 'closed',
            'ping_status'  => 'closed',
        ));
        if (is_wp_error($id)) {
            return; // 建失败就下次再来，不记版本号
        }
    }
    update_option($stamp, $want, false);
}
