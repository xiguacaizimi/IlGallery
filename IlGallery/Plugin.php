<?php
/**
 * 图库相册 —— 为 ilogs 主题打造的相册管理与前台展示插件
 *
 * 后台可创建多个相册、批量上传照片、设定封面与排序；
 * 前台通过 /gallery/ 路由展示相册墙，点开相册进入照片墙，
 * 点开照片在同一页面展开沉浸式大图查看器。
 *
 * 作者：xiguacaizimi　邮箱：xiguacaizimi@163.com
 *
 * @package 图库相册
 * @author xiguacaizimi
 * @version 1.1.8
 * @link https://www.xiguacaizimi.top
 */

namespace TypechoPlugin\IlGallery;

use Typecho\Db;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Plugin implements PluginInterface
{
    /** 插件版本 */
    public const VERSION = '1.1.8';

    /** 前台图库路由名 */
    public const ROUTE = 'ilgallery';

    /** 前台相册详情路由名 */
    public const ROUTE_ALBUM = 'ilgallery_album';

    /** 后台菜单名 */
    private const MENU_NAME = '图库';

    /** 后台面板文件 */
    private const PANEL_FILE = 'IlGallery/Manage.php';

    /**
     * 启用插件
     *
     * 建表、创建承载图库的独立页面、注册后台菜单与前台路由。
     * 重复启用时所有操作都是幂等的，不会产生重复数据。
     */
    public static function activate()
    {
        Store::install();
        self::ensurePage();
        self::ensureConfig();

        self::syncMenu();

        // 让后台自动创建的「相册」页面直接渲染图库（左侧导航指向该页面）
        \Typecho\Plugin::factory('Widget\\Archive')->beforeRender = [self::class, 'beforeRender'];

        // 先移除旧路由，保证重复启用时顺序稳定
        Helper::removeRoute(self::ROUTE);
        Helper::removeRoute(self::ROUTE_ALBUM);

        // 必须插在 feedback 路由之前，否则 /gallery/{slug}/ 会被评论跳转路由截获
        Helper::addRoute(self::ROUTE_ALBUM, '/gallery/[album:alpha]/', 'TypechoPlugin\\IlGallery\\Widget\\Gallery', 'render', 'index');
        Helper::addRoute(self::ROUTE, '/gallery/', 'TypechoPlugin\\IlGallery\\Widget\\Gallery', 'render', 'index');
    }

    /**
     * 禁用插件
     *
     * 只移除菜单与路由，相册数据与已上传图片全部保留，
     * 再次启用后可以继续使用。
     */
    public static function deactivate()
    {
        $db = Db::get();
        $table = self::readPanelTable();

        if (is_array($table)) {
            $table['parent'] = $table['parent'] ?? [];
            $table['child'] = $table['child'] ?? [];
            $table['file'] = $table['file'] ?? [];
            $panelKey = urlencode(self::PANEL_FILE);

            foreach ($table['parent'] as $index => $name) {
                if ($name === self::MENU_NAME) {
                    unset($table['parent'][$index]);
                    unset($table['child'][10 + $index]);
                }
            }

            foreach ($table['child'] as $index => $children) {
                foreach ($children as $key => $child) {
                    if (isset($child[2]) && $child[2] === 'extending.php?panel=' . $panelKey) {
                        unset($table['child'][$index][$key]);
                    }
                }
            }

            $table['file'] = array_values(array_filter($table['file'], function ($file) use ($panelKey) {
                return $file !== $panelKey;
            }));

            self::writePanelTable($table);
        }

        Helper::removeRoute(self::ROUTE);
        Helper::removeRoute(self::ROUTE_ALBUM);
    }

    /**
     * 一键修复：补建数据表、承载页面、配置、菜单与前台路由
     *
     * 与 activate() 的区别是不会报错、可反复执行，
     * 用于后台「修复页面与路由」按钮，也用于前台路由发现页面缺失时自愈。
     *
     * @return void
     */
    public static function repair(): void
    {
        // 建表
        try {
            Store::install();
        } catch (\Throwable $e) {
            // 表已存在时忽略
        }

        try {
            self::ensurePage();
            Store::flushHostPage();
        } catch (\Throwable $e) {
            // 忽略
        }

        try {
            self::ensureConfig();
        } catch (\Throwable $e) {
            // 忽略
        }

        try {
            self::syncMenu();
        } catch (\Throwable $e) {
            // 忽略
        }

        // 反复注册不会重复，但先移除可保证路由顺序稳定（必须在 feedback 之前）
        try {
            Helper::removeRoute(self::ROUTE);
            Helper::removeRoute(self::ROUTE_ALBUM);
            Helper::addRoute(self::ROUTE_ALBUM, '/gallery/[album:alpha]/', 'TypechoPlugin\\IlGallery\\Widget\\Gallery', 'render', 'index');
            Helper::addRoute(self::ROUTE, '/gallery/', 'TypechoPlugin\\IlGallery\\Widget\\Gallery', 'render', 'index');
        } catch (\Throwable $e) {
            // 忽略
        }
    }

    /**
     * 插件设置
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        $title = new Text('listTitle', null, '相册', _t('图库页标题'), _t('相册列表页顶部的大标题，留空则使用「相册」。'));
        $form->addInput($title);

        $subtitle = new Text('listSubtitle', null, '收藏日常片段与文章影像', _t('图库页副标题'), _t('标题下方的一行灰色说明文字。'));
        $form->addInput($subtitle);

        $albumCols = new Select(
            'albumColumns',
            ['2' => '2 列', '3' => '3 列', '4' => '4 列'],
            '3',
            _t('相册墙列数'),
            _t('桌面端相册卡片的列数，推荐 3 列。')
        );
        $form->addInput($albumCols);

        $photoCols = new Select(
            'photoColumns',
            ['4' => '4 列', '5' => '5 列', '6' => '6 列（推荐，与官方图库一致）', '7' => '7 列', '8' => '8 列'],
            '6',
            _t('照片墙列数'),
            _t('相册详情页照片缩略图的列数，桌面端 6 列。')
        );
        $form->addInput($photoCols);

        $maxUpload = new Text('maxUpload', null, '20', _t('单张上传上限（MB）'), _t('受服务器 upload_max_filesize 与 post_max_size 限制，超出时以服务器配置为准。'));
        $form->addInput($maxUpload);

        $download = new Select(
            'allowDownload',
            ['1' => '允许', '0' => '不允许'],
            '0',
            _t('查看器下载按钮'),
            _t('是否在图片查看器中显示下载按钮。关闭后仍可通过右键保存图片。')
        );
        $form->addInput($download);

    }

    /**
     * 个人设置（本插件不需要）
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 默认配置
     */
    public static function defaults(): array
    {
        return [
            'listTitle'     => '相册',
            'listSubtitle'  => '收藏日常片段与文章影像',
            'albumColumns'  => '3',
            'photoColumns'  => '6',
            'maxUpload'     => '20',
            'allowDownload' => '0',
        ];
    }

    /**
     * 独立页面入口：当访问的是承载图库的页面时，接管渲染
     *
     * @param mixed $archive
     */
    public static function beforeRender($archive): void
    {
        if (!($archive instanceof \Widget\Archive) || !$archive->is('page')) {
            return;
        }

        $page = Store::hostPage();

        if (empty($page) || (int)$archive->cid !== (int)$page['cid']) {
            return;
        }

        Renderer::render($archive);
        exit;
    }

    /**
     * 读取后台菜单表。
     *
     * Typecho 1.3 起 options 表里的数组字段改用 JSON 保存，1.2 及以前是 PHP serialize()，
     * 这里两种格式都认，否则在 1.3 上会读出 false，
     * 导致误以为菜单表是空的，把别的插件菜单全部覆盖掉。
     *
     * @return array
     */
    private static function readPanelTable(): array
    {
        $db = Db::get();
        $row = $db->fetchRow(
            $db->select('value')->from('table.options')->where('name = ?', 'panelTable')->limit(1)
        );

        $raw = (string) ($row['value'] ?? '');
        if ($raw === '') {
            return [];
        }

        $isSerialized = strpos($raw, 'a:') === 0 || $raw === 'b:0;';
        $table = $isSerialized ? @unserialize($raw) : json_decode($raw, true);

        return is_array($table) ? $table : [];
    }

    /**
     * 写回后台菜单表（按当前 Typecho 版本选择 serialize 或 JSON）。
     *
     * @param array $table
     * @return void
     */
    private static function writePanelTable(array $table): void
    {
        $db = Db::get();
        $raw = self::panelTableEncoding() === 'json'
            ? json_encode($table, JSON_UNESCAPED_UNICODE)
            : serialize($table);

        $db->query(
            $db->update('table.options')->rows(['value' => $raw])->where('name = ?', 'panelTable')
        );
    }

    /**
     * 当前 Typecho 版本里 options 数组字段使用的格式。
     *
     * @return string json | php
     */
    private static function panelTableEncoding(): string
    {
        $version = '';

        if (class_exists('\Typecho\Common') && defined('\Typecho\Common::VERSION')) {
            $version = (string) constant('\Typecho\Common::VERSION');
        }

        return ($version !== '' && version_compare($version, '1.3.0', '>=')) ? 'json' : 'php';
    }

    /**
     * 注册后台菜单（幂等）
     *
     * Typecho 的 Helper::addMenu() 每次调用都会追加一项，
     * 反复启用插件会出现多个「图库」菜单，这里直接重建面板表。
     */
    private static function syncMenu(): void
    {
        $db = Db::get();
        $table = self::readPanelTable();

        if (!is_array($table)) {
            $table = [];
        }

        $table['parent'] = $table['parent'] ?? [];
        $table['child'] = $table['child'] ?? [];
        $table['file'] = $table['file'] ?? [];
        $panelKey = urlencode(self::PANEL_FILE);

        foreach ($table['parent'] as $index => $name) {
            if ($name === self::MENU_NAME) {
                unset($table['parent'][$index]);
                unset($table['child'][10 + $index]);
            }
        }

        foreach ($table['child'] as $index => $children) {
            foreach ($children as $key => $child) {
                if (isset($child[2]) && $child[2] === 'extending.php?panel=' . $panelKey) {
                    unset($table['child'][$index][$key]);
                }
            }
        }

        $table['file'] = array_values(array_filter($table['file'], function ($file) use ($panelKey) {
            return $file !== $panelKey;
        }));

        $table['parent'][] = self::MENU_NAME;
        end($table['parent']);
        $menuIndex = 10 + key($table['parent']);

        $table['child'][$menuIndex] = [[
            '相册管理',
            '创建相册、上传照片与排序',
            'extending.php?panel=' . $panelKey,
            'administrator',
            false,
            '',
        ]];
        $table['file'][] = $panelKey;

        self::writePanelTable($table);
    }

    /**
     * 首次启用时写入默认配置，避免 Options::plugin() 抛异常
     */
    private static function ensureConfig(): void
    {
        $db = Db::get();
        $exists = $db->fetchRow(
            $db->select('name')->from('table.options')->where('name = ?', 'plugin:IlGallery')->limit(1)
        );

        if (empty($exists)) {
            Helper::configPlugin('IlGallery', self::defaults());
        }
    }

    /**
     * 读取插件配置，带默认值
     *
     * @param string|null $key
     * @return mixed
     */
    public static function setting(?string $key = null)
    {
        $values = self::defaults();

        try {
            $config = Helper::options()->plugin('IlGallery');

            foreach ($values as $name => $default) {
                $value = isset($config->{$name}) ? $config->{$name} : null;
                $values[$name] = ($value === null || $value === '') ? $default : $value;
            }
        } catch (\Throwable $e) {
            // 配置尚未写入时直接使用默认值
        }

        if ($key === null) {
            return $values;
        }

        return $values[$key] ?? null;
    }

    /**
     * 创建（或修复）承载图库的独立页面
     *
     * 页面本身不输出正文，由插件接管渲染；同时写入 showPage / iconPage
     * 两个主题自定义字段，让「相册」自动出现在 ilogs 左侧导航里。
     *
     * @return int 页面 cid
     */
    public static function ensurePage(): int
    {
        $db = Db::get();

        $exists = $db->fetchRow(
            $db->select('c.cid')->from('table.contents AS c')
                ->join('table.fields AS f', 'c.cid = f.cid', Db::LEFT_JOIN)
                ->where('f.name = ?', 'ilgalleryHost')
                ->where('c.type = ?', 'page')
                ->limit(1)
        );

        if (!empty($exists)) {
            return (int)$exists['cid'];
        }

        $slug = 'gallery';
        $conflict = $db->fetchRow(
            $db->select('cid')->from('table.contents')
                ->where('slug = ?', $slug)->where('type = ?', 'page')->limit(1)
        );

        if (!empty($conflict)) {
            $slug = 'gallery-' . substr((string)time(), -6);
        }

        // Typecho 查询页面时用的是 created < 当前请求时间（严格小于），
        // 若与请求同一秒创建会被过滤掉导致 404，这里把创建时间往前挪几秒
        $now = time() - 5;
        $authorId = 1;

        try {
            $user = \Widget\User::alloc();
            if (!empty($user->uid)) {
                $authorId = (int)$user->uid;
            }
        } catch (\Throwable $e) {
            // 忽略：CLI 或未登录场景
        }

        $cid = (int)$db->query(
            $db->insert('table.contents')->rows([
                'title'        => '相册',
                'slug'         => $slug,
                'created'      => $now,
                'modified'     => $now,
                'text'         => '',
                'order'        => 0,
                'authorId'     => $authorId,
                'template'     => '',
                'type'         => 'page',
                'status'       => 'publish',
                'password'     => '',
                'commentsNum'  => 0,
                'allowComment' => '0',
                'allowPing'    => '0',
                'allowFeed'    => '0',
                'parent'       => 0,
            ])
        );

        if ($cid <= 0) {
            return 0;
        }

        $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path stroke="none" d="M0 0h24v24H0z" fill="none"/>'
            . '<path d="M15 8h.01"/>'
            . '<path d="M3 6a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12z"/>'
            . '<path d="M3 16l5 -5c.928 -.893 2.072 -.893 3 0l5 5"/>'
            . '<path d="M14 14l1 -1c.928 -.893 2.072 -.893 3 0l3 3"/></svg>';

        $fields = [
            'ilgalleryHost' => '1',
            'showPage'      => '1',
            'iconPage'      => $icon,
        ];

        foreach ($fields as $name => $value) {
            $db->query(
                $db->insert('table.fields')->rows([
                    'cid'         => $cid,
                    'name'        => $name,
                    'type'        => 'str',
                    'str_value'   => $value,
                    'int_value'   => 0,
                    'float_value' => 0,
                ])
            );
        }

        return $cid;
    }
}
