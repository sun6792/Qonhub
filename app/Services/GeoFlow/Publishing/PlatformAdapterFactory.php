<?php

namespace App\Services\GeoFlow\Publishing;

use App\Models\ContentPublisherAccount;
use RuntimeException;

/**
 * 平台适配器工厂。
 *
 * 根据账号的平台类型和凭证类型，创建对应的适配器实例。
 * 新增平台只需在这里注册映射关系。
 */
class PlatformAdapterFactory
{
    /**
     * 适配器注册表：platform_key => adapter_class
     */
    protected static array $registry = [
        // 自媒体 — OAuth
        'toutiao' => \App\Services\GeoFlow\Publishing\Adapters\ToutiaoOAuthAdapter::class,
        // 自媒体 — Cookie (复用现有 ClientPlatformAccount 数据)
        'baijiahao' => \App\Services\GeoFlow\Publishing\Adapters\BaijiahaoCookieAdapter::class,
        'sohu' => \App\Services\GeoFlow\Publishing\Adapters\SohuCookieAdapter::class,
        // 媒体发稿
        'media_box_api' => \App\Services\GeoFlow\Publishing\Adapters\MediaBoxApiAdapter::class,
        // B2B — RPA
        'b2b168' => \App\Services\GeoFlow\Publishing\Adapters\B2b168RpaAdapter::class,
        'shunqi' => \App\Services\GeoFlow\Publishing\Adapters\GenericRpaAdapter::class,
        'huangye88' => \App\Services\GeoFlow\Publishing\Adapters\GenericRpaAdapter::class,
    ];

    /**
     * @throws RuntimeException 如果平台未注册适配器
     */
    public static function create(ContentPublisherAccount $account): BasePlatformAdapter
    {
        $platformKey = $account->platform_key;

        // 1. 精确匹配
        if (isset(static::$registry[$platformKey])) {
            $class = static::$registry[$platformKey];

            return new $class($account);
        }

        // 2. 按平台大类 + 凭证类型兜底
        if ($account->requires_rpa) {
            return new Adapters\GenericRpaAdapter($account);
        }

        if ($account->credential_type === 'oauth_token') {
            return new Adapters\GenericOAuthAdapter($account);
        }

        throw new RuntimeException("未找到平台 {$platformKey} 的适配器，请先注册");
    }

    /**
     * 注册新的适配器。
     */
    public static function register(string $platformKey, string $adapterClass): void
    {
        static::$registry[$platformKey] = $adapterClass;
    }
}
