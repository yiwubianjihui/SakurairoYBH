<?php
/**
 * YBH · 前台编辑器（T64）
 *
 * ===================================================================
 * 为什么把编辑器搬到前台
 * ===================================================================
 *   本站 `default_role = contributor`，绝大多数用户是投稿同学。原来的"我要投稿"
 *   虽然已经一键直达 `wp-admin/post-new.php`，但**后台终究是后台**：菜单、工具栏、
 *   各种提示都是给站点管理员设计的，投稿同学容易被吓到或误点。
 *   现在 `/write/` 就是一个干净的写作页：标题、正文、摘要、两个按钮。
 *
 * ===================================================================
 * 入口是怎么来的（没有建页面、没有改 rewrite 规则）
 * ===================================================================
 *   `/write/` 在 WordPress 里本来是个 404。这里在 `template_redirect`（优先级 0）
 *   接住它：路径等于 `write` 就自己渲染编辑器页并 `exit`。
 *   好处是**不写数据库**（不建页面、不需要 flush_rewrite_rules），删掉本文件即恢复 404。
 *   同时保留短代码 `[ybh_editor]` —— 哪天想把它挂到某个真正的页面上，
 *   建个页面写短代码即可，两条路都能用。
 *
 * ===================================================================
 * 谁会看到什么
 * ===================================================================
 *   · 未登录 → 登录提示（登录后回到 `/write/`）；
 *   · 登录但无 `edit_posts`（例如订阅者）→ 说明 + 去 `/submit/` 看投稿说明；
 *   · 有 `edit_posts` → 编辑器。**没有 `publish_posts` 的账号**（投稿者）按钮是
 *     「保存草稿 / 提交审核」；有发布权的账号是「保存草稿 / 发布」。
 *   权限的最终裁决在服务端（`ybh_front_save` 端点），前端按钮只是提示。
 *
 * ===================================================================
 * 缓存与资源
 * ===================================================================
 *   · 这是"登录用户专属 + 带 nonce"的页面：`DONOTCACHEPAGE` + `nocache_headers()`，
 *     否则 Cache Enabler 会把某个人的 nonce 发给别人；
 *   · 编辑器产物 1.3 MB，**只在真的要显示编辑器时入队**（访客/未登录用户一个字节都不下）。
 *
 * 关闭方式：把 YBH_FRONT_EDITOR 定义为 false。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('YBH_FRONT_EDITOR')) {
    define('YBH_FRONT_EDITOR', true);
}

/** 路由别名：`/write/` */
if (!defined('YBH_FRONT_EDITOR_SLUG')) {
    define('YBH_FRONT_EDITOR_SLUG', 'write');
}

/**
 * 前台编辑器地址。
 *
 * @param int $post_id 0 = 新建；>0 = 编辑该篇。
 * @return string
 */
function ybh_front_editor_url($post_id = 0)
{
    $url = home_url('/' . YBH_FRONT_EDITOR_SLUG . '/');
    $post_id = (int) $post_id;
    return $post_id > 0 ? add_query_arg('post', $post_id, $url) : $url;
}

/** 当前请求路径（去首尾斜杠、去掉查询串） */
function ybh_front_editor_request_path()
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    return trim($path, '/');
}

/**
 * 当前请求是否要显示前台编辑器。
 *
 * 它决定「要不要加载那 1.3 MB 编辑器资源」以及「要不要禁缓存」，
 * 所以判定必须与真正渲染的条件一致。
 */
function ybh_front_editor_is_active()
{
    if (!YBH_FRONT_EDITOR || is_admin() || is_feed() || is_robots()) {
        return false;
    }
    if (ybh_front_editor_request_path() === YBH_FRONT_EDITOR_SLUG) {
        return true;
    }
    // 挂在普通页面上的短代码形式
    $obj = get_queried_object();
    return ($obj instanceof WP_Post) && has_shortcode((string) $obj->post_content, 'ybh_editor');
}

/**
 * 入队编辑器资源（只在真的要显示编辑器时调用）。
 */
function ybh_front_editor_enqueue()
{
    if (!function_exists('ybh_wangeditor_ready') || !ybh_wangeditor_ready()) {
        return false;
    }
    $dir = get_template_directory_uri() . '/' . YBH_WANGEDITOR_DIR;
    $ver = function_exists('ybh_asset_ver') ? ybh_asset_ver(YBH_WANGEDITOR_DIR . '/index.js') : YBH_VERSION;

    wp_enqueue_style('ybh-wangeditor', $dir . '/css/style.css', array(),
        function_exists('ybh_asset_ver') ? ybh_asset_ver(YBH_WANGEDITOR_DIR . '/css/style.css') : YBH_VERSION);
    wp_enqueue_script('ybh-wangeditor', $dir . '/index.js', array(), $ver, true);

    // 自定义菜单（脚注/上下标/注音/查找替换/特殊字符…）—— 全局注册，前后台共用
    wp_enqueue_script('ybh-wangeditor-menus', get_template_directory_uri() . '/js/ybh-wangeditor-menus.js',
        array('ybh-wangeditor'),
        function_exists('ybh_asset_ver') ? ybh_asset_ver('js/ybh-wangeditor-menus.js') : YBH_VERSION, true);

    // 内容规范化（与后台同一个 normalizer）
    wp_enqueue_script('ybh-content', get_template_directory_uri() . '/js/ybh-content.js', array(),
        function_exists('ybh_asset_ver') ? ybh_asset_ver('js/ybh-content.js') : YBH_VERSION, true);

    wp_enqueue_style('ybh-front-editor', get_template_directory_uri() . '/css/ybh.css',
        array(), function_exists('ybh_asset_ver') ? ybh_asset_ver('css/ybh.css') : YBH_VERSION);

    /*
     * T66 修复：**必须**加载 `css/ybh-wangeditor.css`。
     *
     * 这支样式表里放着「自管理弹层」 `.ybh-wam-overlay` / `.ybh-wam-box` 的定位
     * （`position:fixed; z-index:100200`）。前台第一版漏了它，后果实测是：
     *   · 特殊字符弹层 `position:static`、被追加到 body 末尾，**位置在视口外（y=1419）**；
     *   · 靠弹层工作的菜单（注音 Ruby、脚注、查找替换、粘贴为纯文本）点了"没反应"，
     *     看着就像"功能没同步"——其实只是弹层没样式。
     */
    wp_enqueue_style('ybh-wangeditor-panel', get_template_directory_uri() . '/css/ybh-wangeditor.css',
        array(), function_exists('ybh_asset_ver') ? ybh_asset_ver('css/ybh-wangeditor.css') : YBH_VERSION);

    wp_enqueue_script('ybh-front-editor', get_template_directory_uri() . '/js/ybh-frontend-editor.js',
        array('ybh-wangeditor', 'ybh-wangeditor-menus', 'ybh-content'),
        function_exists('ybh_asset_ver') ? ybh_asset_ver('js/ybh-frontend-editor.js') : YBH_VERSION, true);

    wp_localize_script('ybh-front-editor', 'YBH_FE', array(
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'saveAction'   => 'ybh_front_save',
        'saveNonce'    => wp_create_nonce('ybh_front_save'),
        'uploadAction' => 'ybh_wangeditor_upload',
        'uploadNonce'  => wp_create_nonce('ybh_wangeditor_upload'),
        'restTags'     => esc_url_raw(rest_url('wp/v2/tags')),
        'canUpload'    => current_user_can('upload_files'),
        'canPublish'   => current_user_can('publish_posts'),
        'i18n'         => array(
            'placeholder' => '在这里写正文……',
            'ready'       => '就绪：写好后点「保存草稿」或「提交审核」',
        ),
    ));
    return true;
}
add_action('wp_enqueue_scripts', function () {
    if (ybh_front_editor_is_active()) {
        ybh_front_editor_enqueue();
    }
}, 20);

/**
 * 全站加载的一小支：把「去写作页」的链接标成 `data-no-pjax`。
 *
 * 为什么不在写作页里做：**恰恰要在别的页面**（首页/资料页）上生效 ——
 * pjax 换页进写作页时，我们的控制器不会重跑，编辑器就会是空的（实测现象）。
 * 让进入编辑器的链接走整页加载即可，见 js/ybh-no-pjax-editor.js 的说明。
 */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script(
        'ybh-no-pjax-editor',
        get_template_directory_uri() . '/js/ybh-no-pjax-editor.js',
        array(),
        function_exists('ybh_asset_ver') ? ybh_asset_ver('js/ybh-no-pjax-editor.js') : YBH_VERSION,
        true
    );
}, 21);

/* ---------------------------------------------------------------------------
 * 短代码 [ybh_editor] —— 编辑器界面本体
 * ------------------------------------------------------------------------- */
add_shortcode('ybh_editor', 'ybh_front_editor_shortcode');
function ybh_front_editor_shortcode($atts = array())
{
    if (!YBH_FRONT_EDITOR) {
        return '';
    }

    if (!is_user_logged_in()) {
        $here = ybh_front_editor_url();
        return '<div class="ybh-fe-gate">'
            . '<h2>登录后开始写作</h2>'
            . '<p>这个页面用来投稿与写文章，需要先登录。</p>'
            . '<p><a class="ybh-fe__btn primary" href="' . esc_url(wp_login_url($here)) . '">去登录</a> '
            . '<a class="ybh-fe__btn ghost" href="' . esc_url(home_url('/')) . '">回首页</a></p>'
            . '</div>';
    }

    if (!current_user_can('edit_posts')) {
        return '<div class="ybh-fe-gate">'
            . '<h2>当前账号还不能发表文章</h2>'
            . '<p>你的账号没有写作权限。如果想投稿，先看看投稿说明。</p>'
            . '<p><a class="ybh-fe__btn primary" href="' . esc_url(home_url('/submit/')) . '">看看怎么投稿</a></p>'
            . '</div>';
    }

    if (!function_exists('ybh_wangeditor_ready') || !ybh_wangeditor_ready()) {
        return '<div class="ybh-fe-gate"><h2>编辑器暂时不可用</h2>'
            . '<p>编辑器资源缺失，请稍后再试，或改用后台编辑器。</p></div>';
    }

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    $post    = null;
    $notice  = '';

    if ($post_id > 0) {
        $post = get_post($post_id);
        if (!$post || 'post' !== $post->post_type || !current_user_can('edit_post', $post_id) || 'trash' === $post->post_status) {
            return '<div class="ybh-fe-gate"><h2>打不开这篇文章</h2>'
                . '<p>它可能已被删除，或者不属于你。可以回「我的文章」看看，或者新建一篇。</p>'
                . '<p><a class="ybh-fe__btn primary" href="' . esc_url(ybh_front_editor_url()) . '">写新文章</a> '
                . '<a class="ybh-fe__btn ghost" href="' . esc_url(ybh_profile_tab_url('posts')) . '">' . esc_html(ybh_t('我的文章')) . '</a></p></div>';
        }
        if ('publish' === $post->post_status && !current_user_can('publish_posts')) {
            $notice = '这是一篇已发布的文章：保存后会**退回待审核**，审核通过前前台暂时看不到它。';
        }
    }

    $can_publish = current_user_can('publish_posts');
    $title   = $post ? $post->post_title : '';
    $content = $post ? $post->post_content : '';
    $excerpt = $post ? $post->post_excerpt : '';

    ob_start();
    ?>
    <div class="ybh-fe" data-post="<?php echo (int) ($post ? $post->ID : 0); ?>">

      <header class="ybh-fe__bar">
        <h1 class="ybh-fe__heading"><?php echo esc_html(ybh_t($post ? '编辑文章' : '写文章')); ?></h1>
        <span class="ybh-fe__status" id="ybh-fe-status" role="status" aria-live="polite">载入编辑器……</span>
        <div class="ybh-fe__acts">
          <a class="ybh-fe__btn ghost" id="ybh-fe-view" <?php echo ($post && 'publish' === $post->post_status) ? 'href="' . esc_url(get_permalink($post)) . '"' : 'href="#" hidden'; ?> target="_blank" rel="noopener"><?php ybh_e('看看这篇'); ?></a>
          <a class="ybh-fe__btn ghost" href="<?php echo esc_url(ybh_profile_tab_url('posts')); ?>"><?php ybh_e('我的文章'); ?></a>
          <button type="button" class="ybh-fe__side-toggle" id="ybh-fe-side-toggle" aria-expanded="true" aria-controls="ybh-fe-side"><?php echo esc_html(ybh_t('收起选项栏')); ?></button>
          <button type="button" class="ybh-fe__btn" id="ybh-fe-save-draft"><?php ybh_e('保存草稿'); ?></button>
          <button type="button" class="ybh-fe__btn primary" id="ybh-fe-submit"
                  data-status="<?php echo $can_publish ? 'publish' : 'pending'; ?>">
            <?php echo esc_html(ybh_t($can_publish ? '发布' : '提交审核')); ?>
          </button>
        </div>
      </header>

      <?php if ('' !== $notice) : ?>
        <p class="ybh-fe__notice"><?php echo wp_kses_post($notice); ?></p>
      <?php endif; ?>

      <?php
      /*
       * 多余空行提示（保存时检查）：默认隐藏，保存后若服务端回报 `blank_count > 0`
       * 才显示，并给一个「一键清理」按钮 —— 站长的要求是**提醒 + 一键清理**，不自动改。
       */
      ?>
      <div class="ybh-fe__notice ybh-fe__notice--blank" id="ybh-fe-blank" hidden>
        <span id="ybh-fe-blank-text"></span>
        <button type="button" class="ybh-fe__btn" id="ybh-fe-blank-clean">一键清理这些空行</button>
      </div>

      <?php
      /*
       * T68：布局改成「主区 + 右侧栏」（参考后台经典编辑器——标题在上、
       * 语言/标签/分类等元信息在右侧；顶栏按钮可收起，状态记在 localStorage，
       * 窄屏自动落到正文下方）。
       *
       * 标签：可搜索、可选已存在标签（/wp-json/wp/v2/tags 公开数据）、也可新建，
       *   输入框的值仍是逗号分隔文本（id 不变），保存端点无需改动；
       * 分类：**单选**（radio，含"不设分类"项）——服务端只取第一个值兜底。
       */
      $ybh_lang = function_exists('ybh_post_language') ? ybh_post_language($post ? $post->ID : 0) : '';
      $ybh_can_cats = current_user_can('manage_categories');
      $ybh_cats_now = $post ? wp_get_post_categories($post->ID) : array();
      $ybh_cats_now = $ybh_cats_now ? (int) reset($ybh_cats_now) : 0;   // 只取一个（历史数据可能多个，取第一个）
      $ybh_tags_now = $post ? wp_get_post_tags($post->ID, array('fields' => 'names')) : array();
      $ybh_admin_link = current_user_can('edit_others_posts')
          ? ($post ? admin_url('post.php?post=' . (int) $post->ID . '&action=edit') : admin_url('post-new.php'))
          : '';
      ?>
      <div class="ybh-fe__cols" id="ybh-fe-cols">
        <div class="ybh-fe__main">

          <div class="ybh-fe__field">
            <label for="ybh-fe-title"><?php ybh_e('标题'); ?></label>
            <input type="text" id="ybh-fe-title" class="ybh-fe__title" maxlength="200"
                   placeholder="给文章起个标题" value="<?php echo esc_attr($title); ?>" />
          </div>

          <div class="ybh-fe__field">
            <label for="ybh-fe-body"><?php ybh_e('正文'); ?>
              <button type="button" class="ybh-fe__side-toggle" id="ybh-fe-insert-math"
                      title="<?php echo esc_attr(ybh_t('在光标处插入行内公式定界符 \\(\\)，写法如 \\(E=mc^2\\)')) ?>"><?php ybh_e('插入公式'); ?></button>
            </label>
            <?php
            /*
             * 工具栏必须**单独**放一个容器：本站这套 WangEditor 构建的
             * `createEditor()` 只建正文区，工具栏要用 `createToolbar()` 另外创建
             * （后台面板 js/ybh-wangeditor.js 就是这么做的）。
             */
            ?>
            <div id="ybh-fe-toolbar" class="ybh-fe__toolbar"></div>
            <div id="ybh-fe-body" class="ybh-fe__editor" data-post="<?php echo (int) ($post ? $post->ID : 0); ?>"
                 <?php echo '' !== $ybh_lang ? 'lang="' . esc_attr($ybh_lang) . '"' : ''; ?>
                 data-html="<?php echo esc_attr($content); ?>"></div>
          </div>

        </div>

        <aside class="ybh-fe__side" id="ybh-fe-side" aria-label="<?php echo esc_attr(ybh_t('文章选项')); ?>">
          <h3><?php echo esc_html(ybh_t('文章选项')); ?></h3>

          <?php if (function_exists('ybh_post_language_options')) : ?>
          <div class="ybh-fe__field ybh-fe__field--lang">
            <label for="ybh-fe-lang"><?php ybh_e('源语言'); ?></label>
            <select id="ybh-fe-lang" class="ybh-fe__select">
              <?php foreach (ybh_post_language_options() as $ybh_lval => $ybh_llabel) : ?>
                <option value="<?php echo esc_attr($ybh_lval); ?>" <?php selected($ybh_lang, $ybh_lval); ?>>
                  <?php echo esc_html($ybh_llabel); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <p class="ybh-fe__hint"><?php echo esc_html(ybh_t('这篇文章原文的语言；也决定正文的地区字形')); ?></p>
          </div>
          <?php endif; ?>

          <div class="ybh-fe__field ybh-fe__field--tax">
            <label for="ybh-fe-tag-input"><?php ybh_e('标签'); ?></label>
            <?php /* #ybh-fe-tags = 已选标签（逗号分隔，保存就发它）；可见框只负责搜索与新建（T68） */ ?>
            <input type="hidden" id="ybh-fe-tags" maxlength="300"
                   value="<?php echo esc_attr(implode('，', (array) $ybh_tags_now)); ?>" />
            <div class="ybh-fe__tags" id="ybh-fe-tags-box">
              <input type="text" id="ybh-fe-tag-input" class="ybh-fe__tag-input"
                     placeholder="<?php echo esc_attr(ybh_t('输入以搜索或新建标签，回车添加')); ?>" />
              <ul class="ybh-fe__tag-list" id="ybh-fe-tag-list" hidden></ul>
            </div>
            <p class="ybh-fe__hint"><?php ybh_e('可选已有标签，也可以输入新标签（保存时自动创建）'); ?></p>
          </div>

          <?php if ($ybh_can_cats) : ?>
          <div class="ybh-fe__field">
            <label><?php ybh_e('分类'); ?><span class="ybh-fe__hint">（<?php echo esc_html(ybh_t('单选')); ?>）</span></label>
            <div class="ybh-fe__cats" id="ybh-fe-cats">
              <label class="ybh-fe__cat">
                <input type="radio" name="ybh_cats" value="" <?php checked($ybh_cats_now, 0); ?> />
                <span><?php echo esc_html(ybh_t('不设分类')); ?></span>
              </label>
              <?php foreach (get_categories(array('hide_empty' => false, 'number' => 60)) as $ybh_cat) : ?>
                <label class="ybh-fe__cat">
                  <input type="radio" name="ybh_cats" value="<?php echo (int) $ybh_cat->term_id; ?>"
                    <?php checked($ybh_cats_now, (int) $ybh_cat->term_id); ?> />
                  <span><?php echo esc_html($ybh_cat->name); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php else : ?>
          <p class="ybh-fe__hint"><?php ybh_e('分类由编辑在审核时指定；你可以先用标签标注主题。'); ?></p>
          <?php endif; ?>

          <?php if ('' !== $ybh_admin_link) : ?>
          <div class="ybh-fe__field ybh-fe__field--admin">
            <label><?php echo esc_html(ybh_t('更多（后台）')); ?></label>
            <p class="ybh-fe__hint"><?php echo esc_html(ybh_t('封面图、作者、讨论等设置需要到后台。')); ?></p>
            <a class="ybh-fe__btn ghost" data-no-pjax href="<?php echo esc_url($ybh_admin_link); ?>">
              <?php echo esc_html(ybh_t('切换到后台编辑器（TinyMCE）')); ?>
            </a>
          </div>
          <?php endif; ?>
        </aside>
      </div>

      <?php
      /*
       * T66：**移除摘要输入框**。
       * 站长明确「摘要已弃用」——前台写作页不再显示它。
       * 保存端点仍接受 `post_excerpt`（老表单/其它调用路径不会因此报错），只是这里不再收集。
       * 模板里仍读 `$excerpt`，用于保留"编辑既有文章时不丢字段"的语义安全性（不写回而已）。
       */
      ?>

      <noscript>
        <p class="ybh-fe__notice">这个编辑器需要 JavaScript。你也可以
          <a href="<?php echo esc_url(admin_url('post-new.php')); ?>">改用后台编辑器</a>。</p>
      </noscript>

    </div>
    <?php
    return (string) ob_get_clean();
}

/* ---------------------------------------------------------------------------
 * `/write/` 路由：WordPress 会把它判成 404，这里接管
 * ------------------------------------------------------------------------- */
add_action('template_redirect', 'ybh_front_editor_route', 0);
function ybh_front_editor_route()
{
    if (!YBH_FRONT_EDITOR || is_admin() || is_feed() || is_robots()) {
        return;
    }
    if (ybh_front_editor_request_path() !== YBH_FRONT_EDITOR_SLUG) {
        return;
    }

    global $wp_query;
    if ($wp_query) {
        $wp_query->is_404 = false;          // 让主题按正常页面渲染（否则 body 带 error404）
    }
    status_header(200);
    ybh_front_editor_nocache();

    ybh_front_editor_enqueue();             // 早于 get_header()，样式会正常进 <head>

    get_header();
    echo '<main id="main" class="site-main ybh-fe-main" role="main">'
        . do_shortcode('[ybh_editor]')
        . '</main>';
    get_footer();
    exit;
}

/** 登录用户专属页面：禁用页面缓存，并把 nonce 相关的响应头设对 */
function ybh_front_editor_nocache()
{
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);    // Cache Enabler 认这个常量
    }
    if (!defined('DONOTCACHEOBJECT')) {
        define('DONOTCACHEOBJECT', true);
    }
    nocache_headers();
}

// 短代码形式的页面同样不能缓存（内容里有 nonce）
add_action('template_redirect', function () {
    if (ybh_front_editor_is_active()) {
        ybh_front_editor_nocache();
    }
}, 1);

/** 前台编辑器页的 body class：去掉 error404，加上自己的标记（便于写样式与排查） */
add_filter('body_class', function ($classes) {
    if (!ybh_front_editor_is_active()) {
        return $classes;
    }
    $classes = array_diff($classes, array('error404', 'not-found'));
    $classes[] = 'ybh-fe-page';
    return array_values($classes);
});

/** 页面标题（浏览器标签页） */
add_filter('document_title_parts', function ($parts) {
    if (ybh_front_editor_is_active() && ybh_front_editor_request_path() === YBH_FRONT_EDITOR_SLUG) {
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $parts['title'] = $post_id > 0 ? '编辑文章' : '写文章';
    }
    return $parts;
});
