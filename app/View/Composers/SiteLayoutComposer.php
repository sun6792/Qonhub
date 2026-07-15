<?php

namespace App\View\Composers;

use App\Models\Category;
use App\Support\Site\SiteMetaResolver;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * 为前台 Blade 布局注入站点名称、SEO 元数据、分类导航等公共变量。
 *
 * 利用 SiteMetaResolver 统一消除 7 个 Site 控制器中的重复提取逻辑。
 */
final class SiteLayoutComposer
{
    public function compose(View $view): void
    {
        $map = SiteSettingsBag::all();
        $meta = app(SiteMetaResolver::class)->resolve($map);

        $siteLogo = (string) ($map['site_logo'] ?? '');
        $siteFavicon = (string) ($map['site_favicon'] ?? '');
        $copyright = (string) ($map['copyright_info'] ?? '');
        $analyticsCode = (string) ($map['analytics_code'] ?? '');

        $categories = collect();
        if (Schema::hasTable('categories')) {
            $categories = Category::query()
                ->whereHas('articles', function ($q): void {
                    $q->published();
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->withCount([
                    'articles as published_count' => function ($q): void {
                        $q->published();
                    },
                ])
                ->get();
        }

        $view->with([
            'siteName' => $meta['name'],
            'siteDescription' => $meta['description'],
            'siteKeywords' => $meta['keywords'],
            'siteLogo' => $siteLogo,
            'siteFavicon' => $siteFavicon,
            'footerCopyright' => $copyright,
            'headAnalyticsCode' => $analyticsCode,
            'navCategories' => $categories,
        ]);
    }
}
