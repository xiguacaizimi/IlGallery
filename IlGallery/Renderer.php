<?php

namespace TypechoPlugin\IlGallery;

use Typecho\Widget\Exception;
use Widget\Archive;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 图库渲染器
 *
 * 两个入口共用：
 * 1. /gallery/ 与 /gallery/{slug}/ 路由（Widget\Gallery）；
 * 2. 后台自动创建的「相册」独立页面（主题左侧导航指向它）。
 */
class Renderer
{
    /** 照片墙每页数量（与 ilogs 官方图库一致） */
    public const PER_PAGE = 24;

    /**
     * 输出图库页面
     *
     * @param Archive $archive 当前归档对象，模板里仍以 $this 访问主题能力
     * @throws Exception
     */
    public static function render(Archive $archive): void
    {
        $slug = (string)($archive->request->get('album') ?? '');

        $album = null;
        $albums = [];
        $photos = [];
        $currentPhoto = null;
        $photoIndex = -1;

        // 分页相关
        $page = 1;
        $pages = 1;
        $total = 0;
        $pageItems = [];

        if ($slug !== '') {
            $album = Store::albumBySlug($slug);

            if (empty($album)) {
                throw new Exception(_t('相册不存在或已隐藏'), 404);
            }

            $photos = Store::images((int)$album['id']);
            $archive->setArchiveTitle((string)$album['name']);
            $total = count($photos);
            $pages = max(1, (int)ceil($total / self::PER_PAGE));
            // 用 ilpage 而不是 page，避免和 Typecho 保留的分页参数 page 冲突
            $rawPage = $archive->request->get('ilpage');
            $rawPage = ($rawPage === null || $rawPage === '') ? $archive->request->get('page') : $rawPage;
            $page = min($pages, max(1, (int)($rawPage ?? 1)));

            $offset = ($page - 1) * self::PER_PAGE;

            foreach (array_slice($photos, $offset, self::PER_PAGE) as $i => $photo) {
                $pageItems[] = [
                    'photo' => $photo,
                    'index' => $offset + (int)$i,
                ];
            }

            $photoId = (int)($archive->request->get('photo') ?? 0);

            if ($photoId > 0) {
                foreach ($photos as $index => $photo) {
                    if ((int)$photo['id'] === $photoId) {
                        $currentPhoto = $photo;
                        $photoIndex = (int)$index;
                        break;
                    }
                }
            }
        } else {
            $albums = Store::albums(true);
        }

        $isViewing = $currentPhoto !== null;

        // 用闭包绑定当前归档对象，模板里可以继续使用 $this->need() / $this->options
        $render = function () use (
            $album,
            $albums,
            $photos,
            $currentPhoto,
            $photoIndex,
            $isViewing,
            $page,
            $pages,
            $total,
            $pageItems
        ) {
            require __DIR__ . '/templates/gallery.php';
        };

        $render->call($archive);
    }
}
