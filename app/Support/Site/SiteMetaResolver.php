<?php

namespace App\Support\Site;

use Illuminate\Support\Facades\Cache;

/**
 * 站点元数据统一解析器。
 *
 * 消除 7 个文件中对 site_name / site_description / site_keywords
 * 的三行重复提取逻辑（含双层 fallback：SiteSetting map → config.geoflow → config.app）。
 *
 * 使用方式：
 *   $meta = app(SiteMetaResolver::class)->resolve();
 *   view()->share('siteMeta', $meta);  // 或在 View Composer 中注入
 */
class SiteMetaResolver
{
    /**
     * @param  array<string, string|null>|null  $map  SiteSetting::allAsMap() 返回的设置映射
     * @return array{name: string, description: string, keywords: string}
     */
    public function resolve(?array $map = null): array
    {
        $map ??= $this->loadDefaultMap();

        $name = $this->extract($map, 'site_name', 'geoflow.site_name', 'app.name');
        $description = $this->extract($map, 'site_description', 'geoflow.site_description', 'app.name');
        $keywords = $this->extract($map, 'site_keywords', 'geoflow.site_keywords', 'app.name');

        return [
            'name' => $name,
            'description' => $description,
            'keywords' => $keywords,
        ];
    }

    /**
     * 按优先级获取元数据：$map[key] → $configKey → $fallbackConfigKey
     */
    private function extract(array $map, string $key, string $configKey, string $fallbackConfigKey): string
    {
        if (! empty($map[$key])) {
            return (string) $map[$key];
        }

        $configured = config($configKey);
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) config($fallbackConfigKey, '');
    }

    /**
     * 加载默认的 SiteSetting 映射（缓存 5 分钟）。
     *
     * @return array<string, string|null>
     */
    private function loadDefaultMap(): array
    {
        return Cache::remember('site_meta_resolver_map', 300, function (): array {
            return \App\Models\SiteSetting::allAsMap();
        });
    }
}
