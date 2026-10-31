<?php

namespace TypechoPlugin\IlGallery;

use Typecho\Common;
use Typecho\Db;
use Utils\Helper;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 数据访问层
 *
 * 所有相册与照片的读写都收在这里，后台面板与前台路由共用。
 */
class Store
{
    /** 相册表（不含前缀） */
    public const ALBUMS = 'ilgallery_albums';

    /** 照片表（不含前缀） */
    public const IMAGES = 'ilgallery_images';

    /** 承载图库的页面缓存 */
    private static $hostPageCache = null;

    /** 承载图库页面是否已在本次请求中读取过 */
    private static $hostPageLoaded = false;

    /**
     * @return Db
     */
    public static function db(): Db
    {
        return Db::get();
    }

    /**
     * 带前缀的完整表名
     */
    public static function table(string $name): string
    {
        return self::db()->getPrefix() . $name;
    }

    /**
     * 建表
     */
    public static function install(): void
    {
        $db = self::db();
        $albums = self::table(self::ALBUMS);
        $images = self::table(self::IMAGES);
        $sqlite = stripos($db->getAdapterName(), 'sqlite') !== false;

        if ($sqlite) {
            $sql = [
                "CREATE TABLE IF NOT EXISTS `{$albums}` (
                    `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                    `name` varchar(150) NOT NULL DEFAULT '',
                    `slug` varchar(150) NOT NULL DEFAULT '',
                    `description` varchar(500) NOT NULL DEFAULT '',
                    `cover_id` int(10) NOT NULL DEFAULT 0,
                    `sort` int(10) NOT NULL DEFAULT 0,
                    `status` varchar(16) NOT NULL DEFAULT 'publish',
                    `created` int(10) NOT NULL DEFAULT 0,
                    `updated` int(10) NOT NULL DEFAULT 0
                )",
                "CREATE UNIQUE INDEX IF NOT EXISTS `{$albums}_slug` ON `{$albums}` (`slug`)",
                "CREATE TABLE IF NOT EXISTS `{$images}` (
                    `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                    `album_id` int(10) NOT NULL DEFAULT 0,
                    `title` varchar(200) NOT NULL DEFAULT '',
                    `path` varchar(255) NOT NULL DEFAULT '',
                    `thumb` varchar(255) NOT NULL DEFAULT '',
                    `width` int(10) NOT NULL DEFAULT 0,
                    `height` int(10) NOT NULL DEFAULT 0,
                    `size` int(10) NOT NULL DEFAULT 0,
                    `mime` varchar(80) NOT NULL DEFAULT '',
                    `sort` int(10) NOT NULL DEFAULT 0,
                    `created` int(10) NOT NULL DEFAULT 0
                )",
                "CREATE INDEX IF NOT EXISTS `{$images}_album` ON `{$images}` (`album_id`)",
            ];
        } else {
            $sql = [
                "CREATE TABLE IF NOT EXISTS `{$albums}` (
                    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                    `name` varchar(150) NOT NULL DEFAULT '',
                    `slug` varchar(150) NOT NULL DEFAULT '',
                    `description` varchar(500) NOT NULL DEFAULT '',
                    `cover_id` int(10) unsigned NOT NULL DEFAULT 0,
                    `sort` int(10) NOT NULL DEFAULT 0,
                    `status` varchar(16) NOT NULL DEFAULT 'publish',
                    `created` int(10) NOT NULL DEFAULT 0,
                    `updated` int(10) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `slug` (`slug`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS `{$images}` (
                    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                    `album_id` int(10) unsigned NOT NULL DEFAULT 0,
                    `title` varchar(200) NOT NULL DEFAULT '',
                    `path` varchar(255) NOT NULL DEFAULT '',
                    `thumb` varchar(255) NOT NULL DEFAULT '',
                    `width` int(10) NOT NULL DEFAULT 0,
                    `height` int(10) NOT NULL DEFAULT 0,
                    `size` int(10) NOT NULL DEFAULT 0,
                    `mime` varchar(80) NOT NULL DEFAULT '',
                    `sort` int(10) NOT NULL DEFAULT 0,
                    `created` int(10) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `album_id` (`album_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ];
        }

        foreach ($sql as $statement) {
            $db->query($statement);
        }
    }

    /**
     * 相册列表（含照片数量与封面）
     *
     * @param bool $onlyPublished 前台只取已发布的相册
     * @return array
     */
    public static function albums(bool $onlyPublished = false): array
    {
        $db = self::db();
        $albums = $db->fetchAll(
            $db->select()->from(self::table(self::ALBUMS))
                ->order('sort', Db::SORT_ASC)
                ->order('id', Db::SORT_DESC)
        );

        if (empty($albums)) {
            return [];
        }

        $counts = [];
        $rows = $db->fetchAll(
            $db->select('album_id', ['COUNT(id)' => 'num'])
                ->from(self::table(self::IMAGES))
                ->group('album_id')
        );

        foreach ($rows as $row) {
            $counts[(int)$row['album_id']] = (int)$row['num'];
        }

        $result = [];

        foreach ($albums as $album) {
            if ($onlyPublished && $album['status'] !== 'publish') {
                continue;
            }

            $album['id'] = (int)$album['id'];
            $album['count'] = $counts[$album['id']] ?? 0;
            $album['cover'] = self::cover($album);
            $album['url'] = self::albumUrl($album);
            $result[] = $album;
        }

        return $result;
    }

    /**
     * 单个相册
     */
    public static function album(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $db = self::db();
        $row = $db->fetchRow(
            $db->select()->from(self::table(self::ALBUMS))->where('id = ?', $id)->limit(1)
        );

        if (empty($row)) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['count'] = self::countImages($row['id']);
        $row['cover'] = self::cover($row);
        $row['url'] = self::albumUrl($row);

        return $row;
    }

    /**
     * 按别名取相册
     */
    public static function albumBySlug(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        $db = self::db();
        $row = $db->fetchRow(
            $db->select()->from(self::table(self::ALBUMS))
                ->where('slug = ?', $slug)
                ->where('status = ?', 'publish')
                ->limit(1)
        );

        if (empty($row)) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['count'] = self::countImages($row['id']);
        $row['cover'] = self::cover($row);
        $row['url'] = self::albumUrl($row);

        return $row;
    }

    /**
     * 相册封面（优先 cover_id，其次第一张照片）
     *
     * @param array $album
     * @return array|null
     */
    public static function cover(array $album): ?array
    {
        $db = self::db();
        $coverId = (int)($album['cover_id'] ?? 0);

        if ($coverId > 0) {
            $row = $db->fetchRow(
                $db->select()->from(self::table(self::IMAGES))->where('id = ?', $coverId)->limit(1)
            );

            if (!empty($row)) {
                return self::decorateImage($row);
            }
        }

        if (empty($album['id'])) {
            return null;
        }

        $row = $db->fetchRow(
            $db->select()->from(self::table(self::IMAGES))
                ->where('album_id = ?', (int)$album['id'])
                ->order('sort', Db::SORT_ASC)
                ->order('id', Db::SORT_ASC)
                ->limit(1)
        );

        return empty($row) ? null : self::decorateImage($row);
    }

    /**
     * 新建或更新相册
     *
     * @param array $data
     * @param int $id
     * @return int
     */
    public static function saveAlbum(array $data, int $id = 0): int
    {
        $db = self::db();
        $now = time();

        $slug = self::slugify((string)($data['slug'] ?? ''), (string)($data['name'] ?? ''));
        $slug = self::uniqueSlug($slug, $id);

        $row = [
            'name'        => trim((string)($data['name'] ?? '')),
            'slug'        => $slug,
            'description' => trim((string)($data['description'] ?? '')),
            'cover_id'    => (int)($data['cover_id'] ?? 0),
            'sort'        => (int)($data['sort'] ?? 0),
            'status'      => ($data['status'] ?? 'publish') === 'hidden' ? 'hidden' : 'publish',
            'updated'     => $now,
        ];

        if ($id > 0) {
            $db->query($db->update(self::table(self::ALBUMS))->rows($row)->where('id = ?', $id));
            return $id;
        }

        $row['created'] = $now;

        return (int)$db->query($db->insert(self::table(self::ALBUMS))->rows($row));
    }

    /**
     * 删除相册（同时删除相册内照片文件）
     */
    public static function deleteAlbum(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        foreach (self::images($id) as $image) {
            self::deleteImage((int)$image['id']);
        }

        $db = self::db();
        $db->query($db->delete(self::table(self::ALBUMS))->where('id = ?', $id));
    }

    /**
     * 相册内所有照片
     *
     * @return array
     */
    public static function images(int $albumId): array
    {
        if ($albumId <= 0) {
            return [];
        }

        $db = self::db();
        $rows = $db->fetchAll(
            $db->select()->from(self::table(self::IMAGES))
                ->where('album_id = ?', $albumId)
                ->order('sort', Db::SORT_ASC)
                ->order('id', Db::SORT_ASC)
        );

        return array_map([self::class, 'decorateImage'], $rows);
    }

    /**
     * 单张照片
     */
    public static function image(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $db = self::db();
        $row = $db->fetchRow(
            $db->select()->from(self::table(self::IMAGES))->where('id = ?', $id)->limit(1)
        );

        return empty($row) ? null : self::decorateImage($row);
    }

    /**
     * 写入一张照片
     */
    public static function addImage(array $data): int
    {
        $db = self::db();
        $albumId = (int)$data['album_id'];

        $max = $db->fetchObject(
            $db->select(['MAX(sort)' => 'max'])->from(self::table(self::IMAGES))->where('album_id = ?', $albumId)
        );

        $row = [
            'album_id' => $albumId,
            'title'    => (string)($data['title'] ?? ''),
            'path'     => (string)($data['path'] ?? ''),
            'thumb'    => (string)($data['thumb'] ?? ''),
            'width'    => (int)($data['width'] ?? 0),
            'height'   => (int)($data['height'] ?? 0),
            'size'     => (int)($data['size'] ?? 0),
            'mime'     => (string)($data['mime'] ?? ''),
            'sort'     => (int)($max->max ?? 0) + 1,
            'created'  => time(),
        ];

        return (int)$db->query($db->insert(self::table(self::IMAGES))->rows($row));
    }

    /**
     * 更新照片信息（标题等）
     */
    public static function updateImage(int $id, array $data): void
    {
        if ($id <= 0 || empty($data)) {
            return;
        }

        $allow = ['title', 'sort'];
        $row = [];

        foreach ($allow as $key) {
            if (array_key_exists($key, $data)) {
                $row[$key] = $key === 'sort' ? (int)$data[$key] : (string)$data[$key];
            }
        }

        if (empty($row)) {
            return;
        }

        $db = self::db();
        $db->query($db->update(self::table(self::IMAGES))->rows($row)->where('id = ?', $id));
    }

    /**
     * 删除照片（默认同时删除磁盘文件）
     */
    public static function deleteImage(int $id, bool $removeFiles = true): void
    {
        $image = self::image($id);

        if (empty($image)) {
            return;
        }

        if ($removeFiles) {
            foreach (['path', 'thumb'] as $key) {
                if (!empty($image[$key])) {
                    $file = __TYPECHO_ROOT_DIR__ . $image[$key];
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }

        $db = self::db();
        $db->query($db->delete(self::table(self::IMAGES))->where('id = ?', $id));

        $album = self::album((int)$image['album_id']);
        if (!empty($album) && (int)$album['cover_id'] === $id) {
            $db->query(
                $db->update(self::table(self::ALBUMS))->rows(['cover_id' => 0])->where('id = ?', (int)$image['album_id'])
            );
        }
    }

    /**
     * 设置相册封面
     */
    public static function setCover(int $albumId, int $imageId): void
    {
        $db = self::db();
        $db->query(
            $db->update(self::table(self::ALBUMS))
                ->rows(['cover_id' => $imageId, 'updated' => time()])
                ->where('id = ?', $albumId)
        );
    }

    /**
     * 按给定顺序重排照片
     *
     * @param int[] $ids
     */
    public static function sortImages(array $ids): void
    {
        $db = self::db();
        $order = 1;

        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }

            $db->query(
                $db->update(self::table(self::IMAGES))->rows(['sort' => $order])->where('id = ?', $id)
            );
            $order++;
        }
    }

    /**
     * 相册内照片数量
     */
    public static function countImages(int $albumId): int
    {
        $db = self::db();
        $row = $db->fetchObject(
            $db->select(['COUNT(id)' => 'num'])->from(self::table(self::IMAGES))->where('album_id = ?', $albumId)
        );

        return (int)($row->num ?? 0);
    }

    /**
     * 承载图库的独立页面（进程内缓存，beforeRender 会频繁调用）
     */
    public static function hostPage(): ?array
    {
        if (self::$hostPageLoaded) {
            return self::$hostPageCache;
        }

        self::$hostPageLoaded = true;
        $db = self::db();

        $row = $db->fetchRow(
            $db->select('c.*')->from('table.contents AS c')
                ->join('table.fields AS f', 'c.cid = f.cid', Db::LEFT_JOIN)
                ->where('f.name = ?', 'ilgalleryHost')
                ->where('c.type = ?', 'page')
                ->limit(1)
        );

        if (empty($row)) {
            $row = $db->fetchRow(
                $db->select()->from('table.contents')
                    ->where('slug = ?', 'gallery')
                    ->where('type = ?', 'page')
                    ->limit(1)
            );
        }

        self::$hostPageCache = empty($row) ? null : $row;

        return self::$hostPageCache;
    }

    /**
     * 清掉承载页面的进程内缓存（重建页面后需要调用）
     */
    public static function flushHostPage(): void
    {
        self::$hostPageLoaded = false;
        self::$hostPageCache = null;
    }

    /**
     * 相册数据补全：缩略图/大图地址、可读大小、日期
     */
    public static function decorateImage(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['album_id'] = (int)$row['album_id'];
        $row['width'] = (int)$row['width'];
        $row['height'] = (int)$row['height'];
        $row['size'] = (int)$row['size'];
        $row['url'] = self::url((string)$row['path']);
        $row['thumbUrl'] = self::url((string)($row['thumb'] !== '' ? $row['thumb'] : $row['path']));
        $row['sizeText'] = self::sizeText((int)$row['size']);
        $row['date'] = date('Y-m-d H:i', (int)$row['created']);
        $row['fileName'] = basename((string)$row['path']);

        return $row;
    }

    /**
     * 相对路径转绝对 URL
     */
    public static function url(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (preg_match('#^(https?:)?//#i', $path) || strpos($path, 'data:') === 0) {
            return $path;
        }

        return Common::url($path, Helper::options()->siteUrl);
    }

    /**
     * 相册前台地址
     */
    public static function albumUrl(array $album): string
    {
        $options = Helper::options();

        try {
            return \Typecho\Router::url(self::albumRoute(), ['album' => $album['slug']], $options->index);
        } catch (\Throwable $e) {
            return rtrim((string)$options->siteUrl, '/') . '/gallery/' . rawurlencode((string)$album['slug']) . '/';
        }
    }

    /**
     * 相册列表地址
     */
    public static function galleryUrl(): string
    {
        $options = Helper::options();

        try {
            return \Typecho\Router::url('ilgallery', [], $options->index);
        } catch (\Throwable $e) {
            return rtrim((string)$options->siteUrl, '/') . '/gallery/';
        }
    }

    /**
     * 相册详情路由名
     */
    private static function albumRoute(): string
    {
        return 'ilgallery_album';
    }

    /**
     * 生成合法别名
     */
    public static function slugify(string $slug, string $name = ''): string
    {
        $slug = strtolower(trim($slug));

        if ($slug === '') {
            $slug = self::transliterate($name);
        }

        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
        $slug = trim((string)$slug, '-_');

        if ($slug === '' || ctype_digit($slug)) {
            $slug = 'album-' . base_convert((string)time(), 10, 36) . '-' . random_suffix();
        }

        return substr($slug, 0, 140);
    }

    /**
     * 简单的中文转写：只处理常见场景，失败则回落到时间戳别名
     */
    private static function transliterate(string $name): string
    {
        $map = [
            '相册' => 'album', '旅行' => 'travel', '日常' => 'daily', '生活' => 'life',
            '摄影' => 'photo', '随手拍' => 'snap', '记忆' => 'memory', '风景' => 'scenery',
        ];

        foreach ($map as $cn => $en) {
            if (strpos($name, $cn) !== false) {
                return $en;
            }
        }

        return '';
    }

    /**
     * 保证别名唯一
     */
    private static function uniqueSlug(string $slug, int $ignoreId = 0): string
    {
        $db = self::db();
        $base = $slug;
        $index = 2;

        while (true) {
            $row = $db->fetchRow(
                $db->select('id')->from(self::table(self::ALBUMS))->where('slug = ?', $slug)->limit(1)
            );

            if (empty($row) || (int)$row['id'] === $ignoreId) {
                return $slug;
            }

            $slug = $base . '-' . $index;
            $index++;

            if ($index > 200) {
                return $base . '-' . time();
            }
        }
    }

    /**
     * 字节数转可读文本
     */
    public static function sizeText(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 KB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        return max(1, (int)round($bytes / 1024)) . ' KB';
    }
}

/**
 * 生成短随机串（避免依赖第三方函数）
 */
function random_suffix(): string
{
    try {
        return bin2hex(random_bytes(2));
    } catch (\Throwable $e) {
        return substr((string)mt_rand(1000, 9999), 0, 4);
    }
}
