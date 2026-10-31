<?php
/**
 * 图库相册 · 后台管理面板
 *
 * 列表 / 新建 / 编辑 / 照片管理 / 上传接口都在这个文件里，
 * 由 Typecho 的 extending.php 载入，天然处于后台登录态与后台布局中。
 */

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

use TypechoPlugin\IlGallery\Image;
use TypechoPlugin\IlGallery\Plugin;
use TypechoPlugin\IlGallery\Store;
use Utils\Helper;

if (!$user->pass('administrator', true)) {
    $response->redirect($options->adminUrl);
}

$panelUrl = Helper::url('IlGallery/Manage.php');
$pluginUrl = rtrim((string)$options->pluginUrl, '/') . '/IlGallery';
$do = (string)($request->get('do') ?? '');
$isPost = $request->isPost();
$notice = '';
$error = '';

/**
 * 拼接面板地址
 */
function ilg_url(string $base, array $params = []): string
{
    if (empty($params)) {
        return $base;
    }

    return $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($params);
}

/**
 * 输出 JSON 并结束
 */
function ilg_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 校验 AJAX 请求的 CSRF token
 */
function ilg_check_token($security, $request): bool
{
    $token = (string)($request->get('_') ?? '');

    if ($token === '') {
        return false;
    }

    $expect = $security->getToken($request->getReferer());

    return hash_equals($expect, $token);
}

/**
 * 归一化 $_FILES 多文件结构
 */
function ilg_files(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }

    if (!is_array($files['name'])) {
        return [$files];
    }

    $result = [];

    foreach ($files['name'] as $index => $name) {
        $result[] = [
            'name'     => $name,
            'type'     => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error'    => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size'     => $files['size'][$index] ?? 0,
        ];
    }

    return $result;
}

/* =========================================================
 * 接口：上传照片
 * ========================================================= */
if ($isPost && $do === 'upload') {
    if (!ilg_check_token($security, $request)) {
        ilg_json(['ok' => false, 'error' => '安全校验失败，请刷新页面重试'], 403);
    }

    $albumId = (int)($request->get('id') ?? 0);
    $album = Store::album($albumId);

    if (empty($album)) {
        ilg_json(['ok' => false, 'error' => '相册不存在'], 404);
    }

    $files = ilg_files($_FILES['files'] ?? []);
    $uploaded = [];
    $errors = [];

    foreach ($files as $file) {
        $result = Image::handle($file, $albumId);

        if (!empty($result['ok'])) {
            $uploaded[] = $result['image'];
        } else {
            $errors[] = ($file['name'] ?? '文件') . '：' . ($result['error'] ?? '上传失败');
        }
    }

    if (empty($uploaded) && !empty($errors)) {
        ilg_json(['ok' => false, 'error' => implode('；', $errors)]);
    }

    ilg_json([
        'ok'     => true,
        'images' => array_map(function (array $image) {
            return [
                'id'       => $image['id'],
                'title'    => $image['title'],
                'thumbUrl' => $image['thumbUrl'],
                'url'      => $image['url'],
                'sizeText' => $image['sizeText'],
            ];
        }, $uploaded),
        'errors' => $errors,
        'total'  => Store::countImages($albumId),
    ]);
}

/* =========================================================
 * 接口：删除照片
 * ========================================================= */
if ($isPost && $do === 'deleteImage') {
    if (!ilg_check_token($security, $request)) {
        ilg_json(['ok' => false, 'error' => '安全校验失败，请刷新页面重试'], 403);
    }

    $imageId = (int)($request->get('image') ?? 0);
    $image = Store::image($imageId);

    if (empty($image)) {
        ilg_json(['ok' => false, 'error' => '照片不存在'], 404);
    }

    Store::deleteImage($imageId);

    ilg_json(['ok' => true, 'total' => Store::countImages((int)$image['album_id'])]);
}

/* =========================================================
 * 接口：设置封面
 * ========================================================= */
if ($isPost && $do === 'cover') {
    if (!ilg_check_token($security, $request)) {
        ilg_json(['ok' => false, 'error' => '安全校验失败，请刷新页面重试'], 403);
    }

    $albumId = (int)($request->get('id') ?? 0);
    $imageId = (int)($request->get('image') ?? 0);

    if ($imageId > 0) {
        $image = Store::image($imageId);

        if (empty($image) || (int)$image['album_id'] !== $albumId) {
            ilg_json(['ok' => false, 'error' => '照片不属于该相册'], 400);
        }
    }

    Store::setCover($albumId, $imageId);

    ilg_json(['ok' => true]);
}

/* =========================================================
 * 接口：重命名照片
 * ========================================================= */
if ($isPost && $do === 'rename') {
    if (!ilg_check_token($security, $request)) {
        ilg_json(['ok' => false, 'error' => '安全校验失败，请刷新页面重试'], 403);
    }

    $imageId = (int)($request->get('image') ?? 0);
    $title = trim((string)($request->get('title') ?? ''));

    if ($imageId <= 0) {
        ilg_json(['ok' => false, 'error' => '参数错误'], 400);
    }

    Store::updateImage($imageId, ['title' => mb_substr($title, 0, 180, 'UTF-8')]);

    ilg_json(['ok' => true]);
}

/* =========================================================
 * 接口：照片排序
 * ========================================================= */
if ($isPost && $do === 'sortPhotos') {
    if (!ilg_check_token($security, $request)) {
        ilg_json(['ok' => false, 'error' => '安全校验失败，请刷新页面重试'], 403);
    }

    $ids = (string)($request->get('ids') ?? '');
    $list = array_filter(array_map('intval', explode(',', $ids)));

    Store::sortImages($list);

    ilg_json(['ok' => true]);
}

/* =========================================================
 * 表单：保存相册
 * ========================================================= */
if ($isPost && $do === 'save') {
    $security->protect();

    $id = (int)($request->get('id') ?? 0);
    $name = trim((string)($request->get('name') ?? ''));

    if ($name === '') {
        $error = '请填写相册名称';
    } else {
        $savedId = Store::saveAlbum([
            'name'        => $name,
            'slug'        => (string)($request->get('slug') ?? ''),
            'description' => (string)($request->get('description') ?? ''),
            'cover_id'    => (int)($request->get('cover_id') ?? 0),
            'sort'        => (int)($request->get('sort') ?? 0),
            'status'      => (string)($request->get('status') ?? 'publish'),
        ], $id);

        $response->redirect(ilg_url($panelUrl, ['do' => 'photos', 'id' => $savedId, 'done' => 'saved']));
    }
}

/* =========================================================
 * 表单：删除相册
 * ========================================================= */
if ($isPost && $do === 'deleteAlbum') {
    $security->protect();

    $id = (int)($request->get('id') ?? 0);
    Store::deleteAlbum($id);

    $response->redirect(ilg_url($panelUrl, ['done' => 'deleted']));
}

/* =========================================================
 * 表单：重建图库页面
 * ========================================================= */
if ($isPost && $do === 'rebuild') {
    $security->protect();
    // 一键修复：建表、补建承载页面、重写配置、重建菜单与前台路由
    Plugin::repair();

    $response->redirect(ilg_url($panelUrl, ['done' => 'rebuilt']));
}

/* =========================================================
 * 视图数据
 * ========================================================= */
$done = (string)($request->get('done') ?? '');

if ($done === 'saved') {
    $notice = '相册已保存';
} elseif ($done === 'deleted') {
    $notice = '相册已删除';
} elseif ($done === 'rebuilt') {
    $notice = '图库页面已创建或修复';
}

$view = 'list';

if ($do === 'edit' || $do === 'photos') {
    $view = $do;
}

$album = null;

if ($view === 'edit') {
    $albumId = (int)($request->get('id') ?? 0);

    if ($albumId > 0) {
        $album = Store::album($albumId);

        if (empty($album)) {
            $error = '相册不存在';
            $view = 'list';
        }
    }
} elseif ($view === 'photos') {
    $albumId = (int)($request->get('id') ?? 0);
    $album = Store::album($albumId);

    if (empty($album)) {
        $error = '相册不存在';
        $view = 'list';
    }
}

if ($view === 'list') {
    $albums = Store::albums();
}

$photos = in_array($view, ['edit', 'photos'], true) && $album ? Store::images((int)$album['id']) : [];

$form = [
    'id'          => $album['id'] ?? 0,
    'name'        => $album['name'] ?? '',
    'slug'        => $album['slug'] ?? '',
    'description' => $album['description'] ?? '',
    'cover_id'    => (int)($album['cover_id'] ?? 0),
    'sort'        => (int)($album['sort'] ?? 0),
    'status'      => $album['status'] ?? 'publish',
];

$token = $security->getToken($request->getRequestUrl());
$hostPage = Store::hostPage();
$frontUrl = Store::galleryUrl();

include __TYPECHO_ROOT_DIR__ . '/admin/header.php';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($pluginUrl); ?>/assets/admin.css?v=<?php echo Plugin::VERSION; ?>">
<?php include __TYPECHO_ROOT_DIR__ . '/admin/menu.php'; ?>

<div class="main">
    <div class="body container">
        <div class="ilg-panel">
        <?php if ($notice !== ''): ?>
            <p class="message success"><?php echo htmlspecialchars($notice); ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="message error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <?php if ($view === 'list'): ?>
            <h2 class="typecho-page-title ilg-page-title">
                图库相册
                <a class="btn primary" href="<?php echo htmlspecialchars(ilg_url($panelUrl, ['do' => 'edit'])); ?>">
                    新建相册
                </a>
                <a class="btn" href="<?php echo htmlspecialchars($frontUrl); ?>" target="_blank" rel="noopener">预览图库</a>
                <a class="btn" href="<?php echo htmlspecialchars($options->adminUrl('options-plugin.php?config=IlGallery', true)); ?>">插件设置</a>
            </h2>

            <div class="ilg-hint">
                <div class="ilg-hint-main">
                    <strong>前台地址</strong>
                    <code><?php echo htmlspecialchars($frontUrl); ?></code>
                    <span>相册地址形如 <code><?php echo htmlspecialchars(rtrim($frontUrl, '/') . '/{相册别名}/'); ?></code></span>
                </div>
                <?php if (empty($hostPage)): ?>
                    <span class="ilg-hint-warn">承载图库的页面不存在，前台会 404，点右侧按钮自动修复。</span>
                <?php endif; ?>
                <form method="post" action="<?php echo htmlspecialchars(ilg_url($panelUrl, ['do' => 'rebuild'])); ?>">
                    <input type="hidden" name="_" value="<?php echo htmlspecialchars($token); ?>">
                    <button type="submit" class="btn<?php echo empty($hostPage) ? ' primary' : ''; ?>">修复页面与路由</button>
                </form>
            </div>

            <?php if (empty($albums)): ?>
                <div class="ilg-admin-empty">
                    <p>还没有相册</p>
                    <small>点击右上角「新建相册」开始整理你的第一组照片。</small>
                </div>
            <?php else: ?>
                <div class="typecho-list-operate clearfix">
                    <span class="operate">共 <?php echo count($albums); ?> 个相册</span>
                </div>

                <div class="typecho-table-wrap">
                    <table class="typecho-list-table ilg-table">
                        <colgroup>
                            <col width="92">
                            <col>
                            <col width="190">
                            <col width="70">
                            <col width="60">
                            <col width="80">
                            <col width="160">
                        </colgroup>
                        <thead>
                        <tr>
                            <th>封面</th>
                            <th>相册</th>
                            <th>地址</th>
                            <th>照片</th>
                            <th>排序</th>
                            <th>状态</th>
                            <th>操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($albums as $item): ?>
                            <tr>
                                <td>
                                    <a class="ilg-cover" href="<?php echo htmlspecialchars($item['url']); ?>" target="_blank" rel="noopener">
                                        <?php if (!empty($item['cover'])): ?>
                                            <img src="<?php echo htmlspecialchars($item['cover']['thumbUrl']); ?>" alt="">
                                        <?php else: ?>
                                            <span class="ilg-cover-blank">无</span>
                                        <?php endif; ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="ilg-row-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                    <?php if (!empty($item['description'])): ?>
                                        <div class="ilg-row-desc"><?php echo htmlspecialchars($item['description']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><code class="ilg-slug">/gallery/<?php echo htmlspecialchars($item['slug']); ?>/</code></td>
                                <td><?php echo (int)$item['count']; ?></td>
                                <td><?php echo (int)$item['sort']; ?></td>
                                <td>
                                    <?php if ($item['status'] === 'publish'): ?>
                                        <span class="ilg-status is-on">已发布</span>
                                    <?php else: ?>
                                        <span class="ilg-status">已隐藏</span>
                                    <?php endif; ?>
                                </td>
                                <td class="ilg-actions">
                                    <a href="<?php echo htmlspecialchars(ilg_url($panelUrl, ['do' => 'photos', 'id' => $item['id']])); ?>">照片</a>
                                    <a href="<?php echo htmlspecialchars(ilg_url($panelUrl, ['do' => 'edit', 'id' => $item['id']])); ?>">编辑</a>
                                    <form method="post" class="ilg-inline"
                                          action="<?php echo htmlspecialchars(ilg_url($panelUrl, ['do' => 'deleteAlbum'])); ?>"
                                          onsubmit="return confirm('删除相册会同时删除其中的照片文件，确定继续吗？');">
                                        <input type="hidden" name="_" value="<?php echo htmlspecialchars($token); ?>">
                                        <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                                        <button type="submit" class="ilg-link-danger">删除</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="ilg-tips">
                <h3>使用说明</h3>
                <ol>
                    <li>插件启用时会自动创建一个标题为「相册」的独立页面，并在 ilogs 左侧导航中显示。</li>
                    <li>如果你更换了主题或删除了该页面，可点击上方「修复图库页面」重新生成。</li>
                    <li>相册封面默认取相册内第一张照片，也可以在相册编辑页指定。</li>
                </ol>
            </div>

        <?php elseif ($view === 'edit'): ?>
            <h2 class="typecho-page-title ilg-page-title">
                <?php echo $form['id'] > 0 ? '编辑相册' : '新建相册'; ?>
                <a class="btn" href="<?php echo htmlspecialchars($panelUrl); ?>">返回列表</a>
            </h2>

            <form method="post" action="<?php echo htmlspecialchars(ilg_url($panelUrl, ['do' => 'save'])); ?>" class="ilg-form">
                <input type="hidden" name="_" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">

                <ul class="typecho-option">
                    <li>
                        <label class="typecho-label" for="ilg-name">相册名称 <span class="ilg-required">*</span></label>
                        <input id="ilg-name" class="text w-100" type="text" name="name"
                               value="<?php echo htmlspecialchars($form['name']); ?>" maxlength="150"
                               placeholder="例如：2026 随手拍" required>
                        <p class="description">显示在相册墙与相册详情页顶部。</p>
                    </li>
                    <li>
                        <label class="typecho-label" for="ilg-slug">相册别名</label>
                        <input id="ilg-slug" class="text w-100" type="text" name="slug"
                               value="<?php echo htmlspecialchars($form['slug']); ?>" maxlength="150"
                               placeholder="留空自动生成，例如 shanghai-2023">
                        <p class="description">用于生成地址 <code>/gallery/别名/</code>，仅支持小写字母、数字、短横线与下划线。</p>
                    </li>
                    <li>
                        <label class="typecho-label" for="ilg-description">相册描述</label>
                        <textarea id="ilg-description" class="text w-100" name="description" rows="3"
                                  maxlength="500" placeholder="一句话介绍这个相册"><?php echo htmlspecialchars($form['description']); ?></textarea>
                        <p class="description">显示在相册标题下方，可留空。</p>
                    </li>
                    <li class="ilg-form-row">
                        <div>
                            <label class="typecho-label" for="ilg-sort">排序</label>
                            <input id="ilg-sort" class="text" type="number" name="sort" step="1"
                                   value="<?php echo (int)$form['sort']; ?>">
                            <p class="description">数字越小越靠前。</p>
                        </div>
                        <div>
                            <label class="typecho-label" for="ilg-status">状态</label>
                            <select id="ilg-status" name="status" class="text">
                                <option value="publish" <?php echo $form['status'] === 'publish' ? 'selected' : ''; ?>>已发布</option>
                                <option value="hidden" <?php echo $form['status'] === 'hidden' ? 'selected' : ''; ?>>已隐藏</option>
                            </select>
                            <p class="description">隐藏后前台不可访问。</p>
                        </div>
                    </li>
                </ul>

                <?php if ($form['id'] > 0 && !empty($photos)): ?>
                    <div class="ilg-cover-picker">
                        <h3>相册封面</h3>
                        <p class="description">点击选择一张照片作为封面，不选则使用相册内第一张。</p>
                        <div class="ilg-cover-grid">
                            <label class="ilg-cover-item <?php echo $form['cover_id'] === 0 ? 'is-active' : ''; ?>">
                                <input type="radio" name="cover_id" value="0" <?php echo $form['cover_id'] === 0 ? 'checked' : ''; ?>>
                                <span class="ilg-cover-auto">自动</span>
                            </label>
                            <?php foreach ($photos as $photo): ?>
                                <label class="ilg-cover-item <?php echo $form['cover_id'] === (int)$photo['id'] ? 'is-active' : ''; ?>">
                                    <input type="radio" name="cover_id" value="<?php echo (int)$photo['id']; ?>"
                                        <?php echo $form['cover_id'] === (int)$photo['id'] ? 'checked' : ''; ?>>
                                    <img src="<?php echo htmlspecialchars($photo['thumbUrl']); ?>" alt="">
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($form['id'] > 0): ?>
                    <p class="ilg-note">相册里还没有照片，保存后前往「照片」页上传，即可选择封面。</p>
                <?php endif; ?>

                <div class="ilg-form-submit">
                    <button type="submit" class="btn primary">保存相册</button>
                    <a class="btn" href="<?php echo htmlspecialchars($panelUrl); ?>">取消</a>
                </div>
            </form>

        <?php elseif ($view === 'photos'): ?>
            <h2 class="typecho-page-title ilg-page-title">
                <?php echo htmlspecialchars($album['name']); ?>
                <a class="btn" href="<?php echo htmlspecialchars($panelUrl); ?>">返回列表</a>
                <a class="btn" href="<?php echo htmlspecialchars(ilg_url($panelUrl, ['do' => 'edit', 'id' => $album['id']])); ?>">编辑相册</a>
                <a class="btn" href="<?php echo htmlspecialchars($album['url']); ?>" target="_blank" rel="noopener">前台查看</a>
            </h2>

            <div class="ilg-album-summary">
                <div>
                    <span class="ilg-summary-num" id="ilgPhotoCount"><?php echo (int)$album['count']; ?></span>
                    <span class="ilg-summary-label">张照片</span>
                </div>
                <div class="ilg-summary-meta">
                    地址 <code>/<?php echo htmlspecialchars($album['slug']); ?>/</code>
                    <?php if (!empty($album['description'])): ?>
                        · <?php echo htmlspecialchars($album['description']); ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ilg-drop" id="ilgDrop" tabindex="0" role="button" aria-label="上传照片">
                <input type="file" id="ilgFile" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/>
                    <path d="M7 9l5 -5l5 5"/>
                    <path d="M12 4l0 12"/>
                </svg>
                <p>把图片拖到这里，或点击选择文件</p>
                <small>支持 JPG / PNG / GIF / WebP，可一次选择多张，单张不超过 <?php echo (int)Plugin::setting('maxUpload'); ?> MB</small>
                <div class="ilg-drop-progress" id="ilgProgress"></div>
            </div>

            <div class="ilg-photo-admin" id="ilgPhotoAdmin"
                 data-endpoint="<?php echo htmlspecialchars($panelUrl); ?>"
                 data-album="<?php echo (int)$album['id']; ?>"
                 data-token="<?php echo htmlspecialchars($token); ?>">
                <?php foreach ($photos as $photo): ?>
                    <div class="ilg-card" draggable="true" data-id="<?php echo (int)$photo['id']; ?>">
                        <div class="ilg-card-media">
                            <img src="<?php echo htmlspecialchars($photo['thumbUrl']); ?>" alt="" loading="lazy">
                            <span class="ilg-card-badge<?php echo (int)$album['cover_id'] === (int)$photo['id'] ? ' is-on' : ''; ?>">封面</span>
                            <span class="ilg-card-drag" title="拖动排序">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                    <path d="M9 5h.01"/><path d="M9 12h.01"/><path d="M9 19h.01"/>
                                    <path d="M15 5h.01"/><path d="M15 12h.01"/><path d="M15 19h.01"/>
                                </svg>
                            </span>
                        </div>
                        <input class="ilg-card-title" type="text" value="<?php echo htmlspecialchars($photo['title']); ?>"
                               maxlength="180" aria-label="照片标题">
                        <div class="ilg-card-meta">
                            <span><?php echo htmlspecialchars($photo['sizeText']); ?></span>
                            <span><?php echo htmlspecialchars($photo['date']); ?></span>
                        </div>
                        <div class="ilg-card-actions">
                            <button type="button" data-act="cover">设为封面</button>
                            <button type="button" data-act="delete" class="ilg-link-danger">删除</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="ilg-photo-admin-empty" id="ilgPhotoEmpty" <?php echo empty($photos) ? '' : 'hidden'; ?>>
                <p>还没有照片</p>
                <small>拖拽或点击上方区域上传，第一张照片会自动成为封面。</small>
            </div>

            <script>
                window.ilgAdmin = {
                    endpoint: <?php echo json_encode($panelUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>,
                    album: <?php echo (int)$album['id']; ?>,
                    token: <?php echo json_encode($token, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>,
                    maxUpload: <?php echo (int)Plugin::setting('maxUpload'); ?>
                };
            </script>
            <script src="<?php echo htmlspecialchars($pluginUrl); ?>/assets/admin.js?v=<?php echo Plugin::VERSION; ?>"></script>
        <?php endif; ?>
        </div>
    </div>
</div>

<?php include __TYPECHO_ROOT_DIR__ . '/admin/footer.php'; ?>
