<?php

namespace App\Services\GeoFlow\Publishing\Adapters;

/**
 * 搜狐号 Cookie 适配器（占位实现，继承通用 RPA 适配器）。
 *
 * 搜狐号无开放 API，通过 Cookie + RPA 浏览器自动化发布。
 */
class SohuCookieAdapter extends GenericRpaAdapter
{
    public function platformKey(): string
    {
        return 'sohu';
    }
}
