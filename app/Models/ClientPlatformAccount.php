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
            'toutiao'    => ['name'=>'今日头条', 'icon'=>'newspaper', 'color'=>'#E5332C', 'login_url'=>'https://mp.toutiao.com/'],
            'baijiahao'  => ['name'=>'百家号',   'icon'=>'file-text', 'color'=>'#3B5998', 'login_url'=>'https://baijiahao.baidu.com/'],
            'wechat_mp'  => ['name'=>'微信公众号', 'icon'=>'message-circle', 'color'=>'#07C160', 'login_url'=>'https://mp.weixin.qq.com/'],
            'sohu'       => ['name'=>'搜狐号',   'icon'=>'rss', 'color'=>'#FDD000', 'login_url'=>'https://mp.sohu.com/'],
            'xiaohongshu'=> ['name'=>'小红书',   'icon'=>'heart', 'color'=>'#FE2C55', 'login_url'=>'https://creator.xiaohongshu.com/'],
            'wangyihao'  => ['name'=>'网易号',   'icon'=>'globe', 'color'=>'#D32F2F', 'login_url'=>'https://mp.163.com/'],
            'bilibili'   => ['name'=>'哔哩哔哩', 'icon'=>'video', 'color'=>'#FB7299', 'login_url'=>'https://member.bilibili.com/'],
            'qiehao'     => ['name'=>'企鹅号',   'icon'=>'send', 'color'=>'#12B7F5', 'login_url'=>'https://om.qq.com/'],
            'smzdm'      => ['name'=>'值得买',   'icon'=>'shopping-cart', 'color'=>'#E12525', 'login_url'=>'https://www.smzdm.com/'],
            'douyin'     => ['name'=>'抖音',     'icon'=>'music', 'color'=>'#000000', 'login_url'=>'https://creator.douyin.com/'],
            'kuaishou'   => ['name'=>'快手',     'icon'=>'play', 'color'=>'#FF4906', 'login_url'=>'https://cp.kuaishou.com/'],
        ];
    }
}
