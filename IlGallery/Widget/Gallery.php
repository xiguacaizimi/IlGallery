<?php

namespace TypechoPlugin\IlGallery\Widget;

use Typecho\Config;
use TypechoPlugin\IlGallery\Plugin;
use TypechoPlugin\IlGallery\Renderer;
use TypechoPlugin\IlGallery\Store;
use Widget\Archive;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 前台图库路由
 *
 * 借用 Typecho 的独立页面渲染流程：先把承载图库的页面载入为当前归档，
 * 再接管 render()，输出主题的 header/left/nav/footer 与插件自己的图库模板。
 * 这样能完整继承 ilogs 的两栏/三栏布局、深浅色与左侧导航高亮。
 */
class Gallery extends Archive
{
    /**
     * 强制以独立页面身份初始化，避免路由名影响 Archive 的分支判断
     */
    protected function initParameter(Config $parameter)
    {
        parent::initParameter($parameter);
        $parameter->type = 'page';
    }

    /**
     * 载入承载图库的页面
     */
    public function execute()
    {
        if ($this->have()) {
            return;
        }

        $page = Store::hostPage();

        // 承载页面被删除（或从未创建）时自动重建，避免前台直接 404
        if (empty($page)) {
            Plugin::ensurePage();
            Store::flushHostPage();
            $page = Store::hostPage();
        }

        if (empty($page)) {
            // 兜底：拿不到页面上下文也要把图库渲染出来，
            // 模板只用到 $this->need() 与 $this->options，不强依赖页面数据
            return;
        }

        $this->request->setParam('cid', (int)$page['cid']);
        parent::execute();
    }

    /**
     * 输出插件模板（主题外壳在模板内通过 need() 引入）
     */
    public function render()
    {
        Renderer::render($this);
    }
}
