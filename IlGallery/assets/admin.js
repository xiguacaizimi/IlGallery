/**
 * 图库相册 · 后台交互
 *
 * 负责：拖拽/点击上传、标题即时保存、封面设置、删除、拖动排序。
 */
(function () {
    'use strict';

    var config = window.ilgAdmin;
    var admin = document.getElementById('ilgPhotoAdmin');

    if (!config || !admin) {
        return;
    }

    var drop = document.getElementById('ilgDrop');
    var fileInput = document.getElementById('ilgFile');
    var progress = document.getElementById('ilgProgress');
    var empty = document.getElementById('ilgPhotoEmpty');
    var countNode = document.getElementById('ilgPhotoCount');
    var draggingCard = null;

    /**
     * 右下角提示
     */
    function toast(message, isError) {
        var node = document.createElement('div');
        node.className = 'ilg-toast' + (isError ? ' is-error' : '');
        node.textContent = message;
        document.body.appendChild(node);

        setTimeout(function () {
            node.classList.add('is-show');
        }, 10);

        setTimeout(function () {
            node.classList.remove('is-show');
            setTimeout(function () {
                node.parentNode && node.parentNode.removeChild(node);
            }, 250);
        }, 2600);
    }

    /**
     * 统一 POST，返回 JSON
     */
    function post(action, data, isForm) {
        var url = config.endpoint + '&do=' + action;
        var options = {method: 'POST', credentials: 'same-origin'};
        var body;

        if (isForm) {
            body = data;
        } else {
            body = new FormData();

            Object.keys(data || {}).forEach(function (key) {
                body.append(key, data[key]);
            });

            body.append('_', config.token);
        }

        options.body = body;

        return fetch(url, options).then(function (response) {
            return response.json().catch(function () {
                return {ok: false, error: '服务器返回了异常内容'};
            });
        });
    }

    /**
     * 更新照片计数
     */
    function setCount(total) {
        if (countNode && typeof total === 'number') {
            countNode.textContent = total;
        }

        if (empty) {
            empty.hidden = admin.querySelectorAll('.ilg-card').length > 0;
        }
    }

    /**
     * 生成一张照片卡片
     */
    function buildCard(image) {
        var card = document.createElement('div');
        card.className = 'ilg-card';
        card.draggable = true;
        card.setAttribute('data-id', image.id);

        var media = document.createElement('div');
        media.className = 'ilg-card-media';

        var img = document.createElement('img');
        img.src = image.thumbUrl;
        img.alt = '';
        img.loading = 'lazy';
        media.appendChild(img);

        var badge = document.createElement('span');
        badge.className = 'ilg-card-badge';
        badge.textContent = '封面';
        media.appendChild(badge);

        var handle = document.createElement('span');
        handle.className = 'ilg-card-drag';
        handle.title = '拖动排序';
        handle.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5h.01"/><path d="M9 12h.01"/><path d="M9 19h.01"/><path d="M15 5h.01"/><path d="M15 12h.01"/><path d="M15 19h.01"/></svg>';
        media.appendChild(handle);

        var title = document.createElement('input');
        title.className = 'ilg-card-title';
        title.type = 'text';
        title.maxLength = 180;
        title.value = image.title || '';
        title.setAttribute('aria-label', '照片标题');

        var meta = document.createElement('div');
        meta.className = 'ilg-card-meta';

        var size = document.createElement('span');
        size.textContent = image.sizeText || '';
        var date = document.createElement('span');
        meta.appendChild(size);
        meta.appendChild(date);

        var actions = document.createElement('div');
        actions.className = 'ilg-card-actions';

        var cover = document.createElement('button');
        cover.type = 'button';
        cover.setAttribute('data-act', 'cover');
        cover.textContent = '设为封面';

        var remove = document.createElement('button');
        remove.type = 'button';
        remove.setAttribute('data-act', 'delete');
        remove.className = 'ilg-link-danger';
        remove.textContent = '删除';

        actions.appendChild(cover);
        actions.appendChild(remove);

        card.appendChild(media);
        card.appendChild(title);
        card.appendChild(meta);
        card.appendChild(actions);

        bindCard(card);

        return card;
    }

    /**
     * 绑定单张卡片事件
     */
    function bindCard(card) {
        var title = card.querySelector('.ilg-card-title');

        if (title) {
            title.addEventListener('change', function () {
                post('rename', {
                    image: card.getAttribute('data-id'),
                    title: title.value
                }).then(function (result) {
                    if (!result.ok) {
                        toast(result.error || '保存失败', true);
                    }
                });
            });
        }

        card.addEventListener('click', function (event) {
            var button = event.target.closest('[data-act]');

            if (!button) {
                return;
            }

            var action = button.getAttribute('data-act');
            var id = card.getAttribute('data-id');

            if (action === 'cover') {
                post('cover', {id: config.album, image: id}).then(function (result) {
                    if (!result.ok) {
                        toast(result.error || '设置失败', true);
                        return;
                    }

                    admin.querySelectorAll('.ilg-card-badge').forEach(function (badge) {
                        badge.classList.remove('is-on');
                    });

                    var badge = card.querySelector('.ilg-card-badge');

                    if (badge) {
                        badge.classList.add('is-on');
                    }

                    toast('已设为相册封面');
                });
            } else if (action === 'delete') {
                if (!window.confirm('确定删除这张照片吗？原图与缩略图都会被移除。')) {
                    return;
                }

                post('deleteImage', {image: id}).then(function (result) {
                    if (!result.ok) {
                        toast(result.error || '删除失败', true);
                        return;
                    }

                    card.parentNode && card.parentNode.removeChild(card);
                    setCount(result.total);
                    toast('照片已删除');
                });
            }
        });

        card.addEventListener('dragstart', function (event) {
            if (event.target.closest('.ilg-card-title')) {
                event.preventDefault();
                return;
            }

            draggingCard = card;
            card.classList.add('is-dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.getAttribute('data-id'));
        });

        card.addEventListener('dragend', function () {
            card.classList.remove('is-dragging');
            admin.querySelectorAll('.ilg-card.is-over').forEach(function (node) {
                node.classList.remove('is-over');
            });
            draggingCard = null;
        });

        card.addEventListener('dragover', function (event) {
            if (!draggingCard || draggingCard === card) {
                return;
            }

            event.preventDefault();

            var rect = card.getBoundingClientRect();
            var after = (event.clientX - rect.left) > rect.width / 2;

            card.classList.add('is-over');

            admin.querySelectorAll('.ilg-card.is-over').forEach(function (node) {
                if (node !== card) {
                    node.classList.remove('is-over');
                }
            });

            if (after) {
                card.parentNode.insertBefore(draggingCard, card.nextSibling);
            } else {
                card.parentNode.insertBefore(draggingCard, card);
            }
        });

        card.addEventListener('dragleave', function () {
            card.classList.remove('is-over');
        });

        card.addEventListener('drop', function (event) {
            event.preventDefault();
            card.classList.remove('is-over');
            saveOrder();
        });
    }

    /**
     * 保存排序
     */
    function saveOrder() {
        var ids = Array.prototype.map.call(admin.querySelectorAll('.ilg-card'), function (card) {
            return card.getAttribute('data-id');
        }).join(',');

        post('sortPhotos', {ids: ids}).then(function (result) {
            if (!result.ok) {
                toast(result.error || '排序保存失败', true);
            }
        });
    }

    /* ---------- 上传 ---------- */

    function upload(files) {
        if (!files || !files.length) {
            return;
        }

        var allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        var list = Array.prototype.filter.call(files, function (file) {
            if (allowed.indexOf(file.type) !== -1) {
                return true;
            }

            return /\.(jpe?g|png|gif|webp)$/i.test(file.name || '');
        });

        if (!list.length) {
            toast('请选择 JPG / PNG / GIF / WebP 图片', true);
            return;
        }

        drop.classList.add('is-busy');
        progress.textContent = '正在上传 0 / ' + list.length;

        var batches = [];
        var cursor = 0;

        while (cursor < list.length) {
            batches.push(list.slice(cursor, cursor + 6));
            cursor += 6;
        }

        var uploaded = 0;
        var errors = [];

        function next(index) {
            if (index >= batches.length) {
                drop.classList.remove('is-busy');
                progress.textContent = '';

                if (uploaded > 0) {
                    toast('已上传 ' + uploaded + ' 张照片');
                }

                if (errors.length) {
                    toast(errors[0], true);
                }

                return;
            }

            var form = new FormData();

            batches[index].forEach(function (file) {
                form.append('files[]', file);
            });

            form.append('_', config.token);

            var url = config.endpoint + '&do=upload&id=' + config.album;

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                body: form
            }).then(function (response) {
                return response.json().catch(function () {
                    return {ok: false, error: '服务器返回了异常内容'};
                });
            }).then(function (result) {
                if (!result.ok) {
                    errors.push(result.error || '上传失败');
                } else {
                    (result.images || []).forEach(function (image) {
                        admin.appendChild(buildCard(image));
                    });

                    uploaded += (result.images || []).length;
                    setCount(result.total);

                    if (result.errors && result.errors.length) {
                        errors = errors.concat(result.errors);
                    }
                }

                progress.textContent = '已上传 ' + uploaded + ' / ' + list.length;
                next(index + 1);
            }).catch(function () {
                errors.push('网络异常，请重试');
                next(index + 1);
            });
        }

        next(0);
    }

    if (drop) {
        drop.addEventListener('click', function () {
            fileInput && fileInput.click();
        });

        drop.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                fileInput && fileInput.click();
            }
        });

        ['dragenter', 'dragover'].forEach(function (name) {
            drop.addEventListener(name, function (event) {
                event.preventDefault();
                drop.classList.add('is-over');
            });
        });

        ['dragleave', 'drop'].forEach(function (name) {
            drop.addEventListener(name, function (event) {
                event.preventDefault();

                if (name === 'dragleave' && drop.contains(event.relatedTarget)) {
                    return;
                }

                drop.classList.remove('is-over');
            });
        });

        drop.addEventListener('drop', function (event) {
            upload(event.dataTransfer && event.dataTransfer.files);
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            upload(fileInput.files);
            fileInput.value = '';
        });
    }

    admin.addEventListener('dragover', function (event) {
        if (draggingCard) {
            event.preventDefault();
        }
    });

    admin.addEventListener('drop', function (event) {
        if (draggingCard) {
            event.preventDefault();
            saveOrder();
        }
    });

    /* 已有卡片初始化 */
    admin.querySelectorAll('.ilg-card').forEach(bindCard);
})();
