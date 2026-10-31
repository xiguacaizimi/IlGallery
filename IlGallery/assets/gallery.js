/**
 * 图库相册 · 前台交互
 *
 * 相册墙 / 照片墙 / 分页由服务端渲染，这里负责图片查看器：
 * 打开、跨页切换、缩放、还原、拖拽、加载指示、缩略图条同步与地址栏状态。
 */
(function () {
    'use strict';

    // PJAX 会替换 #middle 并重新执行本脚本，先清理上一次注册在 window/document 上的监听
    if (typeof window.__ilgTeardown === 'function') {
        try {
            window.__ilgTeardown();
        } catch (error) {
            // 忽略
        }
    }

    var root = document.getElementById('ilgPage');

    if (!root || !root.getAttribute('data-ilg-data')) {
        return;
    }

    if (root.getAttribute('data-ilg-ready') === '1') {
        return;
    }

    root.setAttribute('data-ilg-ready', '1');

    var data;

    try {
        data = JSON.parse(root.getAttribute('data-ilg-data'));
    } catch (error) {
        return;
    }

    var photos = data.photos || [];

    if (!photos.length) {
        return;
    }

    var cleanups = [];

    function listen(target, type, handler, options) {
        target.addEventListener(type, handler, options);
        cleanups.push(function () {
            target.removeEventListener(type, handler, options);
        });
    }

    window.__ilgTeardown = function () {
        cleanups.forEach(function (off) {
            off();
        });
        cleanups = [];
        window.__ilgTeardown = null;
    };

    var canvas = document.getElementById('ilgCanvas');
    var image = document.getElementById('ilgImage');
    var loading = document.getElementById('ilgLoading');
    var titleNode = document.getElementById('ilgPhotoTitle');
    var metaNode = document.getElementById('ilgPhotoMeta');
    var articleNode = document.getElementById('ilgArticle');
    var articleTitle = document.getElementById('ilgArticleTitle');
    var downloadNode = document.getElementById('ilgDownload');
    var thumbsNode = document.getElementById('ilgThumbs');
    var prevButton = root.querySelector('[data-ilg="prev"]');
    var nextButton = root.querySelector('[data-ilg="next"]');
    var stripPrev = root.querySelector('[data-ilg="strip-prev"]');
    var stripNext = root.querySelector('[data-ilg="strip-next"]');
    var grid = document.getElementById('ilgGrid');

    if (!canvas || !image) {
        return;
    }

    var albumName = data.album || '';
    var current = -1;
    var opened = false;
    var zoom = 1;
    var panX = 0;
    var panY = 0;
    var baseWidth = 0;
    var baseHeight = 0;
    var dragging = false;
    var dragStartX = 0;
    var dragStartY = 0;
    var dragOriginX = 0;
    var dragOriginY = 0;

    /**
     * 计算图片在舞台内的适配尺寸（铺满可用空间，等比不裁切）
     */
    function fit() {
        if (!image.naturalWidth || !image.naturalHeight) {
            return;
        }

        var canvasWidth = Math.max(0, canvas.clientWidth);
        var canvasHeight = Math.max(0, canvas.clientHeight);

        if (canvasWidth <= 0 || canvasHeight <= 0) {
            return;
        }

        var scale = Math.min(1, canvasWidth / image.naturalWidth, canvasHeight / image.naturalHeight);

        baseWidth = Math.round(image.naturalWidth * scale);
        baseHeight = Math.round(image.naturalHeight * scale);

        image.style.width = baseWidth + 'px';
        image.style.height = baseHeight + 'px';

        clampPan();
        applyTransform();
    }

    /**
     * 应用缩放 / 位移
     */
    function applyTransform() {
        image.style.transform = 'translate3d(' + panX + 'px,' + panY + 'px,0) scale(' + zoom + ')';
        image.classList.toggle('is-zoomed', zoom > 1);
    }

    /**
     * 限制拖拽范围，避免图片被拖出舞台
     */
    function clampPan() {
        var maxX = Math.max(0, (baseWidth * zoom - canvas.clientWidth) / 2);
        var maxY = Math.max(0, (baseHeight * zoom - canvas.clientHeight) / 2);

        panX = Math.min(maxX, Math.max(-maxX, panX));
        panY = Math.min(maxY, Math.max(-maxY, panY));
    }

    /**
     * 还原视图
     */
    function resetView() {
        zoom = 1;
        panX = 0;
        panY = 0;
        fit();
    }

    /**
     * 更新地址栏（不刷新页面）
     */
    function pushUrl(photo, replace) {
        if (!window.history || !window.history.pushState) {
            return;
        }

        var url = new URL(window.location.href);
        var state = {ilg: photo ? 1 : 0};

        if (photo) {
            url.searchParams.set('photo', photo.id);
        } else {
            url.searchParams.delete('photo');
        }

        var next = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash;

        if (replace) {
            window.history.replaceState(state, '', next);
        } else {
            window.history.pushState(state, '', next);
        }
    }

    /**
     * 高亮当前缩略图并滚动到可视区域
     */
    function syncThumbs() {
        var thumbs = thumbsNode ? thumbsNode.querySelectorAll('.photo-preview-item') : [];

        for (var i = 0; i < thumbs.length; i++) {
            thumbs[i].classList.toggle('is-active', i === current);
        }

        var active = thumbs[current];

        if (active && thumbsNode) {
            var nodeRect = active.getBoundingClientRect();
            var wrapRect = thumbsNode.getBoundingClientRect();

            if (nodeRect.left < wrapRect.left || nodeRect.right > wrapRect.right) {
                thumbsNode.scrollLeft += nodeRect.left - wrapRect.left - (wrapRect.width - nodeRect.width) / 2;
            }
        }
    }

    /**
     * 缩略图条滚动箭头的显隐
     */
    function syncStripArrows() {
        if (!thumbsNode) {
            return;
        }

        var scrollable = thumbsNode.scrollWidth > thumbsNode.clientWidth + 4;

        if (stripPrev) {
            stripPrev.classList.toggle('is-visible', scrollable && thumbsNode.scrollLeft > 4);
        }

        if (stripNext) {
            stripNext.classList.toggle('is-visible', scrollable && thumbsNode.scrollLeft < thumbsNode.scrollWidth - thumbsNode.clientWidth - 4);
        }
    }

    /**
     * 上一张 / 下一张按钮状态
     */
    function syncNav() {
        if (prevButton) {
            prevButton.disabled = current <= 0;
        }

        if (nextButton) {
            nextButton.disabled = current >= photos.length - 1;
        }
    }

    /**
     * 显示指定照片
     */
    function show(index) {
        if (index < 0 || index >= photos.length) {
            return;
        }

        current = index;
        var photo = photos[index];

        if (titleNode) {
            titleNode.textContent = photo.title || '照片详情';
            titleNode.title = photo.title || '';
        }

        if (metaNode) {
            metaNode.textContent = '';
            var small = document.createElement('small');
            small.textContent = [albumName, photo.date, photo.size].filter(Boolean).join(' · ');
            metaNode.appendChild(small);
        }

        if (articleNode && articleTitle) {
            if (photo.title && photo.title !== photo.fileName) {
                articleTitle.textContent = photo.title;
                articleNode.hidden = false;
            } else if (photo.title) {
                articleTitle.textContent = photo.title;
                articleNode.hidden = false;
            } else {
                articleNode.hidden = true;
            }
        }

        if (downloadNode) {
            downloadNode.href = photo.url;
            downloadNode.setAttribute('download', photo.fileName || '');
        }

        zoom = 1;
        panX = 0;
        panY = 0;

        if (loading) {
            loading.hidden = false;
        }

        image.src = photo.url;
        image.alt = photo.title || '';

        // 查看器刚从 display:none 切换为可见时，浏览器可能还没完成布局，
        // 这里在下一帧再量一次，避免图片尺寸算成 0。
        fit();

        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(function () {
                if (opened) {
                    fit();
                }
            });
        }

        syncThumbs();
        syncNav();
        syncStripArrows();
        preload(index);
    }

    /**
     * 预加载相邻照片
     */
    function preload(index) {
        [index - 1, index + 1].forEach(function (offset) {
            var photo = photos[offset];

            if (photo) {
                var loader = new Image();
                loader.src = photo.url;
            }
        });
    }

    /**
     * 打开查看器
     */
    function open(index, push) {
        var wasOpened = opened;

        if (!opened) {
            root.classList.add('is-viewing');
            opened = true;

            var viewer = document.getElementById('ilgViewer');

            if (viewer && viewer.getBoundingClientRect().top > 80) {
                viewer.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        }

        show(index);

        if (push) {
            // 首次打开写入一条历史记录；之后切换图片只替换当前记录
            pushUrl(photos[index], wasOpened);
        }
    }

    /**
     * 关闭查看器，回到照片墙
     */
    function close(updateUrl) {
        if (!opened) {
            return;
        }

        root.classList.remove('is-viewing');
        opened = false;
        image.removeAttribute('src');

        if (loading) {
            loading.hidden = true;
        }

        if (updateUrl !== false) {
            // 关闭时替换当前记录，避免历史里留下 ?photo= 的空状态
            pushUrl(null, true);
        }

        if (grid) {
            var active = grid.querySelector('.album-photo-item[data-index="' + current + '"]');

            if (active) {
                active.scrollIntoView({behavior: 'smooth', block: 'center'});
            }
        }
    }

    /* ---------- 事件绑定 ---------- */

    if (grid) {
        grid.addEventListener('click', function (event) {
            var card = event.target.closest('.album-photo-item');

            if (!card) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            open(parseInt(card.getAttribute('data-index'), 10) || 0, true);
        });
    }

    if (thumbsNode) {
        thumbsNode.addEventListener('click', function (event) {
            var thumb = event.target.closest('.photo-preview-item');

            if (!thumb) {
                return;
            }

            open(parseInt(thumb.getAttribute('data-index'), 10) || 0, true);
        });

        listen(thumbsNode, 'scroll', syncStripArrows);
    }

    root.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-ilg]');

        if (!trigger) {
            return;
        }

        var action = trigger.getAttribute('data-ilg');

        if (action === 'prev') {
            open(Math.max(0, current - 1), true);
        } else if (action === 'next') {
            open(Math.min(photos.length - 1, current + 1), true);
        } else if (action === 'zoom-in') {
            zoom = Math.min(4, zoom + 0.3);
            clampPan();
            applyTransform();
        } else if (action === 'zoom-out') {
            zoom = Math.max(1, zoom - 0.3);

            if (zoom === 1) {
                panX = 0;
                panY = 0;
            }

            clampPan();
            applyTransform();
        } else if (action === 'reset') {
            resetView();
        } else if (action === 'strip-prev') {
            if (thumbsNode) {
                thumbsNode.scrollBy({left: -thumbsNode.clientWidth * 0.8, behavior: 'smooth'});
            }
        } else if (action === 'strip-next') {
            if (thumbsNode) {
                thumbsNode.scrollBy({left: thumbsNode.clientWidth * 0.8, behavior: 'smooth'});
            }
        }
    });

    image.addEventListener('load', function () {
        if (loading) {
            loading.hidden = true;
        }

        fit();
    });

    image.addEventListener('error', function () {
        if (loading) {
            loading.hidden = true;
        }
    });

    image.addEventListener('dblclick', function () {
        zoom = zoom > 1 ? 1 : 2;
        panX = 0;
        panY = 0;
        clampPan();
        applyTransform();
    });

    canvas.addEventListener('pointerdown', function (event) {
        if (zoom <= 1) {
            return;
        }

        dragging = true;
        dragStartX = event.clientX;
        dragStartY = event.clientY;
        dragOriginX = panX;
        dragOriginY = panY;
        canvas.classList.add('is-dragging');
        canvas.setPointerCapture(event.pointerId);
    });

    canvas.addEventListener('pointermove', function (event) {
        if (!dragging) {
            return;
        }

        panX = dragOriginX + (event.clientX - dragStartX);
        panY = dragOriginY + (event.clientY - dragStartY);
        clampPan();
        applyTransform();
    });

    function endDrag(event) {
        if (!dragging) {
            return;
        }

        dragging = false;
        canvas.classList.remove('is-dragging');

        try {
            canvas.releasePointerCapture(event.pointerId);
        } catch (error) {
            // 忽略
        }
    }

    canvas.addEventListener('pointerup', endDrag);
    canvas.addEventListener('pointercancel', endDrag);

    /* ---------- 触摸滑动切图 ----------
       未放大时单指左右滑动切换上一张 / 下一张；
       放大后单指滑动仍然是平移图片（由上面的拖拽逻辑处理）。 */
    var swipeStartX = 0;
    var swipeStartY = 0;
    var swipeStartAt = 0;
    var swipeTracking = false;

    canvas.addEventListener('pointerdown', function (event) {
        if (zoom > 1 || event.pointerType === 'mouse') {
            swipeTracking = false;
            return;
        }

        swipeTracking = true;
        swipeStartX = event.clientX;
        swipeStartY = event.clientY;
        swipeStartAt = Date.now();
    });

    canvas.addEventListener('pointerup', function (event) {
        if (!swipeTracking) {
            return;
        }

        swipeTracking = false;

        if (zoom > 1) {
            return;
        }

        var dx = event.clientX - swipeStartX;
        var dy = event.clientY - swipeStartY;
        var elapsed = Date.now() - swipeStartAt;

        // 滑动要够快、够远，且以横向为主，才判定为切图
        if (elapsed > 800 || Math.abs(dx) < 45 || Math.abs(dx) < Math.abs(dy) * 1.4) {
            return;
        }

        if (dx > 0) {
            open(Math.max(0, current - 1), true);
        } else {
            open(Math.min(photos.length - 1, current + 1), true);
        }
    });

    canvas.addEventListener('pointercancel', function () {
        swipeTracking = false;
    });

    listen(document, 'keydown', function (event) {
        if (!opened) {
            return;
        }

        if (event.key === 'Escape') {
            close();
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            open(Math.max(0, current - 1), true);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            open(Math.min(photos.length - 1, current + 1), true);
        } else if (event.key === '+' || event.key === '=') {
            zoom = Math.min(4, zoom + 0.3);
            clampPan();
            applyTransform();
        } else if (event.key === '-') {
            zoom = Math.max(1, zoom - 0.3);
            clampPan();
            applyTransform();
        } else if (event.key === '0') {
            resetView();
        }
    });

    listen(window, 'resize', function () {
        if (opened) {
            fit();
        }

        syncStripArrows();
    });

    listen(window, 'popstate', function () {
        var params = new URLSearchParams(window.location.search);
        var photoId = parseInt(params.get('photo'), 10);

        if (photoId) {
            var index = photos.findIndex(function (photo) {
                return photo.id === photoId;
            });

            if (index >= 0) {
                open(index, false);
                return;
            }
        }

        close(false);
    });

    /* ---------- 初始化 ---------- */

    var startIndex = parseInt(data.index, 10);

    if (startIndex >= 0 && startIndex < photos.length) {
        opened = true;
        show(startIndex);
    } else {
        // 预先加载第一张，点开时更快
        var loader = new Image();
        loader.src = photos[0].url;
        syncStripArrows();
    }
})();
