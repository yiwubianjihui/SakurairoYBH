<?php
/**
 * YBH · 自建头像 API（T24）
 *
 * ===========================================================================
 * 一、这个模块解决什么
 * ===========================================================================
 * 站点原先的头像完全交给第三方：
 *   · 无头像的邮箱 → WP 的 `mystery` 灰色小人 / Cravatar 的 `d=mm` 占位图
 *   · 有头像的邮箱 → 浏览器直接去 `cravatar.com` 取图（客户端可达性不稳）
 *   · 已激活的 WPAvatar 插件在 `uploads/cravatar/` 落了一份缓存，但里面
 *     **混着真头像与占位图**（实测 40 个文件里绝大多数是同一张 694 B 的灰人）
 *
 * 本模块把这套链路整体接管为本站自建：
 *   · `get_avatar_url` 优先级 **1000**（压过 WPAvatar 的 999），URL 一律指向
 *     `ybh-avatar.php`；
 *   · `get_avatar` 优先级 **21**（在 WPAvatar 的 `serve_cached_avatar`(20) 之后）
 *     重写整个 `<img>`，于是 WPAvatar 塞进去的 `onerror=…default-avatar.png`、
 *     以及 Cravatar 的 `d=mm` 统统不会再出现在页面里；
 *   · 没有头像的邮箱统一走**站点默认头像**（`img/ybh-default-avatar.webp`，阿卡林），
 *     不再出现灰色小人 / `d=mm`；
 *   · 注册用户可以在后台资料页（或前台短代码）**自行上传头像**。
 *
 * ===========================================================================
 * 二、为什么是「接管 URL」而不是「停用 WPAvatar」
 * ===========================================================================
 * WPAvatar 的抓取/落盘已经跑通，且它注册的 `pre_get_avatar_data` 会做
 * URL 规整。直接停用会牵动缓存目录迁移与一批历史文件；而我们的优先级更高，
 * 接管后 WPAvatar 的输出钩子实际已不生效，**保留激活状态但不产生作用**，
 * 是风险最小的做法（详见交接文档 T24 的「方案 A」）。
 *
 * 为了避免做无用功，下面会主动摘掉 WPAvatar 的两个**输出型**钩子
 * （`get_avatar` prio 20 / `get_avatar_url` prio 999）—— 它们在被我们覆盖前
 * 会先读一次磁盘、拼一次 URL，纯属浪费。摘除失败也无害（我们优先级更高）。
 *
 * ===========================================================================
 * 三、缓存与「换头像立即生效」
 * ===========================================================================
 * 头像 URL 形如
 *     …/ybh-avatar.php?u=17&h=<md5>&s=96&v=1700000000
 * 其中 `v` 是用户上传文件（或回源落盘文件）的 mtime。用户换头像 ⇒ 文件被重写
 * ⇒ mtime 变 ⇒ URL 变 ⇒ **浏览器与 CDN 都不会命中旧图**。因此端点可以对
 * 「已落盘的头像」放心使用一年期的强缓存。
 *
 * ===========================================================================
 * 四、上传限制
 * ===========================================================================
 * 仅**已登录且能编辑该用户资料**的人可上传；≤ 2 MB；jpg / png / webp；
 * 一律经 GD 居中裁方并**重编码为 webp**（重编码同时起到「剥离任何嵌入内容」
 * 的作用）；落盘为 `uploads/ybh-avatars/u{user_id}.webp`，文件名固定，
 * 不接受用户提供的任何路径片段。
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ===================================================================== *
 * 第一部分：URL 生成
 * ===================================================================== */

/**
 * 头像端点地址。
 */
function ybh_avatar_endpoint_url(): string
{
    return get_template_directory_uri() . '/ybh-avatar.php';
}

/**
 * 站点默认头像地址（无 h/u 参数 ⇒ 端点直接吐阿卡林）。
 */
function ybh_avatar_default_url(int $size = 96): string
{
    return add_query_arg('s', $size, ybh_avatar_endpoint_url());
}

/**
 * 从「get_avatar 家族」的 $id_or_email 里解析出 user_id 与邮箱。
 *
 * 逻辑与 WP 核心 `get_avatar_data()` 保持一致，但我们自己实现一份，
 * 因为过滤器的参数就是原始的 `$id_or_email`，拿不到核心已解析的结果。
 *
 * @param mixed $id_or_email
 * @return array{0:int,1:string} [user_id, email]
 */
function ybh_avatar_resolve($id_or_email): array
{
    $user_id = 0;
    $email   = '';

    if (is_numeric($id_or_email)) {
        $user_id = (int) $id_or_email;
    } elseif ($id_or_email instanceof WP_User) {
        $user_id = (int) $id_or_email->ID;
        $email   = (string) $id_or_email->user_email;
    } elseif ($id_or_email instanceof WP_Post) {
        $user_id = (int) $id_or_email->post_author;
    } elseif ($id_or_email instanceof WP_Comment) {
        if (!empty($id_or_email->user_id)) {
            $user_id = (int) $id_or_email->user_id;
        }
        $email = (string) $id_or_email->comment_author_email;
    } elseif (is_object($id_or_email)) {
        // 兼容各种「看起来像评论/用户」的对象
        if (!empty($id_or_email->comment_author_email)) {
            $email = (string) $id_or_email->comment_author_email;
        }
        if (!empty($id_or_email->user_id)) {
            $user_id = (int) $id_or_email->user_id;
        } elseif (!empty($id_or_email->ID) && !empty($id_or_email->user_email)) {
            $user_id = (int) $id_or_email->ID;
            $email   = (string) $id_or_email->user_email;
        }
    } elseif (is_string($id_or_email) && strpos($id_or_email, '@') !== false) {
        $email = $id_or_email;
    }

    // 有 user_id 却缺邮箱时补一次查询（hash 需要邮箱）
    if ($user_id > 0 && $email === '') {
        $u = get_userdata($user_id);
        if ($u) {
            $email = (string) $u->user_email;
        }
    }

    return array($user_id, strtolower(trim($email)));
}

/**
 * 头像缓存文件的绝对路径（与 ybh-avatar.php 中的命名约定必须一致）。
 */
function ybh_avatar_cache_file(int $user_id, string $hash): ?string
{
    $dir = wp_upload_dir();
    if (empty($dir['basedir'])) {
        return null;
    }
    $base = trailingslashit($dir['basedir']) . 'ybh-avatars';

    if ($user_id > 0) {
        $f = $base . '/u' . $user_id . '.webp';
        if (is_readable($f) && filesize($f) > 0) {
            return $f;
        }
    }
    if ($hash !== '' && preg_match('/^[a-f0-9]{32}$/', $hash)) {
        $f = $base . '/' . $hash . '.webp';
        if (is_readable($f) && filesize($f) > 0) {
            return $f;
        }
    }

    return null;
}

/**
 * 拼出一个完整的头像端点 URL。
 *
 * `v`（版本）只在「本服务器上确实有这个文件」时才带上 —— 这样：
 *   · 用户传过头像 ⇒ URL 带 v ⇒ 换头像即刻生效（详见文件头第三节）；
 *   · 从没传过 ⇒ URL 不带 v ⇒ 端点自行回源或给默认图。
 *
 * @param mixed $id_or_email
 */
function ybh_avatar_build_url($id_or_email, int $size = 96): string
{
    list($user_id, $email) = ybh_avatar_resolve($id_or_email);
    $hash = ($email !== '') ? md5($email) : '';

    if ($user_id <= 0 && $hash === '') {
        return ybh_avatar_default_url($size);
    }

    $args = array('s' => $size);
    if ($user_id > 0) {
        $args['u'] = $user_id;
    }
    if ($hash !== '') {
        $args['h'] = $hash;
    }

    $file = ybh_avatar_cache_file($user_id, $hash);
    if ($file) {
        $args['v'] = (int) @filemtime($file);
    }

    return add_query_arg($args, ybh_avatar_endpoint_url());
}

/**
 * 【关键钩子 1】接管 `get_avatar_url`。
 *
 * ⚠️ 优先级必须 **> 999**：本站 `get_avatar_url` 上已经挂了三方，其中
 * WPAvatar 的 `Cravatar::get_avatar_url` 用的是 **999**，在它之前主题的
 * `gravatar_cn` 用 **4**（把 gravatar.com 替换成国内镜像）。
 * 要在它们全部跑完之后说话，只能用 1000。
 * **请勿因为「1000 看起来很奇怪」就把它改小或删掉。**
 */
add_filter('get_avatar_url', 'ybh_avatar_url', 1000, 3);
function ybh_avatar_url($url, $id_or_email, $args = array())
{
    $args = is_array($args) ? $args : array();

    // 调用方显式要求「就用默认头像」（例如 get_avatar(..., $default, ..., ['force_default'=>true])）
    if (!empty($args['force_default'])) {
        $size = isset($args['size']) ? (int) $args['size'] : 96;
        return ybh_avatar_default_url($size > 0 ? $size : 96);
    }

    $size = isset($args['size']) && (int) $args['size'] > 0 ? (int) $args['size'] : 96;

    return ybh_avatar_build_url($id_or_email, $size);
}

/**
 * 【关键钩子 2】接管 `get_avatar` 的 HTML。
 *
 * 为什么有了 URL 还要重写 HTML：本站有**两处**会在 `get_avatar` 里直接拼 `<img>`，
 *   · 主题 `change_avatar`（prio 10）—— 评论带 QQ 号时换成 QQ 头像；
 *   · WPAvatar `Cache::serve_cached_avatar`（prio 20）—— 直接吐本地缓存 + 塞
 *     `onerror` 回退到插件自己的 default-avatar.png。
 * 只改 URL 拦不住它们；必须在它们之后（**prio 21**）整体重写标签。
 *
 * QQ 头像分支要**放过**：那是主题的既定行为，且与「无头像时用什么」无关。
 */
add_filter('get_avatar', 'ybh_avatar_html', 21, 6);
function ybh_avatar_html($avatar, $id_or_email, $size = 96, $default = '', $alt = '', $args = array())
{
    // 尊重主题的 QQ 头像（qlogo / 主题自建 REST 端点），不干预
    if (is_string($avatar) && $avatar !== ''
        && (strpos($avatar, 'qlogo.cn') !== false || strpos($avatar, 'qqinfo/avatar') !== false)) {
        return $avatar;
    }

    $size = (int) $size;
    if ($size <= 0) {
        $size = 96;
    }

    // 主题/插件若关闭了头像显示，保持关闭
    if (function_exists('get_option') && !get_option('show_avatars')) {
        return false;
    }

    $args = is_array($args) ? $args : array();

    $src = (!empty($args['force_default']))
        ? ybh_avatar_default_url($size)
        : ybh_avatar_build_url($id_or_email, $size);

    // alt：沿用 WP 的语义 —— 为空时输出空 alt（装饰性）
    if ($alt === '' || $alt === false || $alt === null) {
        $altAttr = '';
    } else {
        $altAttr = ' alt="' . esc_attr((string) $alt) . '"';
    }

    /*
     * ⚠️ 必须保留调用方通过 $args 传进来的 class / extra_attr。
     *
     * 本站评论区模板是这么调的：
     *     get_avatar($email, 80, '', $name, array('class' => array('lazyload')))
     * 那个 `lazyload` 是**功能性的**，不是样式修饰 —— 模板随后还会把标签里的
     * `src=` 换成一个占位 SVG、把真实地址挪到 `data-src=`，全靠这个 class 让
     * 懒加载脚本把图补回来。重写 HTML 时若把它丢了，头像会**永远停在占位图上**。
     * 同理 extra_attr 也照搬（WP 核心就是把它原样拼在 class 之后的）。
     */
    $classes = array('avatar', 'avatar-' . $size, 'photo');
    if (!empty($args['class'])) {
        $extra = is_array($args['class']) ? $args['class'] : array($args['class']);
        foreach ($extra as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $classes[] = $c;
            }
        }
    }
    $classes = array_values(array_unique($classes));

    $extraAttr = '';
    if (!empty($args['extra_attr'])) {
        $extraAttr = ' ' . (string) $args['extra_attr'];
    }

    // 站点多处用 <i>/<em> 做图标宿主，头像一律用 <img>，避免被图标字体规则波及
    $srcset = ' srcset="' . esc_url(add_query_arg('s', $size * 2, $src)) . ' 2x"';

    return sprintf(
        '<img%1$s src="%2$s"%3$s class="%4$s" height="%5$d" width="%5$d" loading="lazy" decoding="async" data-ybh-avatar="1"%6$s />',
        $altAttr,
        esc_url($src),
        $srcset,
        esc_attr(implode(' ', $classes)),
        $size,
        $extraAttr
    );
}

/**
 * 【性能】摘掉 WPAvatar 的输出型 / 短路型钩子。
 *
 * 逐个说明为什么：
 *   · `get_avatar` prio 20（serve_cached_avatar）—— 直接吐它自己缓存目录里的图，
 *     还会在标签上塞 `onerror` 回退到插件自带的 default-avatar.png。我们 prio 21
 *     会整个重写，留着它纯属多读一次磁盘。
 *   · `get_avatar_url` prio 999 —— 我们 prio 1000 覆盖它，留着只是多拼一次字符串。
 *   · `pre_get_avatar_data` prio 1 —— **这个最要紧**：它在 `get_avatar_data()` 的最开头
 *     运行，一旦它往 `$args['url']` 里写了值，核心就会**提前 return 且完全跳过
 *     `get_avatar_url` 过滤器** ⇒ 直接调用 `get_avatar_url()` 的地方会拿到第三方 URL。
 *     必须摘掉，否则「接管 URL」就不完整。
 *
 * 回调注册的确切形态不确定（可能是实例数组、也可能是静态字符串），因此按几种
 * 常见写法都试一遍，最后再从全局 $wp_filter 里兜底捞一遍。**失败不影响正确性**。
 */
add_action('init', 'ybh_avatar_unhook_wpavatar', 99);
function ybh_avatar_unhook_wpavatar()
{
    $targets = array(
        'get_avatar'          => array(20, 'serve_cached_avatar'),
        'get_avatar_url'      => array(999, 'get_avatar_url'),
        'pre_get_avatar_data' => array(1, 'pre_get_avatar_data'),
    );

    foreach ($targets as $tag => $spec) {
        list($prio, $method) = $spec;
        foreach (array('\WPAvatar\Cache', 'WPAvatar\Cache', '\WPAvatar\Cravatar', 'WPAvatar\Cravatar') as $cls) {
            remove_filter($tag, array($cls, $method), $prio);
        }
    }

    // 实例化注册的写法：遍历全局钩子表按类名移除
    global $wp_filter;
    foreach (array('get_avatar', 'get_avatar_url', 'pre_get_avatar_data') as $tag) {
        if (empty($wp_filter[$tag]) || !is_object($wp_filter[$tag])) {
            continue;
        }
        foreach ($wp_filter[$tag]->callbacks as $prio => $cbs) {
            foreach ($cbs as $cb) {
                $fn = isset($cb['function']) ? $cb['function'] : null;
                if (is_array($fn) && is_object($fn[0]) && strpos(get_class($fn[0]), 'WPAvatar') !== false) {
                    remove_filter($tag, $fn, $prio);
                }
            }
        }
    }
}

/* ===================================================================== *
 * 第二部分：用户上传
 * ===================================================================== */

/** 上传体积上限（字节） */
define('YBH_AVATAR_MAX_BYTES', 2 * 1024 * 1024);

/** 允许的图片类型 */
function ybh_avatar_allowed_types(): array
{
    return array('image/jpeg', 'image/png', 'image/webp');
}

/**
 * 用户头像文件的绝对路径。与 ybh-avatar.php 的 `u{id}.webp` 约定一致。
 */
function ybh_avatar_user_file(int $user_id): ?string
{
    $dir = wp_upload_dir();
    if (empty($dir['basedir'])) {
        return null;
    }
    return trailingslashit($dir['basedir']) . 'ybh-avatars/u' . $user_id . '.webp';
}

/**
 * 用户是否已上传过自己的头像。
 */
function ybh_avatar_user_has_file(int $user_id): bool
{
    $f = ybh_avatar_user_file($user_id);
    return $f && is_readable($f) && filesize($f) > 0;
}

/**
 * 清掉某用户的头像缓存（主文件 + 全部尺寸变体 + 该邮箱的回源缓存）。
 *
 * ⚠️ 换头像后**必须**调用：端点会按 `{base}-{mtime}-{size}.webp` 生成尺寸变体，
 * 虽然文件名里的 mtime 已经能自动区分新旧，但旧变体会永远留在磁盘上。
 */
function ybh_avatar_purge_user(int $user_id): void
{
    $dir = wp_upload_dir();
    if (empty($dir['basedir'])) {
        return;
    }
    $base = trailingslashit($dir['basedir']) . 'ybh-avatars';

    foreach ((array) glob($base . '/u' . $user_id . '*.webp') as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }

    // 同时清掉「以该用户邮箱算出的 hash」的回源缓存：用户传了新头像后，
    // 若哪天他删掉了上传，应该回到回源结果，而不是一份过期的旧图。
    $u = get_userdata($user_id);
    if ($u && !empty($u->user_email)) {
        $hash = md5(strtolower(trim((string) $u->user_email)));
        foreach ((array) glob($base . '/' . $hash . '*.webp') as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @unlink($base . '/.miss/' . $hash);
    }
    // 让 URL 上的 v 立刻变化（filemtime 可能因文件系统精度在极短时间内不变）
    update_user_meta($user_id, 'ybh_avatar_ver', time());
}

/**
 * 把上传的图片处理成 256×256 的方形 webp。
 *
 * @param string $tmp  临时文件路径
 * @param string $dest 目标路径
 * @return true|WP_Error
 */
function ybh_avatar_process_upload(string $tmp, string $dest)
{
    if (!file_exists($tmp)) {
        return new WP_Error('ybh_avatar_missing', '上传的文件不存在，请重试。');
    }
    $bytes = filesize($tmp);
    if ($bytes === false || $bytes <= 0) {
        return new WP_Error('ybh_avatar_empty', '上传的文件是空的。');
    }
    if ($bytes > YBH_AVATAR_MAX_BYTES) {
        return new WP_Error('ybh_avatar_too_big', '图片不能超过 2 MB。');
    }

    // 真正的类型判定交给 GD 解码前的 getimagesize（不信任 $_FILES['type']）
    $info = @getimagesize($tmp);
    if (!$info || empty($info['mime'])) {
        return new WP_Error('ybh_avatar_not_image', '这不是一张有效的图片。');
    }
    if (!in_array($info['mime'], ybh_avatar_allowed_types(), true)) {
        return new WP_Error('ybh_avatar_type', '只支持 JPG / PNG / WebP 格式。');
    }
    if ($info[0] < 16 || $info[1] < 16) {
        return new WP_Error('ybh_avatar_too_small', '图片太小了，请上传至少 16×16 的图片。');
    }
    if (!function_exists('imagewebp')) {
        return new WP_Error('ybh_avatar_no_gd', '服务器缺少图像处理支持，暂时无法上传头像。');
    }

    $data = @file_get_contents($tmp);
    if ($data === false || $data === '') {
        return new WP_Error('ybh_avatar_read', '读取上传文件失败。');
    }

    $src = @imagecreatefromstring($data);
    if (!$src) {
        return new WP_Error('ybh_avatar_decode', '图片解码失败，请换一张图试试。');
    }

    // 居中裁成正方形（头像容器是圆形，非方图会被拉变形）
    $w    = imagesx($src);
    $h    = imagesy($src);
    $side = min($w, $h);
    $sx   = (int) (($w - $side) / 2);
    $sy   = (int) (($h - $side) / 2);

    $target = 256;
    $dst    = imagecreatetruecolor($target, $target);
    $white  = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $target, $target, $white);

    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $target, $target, $side, $side);

    $dir = dirname($dest);
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }
    // 防护文件（端点侧也会补，这里保证目录刚建出来就是安全的）
    if (!file_exists($dir . '/index.php')) {
        @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
    }
    if (!file_exists($dir . '/.htaccess')) {
        @file_put_contents(
            $dir . '/.htaccess',
            "<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n"
            . "AddType text/plain .php .php3 .php4 .php5 .php7 .phtml .phar\n"
        );
    }

    $ok = @imagewebp($dst, $dest, 88);
    imagedestroy($dst);
    imagedestroy($src);

    if (!$ok || !is_file($dest) || filesize($dest) <= 0) {
        return new WP_Error('ybh_avatar_write', '保存头像失败，请检查 uploads 目录是否可写。');
    }

    return true;
}

/**
 * 后台资料页的「上传头像」区块。
 */
add_action('show_user_profile', 'ybh_avatar_profile_field');
add_action('edit_user_profile', 'ybh_avatar_profile_field');
function ybh_avatar_profile_field($profile_user)
{
    if (!($profile_user instanceof WP_User)) {
        return;
    }
    $uid    = (int) $profile_user->ID;
    $has    = ybh_avatar_user_has_file($uid);
    $src    = ybh_avatar_build_url($uid, 96);
    $canEdit = current_user_can('edit_user', $uid);
    ?>
    <h2 id="ybh-avatar">头像</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="ybh_avatar_file">自定义头像</label></th>
            <td>
                <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
                    <img src="<?php echo esc_url($src); ?>" alt="当前头像"
                         style="width:96px;height:96px;border-radius:100%;object-fit:cover;border:1px solid #dcdcde;background:#fff">
                    <div>
                        <input type="file" name="ybh_avatar_file" id="ybh_avatar_file"
                               accept="image/jpeg,image/png,image/webp" <?php disabled(!$canEdit); ?> />
                        <p class="description" style="margin:6px 0 0">
                            支持 JPG / PNG / WebP，不超过 <strong>2 MB</strong>；会自动居中裁成正方形。
                            未上传时显示站点默认头像。
                        </p>
                        <?php if ($has) : ?>
                            <label style="display:inline-block;margin-top:8px">
                                <input type="checkbox" name="ybh_avatar_delete" value="1" <?php disabled(!$canEdit); ?> />
                                删除我上传的头像，恢复为默认
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
                <?php wp_nonce_field('ybh_avatar_' . $uid, 'ybh_avatar_nonce'); ?>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * 处理上传。
 *
 * 权限用 `edit_user`（等价于「能编辑这个人的资料」）—— 订阅者可以编辑**自己**，
 * 所以普通注册用户能换自己的头像，但换不了别人的。
 */
add_action('personal_options_update', 'ybh_avatar_profile_save');
add_action('edit_user_profile_update', 'ybh_avatar_profile_save');
function ybh_avatar_profile_save($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0 || !current_user_can('edit_user', $user_id)) {
        return false;
    }
    $nonce = isset($_POST['ybh_avatar_nonce']) ? (string) $_POST['ybh_avatar_nonce'] : '';
    if (!wp_verify_nonce($nonce, 'ybh_avatar_' . $user_id)) {
        return false;
    }

    // 删除
    if (!empty($_POST['ybh_avatar_delete'])) {
        ybh_avatar_purge_user($user_id);
        return true;
    }

    if (empty($_FILES['ybh_avatar_file']['name']) || empty($_FILES['ybh_avatar_file']['tmp_name'])) {
        return false;
    }

    $f = $_FILES['ybh_avatar_file'];
    if (!empty($f['error']) && (int) $f['error'] !== UPLOAD_ERR_OK) {
        ybh_avatar_profile_notice(ybh_avatar_upload_error_message((int) $f['error']), 'error');
        return false;
    }

    // ⚠️ WP 的 profile 表单默认没有 enctype="multipart/form-data"，
    //    文件名存在但 tmp_name 为空即为「表单没带 multipart」。
    if (empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
        ybh_avatar_profile_notice('上传失败：表单未启用文件上传。请刷新页面后重试。', 'error');
        return false;
    }

    $dest = ybh_avatar_user_file($user_id);
    if (!$dest) {
        ybh_avatar_profile_notice('上传失败：无法定位 uploads 目录。', 'error');
        return false;
    }

    $res = ybh_avatar_process_upload((string) $f['tmp_name'], $dest);
    if (is_wp_error($res)) {
        ybh_avatar_profile_notice('上传失败：' . $res->get_error_message(), 'error');
        return false;
    }

    // 清掉尺寸变体，并刷新 URL 上的 v
    foreach ((array) glob(trailingslashit(dirname($dest)) . 'u' . $user_id . '-*.webp') as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }
    update_user_meta($user_id, 'ybh_avatar_ver', time());
    ybh_avatar_profile_notice('头像已更新。', 'success');

    return true;
}

/**
 * 上传错误码 → 人话。
 */
function ybh_avatar_upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return '图片超过了服务器允许的上传大小。';
        case UPLOAD_ERR_PARTIAL:
            return '文件只上传了一部分，请重试。';
        case UPLOAD_ERR_NO_FILE:
            return '没有选择文件。';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return '服务器临时目录不可写，请联系管理员。';
        default:
            return '上传出错了（错误码 ' . $code . '）。';
    }
}

/**
 * 把处理结果丢给下一次请求显示（profile.php 会 302 回自己）。
 */
function ybh_avatar_profile_notice(string $msg, string $type = 'success'): void
{
    set_transient('ybh_avatar_notice_' . get_current_user_id(), array('m' => $msg, 't' => $type), 60);
}

/**
 * 显示上一步的结果。
 */
add_action('admin_notices', 'ybh_avatar_profile_notice_render');
function ybh_avatar_profile_notice_render()
{
    $key = 'ybh_avatar_notice_' . get_current_user_id();
    $n   = get_transient($key);
    if (!$n || empty($n['m'])) {
        return;
    }
    delete_transient($key);
    printf(
        '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
        $n['t'] === 'error' ? 'error' : 'success',
        esc_html((string) $n['m'])
    );
}

/**
 * 【必需】让资料页表单支持文件上传。
 *
 * WP 核心的 `<form id="your-profile">` **没有** enctype，不加这行选中的文件根本
 * 不会随 POST 提交（表现为「点了更新但什么都没发生」）。核心没有提供 PHP 过滤器
 * 来注入该属性，社区的标准做法就是在 admin_footer 里改 DOM。
 */
add_action('admin_footer-profile.php', 'ybh_avatar_form_enctype');
add_action('admin_footer-user-edit.php', 'ybh_avatar_form_enctype');
function ybh_avatar_form_enctype()
{
    ?>
    <script>
    (function () {
      var f = document.getElementById('your-profile');
      if (f && !f.getAttribute('enctype')) {
        f.setAttribute('enctype', 'multipart/form-data');
      }
    })();
    </script>
    <?php
}

/* ===================================================================== *
 * 第三部分：前台入口
 * ===================================================================== */

/**
 * 换掉评论表单里那张**内联的灰色人形 SVG**。
 *
 * `comments.php` 给未登录访客输出的预览位，初始 src 是一段
 * `data:image/svg+xml;base64,…` 的 FontAwesome 人形剪影 —— 这其实就是访客
 * 一进页面就看到的「初认头像」。它是「无头像时显示什么」的默认答案，
 * 按本次需求一并换成站点自建图。
 *
 * 走过滤器而不是改 comments.php：那份数组是 `apply_filters('comment_form_default_fields', …)`
 * 的参数，在过滤器里替换最干净，且不与其它任务的改动打架。
 */
add_filter('comment_form_default_fields', 'ybh_avatar_comment_form_avatar', 20);
function ybh_avatar_comment_form_avatar($fields)
{
    if (!is_array($fields) || empty($fields['avatar'])) {
        return $fields;
    }
    // 只替换 data: URI 那一处 src，其余结构（QQ / Gravatar 角标）原样保留
    $fields['avatar'] = preg_replace(
        '#src="data:image/svg\+xml;base64,[^"]*"#i',
        'src="' . esc_url(ybh_avatar_default_url(80)) . '"',
        (string) $fields['avatar'],
        1
    );
    return $fields;
}

/**
 * 前端接管脚本。
 *
 * 解决两件事，都不改主题的打包产物（`js/page.js`）：
 *
 * 1. **评论表单的「邮箱 → 头像实时预览」**
 *    主题 JS 把地址硬拼成
 *        "https://" + _iro.gravatar_url + "/" + md5(email) + ".jpg?s=80&d=mm"
 *    它每次调用都现读 `_iro.gravatar_url`，所以这里把基串改成站点端点即可，
 *    拼出来是 `…/ybh-avatar.php/<md5>.jpg?s=80&d=mm`，端点会从 PATH_INFO 取 hash。
 *    ⇒ 第三方请求**根本不会发出**。
 *
 * 2. **兜底归一**
 *    万一服务器没给 PHP 透传 PATH_INFO（宝塔/nginx 配置差异），上面的形态会 404。
 *    用 MutationObserver 盯住预览图，只要它的 src 还指向第三方就换成规范形态
 *    `…/ybh-avatar.php?h=<md5>&s=80`。hash 原本就在 URL 里，不需要邮箱，
 *    也就不必把 md5 逻辑再实现一遍。
 *    顺带清掉 localStorage 里缓存的第三方预览地址（QQ 头像的 qlogo 保留）。
 */
add_action('wp_footer', 'ybh_avatar_front_script', 100);
function ybh_avatar_front_script()
{
    if (is_admin() || is_feed() || is_robots()) {
        return;
    }
    $ep   = ybh_avatar_endpoint_url();
    $bare = preg_replace('#^https?://#i', '', $ep);
    ?>
    <script>
    (function () {
      var EP   = <?php echo wp_json_encode($ep); ?>;
      var BARE = <?php echo wp_json_encode($bare); ?>;

      /* —— 1) 让主题 JS 拼出的预览地址落到本站端点 —— */
      if (window._iro) { _iro.gravatar_url = BARE; }

      /* —— 清掉历史遗留的第三方预览缓存（不动 qlogo 的 QQ 头像） —— */
      try {
        var cached = localStorage.getItem('user_avatar');
        if (cached && /(gravatar|cravatar|weavatar)/i.test(cached)) {
          localStorage.removeItem('user_avatar');
        }
      } catch (e) {}

      /* —— 2) 兜底：把第三方预览图归一到本站端点 —— */
      var RE = /(gravatar|cravatar|weavatar)\./i;

      function fix(img) {
        if (!img || !img.getAttribute) return;
        var s = img.getAttribute('src') || '';
        if (s.indexOf(EP) === 0) return;
        if (!RE.test(s)) return;
        var m = s.match(/([a-f0-9]{32})/i);
        if (!m) return;
        img.setAttribute('src', EP + '?h=' + m[1].toLowerCase() + '&s=80');
      }

      function scan(root) {
        if (!root) return;
        if (root.nodeType === 1 && root.tagName === 'IMG') { fix(root); return; }
        if (!root.querySelectorAll) return;
        var list = root.querySelectorAll('div.comment-user-avatar img');
        for (var i = 0; i < list.length; i++) fix(list[i]);
      }

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scan(document); });
      } else {
        scan(document);
      }
      document.addEventListener('pjax:complete', function () { scan(document); });

      if (window.MutationObserver) {
        new MutationObserver(function (muts) {
          for (var i = 0; i < muts.length; i++) {
            var m = muts[i];
            if (m.type === 'attributes') { fix(m.target); continue; }
            if (m.addedNodes) {
              for (var j = 0; j < m.addedNodes.length; j++) scan(m.addedNodes[j]);
            }
          }
        }).observe(document.documentElement || document, {
          subtree: true, childList: true, attributes: true, attributeFilter: ['src']
        });
      }
    })();
    </script>
    <?php
}

/**
 * 短代码 `[ybh_avatar_upload]` —— 把上传表单放到任意页面。
 *
 * 用法：新建一个「修改头像」页面，正文里写 `[ybh_avatar_upload]`。
 */
add_shortcode('ybh_avatar_upload', 'ybh_avatar_upload_shortcode');
function ybh_avatar_upload_shortcode($atts = array())
{
    if (!is_user_logged_in()) {
        return '<p class="ybh-avatar-tip">请先<a href="' . esc_url(wp_login_url(get_permalink() ?: home_url('/'))) . '">登录</a>后再上传头像。</p>';
    }

    $uid  = get_current_user_id();
    $has  = ybh_avatar_user_has_file($uid);
    $src  = ybh_avatar_build_url($uid, 160);
    $sent = isset($_GET['ybh_avatar']) ? sanitize_key((string) $_GET['ybh_avatar']) : '';

    ob_start();
    ?>
    <div class="ybh-avatar-box">
      <div class="ybh-avatar-preview">
        <img src="<?php echo esc_url($src); ?>" alt="我的头像" width="160" height="160" />
      </div>
      <form class="ybh-avatar-form" method="post" enctype="multipart/form-data"
            action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="ybh_avatar_upload" />
        <?php wp_nonce_field('ybh_avatar_front_' . $uid, 'ybh_avatar_front_nonce'); ?>
        <label class="ybh-avatar-file">
          <input type="file" name="ybh_avatar_file" accept="image/jpeg,image/png,image/webp" required />
        </label>
        <p class="ybh-avatar-hint">支持 JPG / PNG / WebP，不超过 2 MB，会自动裁成正方形。</p>
        <p class="ybh-avatar-actions">
          <button type="submit" class="ybh-avatar-save">上传头像</button>
          <?php if ($has) : ?>
            <button type="submit" class="ybh-avatar-del" name="ybh_avatar_delete" value="1"
                    formnovalidate>恢复默认</button>
          <?php endif; ?>
        </p>
        <?php if ($sent === 'ok') : ?>
          <p class="ybh-avatar-msg ok">头像已更新。</p>
        <?php elseif ($sent === 'del') : ?>
          <p class="ybh-avatar-msg ok">已恢复为默认头像。</p>
        <?php elseif ($sent === 'err') : ?>
          <p class="ybh-avatar-msg err">上传失败，请确认是 JPG / PNG / WebP 且不超过 2 MB。</p>
        <?php endif; ?>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * 前台表单的提交处理（admin-post.php?action=ybh_avatar_upload）。
 */
add_action('admin_post_ybh_avatar_upload', 'ybh_avatar_front_handle');
function ybh_avatar_front_handle()
{
    $uid = get_current_user_id();
    if ($uid <= 0) {
        wp_safe_redirect(home_url('/'));
        exit;
    }
    $nonce = isset($_POST['ybh_avatar_front_nonce']) ? (string) $_POST['ybh_avatar_front_nonce'] : '';
    $back  = wp_get_referer() ?: home_url('/');

    if (!wp_verify_nonce($nonce, 'ybh_avatar_front_' . $uid)) {
        wp_safe_redirect(add_query_arg('ybh_avatar', 'err', $back));
        exit;
    }

    if (!empty($_POST['ybh_avatar_delete'])) {
        ybh_avatar_purge_user($uid);
        wp_safe_redirect(add_query_arg('ybh_avatar', 'del', $back));
        exit;
    }

    $f = isset($_FILES['ybh_avatar_file']) ? $_FILES['ybh_avatar_file'] : null;
    $dest = ybh_avatar_user_file($uid);
    if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name']) || !$dest) {
        wp_safe_redirect(add_query_arg('ybh_avatar', 'err', $back));
        exit;
    }

    $res = ybh_avatar_process_upload((string) $f['tmp_name'], $dest);
    if (is_wp_error($res)) {
        wp_safe_redirect(add_query_arg('ybh_avatar', 'err', $back));
        exit;
    }

    foreach ((array) glob(trailingslashit(dirname($dest)) . 'u' . $uid . '-*.webp') as $old) {
        if (is_file($old)) {
            @unlink($old);
        }
    }
    update_user_meta($uid, 'ybh_avatar_ver', time());

    wp_safe_redirect(add_query_arg('ybh_avatar', 'ok', $back));
    exit;
}

/* ===================================================================== *
 * 第四部分：缓存重建（供一次性预热 / 日后维护使用）
 * ===================================================================== */

/**
 * 收集站点里所有「需要头像的邮箱」。
 *
 * 规模很小（25 用户 + 15 评论），一次性全量预热成本极低，
 * 不需要后台任务队列。
 *
 * @return array<string,array{email:string,user_id:int}>
 */
function ybh_avatar_collect_targets(): array
{
    $out = array();

    $users = get_users(array('fields' => array('ID', 'user_email')));
    foreach ($users as $u) {
        $email = strtolower(trim((string) $u->user_email));
        if ($email === '' || strpos($email, '@') === false) {
            continue;
        }
        $out[$email] = array('email' => $email, 'user_id' => (int) $u->ID);
    }

    $comments = get_comments(array(
        'status' => 'approve',
        'fields' => 'all',
        'number' => 0,
    ));
    foreach ($comments as $c) {
        $email = strtolower(trim((string) $c->comment_author_email));
        if ($email === '' || strpos($email, '@') === false || isset($out[$email])) {
            continue;
        }
        $out[$email] = array('email' => $email, 'user_id' => (int) $c->user_id);
    }

    return $out;
}

/**
 * 头像体检（原「预热」，2026-09-14 起不再访问任何外部站点）。
 *
 * 原文是「把指定邮箱的头像从 Cravatar 抓到本地」。按用户要求取消外部头像源后，
 * 这个函数改为**只统计与清理**，一件外部请求都不发：
 *
 *   · 统计：多少人有真头像（自传 / 历史落盘）、多少人会看到默认图；
 *   · 清理：删掉「尺寸变体」里 mtime 已对不上的陈旧文件
 *     （命名约定 `<base>-<mtime>-<size>.webp`，源文件换了 mtime，旧变体就永远不会再被命中，
 *      留着只是占地方；用户 2 那种传过好几次头像的，最容易攒出一堆）。
 *
 * @return array 统计
 */
function ybh_avatar_audit(): array
{
    $stat = array(
        'targets' => 0, 'uploaded' => 0, 'cached' => 0, 'default' => 0,
        'variants' => 0, 'pruned' => 0, 'bytes' => 0,
    );

    $dir = wp_upload_dir();
    if (empty($dir['basedir'])) {
        return $stat;
    }
    $base = trailingslashit($dir['basedir']) . 'ybh-avatars';

    /* ---- 1) 覆盖度：每个已知邮箱落到哪一档 ---- */
    foreach (ybh_avatar_collect_targets() as $email => $meta) {
        $stat['targets']++;
        $uid  = (int) $meta['user_id'];
        $hash = md5($email);

        if ($uid > 0 && ybh_avatar_user_has_file($uid)) {
            $stat['uploaded']++;
        } elseif (is_file($base . '/' . $hash . '.webp') && filesize($base . '/' . $hash . '.webp') > 0) {
            $stat['cached']++;
        } else {
            $stat['default']++;
        }
    }

    /* ---- 2) 清理陈旧尺寸变体 ---- */
    foreach ((array) glob($base . '/*.webp') as $f) {
        $name = basename($f);
        // 只处理带 `-<mtime>-<size>` 的变体
        if (!preg_match('/^(.+)-(\d{9,11})-(\d{2,3})\.webp$/', $name, $m)) {
            continue;
        }
        $stat['variants']++;
        $src = $base . '/' . $m[1] . '.webp';
        $srcMtime = is_file($src) ? (int) @filemtime($src) : 0;
        // 源文件不在了，或 mtime 与变体名里的对不上 ⇒ 这个变体永远不会再被命中
        if ($srcMtime === 0 || (string) $srcMtime !== $m[2]) {
            if (@unlink($f)) {
                $stat['pruned']++;
            }
        }
    }

    /* ---- 3) 体积 ---- */
    foreach ((array) glob($base . '/*.webp') as $f) {
        $stat['bytes'] += (int) @filesize($f);
    }

    return $stat;
}

/**
 * 后台一键「头像体检」：`/wp-admin/admin-post.php?action=ybh_avatar_rebuild`
 *
 * 不再抓取任何外部头像（见 ybh_avatar_audit 的说明），只统计覆盖度并清理陈旧变体。
 */
add_action('admin_post_ybh_avatar_rebuild', 'ybh_avatar_rebuild_handle');
function ybh_avatar_rebuild_handle()
{
    if (!current_user_can('manage_options')) {
        wp_die('权限不足。');
    }
    check_admin_referer('ybh_avatar_rebuild');
    $stat = ybh_avatar_audit();
    wp_safe_redirect(add_query_arg(
        array(
            'ybh_avatar_done' => 1,
            't' => $stat['targets'],
            'u' => $stat['uploaded'],
            'c' => $stat['cached'],
            'd' => $stat['default'],
            'p' => $stat['pruned'],
        ),
        admin_url('profile.php')
    ));
    exit;
}
