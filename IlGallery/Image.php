<?php

namespace TypechoPlugin\IlGallery;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 图片上传与缩略图处理
 */
class Image
{
    /** 允许的扩展名 */
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** 缩略图最长边 */
    private const THUMB_SIZE = 960;

    /**
     * 处理一个上传文件
     *
     * @param array $file $_FILES 中的单个文件数组
     * @param int $albumId
     * @return array{ok:bool,error?:string,image?:array}
     */
    public static function handle(array $file, int $albumId): array
    {
        if ($albumId <= 0) {
            return ['ok' => false, 'error' => '相册不存在'];
        }

        if (!isset($file['error']) || is_array($file['error'])) {
            return ['ok' => false, 'error' => '上传参数异常'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => self::uploadError((int)$file['error'])];
        }

        $maxMb = (int)Plugin::setting('maxUpload');
        if ($maxMb > 0 && (int)$file['size'] > $maxMb * 1024 * 1024) {
            return ['ok' => false, 'error' => '图片超过 ' . $maxMb . ' MB 限制'];
        }

        $originalName = (string)$file['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, self::ALLOWED, true)) {
            return ['ok' => false, 'error' => '仅支持 JPG / PNG / GIF / WebP'];
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'error' => '上传文件校验失败'];
        }

        $info = @getimagesize($file['tmp_name']);
        $types = [
            IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
            IMAGETYPE_PNG  => ['png', 'image/png'],
            IMAGETYPE_GIF  => ['gif', 'image/gif'],
            IMAGETYPE_WEBP => ['webp', 'image/webp'],
        ];

        if ($info === false || !isset($types[$info[2]])) {
            return ['ok' => false, 'error' => '文件不是有效的图片'];
        }

        [$realExt, $mime] = $types[$info[2]];

        // 以真实图片类型为准，避免改扩展名绕过校验
        if (!in_array($realExt, self::ALLOWED, true)) {
            return ['ok' => false, 'error' => '不支持的图片格式'];
        }

        $relativeDir = '/usr/uploads/ilgallery/' . date('Y/m');
        $absoluteDir = __TYPECHO_ROOT_DIR__ . $relativeDir;

        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            return ['ok' => false, 'error' => '无法创建上传目录，请检查 usr/uploads 权限'];
        }

        $base = date('YmdHis') . '-' . self::randomHex(3);
        $fileName = $base . '.' . $realExt;
        $target = $absoluteDir . '/' . $fileName;

        if (!@move_uploaded_file($file['tmp_name'], $target)) {
            return ['ok' => false, 'error' => '保存文件失败，请检查目录权限'];
        }

        @chmod($target, 0644);

        $width = (int)$info[0];
        $height = (int)$info[1];

        // 按 EXIF 方向校正，避免手机照片横躺
        self::fixOrientation($target, $realExt);
        $size = @getimagesize($target);

        if ($size !== false) {
            $width = (int)$size[0];
            $height = (int)$size[1];
        }

        $thumbName = self::makeThumb($target, $absoluteDir, $base, $realExt);
        $thumbRelative = $thumbName !== null ? $relativeDir . '/' . $thumbName : '';

        $id = Store::addImage([
            'album_id' => $albumId,
            'title'    => self::cleanTitle($originalName),
            'path'     => $relativeDir . '/' . $fileName,
            'thumb'    => $thumbRelative,
            'width'    => $width,
            'height'   => $height,
            'size'     => (int)filesize($target),
            'mime'     => $mime,
        ]);

        if ($id <= 0) {
            @unlink($target);
            if ($thumbRelative !== '') {
                @unlink(__TYPECHO_ROOT_DIR__ . $thumbRelative);
            }

            return ['ok' => false, 'error' => '写入数据库失败'];
        }

        return ['ok' => true, 'image' => Store::image($id)];
    }

    /**
     * 生成缩略图，成功返回文件名，失败返回 null
     */
    public static function makeThumb(string $source, string $dir, string $base, string $ext): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $image = self::open($source, $ext);

        if (!$image) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::THUMB_SIZE / max($width, $height));
        $thumbWidth = max(1, (int)round($width * $scale));
        $thumbHeight = max(1, (int)round($height * $scale));

        $canvas = imagecreatetruecolor($thumbWidth, $thumbHeight);

        if (!$canvas) {
            imagedestroy($image);
            return null;
        }

        // 保留透明通道（WebP / PNG 素材）
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, $thumbWidth, $thumbHeight, $transparent);
        imagealphablending($canvas, true);

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);

        $useWebp = function_exists('imagewebp');
        $name = $base . '-thumb.' . ($useWebp ? 'webp' : 'jpg');
        $target = $dir . '/' . $name;
        $ok = false;

        if ($useWebp) {
            $ok = @imagewebp($canvas, $target, 82);
        } else {
            $ok = @imagejpeg($canvas, $target, 82);
        }

        if ($ok) {
            @chmod($target, 0644);
        }

        imagedestroy($canvas);
        imagedestroy($image);

        return $ok ? $name : null;
    }

    /**
     * 打开图片资源
     *
     * @return resource|\GdImage|false
     */
    private static function open(string $file, string $ext)
    {
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($file) : false;
            case 'png':
                return function_exists('imagecreatefrompng') ? @imagecreatefrompng($file) : false;
            case 'gif':
                return function_exists('imagecreatefromgif') ? @imagecreatefromgif($file) : false;
            case 'webp':
                return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false;
        }

        return false;
    }

    /**
     * 依据 EXIF Orientation 旋转原图
     */
    private static function fixOrientation(string $file, string $ext): bool
    {
        if (!in_array($ext, ['jpg', 'jpeg'], true) || !function_exists('exif_read_data')) {
            return false;
        }

        $exif = @exif_read_data($file);

        if (empty($exif['Orientation'])) {
            return false;
        }

        $orientation = (int)$exif['Orientation'];

        if ($orientation < 2 || $orientation > 8) {
            return false;
        }

        $image = self::open($file, $ext);

        if (!$image) {
            return false;
        }

        $angle = 0;
        $flip = false;

        switch ($orientation) {
            case 2:
                $flip = true;
                break;
            case 3:
                $angle = 180;
                break;
            case 4:
                $flip = true;
                $angle = 180;
                break;
            case 5:
                $flip = true;
                $angle = 270;
                break;
            case 6:
                $angle = 270;
                break;
            case 7:
                $flip = true;
                $angle = 90;
                break;
            case 8:
                $angle = 90;
                break;
        }

        if ($flip) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        $ok = @imagejpeg($image, $file, 90);
        imagedestroy($image);

        return (bool)$ok;
    }

    /**
     * 清理标题（保留原始文件名，去掉路径与控制字符）
     */
    private static function cleanTitle(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim((string)$name);

        if ($name === '') {
            $name = '未命名图片';
        }

        return mb_substr($name, 0, 180, 'UTF-8');
    }

    /**
     * 随机十六进制串
     */
    private static function randomHex(int $bytes): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (\Throwable $e) {
            return substr(md5((string)mt_rand()), 0, $bytes * 2);
        }
    }

    /**
     * 上传错误码转文案
     */
    private static function uploadError(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return '图片超过了服务器允许的大小';
            case UPLOAD_ERR_PARTIAL:
                return '文件只上传了一部分，请重试';
            case UPLOAD_ERR_NO_FILE:
                return '没有选择文件';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '服务器缺少临时目录';
            case UPLOAD_ERR_CANT_WRITE:
                return '服务器无法写入文件';
            case UPLOAD_ERR_EXTENSION:
                return '上传被服务器扩展阻止';
        }

        return '上传失败（错误码 ' . $code . '）';
    }
}
