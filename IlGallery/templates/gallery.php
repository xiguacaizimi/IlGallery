<?php
/**
 * 图库前台模板
 *
 * 列表页：相册墙；详情页：照片墙（分页）+ 内嵌大图查看器。
 * 元素结构与样式对齐 ilogs 官方图库（album-page-shell 体系），
 * 主题外壳（页头、左栏、导航、右栏、页脚）全部复用 ilogs 原模板。
 *
 * @var \TypechoPlugin\IlGallery\Widget\Gallery $this
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use TypechoPlugin\IlGallery\Plugin;
use TypechoPlugin\IlGallery\Store;

$layoutMode = $this->options->layoutMode ?? 'two';
$settings = Plugin::setting();
$galleryUrl = Store::galleryUrl();
$pluginAssets = rtrim((string)$this->options->pluginUrl, '/') . '/IlGallery/assets';
$isViewing = $currentPhoto !== null;
$albumCols = max(2, min(4, (int)$settings['albumColumns']));
$photoCols = max(3, min(6, (int)$settings['photoColumns']));

$this->need('template-parts/header.php');

// 查看器数据：包含相册内全部照片（可跨分页浏览）
$galleryData = $album ? [
    'index'  => $isViewing ? $photoIndex : -1,
    'album'  => $album['name'],
    'albumUrl' => $album['url'],
    'photos' => array_map(function (array $photo) {
        return [
            'id'       => (int)$photo['id'],
            'title'    => $photo['title'],
            'url'      => $photo['url'],
            'thumb'    => $photo['thumbUrl'],
            'date'     => $photo['date'],
            'size'     => $photo['sizeText'],
            'fileName' => $photo['fileName'],
            'width'    => (int)$photo['width'],
            'height'   => (int)$photo['height'],
        ];
    }, $photos),
] : null;

// 内联 SVG 图标（与 tabler icons 同路径），不依赖主题是否加载图标字体
$ilgIcon = function (string $name, int $size = 16, float $stroke = 2): string {
    $paths = [
        'arrow-left'  => '<path d="M5 12l14 0"/><path d="M5 12l6 6"/><path d="M5 12l6 -6"/>',
        'chevron-left'  => '<path d="M15 6l-6 6l6 6"/>',
        'chevron-right' => '<path d="M9 6l6 6l-6 6"/>',
        'zoom-in'     => '<circle cx="10" cy="10" r="7"/><path d="M21 21l-6 -6"/><path d="M10 7v6"/><path d="M7 10h6"/>',
        'zoom-out'    => '<circle cx="10" cy="10" r="7"/><path d="M21 21l-6 -6"/><path d="M7 10h6"/>',
        'refresh'     => '<path d="M20 11a8 8 0 1 0 -.5 4m.5 5v-5h-5"/>',
        'download'    => '<path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 11l5 5l5 -5"/><path d="M12 4l0 12"/>',
        'file-text'   => '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/><path d="M9 9l1 0"/><path d="M9 13l6 0"/><path d="M9 17l6 0"/>',
        'photo'       => '<path d="M15 8h.01"/><path d="M3 6a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12z"/><path d="M3 16l5 -5c.928 -.893 2.072 -.893 3 0l5 5"/><path d="M14 14l1 -1c.928 -.893 2.072 -.893 3 0l3 3"/>',
    ];

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $stroke
        . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
};
?>
<?php if ($layoutMode !== 'one'): ?>
    <?php $this->need('template-parts/left.php'); ?>
<?php endif; ?>
<div class="col-12 col-md-12 col-lg-<?php echo $layoutMode === 'one' ? '12' : ($layoutMode === 'three' ? '8' : '9'); ?>" id="middle">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($pluginAssets); ?>/gallery.css?v=<?php echo Plugin::VERSION; ?>">
    <?php $this->need('template-parts/nav-menu.php'); ?>

    <div class="album-page-shell ilg-page<?php echo $isViewing ? ' is-viewing' : ''; ?>" id="ilgPage"
        style="--ilg-album-cols: <?php echo $albumCols; ?>; --ilg-photo-cols: <?php echo $photoCols; ?>;"
        <?php if ($galleryData): ?>data-ilg-data="<?php echo htmlspecialchars(json_encode($galleryData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>

        <?php if (!$album): ?>
            <?php /* ---------- 相册墙 ---------- */ ?>
            <header class="album-page-heading">
                <div>
                    <h1><?php echo htmlspecialchars((string)$settings['listTitle']); ?></h1>
                    <p><?php echo htmlspecialchars((string)$settings['listSubtitle']); ?></p>
                </div>
                <span><?php echo count($albums); ?> 个相册</span>
            </header>

            <?php if (empty($albums)): ?>
                <div class="album-empty-state">
                    <?php echo $ilgIcon('photo', 32, 1.5); ?>
                    <p>还没有相册，进入后台「图库 → 相册管理」创建第一个相册</p>
                </div>
            <?php else: ?>
                <div class="album-cover-grid">
                    <?php foreach ($albums as $item): ?>
                        <a class="album-cover-item" href="<?php echo htmlspecialchars($item['url']); ?>"
                           title="<?php echo htmlspecialchars($item['name']); ?>">
                            <div class="album-cover-media">
                                <?php if (!empty($item['cover'])): ?>
                                    <img src="<?php echo htmlspecialchars($item['cover']['thumbUrl']); ?>"
                                         alt="<?php echo htmlspecialchars($item['name']); ?>" loading="lazy"
                                         onerror="this.style.opacity=.25;">
                                <?php else: ?>
                                    <span class="album-cover-placeholder"><?php echo $ilgIcon('photo', 34, 1.5); ?></span>
                                <?php endif; ?>
                                <div class="album-cover-shade"></div>
                                <div class="album-cover-copy">
                                    <div class="album-cover-title-row">
                                        <h2><?php echo htmlspecialchars($item['name']); ?></h2>
                                        <span><?php echo (int)$item['count']; ?> 张</span>
                                    </div>
                                    <?php if (!empty($item['description'])): ?>
                                        <p><?php echo htmlspecialchars($item['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <?php /* ---------- 照片墙 + 查看器 ---------- */ ?>
            <header class="album-detail-heading">
                <a href="<?php echo htmlspecialchars($galleryUrl); ?>" class="album-back-link">
                    <?php echo $ilgIcon('arrow-left', 14); ?><span><?php echo htmlspecialchars((string)$settings['listTitle']); ?></span>
                </a>
                <div class="album-detail-title-row">
                    <div>
                        <h1><?php echo htmlspecialchars($album['name']); ?></h1>
                        <?php if (!empty($album['description'])): ?>
                            <p><?php echo htmlspecialchars($album['description']); ?></p>
                        <?php endif; ?>
                    </div>
                    <span class="album-photo-count"><?php echo (int)$total; ?> 张照片</span>
                </div>
            </header>

            <?php if (empty($photos)): ?>
                <div class="album-empty-state">
                    <?php echo $ilgIcon('photo', 32, 1.5); ?>
                    <p>这个相册还是空的，进入后台「相册管理 → 照片」上传图片</p>
                </div>
            <?php else: ?>
                <div class="album-photo-grid" id="ilgGrid">
                    <?php foreach ($pageItems as $item): ?>
                        <?php $photo = $item['photo']; ?>
                        <a class="album-photo-item" href="<?php echo htmlspecialchars($album['url'] . '?photo=' . (int)$photo['id']); ?>"
                           data-no-pjax
                           data-index="<?php echo (int)$item['index']; ?>"
                           data-id="<?php echo (int)$photo['id']; ?>"
                           title="<?php echo htmlspecialchars($photo['title']); ?>">
                            <img src="<?php echo htmlspecialchars($photo['thumbUrl']); ?>"
                                 alt="<?php echo htmlspecialchars($photo['title']); ?>" loading="lazy"
                                 onerror="this.style.opacity=.2;">
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($pages > 1): ?>
                    <nav class="album-pagination" aria-label="照片分页">
                        <?php for ($p = 1; $p <= $pages; $p++): ?>
                            <a href="<?php echo htmlspecialchars($album['url'] . ($p > 1 ? '?ilpage=' . $p : '')); ?>"
                               data-page="<?php echo $p; ?>"<?php echo $p === $page ? ' class="active" aria-current="page"' : ''; ?>><?php echo $p; ?></a>
                        <?php endfor; ?>
                    </nav>
                <?php endif; ?>

                <section class="album-viewer" id="ilgViewer">
                    <header class="album-viewer-header">
                        <nav class="album-viewer-breadcrumb" aria-label="breadcrumb">
                            <a href="<?php echo htmlspecialchars($galleryUrl); ?>">
                                <?php echo $ilgIcon('arrow-left', 14); ?><span><?php echo htmlspecialchars((string)$settings['listTitle']); ?></span>
                            </a>
                            <span>/</span>
                            <a href="<?php echo htmlspecialchars($album['url']); ?>"><?php echo htmlspecialchars($album['name']); ?></a>
                        </nav>
                        <div class="album-viewer-heading">
                            <h1 id="ilgPhotoTitle"><?php echo $isViewing ? htmlspecialchars($currentPhoto['title']) : '照片详情'; ?></h1>
                            <p id="ilgPhotoMeta"><small><?php echo $isViewing ? htmlspecialchars($album['name'] . ' · ' . $currentPhoto['date'] . ' · ' . $currentPhoto['sizeText']) : ''; ?></small></p>
                        </div>
                    </header>

                    <div class="album-photo-stage">
                        <div class="album-photo-tools" aria-label="照片工具">
                            <button type="button" data-ilg="zoom-in" title="放大" aria-label="放大"><?php echo $ilgIcon('zoom-in', 17); ?></button>
                            <button type="button" data-ilg="zoom-out" title="缩小" aria-label="缩小"><?php echo $ilgIcon('zoom-out', 17); ?></button>
                            <button type="button" data-ilg="reset" title="还原" aria-label="还原"><?php echo $ilgIcon('refresh', 17); ?></button>
                            <?php if ($settings['allowDownload'] === '1'): ?>
                                <a id="ilgDownload" href="<?php echo htmlspecialchars($currentPhoto['url'] ?? ''); ?>"
                                   title="下载原图" aria-label="下载原图" download><?php echo $ilgIcon('download', 17); ?></a>
                            <?php endif; ?>
                        </div>

                        <button type="button" class="album-stage-arrow is-prev" data-ilg="prev" aria-label="上一张" title="上一张">
                            <?php echo $ilgIcon('chevron-left', 25); ?>
                        </button>

                        <div class="album-photo-loading" id="ilgLoading" hidden><span></span></div>

                        <div class="ilg-canvas" id="ilgCanvas">
                            <img id="ilgImage" src="" alt="" draggable="false">
                        </div>

                        <button type="button" class="album-stage-arrow is-next" data-ilg="next" aria-label="下一张" title="下一张">
                            <?php echo $ilgIcon('chevron-right', 25); ?>
                        </button>

                        <div class="album-photo-article" id="ilgArticle" hidden>
                            <span><?php echo $ilgIcon('file-text', 14); ?><span id="ilgArticleTitle"></span></span>
                        </div>
                    </div>

                    <section class="album-filmstrip" aria-label="本相册其他照片">
                        <div class="album-filmstrip-heading"><span>本相册</span></div>
                        <div class="album-filmstrip-track">
                            <button type="button" class="album-filmstrip-arrow is-prev" data-ilg="strip-prev" aria-label="向左滚动" title="向左滚动">
                                <?php echo $ilgIcon('chevron-left', 25); ?>
                            </button>
                            <div class="photo-preview-container" id="ilgThumbs">
                                <div class="photo-preview-items" id="ilgThumbItems">
                                    <?php foreach ($photos as $index => $photo): ?>
                                        <button type="button" class="photo-preview-item" data-index="<?php echo (int)$index; ?>"
                                                aria-label="<?php echo htmlspecialchars($photo['title']); ?>">
                                            <img src="<?php echo htmlspecialchars($photo['thumbUrl']); ?>" alt="" loading="lazy"
                                                 onerror="this.style.opacity=.2;">
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <button type="button" class="album-filmstrip-arrow is-next" data-ilg="strip-next" aria-label="向右滚动" title="向右滚动">
                                <?php echo $ilgIcon('chevron-right', 25); ?>
                            </button>
                        </div>
                    </section>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="<?php echo htmlspecialchars($pluginAssets); ?>/gallery.js?v=<?php echo Plugin::VERSION; ?>"></script>
</div>
<?php if ($layoutMode !== 'one'): ?>
    <?php $this->need('template-parts/right.php'); ?>
<?php endif; ?>
<?php $this->need('template-parts/footer-copyright.php'); ?>
<?php $this->need('template-parts/footer.php'); ?>
