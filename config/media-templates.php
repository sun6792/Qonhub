<?php

/**
 * 内容模板配置：一个模板覆盖一组平台。
 *
 * 添加新平台：找到对应组，在 platforms 里加名字即可。
 * 添加新模板组：复制一个组，改 key/name/prompt。
 *
 * 每个模板组：
 *   key       — 唯一标识
 *   name      — 显示名称
 *   prompt    — AI 改写指令（发给 DeepSeek）
 *   style     — 改写风格说明（给 AI 的 system prompt）
 *   platforms — 适用平台列表 ['name' => '平台名', 'login_url' => '登录/发布地址']
 */

return [
    'templates' => [
        [
            'key' => 'zhihu',
            'name' => '知乎深度版',
            'style' => '知乎专栏风格：第一人称经验分享，逻辑严密，分段清晰，有数据支撑，拒绝情绪化标题党。',
            'prompt' => <<<'PROMPT'
请将以下文章改写成知乎专栏风格。

要求：
1. 标题：克制专业，≤30字，不用感叹号和震惊体
2. 正文：第一人称经验分享，分4-6个小标题，每个小标题下2-3段
3. 字数：1200-1500字
4. 格式：Markdown，关键句加粗
5. 结尾加 1-2 个引导讨论的问题
6. 不要出现"小编""我们"——用"我"
PROMPT,
            'platforms' => [
                ['name' => '知乎', 'login_url' => 'https://www.zhihu.com/signin'],
            ],
        ],
        [
            'key' => 'toutiao',
            'name' => '头条快消版',
            'style' => '今日头条风格：短平快、口语化、数字化标题、碎片阅读场景适配。',
            'prompt' => <<<'PROMPT'
请将以下文章改写成今日头条号风格。

要求：
1. 标题：数字+悬念，25-30字，让人想点进去
2. 正文：口语化短句，每段不超过3行
3. 字数：500-700字
4. 开头第一句要抓人（抛痛点或甩结论）
5. 不加小标题，用自然过渡
6. 拒绝学术术语、英文缩写
PROMPT,
            'platforms' => [
                ['name' => '头条号', 'login_url' => 'https://mp.toutiao.com/'],
            ],
        ],
        [
            'key' => 'baijiahao',
            'name' => '百家号SEO版',
            'style' => '百家号风格：SEO关键词密集、结构规整、适合百度收录的长文。',
            'prompt' => <<<'PROMPT'
请将以下文章改写成百度百家号风格。

要求：
1. 标题：含核心关键词，≤32字，搜索友好
2. 正文：开头概述背景（200字），然后分点展开（4-6个要点）
3. 字数：900-1200字
4. 段落分明，多用"首先/其次/最后/总结"等过渡词
5. 严禁使用"最好""第一""国家级"等绝对化用语
6. 不要加外链
PROMPT,
            'platforms' => [
                ['name' => '百家号', 'login_url' => 'https://baijiahao.baidu.com/'],
            ],
        ],
        [
            'key' => 'business_general',
            'name' => '通用商务版',
            'style' => 'B2B/新闻源/行业站通用：资讯风、含关键词、800字左右、结构清晰。',
            'prompt' => <<<'PROMPT'
请将以下文章改写成行业资讯/B2B商务风格。

要求：
1. 标题：含行业关键词，25-35字，资讯风
2. 正文：三段式结构——行业背景（150字）→ 核心内容（400字）→ 总结展望（150字）
3. 字数：700-900字
4. 语言正式但不僵硬，适合企业/行业网站
5. 可在正文自然嵌入 2-3 个行业关键词
6. 不加外链，不加emoji
PROMPT,
            'platforms' => [
                // B2B 行业站
                ['name' => '天助网', 'login_url' => 'https://www.tianzhu.com/'],
                ['name' => '八方资源网', 'login_url' => 'https://www.bafangzyw.com/'],
                ['name' => '无忧商务网', 'login_url' => 'https://www.wuyousw.com/'],
                ['name' => 'K2商务网', 'login_url' => 'https://www.k2sw.com/'],
                ['name' => '领商网', 'login_url' => 'https://www.lingshang.com/'],
                ['name' => '万家商务网', 'login_url' => 'https://www.wjsww.com/'],
                ['name' => '九州资源网', 'login_url' => 'https://www.jiuzhouzyw.com/'],
                // 新闻媒体
                ['name' => '山西科技报', 'login_url' => 'https://www.sxkjb.com/'],
                ['name' => '河青新闻网', 'login_url' => 'https://www.heqingnews.com/'],
                ['name' => '科技新闻网', 'login_url' => 'https://www.kjxww.com/'],
                ['name' => '咸宁新闻网', 'login_url' => 'https://www.xnnews.com/'],
                ['name' => '淄博新闻网', 'login_url' => 'https://www.zibonews.com/'],
                ['name' => '盐城网', 'login_url' => 'https://www.yancheng.com/'],
                ['name' => '亮点黔西南', 'login_url' => 'https://www.ldqxn.com/'],
                ['name' => '四平新闻网', 'login_url' => 'https://www.spnews.com/'],
                ['name' => '红安网', 'login_url' => 'https://www.hongan.com/'],
                ['name' => '景德镇新闻网', 'login_url' => 'https://www.jdznews.com/'],
                ['name' => '云上团风', 'login_url' => 'https://www.tuanfeng.com/'],
                ['name' => '耒阳新闻网', 'login_url' => 'https://www.leiyangnews.com/'],
                // 自媒体/行业站
                ['name' => '网易号', 'login_url' => 'https://mp.163.com/'],
                ['name' => '企鹅号', 'login_url' => 'https://om.qq.com/'],
                ['name' => '搜狐号', 'login_url' => 'https://mp.sohu.com/'],
                ['name' => '小红书', 'login_url' => 'https://creator.xiaohongshu.com/'],
                ['name' => '博客园', 'login_url' => 'https://www.cnblogs.com/'],
                ['name' => 'B站专栏', 'login_url' => 'https://member.bilibili.com/'],
                ['name' => '值得买', 'login_url' => 'https://www.smzdm.com/'],
                ['name' => '商业新知', 'login_url' => 'https://www.shangyexinzhi.com/'],
                // 行业垂直站
                ['name' => '中国化工网', 'login_url' => 'https://www.chemnet.com/'],
                ['name' => '涂料在线', 'login_url' => 'https://www.tuliao.com/'],
                ['name' => '沥青在线', 'login_url' => 'https://www.liqing.com/'],
                ['name' => '中机在线', 'login_url' => 'https://www.zjzx.com/'],
                ['name' => '华网', 'login_url' => 'https://www.huawang.com/'],
                ['name' => '中国牛涂网', 'login_url' => 'https://www.niutu.com/'],
                ['name' => 'W10系统网', 'login_url' => 'https://www.w10xt.com/'],
                ['name' => 'OK资讯网', 'login_url' => 'https://www.okzixun.com/'],
            ],
        ],
        [
            'key' => 'short_video',
            'name' => '短视频图文版',
            'style' => '抖音/快手图文模式：爆款文案+标签、200字以内、适合图文发布。',
            'prompt' => <<<'PROMPT'
请将以下文章改写成抖音/快手图文发布的文案。

要求：
1. 标题文案：1-2句话，抓眼球，≤50字
2. 正文：3段短文案，每段1-2句，口语化
3. 总字数：150-250字
4. 自动生成 5-8 个热门标签，用 # 开头
5. 结尾加引导互动（"你觉得呢？""收藏备用"之类）
PROMPT,
            'platforms' => [
                ['name' => '抖音图文', 'login_url' => 'https://creator.douyin.com/'],
                ['name' => '快手图文', 'login_url' => 'https://cp.kuaishou.com/'],
            ],
        ],
    ],
];
