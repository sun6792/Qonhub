<?php

/**
 * 自媒体分发平台配置。
 *
 * 添加新平台：复制一个数组元素，填好 key/name/login_url 即可。
 * 删除平台：删除对应数组元素或注释掉。
 *
 * 每个平台的字段说明：
 *   key         — 唯一标识（英文）
 *   name        — 平台中文名
 *   icon        — SVG path（24x24 图标）
 *   login_url   — 平台创作者中心/登录页地址
 *   color       — 品牌色（用于卡片图标背景）
 *   description — 一句话说明平台特点
 */

return [
    'platforms' => [
        [
            'key' => 'zhihu',
            'name' => '知乎',
            'icon' => 'M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm-.5 5.5v3.5h-3.5v-3.5h3.5zM8.5 14.5v-3.5h3.5v3.5H8.5zm7 0h-3.5v-3.5h3.5v3.5z',
            'login_url' => 'https://www.zhihu.com/signin',
            'color' => '#0066FF',
            'description' => '深度内容社区，偏好专业分析、经验分享',
        ],
        [
            'key' => 'toutiao',
            'name' => '头条号',
            'icon' => 'M4 4h16v16H4V4zm2 2v12h12V6H6zm2 2h8v2H8V8zm0 4h8v2H8v-2zm0 4h5v2H8v-2z',
            'login_url' => 'https://mp.toutiao.com/',
            'color' => '#E13D3D',
            'description' => '算法推荐驱动，短平快内容，流量大',
        ],
        [
            'key' => 'baijiahao',
            'name' => '百家号',
            'icon' => 'M3 3h18v18H3V3zm2 2v14h14V5H5zm2 2h10v2H7V7zm0 4h10v2H7v-2zm0 4h7v2H7v-2z',
            'login_url' => 'https://baijiahao.baidu.com/',
            'color' => '#DE493C',
            'description' => '百度搜索流量，适合SEO长文，审核严格',
        ],
        [
            'key' => 'xiaohongshu',
            'name' => '小红书',
            'icon' => 'M5 5h14v14H5V5zm2 2v10h10V7H7zm2 2h6v2H9V9zm0 4h6v2H9v-2z',
            'login_url' => 'https://creator.xiaohongshu.com/',
            'color' => '#FF2442',
            'description' => '生活方式社区，图文种草内容为主',
        ],
        [
            'key' => 'sohu',
            'name' => '搜狐号',
            'icon' => 'M4 4h16v16H4V4zm2 2v12h12V6H6zm2 2h8v2H8V8zm0 4h8v2H8v-2z',
            'login_url' => 'https://mp.sohu.com/',
            'color' => '#FFD100',
            'description' => '门户自媒体，适合资讯类内容分发',
        ],
        [
            'key' => 'bilibili',
            'name' => 'B站专栏',
            'icon' => 'M6 4h12v16H6V4zm2 2v12h8V6H8zm1 2h6v3H9V8zm0 5h6v3H9v-3z',
            'login_url' => 'https://member.bilibili.com/',
            'color' => '#FB7299',
            'description' => '年轻用户为主，适合教程/评测/科普',
        ],

        // ===== 按需添加更多平台（复制上面的格式） =====
        // [
        //     'key' => 'wangyihao',
        //     'name' => '网易号',
        //     'icon' => '...',
        //     'login_url' => 'https://mp.163.com/',
        //     'color' => '#E60012',
        //     'description' => '网易门户自媒体平台',
        // ],
        // [
        //     'key' => 'jian_shu',
        //     'name' => '简书',
        //     'icon' => '...',
        //     'login_url' => 'https://www.jianshu.com/sign_in',
        //     'color' => '#EA6F5A',
        //     'description' => '文艺创作社区',
        // ],
    ],
];
