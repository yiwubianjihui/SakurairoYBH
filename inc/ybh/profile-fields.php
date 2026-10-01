<?php
/**
 * YBH · 个人资料字段注册表（T61）
 *
 * ===================================================================
 * 这个文件解决什么
 * ===================================================================
 *   1. **社交账号**：本站此前**没有任何 per-user 社交字段** —— 首页那几个
 *      GitHub / 微信 / 邮箱图标是**站长级主题选项**（`layouts/imgbox.php`），
 *      和作者本人无关。这里给每个用户加一组社交账号，并把它喂给已经写好、
 *      一直在等数据的 `ybh_author_social_links` 过滤器
 *      （消费方：`tpl/author-card.php` 作者页信息卡、`tpl/user-card.php` 搜人卡片）。
 *   2. **字段的单一来源**：键名、标签、图标、占位提示、校验规则都写在这一处，
 *      前台资料页与作者页都读它 —— 不会再出现"一处加字段、另一处忘了"。
 *   3. **拿掉"姓 / 名"的填写入口**：WordPress 核心的「个人资料」表单里有
 *      `first_name` / `last_name` 两个字段，而本站只需要**昵称与显示名**。
 *      核心**没有**提供移除它们的过滤器，所以只能用 CSS/JS 隐藏那两行。
 *      ⚠️ 只隐藏、不删除：输入框仍在 DOM 里、提交时原样回传，
 *      因此**不会把既有的姓/名数据清空**（这一点比"输出缓冲改写表单"安全）。
 *
 * ===================================================================
 * 存储约定
 * ===================================================================
 *   user_meta 键：`ybh_social_{platform}`（带前缀，不与 Yoast 等插件的
 *   裸键 `twitter`/`facebook` 冲突；也不往 wp-admin 表单里再塞字段）。
 *   值：URL 或纯文本（微信号 / QQ 号这类没有主页的，存纯文本，展示时不给链接）。
 *
 *   邮箱**不在这里**：前台不公开邮箱（见 `inc/ybh/profile.php` 的说明）。
 *
 * @package SakurairoYBH
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 社交平台注册表。
 *
 * 每项：`label` 显示名 ｜ `icon` FontAwesome 类 ｜ `ph` 输入框占位 ｜
 * `kind` 输入类型 ｜ `compose` 链接模板（uid/slug 用） ｜ `pattern` handle 校验 ｜
 * `extract` 从完整 URL 反解出 handle 的正则（捕获组 1） ｜ `url` 是否产出链接（兼容旧消费者）。
 *
 * kind 取值：
 *   · `uid`  —— 纯数字 ID，展示/跳转时按 `compose` 拼成链接（bilibili、微博）；
 *   · `slug` —— 用户名/handle，同样按 `compose` 拼（github、x、telegram、知乎、豆瓣）；
 *   · `url`  —— 必须填完整链接（Mastodon：实例名因人而异，没法替用户拼）；
 *   · `text` —— 纯文本展示、不给链接（微信号、QQ 号）。
 *
 * T68：用户**只填 UID 或用户名**即可 —— 拼链接这件事由这里统一做；
 * 填完整链接也行（会自动反解出 handle 再规范化存储；反解不出的原样按链接存）。
 * 要加平台就往下加一行 —— 前台表单、保存校验、作者页展示会同时生效。
 */
function ybh_profile_social_fields()
{
    $fields = array(
        'github'    => array(
            'label' => 'GitHub', 'icon' => 'fa-brands fa-github',
            'ph' => 'GitHub 用户名（填完整链接也行）',
            'kind' => 'slug', 'compose' => 'https://github.com/%s',
            'pattern' => '~^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$~',
            'extract' => '~github\.com/([A-Za-z0-9-]{1,39})~i',
        ),
        'bilibili'  => array(
            'label' => '哔哩哔哩', 'icon' => 'fa-brands fa-bilibili',
            'ph' => 'UID（纯数字，填完整链接也行）',
            'kind' => 'uid', 'compose' => 'https://space.bilibili.com/%s',
            'pattern' => '~^\d{1,20}$~',
            'extract' => '~space\.bilibili\.com/(\d{1,20})~i',
        ),
        'zhihu'     => array(
            'label' => '知乎', 'icon' => 'fa-brands fa-zhihu',
            'ph' => '知乎 ID（个人主页 people/ 后面那串）',
            'kind' => 'slug', 'compose' => 'https://www.zhihu.com/people/%s',
            'pattern' => '~^[A-Za-z0-9._-]{1,100}$~',
            'extract' => '~zhihu\.com/people/([A-Za-z0-9._-]{1,100})~i',
        ),
        'weibo'     => array(
            'label' => '微博', 'icon' => 'fa-brands fa-weibo',
            'ph' => '微博 UID（weibo.com/u/ 后面的数字）',
            'kind' => 'uid', 'compose' => 'https://weibo.com/u/%s',
            'pattern' => '~^\d{1,20}$~',
            'extract' => '~weibo\.com/u/(\d{1,20})~i',
        ),
        'telegram'  => array(
            'label' => 'Telegram', 'icon' => 'fa-brands fa-telegram',
            'ph' => 'Telegram 用户名（@开头也行）',
            'kind' => 'slug', 'compose' => 'https://t.me/%s',
            'pattern' => '~^[A-Za-z0-9_]{4,32}$~',
            'extract' => '~t\.me/([A-Za-z0-9_]{4,32})~i',
        ),
        'x'         => array(
            'label' => 'X（推特）', 'icon' => 'fa-brands fa-x-twitter',
            'ph' => 'X 用户名（@开头也行）',
            'kind' => 'slug', 'compose' => 'https://x.com/%s',
            'pattern' => '~^[A-Za-z0-9_]{1,15}$~',
            'extract' => '~(?:twitter\.com|x\.com)/([A-Za-z0-9_]{1,15})~i',
        ),
        'mastodon'  => array(
            'label' => 'Mastodon', 'icon' => 'fa-brands fa-mastodon',
            'ph' => '完整主页链接（实例名因人而异）',
            'kind' => 'url',
        ),
        'douban'    => array(
            'label' => '豆瓣', 'icon' => 'fa-solid fa-book-open',
            'ph' => '豆瓣 ID（people/ 后面那串）',
            'kind' => 'slug', 'compose' => 'https://www.douban.com/people/%s',
            'pattern' => '~^[A-Za-z0-9._-]{1,100}$~',
            'extract' => '~douban\.com/people/([A-Za-z0-9._-]{1,100})~i',
        ),
        'qq'        => array(
            'label' => 'QQ', 'icon' => 'fa-brands fa-qq',
            'ph' => 'QQ 号（只显示数字，不成链接）',
            'kind' => 'text',
        ),
        'wechat'    => array(
            'label' => '微信', 'icon' => 'fa-brands fa-weixin',
            'ph' => '微信号（只显示文字，不成链接）',
            'kind' => 'text',
        ),
    );

    // 兼容旧消费者：`url` 布尔 = 这个字段会不会产出链接（text 之外都是）
    foreach ($fields as $k => $cfg) {
        $fields[$k]['url'] = ($cfg['kind'] ?? 'url') !== 'text';
    }

    /**
     * 过滤：社交平台清单。加平台不必改本文件（但前台表单是按本清单渲染的，
     * 用过滤器加进来的项同样会出现）。
     *
     * @param array $fields 平台键 => 配置。
     */
    return apply_filters('ybh_profile_social_fields', $fields);
}

/** 单个平台的 user_meta 键 */
function ybh_profile_social_meta_key($key)
{
    return 'ybh_social_' . sanitize_key($key);
}

/**
 * 取某用户填过的社交账号（已过滤空值）。
 *
 * T68：meta 里存的是 **handle（UID/用户名）**（ Mastodon 等存完整 URL），
 * 展示用链接在这里按 `compose` 模板拼出来 —— 平台改版只改模板一处。
 * 存量数据（早期填的完整 URL）原样兼容。
 *
 * @param int $uid
 * @return array 平台键 => array( 'label', 'icon', 'value', 'url'|'' )
 */
function ybh_profile_social_values($uid)
{
    $out = array();
    foreach (ybh_profile_social_fields() as $key => $cfg) {
        $val = (string) get_user_meta((int) $uid, ybh_profile_social_meta_key($key), true);
        $val = trim($val);
        if ($val === '') {
            continue;
        }
        $kind = $cfg['kind'] ?? 'url';
        $url = '';
        if ($kind === 'url') {
            $url = $val;
        } elseif ($kind !== 'text') {
            $url = preg_match('#^https?://#i', $val)
                ? $val                                    // 存量数据：当时存的就是完整链接
                : sprintf($cfg['compose'], $val);         // T68：handle → 链接
        }
        $out[$key] = array(
            'label' => $cfg['label'],
            'icon'  => $cfg['icon'],
            'value' => $val,
            // 非链接类（微信号/QQ）不给 href，避免把纯文本塞进 href
            'url'   => $url,
        );
    }
    return $out;
}

/**
 * 清洗一个社交字段的值。
 *
 * T68 按 `kind` 分流：
 *   · `text`（微信号 / QQ）——纯文本，限长；
 *   · `uid` / `slug`——接受 **handle**（UID 或用户名，@ 前缀自动去掉），
 *     也接受完整链接（自动反解出 handle 再存；反解不出才按链接存）；
 *     校验不过返回 ''（前台整体回退并提示）。
 *   · `url`（Mastodon）——与旧规则一致：必须是"有域名"的 http(s) 链接，
 *     缺协议自动补 https://。
 *
 * @param string $key  平台键
 * @param string $raw  原始输入
 * @return string 清洗后的值（'' 表示清空该字段 / 校验失败）
 */
function ybh_profile_social_sanitize($key, $raw)
{
    $fields = ybh_profile_social_fields();
    if (!isset($fields[$key])) {
        return '';
    }
    $raw = trim(wp_unslash((string) $raw));
    if ($raw === '') {
        return '';
    }
    $kind = $fields[$key]['kind'] ?? 'url';

    if ($kind === 'text') {
        // 微信号 / QQ 号：纯文本，限长，去掉控制字符
        return mb_substr(sanitize_text_field($raw), 0, 60);
    }

    if ($kind === 'uid' || $kind === 'slug') {
        // 完整链接 → 反解出 handle（拿不准的（如自建域名）才落到"按链接存"）
        if (preg_match('#^https?://#i', $raw)) {
            if (!empty($fields[$key]['extract'])
                && preg_match($fields[$key]['extract'], $raw, $m)) {
                return $m[1];
            }
            $clean = esc_url_raw($raw);
            return ($clean !== '' && preg_match('#^https?://[^\s.]+\.#i', $clean))
                ? mb_substr($clean, 0, 200) : '';
        }
        $handle = ltrim($raw, '@');                       // @用户名 的习惯写法
        $handle = sanitize_text_field($handle);
        if ($handle === '' || mb_strlen($handle) > 120) {
            return '';
        }
        if (empty($fields[$key]['pattern']) || !preg_match($fields[$key]['pattern'], $handle)) {
            return '';                                     // handle 不合法 → 整表单回退提示
        }
        return $handle;
    }

    // kind = url：缺协议自动补 https://，再按"有域名"校验
    if (!preg_match('#^https?://#i', $raw)) {
        $raw = 'https://' . ltrim($raw, '/');
    }
    $clean = esc_url_raw($raw);
    if ($clean === '' || !preg_match('#^https?://[^\s.]+\.#i', $clean)) {
        return '';
    }
    return mb_substr($clean, 0, 200);
}

/**
 * 把该用户的社交账号转成「作者页 / 搜人卡片」要的格式，并挂到那条过滤器上。
 *
 * 消费方（都已实现，本模块上线前它们拿到的是空数组 ⇒ 社交区整段不输出）：
 *   · `tpl/author-card.php` 作者信息卡
 *   · `tpl/user-card.php`   搜索「搜人」的卡片
 *   · `inc/ybh/user-search.php` 里对 `ybh_author_profile()` 的调用链
 */
add_filter('ybh_author_social_links', 'ybh_profile_author_social_links', 10, 2);
function ybh_profile_author_social_links($links, $uid)
{
    $links = is_array($links) ? $links : array();
    foreach (ybh_profile_social_values($uid) as $one) {
        $links[] = array(
            'label' => $one['label'],
            'icon'  => $one['icon'],
            'url'   => $one['url'] !== '' ? $one['url'] : '',       // 空表示"不链接"
            'text'  => $one['value'],                               // 非链接类展示这个
        );
    }
    return $links;
}

/* ===========================================================================
 * 拿掉「姓 / 名」的填写入口
 * ========================================================================= */

/**
 * 在核心「个人资料 / 添加用户 / 编辑用户」表单里隐藏 `first_name` / `last_name`。
 *
 * 为什么用 CSS 而不是"输出缓冲改写表单"：
 *   · 核心没有过滤器，改写 HTML 要吞整个页面缓冲，升级 WP 就可能失效甚至白屏；
 *   · **只隐藏不会丢数据** —— 输入框还在 DOM 里，提交时原样回传，
 *     既有的姓/名不会被清空（真要清空得显式删 meta，那属于数据操作，不在本项范围）。
 *
 * ⚠️ 依赖核心的 `<tr class="user-first-name-wrap">` / `user-last-name-wrap` 结构；
 *    换语言包不影响（是 class 不是文案）。若某天核心改了结构，最坏结果是这两行
 *    重新出现，不会造成别的破坏。
 */
add_action('admin_head-profile.php', 'ybh_profile_hide_name_fields');
add_action('admin_head-user-edit.php', 'ybh_profile_hide_name_fields');
add_action('admin_head-user-new.php', 'ybh_profile_hide_name_fields');
function ybh_profile_hide_name_fields()
{
    ?>
    <style>
      /* T61：本站只用昵称与显示名，姓/名两行不显示（输入框仍在，提交不受影响） */
      .user-first-name-wrap,
      .user-last-name-wrap,
      .user-nickname-wrap ~ .user-first-name-wrap,
      #first_name, #last_name,
      .form-field input[name="first_name"], .form-field input[name="last_name"] {
        display: none !important;
      }
    </style>
    <?php
}

/**
 * 提交时**丢弃**新填的姓/名（但不动已有数据）。
 *
 * 说明：只隐藏的话，用户在页面上看不到也改不了，值不会变 —— 一般不需要这一步。
 * 但"添加用户"表单里姓/名默认是空的，某些插件/主题会把空值写进去覆盖；
 * 这里显式挡一道：**只有当值真的变了才干预**，且只把"变成空"这种情况还原回来。
 */
add_action('personal_options_update', 'ybh_profile_keep_name_fields');
add_action('edit_user_profile_update', 'ybh_profile_keep_name_fields');
function ybh_profile_keep_name_fields($uid)
{
    $uid = (int) $uid;
    if ($uid <= 0 || !current_user_can('edit_user', $uid)) {
        return;
    }
    foreach (array('first_name', 'last_name') as $k) {
        if (!isset($_POST[$k])) {
            continue;                       // 表单里没有这个字段（例如我们隐藏后的自定义表单）
        }
        $old = (string) get_user_meta($uid, $k, true);
        $new = sanitize_text_field(wp_unslash((string) $_POST[$k]));
        // 从"有值"变成"空" ⇒ 视为被隐藏字段的空提交，还原旧值，避免静默清空
        if ($old !== '' && $new === '') {
            update_user_meta($uid, $k, $old);
        }
    }
}
