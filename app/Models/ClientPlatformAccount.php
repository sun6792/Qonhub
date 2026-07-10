<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $platform_key
 * @property string $status
 */
class ClientPlatformAccount extends Model
{
    protected $fillable = [
        'workspace_id', 'platform_key', 'platform_account_name',
        'credential_ciphertext', 'credential_meta',
        'status', 'last_verified_at', 'expires_at', 'last_error_message',
    ];

    protected $casts = [
        'credential_meta' => 'array',
        'last_verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * 内置支持的所有平台。
     *
     * @return array<string, array{name:string, icon:string, color:string, login_url:string}>
     */
    /**
     * 客户需自行注册的平台（需身份证/营业执照认证）。
     * 这些平台需要客户亲自操作，运营人员无法代劳。
     */
    public static function supportedPlatforms(): array
    {
        return [
            'toutiao' => [
                'name' => '头条号', 'icon' => 'toutiao', 'color' => '#E13D3D',
                'login_url' => 'https://mp.toutiao.com/',
                'need_verify' => '需实名认证（身份证）',
            ],
            'baijiahao' => [
                'name' => '百家号', 'icon' => 'baijiahao', 'color' => '#DE493C',
                'login_url' => 'https://baijiahao.baidu.com/',
                'need_verify' => '需企业认证（营业执照）',
            ],
            'xiaohongshu' => [
                'name' => '小红书', 'icon' => 'xiaohongshu', 'color' => '#FF2442',
                'login_url' => 'https://creator.xiaohongshu.com/',
                'need_verify' => '需企业号认证（营业执照）',
            ],
            '1688' => [
                'name' => '阿里1688', 'icon' => 'b2b', 'color' => '#FF6A00',
                'login_url' => 'https://www.1688.com/',
                'need_verify' => '需店铺认证（营业执照）',
            ],
            'b2b_baidu' => [
                'name' => '百度爱采购', 'icon' => 'b2b', 'color' => '#2563EB',
                'login_url' => 'https://b2b.baidu.com/',
                'need_verify' => '需企业认证（营业执照）',
            ],
            'sohu' => [
                'name' => '搜狐号', 'icon' => 'sohu', 'color' => '#FFD100',
                'login_url' => 'https://mp.sohu.com/',
                'need_verify' => '需实名认证（身份证+手机号）',
            ],
        ];
    }
}
