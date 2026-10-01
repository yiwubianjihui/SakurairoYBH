<?php
/**
 * YBH · 前台「搜人」（T60）
 *
 * 目的：让读者按**昵称 / 用户名**找到站内的作者，并落到作者页（author archive）。
 * 数据层只做查询与拼装，渲染交给 `tpl/user-card.php`（`ybh_render_user_card()`）。
 *
 * ===================================================================
 * 🔴 隐私红线（三条，改动前请先读完）
 * ===================================================================
 *
 * 一、**`search_columns` 必须显式限定，绝不能含 `user_email`。**
 *
 *     `WP_User_Query` 的 `search` 参数在「没有显式给 search_columns」时是**按搜索词
 *     猜列**的（见 wp-includes/class-wp-user-query.php 的 prepare_query()）：
 *
 *         if ( str_contains( $search, '@' ) ) {
 *             $search_columns = array( 'user_email' );        // ← 就这一行
 *         } else {
 *             $search_columns = array( 'user_login', 'user_url',
 *                                      'user_email', 'user_nicename',
 *                                      'display_name' );       // ← 还是带 email
 *         }
 *
 *     也就是说：**只要有人在前台搜索框里输入 `@` 或 `@qq.com`，默认实现就会拿它去
 *     LIKE `user_email`**。返回 JSON / HTML 里哪怕只回一个「有没有这个人」的布尔差异，
 *     都足以把全站注册邮箱一个个枚举出来（email enumeration）。这不是"信息泄露一点点"，
 *     而是**上线即出事**：老师手工开通的账号邮箱会被爬走，随后就是钓鱼与撞库。
 *
 *     所以本文件把 `search_columns` 写死为
 *     `array( 'display_name', 'user_login', 'user_nicename' )`：
 *       · 注意 core 还会做一次 `array_intersect(...)` 校验，所以这**同时是**白名单兜底；
 *       · 万一以后有人加了 `user_search_columns` 过滤器想塞回 email，交集结果里也不会有。
 *     一并说明：**拼装 item 时也不取 `user_email`**（连字段都不读，就不存在"顺手输出"）。
 *
 * 二、**角色白名单，默认排除 `subscriber`。**
 *
 *     本站 `subscriber` 是「只是注册了/被塞进来的」账号（订阅、答题、评论用），
 *     它们的 display_name 往往就是真实姓名。把订阅者列进公开目录等于给全站用户
 *     做了一份姓名花名册。默认只列 `administrator / editor / author / contributor`
 *     —— 「写过东西的人」。可用 `ybh_user_search_roles` 覆盖。
 *
 * 三、**默认只列「有已发布文章」的用户**（`has_published_posts => array('post')`，WP ≥ 5.9）。
 *
 *     只读过文章、没投过稿的账号不进目录：搜人页是"找作者"的入口，不是站内用户列表。
 *     可用 `ybh_user_search_require_posts` 关掉（关掉后隐私风险回到第二条的角色白名单兜底）。
 *
 * 补充（权限判定）：本功能是**前台只读**的公开目录，**不要**用后台专用能力
 * `search_users`（那是 `list_users` 体系给 wp-admin 的安全阀）来卡权限 —— 那样只有
 * 管理员能用，功能就等于没做。`ybh_user_search_enabled()` 默认 true，接入方想关就
 * 用 `ybh_user_search_enabled` 过滤器关。
 *
 * ===================================================================
 * 与其他模块的关系
 * ===================================================================
 *   · 渲染          → `tpl/user-card.php` 的 `ybh_render_user_card( array $u )`
 *   · 头像          → `get_avatar_url()`，已被 `inc/ybh/avatar.php` 接管，直接用
 *   · 社交链接      → `apply_filters('ybh_author_social_links', array(), $id)`（T61 提供）
 *   · 作者资料聚合  → `ybh_author_profile()`（T62 提供），存在就优先复用
 *   · 分页链接      → `ybh_user_search_paginate()`，class 沿用主题 `page-numbers`
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 「搜人」总开关。
 *
 * 默认开。要用过滤器关（不要靠改本文件）：
 *
 *     add_filter('ybh_user_search_enabled', '__return_false');
 */
function ybh_user_search_enabled(): bool
{
    return (bool) apply_filters('ybh_user_search_enabled', true);
}

/**
 * 清理搜索词：去首尾空白 + 长度上限 100 字符（中文按字符数算）。
 *
 * 为什么要有上限：这个值会进 LIKE 查询，超长串既没意义又白烧数据库；
 * 100 字符足够覆盖最长的中文昵称。
 */
function ybh_user_search_clean_query(string $q): string
{
    $q = trim(sanitize_text_field($q));
    if ($q === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return (string) mb_substr($q, 0, 100, 'UTF-8');
    }
    return (string) substr($q, 0, 100);
}

/**
 * 允许进入「搜人」结果的角色。
 *
 * 默认四角色（见文件头红线二）；返回前统一：
 *   · 过滤空值 / 去重 / 转字符串；
 *   · 与站点实际存在的角色取交集（防拼错角色名导致 SQL 里出现不存在的 meta 值）；
 *   · **无条件剔除 `subscriber`**：即使有人用过滤器把它加回来，也不放行。
 *
 * @return string[]
 */
function ybh_user_search_roles(): array
{
    $default = array('administrator', 'editor', 'author', 'contributor');

    $roles = (array) apply_filters('ybh_user_search_roles', $default);
    $roles = array_values(array_filter(array_map('strval', $roles), 'strlen'));

    if (!$roles) {
        // 过滤器被清空时退回默认值：否则 role__in 为空 ⇒ 查询退化成"全部用户"，
        // 那才是真正的隐私事故（subscriber 会一起被列出来）。
        $roles = $default;
    }

    $roles = array_diff($roles, array('subscriber'));

    $wp_roles = wp_roles();
    if ($wp_roles instanceof WP_Roles) {
        $roles = array_intersect($roles, array_keys((array) $wp_roles->roles));
    }

    return array_values($roles);
}

/**
 * 是否只列「有已发布文章」的用户（文件头红线三）。
 */
function ybh_user_search_require_posts(): bool
{
    return (bool) apply_filters('ybh_user_search_require_posts', true);
}

/**
 * 搜人。
 *
 * @param string $q        搜索词（昵称 / 用户名 / user_nicename）
 * @param int    $paged    页码，从 1 开始
 * @param int    $per_page 每页条数（1..50，超出会被夹紧）
 * @return array{items:array,total:int,pages:int,per_page:int,paged:int,q:string}
 */
function ybh_user_search(string $q, int $paged = 1, int $per_page = 12): array
{
    // 每页条数上限 50：这是公开只读接口，没有上限就能被 `?per_page=100000` 拖死数据库
    $per_page = max(1, min(50, $per_page));
    $paged    = max(1, $paged);

    $q     = ybh_user_search_clean_query($q);
    $empty = array(
        'items'    => array(),
        'total'    => 0,
        'pages'    => 0,
        'per_page' => $per_page,
        'paged'    => $paged,
        'q'        => $q,
    );

    // 空查询直接返回空结果。
    // 🔴 绝不能因为"没搜到词"就退化成列出全部用户 —— 那等于给了一个用户目录接口。
    if ($q === '' || !ybh_user_search_enabled()) {
        return $empty;
    }

    $args = array(
        'search'         => $q,
        // 🔴 隐私红线一：显式列列，绝不包含 user_email。详见文件头。
        'search_columns' => array('display_name', 'user_login', 'user_nicename'),
        'role__in'       => ybh_user_search_roles(),
        'orderby'        => 'display_name',
        'order'          => 'ASC',
        // 自己算 offset 更稳：显式 offset 会覆盖 paged 的推算
        // （core 的 LIMIT 分支是 `if ( $qv['offset'] ) {...} else { number*(paged-1) }`）。
        // 这里同时传 paged，保证 core 用同一条公式算出同样的位置。
        'paged'          => $paged,
        'number'         => $per_page,
        'count_total'    => true,   // 供 total / pages 用（core 走 FOUND_ROWS()）
        'fields'         => 'all',  // 要 WP_User 对象来拿 display_name / roles
    );

    // 🔴 隐私红线三：默认只要写过已发布文章的人。
    // has_published_posts 是 WP 5.9 才有的参数；老版本降级为"不限制"，
    // 此时安全性由红线二的角色白名单兜底（所以这条不能当成唯一防线）。
    if (ybh_user_search_require_posts() && version_compare((string) get_bloginfo('version'), '5.9', '>=')) {
        $args['has_published_posts'] = array('post');
    }

    $query = new WP_User_Query($args);

    $items = array();
    foreach ((array) $query->get_results() as $user) {
        if ($user instanceof WP_User) {
            $items[] = ybh_user_search_item($user);
        }
    }

    $total = (int) $query->get_total();

    return array(
        'items'    => $items,
        'total'    => $total,
        'pages'    => (int) ceil($total / $per_page),
        'per_page' => $per_page,
        'paged'    => $paged,
        'q'        => $q,
    );
}

/**
 * 按字符截断（中文按一个字算），超出补省略号。
 *
 * 不用 `wp_trim_words()`：它是按**词**切的，中文没有空格 ⇒ 整段会被当成一个词，
 * 结果要么原样返回要么整段丢掉。中英混排必须按字符裁。
 */
function ybh_user_search_cut(string $text, int $limit = 80): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $len = function_exists('mb_strlen') ? (int) mb_strlen($text, 'UTF-8') : strlen($text);
    if ($len <= $limit) {
        return $text;
    }

    $cut = function_exists('mb_substr')
        ? (string) mb_substr($text, 0, $limit, 'UTF-8')
        : (string) substr($text, 0, $limit);

    return $cut . '…';
}

/**
 * 取某个作者的资料聚合（T62 模块 `ybh_author_profile()`）。
 *
 * 该函数**本任务时不保证存在**，所以：
 *   · `function_exists` 守卫；返回值不是数组就当作没有；
 *   · 任何签名不匹配（TypeError 等）都不该把整个搜人页打成 500 —— try/catch 兜住。
 *
 * @return array<string,mixed>
 */
function ybh_user_search_profile_data(int $user_id): array
{
    if (!function_exists('ybh_author_profile')) {
        return array();
    }

    try {
        $data = ybh_author_profile($user_id);
    } catch (Throwable $e) {
        return array();
    }

    return is_array($data) ? $data : array();
}

/**
 * 从聚合数据里挑一个字符串字段（兼容同一语义的多个键名）。
 *
 * T62 的返回结构尚未冻结，这里只按语义取第一个非空值，取不到就返回空串。
 */
function ybh_user_search_take(array $src, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($src[$key]) && is_scalar($src[$key]) && (string) $src[$key] !== '') {
            return (string) $src[$key];
        }
    }
    return '';
}

/**
 * 拼装单个用户 item。
 *
 * 🔴 这里**不读 `user_email`**（红线一）：不是"取出来再藏起来"，而是根本不取。
 *
 * @param WP_User $user
 * @return array{id:int,display_name:string,nickname:string,avatar_url:string,bio:string,url:string,post_count:int,roles:string[]}
 */
function ybh_user_search_item(WP_User $user): array
{
    $id = (int) $user->ID;

    $display_name = (string) $user->display_name;
    if ($display_name === '') {
        $display_name = (string) $user->user_nicename;
    }

    $nickname = (string) $user->nickname;
    if ($nickname === '') {
        $nickname = $display_name;
    }

    // 简介：作者 description（个人简介），裁到约 80 字。
    // 注意这里**只截断不转义** —— 转义统一在 `ybh_render_user_card()` 里做，
    // 免得数据层返回的字符串带着 `&amp;` 再被二次转义。
    $bio = (string) get_the_author_meta('description', $id);
    if ($bio === '') {
        $bio = $nickname;
    }
    $bio = ybh_user_search_cut($bio, 80);

    // 头像：本站已被 inc/ybh/avatar.php 接管（get_avatar_url 优先级 1000），直接用即可
    $avatar_url = (string) get_avatar_url($id, array('size' => 96));

    $url       = (string) get_author_posts_url($id);
    $postCount = (int) count_user_posts($id, 'post', true); // 第三个参数 true = 只数已发布

    $roles = array();
    if (isset($user->roles) && is_array($user->roles)) {
        $roles = array_values(array_filter(array_map('strval', $user->roles), 'strlen'));
    }

    $item = array(
        'id'           => $id,
        'display_name' => $display_name,
        'nickname'     => $nickname,
        'avatar_url'   => $avatar_url,
        'bio'          => $bio,
        'url'          => $url,
        'post_count'   => $postCount,
        'roles'        => $roles,
    );

    // T62 `ybh_author_profile()` 存在就优先复用它（头像 / 简介 / 主页 / 社交链接同一来源，
    // 免得和作者页显示的资料对不上）。字段名按语义兼容几种写法。
    $profile = ybh_user_search_profile_data($id);
    if ($profile) {
        $name = ybh_user_search_take($profile, array('display_name', 'nickname', 'name'));
        if ($name !== '') {
            $item['display_name'] = $name;
        }

        $pBio = ybh_user_search_take($profile, array('bio', 'description', 'intro'));
        if ($pBio !== '') {
            $item['bio'] = ybh_user_search_cut($pBio, 80);
        }

        $pAvatar = ybh_user_search_take($profile, array('avatar_url', 'avatar'));
        if ($pAvatar !== '') {
            $item['avatar_url'] = $pAvatar;
        }

        $pUrl = ybh_user_search_take($profile, array('url', 'permalink', 'author_url'));
        if ($pUrl !== '') {
            $item['url'] = $pUrl;
        }

        $pRoles = $profile['roles'] ?? null;
        if (is_array($pRoles) && $pRoles) {
            $item['roles'] = array_values(array_filter(array_map('strval', $pRoles), 'strlen'));
        }
    }

    /**
     * 过滤单个用户的对外字段。
     *
     * ⚠️ 往 `$item` 里加字段是安全的，但**不要往里塞 user_email**：
     * 渲染层不做白名单，会把数组里认识的字段直接输出（见 tpl/user-card.php）。
     *
     * @param array   $item
     * @param WP_User $user
     */
    return (array) apply_filters('ybh_user_search_item', $item, $user);
}

/**
 * 社交链接（T61 提供数据，现在必然为空数组 ⇒ 渲染层不输出社交区）。
 *
 * @return array<int,array{label?:string,url?:string,icon?:string}>
 */
function ybh_user_search_social(int $user_id): array
{
    $links = apply_filters('ybh_author_social_links', array(), $user_id);
    return is_array($links) ? $links : array();
}

/**
 * `tpl/user-card.php` 的绝对路径。
 *
 * 用 `get_template_directory()` 而不是相对路径：本文件既可能被 bootstrap 引入，
 * 也可能被子主题 / 短代码在别的上下文里引入，相对路径会飘。
 */
function ybh_user_card_template_path(): string
{
    return get_template_directory() . '/tpl/user-card.php';
}

/**
 * 渲染一张用户卡（转发到 `tpl/user-card.php`）。
 *
 * 渲染函数本体必须放在 tpl/user-card.php（下游依赖这个文件路径），
 * 这里按需 `require_once`，省得接入方必须记得同时引入 tpl 文件；
 * 万一渲染层还没加载，就返回空串 —— 卡片没了总比整个搜索页 fatal 好。
 */
function ybh_user_card_html(array $u): string
{
    if (!function_exists('ybh_render_user_card')) {
        $file = ybh_user_card_template_path();
        if (is_readable($file)) {
            require_once $file;
        }
    }

    if (!function_exists('ybh_render_user_card')) {
        return '';
    }

    return ybh_render_user_card($u);
}

/**
 * 渲染「搜人」结果列表。
 *
 * 容器 class 固定 `ybh-user-list`；样式由后续 CSS 任务负责，本文件不含任何 CSS。
 */
function ybh_user_search_list_html(array $result): string
{
    $items = isset($result['items']) && is_array($result['items']) ? $result['items'] : array();

    if (!$items) {
        $q = isset($result['q']) ? (string) $result['q'] : '';
        return '<p class="ybh-user-list__empty">'
            . ($q === ''
                ? '输入昵称或用户名开始搜索。'
                : '没有找到匹配的作者，换个关键词试试。')
            . '</p>';
    }

    $out = '<div class="ybh-user-list">';
    foreach ($items as $item) {
        if (is_array($item)) {
            $out .= ybh_user_card_html($item);
        }
    }
    $out .= '</div>';

    return $out;
}

/**
 * 分页链接。
 *
 * 直接复用 WP 的 `paginate_links()`（输出 `page-numbers` / `current`，
 * 主题的 `.page-numbers` 样式本来就覆盖到了），不自己拼 HTML。
 *
 * ⚠️ 两点需要注意：
 *   1. **搜索词必须跟着翻页走**。不给 `add_args` 的话，第 2 页的链接里没有搜索词，
 *      用户点过去会看到"第二页突然空了"。搜索词用什么参数名由接入方决定
 *      （默认同时也是 `s`），见下面的 `attach_query`。
 *   2. 基础地址默认是当前请求 URL（`paginate_links()` 自己去猜），这样无论接入方
 *      把搜人放在搜索页还是独立页面，链接都落在同一个页面上。
 */
function ybh_user_search_paginate(array $result, array $attach_query = array()): string
{
    $pages = isset($result['pages']) ? (int) $result['pages'] : 0;
    $paged = isset($result['paged']) ? max(1, (int) $result['paged']) : 1;
    $q     = isset($result['q']) ? (string) $result['q'] : '';

    if ($pages <= 1) {
        return '';
    }

    // 默认把搜索词挂在 `s` 上（`search.php` 就是这么读的）；接入方可以覆盖键名。
    $add_args = $q !== '' ? array('s' => $q) : array();
    foreach ($attach_query as $key => $value) {
        if (is_string($key) && $key !== '' && is_scalar($value)) {
            $add_args[$key] = (string) $value;
        }
    }

    return (string) paginate_links(array(
        'base'      => add_query_arg('paged', '%#%'),
        'format'    => '',
        'current'   => $paged,
        'total'     => $pages,
        'mid_size'  => 2,
        'end_size'  => 1,
        'type'      => 'list',
        'add_args'  => $add_args,
        'prev_text' => '上一页',
        'next_text' => '下一页',
    ));
}
