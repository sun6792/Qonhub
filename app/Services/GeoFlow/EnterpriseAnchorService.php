<?php

namespace App\Services\GeoFlow;

use App\Models\EnterpriseAnchorCertification;
use App\Models\EnterpriseProfile;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * 企业 B2B 信息锚点服务。
 *
 * 核心职责：
 * 1. 管理 B2B 锚点平台的定义和元数据
 * 2. 企业档案的创建、更新、NAP+W 一致性校验
 * 3. B2B 平台认证进度的追踪和统计
 *
 * 信息锚点 ≠ 内容分发：这些平台用来让企业信息被大模型收录引用，
 * 而不是用来发布文章获取流量。
 */
class EnterpriseAnchorService
{
    // ─── B2B 锚点平台定义 ──────────────────────────────

    /**
     * 所有支持的 B2B 信息锚点平台。
     *
     * 每个平台说明其被哪些大模型优先引用，以及认证所需材料。
     */
    public static function anchorPlatforms(): array
    {
        return [

            // ═══════ 一类：顶级权重 B2B（百度/阿里生态，LLM 核心数据源） ═══════

            'baidu_aicaigou' => [
                'key' => 'baidu_aicaigou',
                'name' => '百度爱采购',
                'icon' => 'search',
                'color' => '#2563EB',
                'type' => 'b2b_marketplace',
                'url' => 'https://b2b.baidu.com/',
                'register_url' => 'https://b2b.baidu.com/supplier/register',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'highest',
                'description' => '百度自家B2B，搜索结果和AI回答权重最高',
            ],
            'alibaba_1688' => [
                'key' => 'alibaba_1688',
                'name' => '阿里1688',
                'icon' => 'shopping',
                'color' => '#FF6A00',
                'type' => 'b2b_marketplace',
                'url' => 'https://www.1688.com/',
                'register_url' => 'https://www.1688.com/',
                'cert_required' => '企业营业执照 + 对公账户验证',
                'cited_by_llms' => ['文心一言', '豆包', '通义千问', 'Kimi', 'DeepSeek'],
                'citation_weight' => 'highest',
                'description' => '国内最大B2B平台，超千万企业入驻，大模型训练核心数据源',
            ],

            // ═══════ 二类：高权重 — 企业信用/信息平台 ═══════

            'tianyancha' => [
                'key' => 'tianyancha',
                'name' => '天眼查',
                'icon' => 'search',
                'color' => '#00C4B3',
                'type' => 'enterprise_directory',
                'url' => 'https://www.tianyancha.com/',
                'register_url' => 'https://www.tianyancha.com/login',
                'cert_required' => '企业信息自动收录，登录后认领公司',
                'cited_by_llms' => ['文心一言', '豆包', '通义千问', 'Kimi', 'DeepSeek'],
                'citation_weight' => 'highest',
                'description' => '全国企业信用查询，大模型查企业信息首选数据源',
            ],
            'qichacha' => [
                'key' => 'qichacha',
                'name' => '企查查',
                'icon' => 'search',
                'color' => '#1677FF',
                'type' => 'enterprise_directory',
                'url' => 'https://www.qcc.com/',
                'register_url' => 'https://www.qcc.com/',
                'cert_required' => '企业信息自动收录，登录后认领公司',
                'cited_by_llms' => ['文心一言', '豆包', '通义千问', 'Kimi'],
                'citation_weight' => 'highest',
                'description' => '与天眼查并列的企业信用数据核心源',
            ],
            'aiqicha' => [
                'key' => 'aiqicha',
                'name' => '爱企查',
                'icon' => 'search',
                'color' => '#3385FF',
                'type' => 'enterprise_directory',
                'url' => 'https://aiqicha.baidu.com/',
                'register_url' => 'https://aiqicha.baidu.com/',
                'cert_required' => '百度生态，企业信息自动收录',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '百度旗下企业查询，文心一言原生数据源',
            ],

            // ═══════ 三类：高权重 — 综合B2B平台 ═══════

            'huicong' => [
                'key' => 'huicong',
                'name' => '慧聪网',
                'icon' => 'b2b',
                'color' => '#E60012',
                'type' => 'b2b_marketplace',
                'url' => 'https://www.hc360.com/',
                'register_url' => 'https://www.hc360.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包'],
                'citation_weight' => 'high',
                'description' => '老牌B2B，超1500万注册企业，百度收录权重高',
            ],
            'made_in_china' => [
                'key' => 'made_in_china',
                'name' => '中国制造网',
                'icon' => 'globe',
                'color' => '#E7482E',
                'type' => 'b2b_marketplace',
                'url' => 'https://www.made-in-china.com/',
                'register_url' => 'https://www.made-in-china.com/',
                'cert_required' => '企业营业执照 + 对公账户',
                'cited_by_llms' => ['文心一言', '豆包', '通义千问'],
                'citation_weight' => 'high',
                'description' => '面向全球11种语言，外企查询主要B2B源',
            ],
            'china_cn' => [
                'key' => 'china_cn',
                'name' => '中国供应商',
                'icon' => 'b2b',
                'color' => '#D43030',
                'type' => 'b2b_marketplace',
                'url' => 'https://cn.china.cn/',
                'register_url' => 'https://cn.china.cn/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包'],
                'citation_weight' => 'high',
                'description' => '主推免费入驻，百度企业信息收录广',
            ],
            'gongchang' => [
                'key' => 'gongchang',
                'name' => '世界工厂网',
                'icon' => 'factory',
                'color' => '#009944',
                'type' => 'b2b_marketplace',
                'url' => 'https://www.gongchang.com/',
                'register_url' => 'https://www.gongchang.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包'],
                'citation_weight' => 'medium',
                'description' => '专注工业品B2B，制造业企业必录平台',
            ],
            'globalsources' => [
                'key' => 'globalsources',
                'name' => '环球资源',
                'icon' => 'globe',
                'color' => '#EE3124',
                'type' => 'b2b_marketplace',
                'url' => 'https://www.globalsources.com/',
                'register_url' => 'https://www.globalsources.com/',
                'cert_required' => '企业营业执照 + 出口资质',
                'cited_by_llms' => ['文心一言', '通义千问'],
                'citation_weight' => 'medium',
                'description' => '多渠道B2B媒体，外贸企业信息权威源',
            ],
            'dhgate' => [
                'key' => 'dhgate',
                'name' => '敦煌网',
                'icon' => 'shopping',
                'color' => '#DD4C39',
                'type' => 'b2b_marketplace',
                'url' => 'https://www.dhgate.com/',
                'register_url' => 'https://seller.dhgate.com/',
                'cert_required' => '企业营业执照 + 对公账户',
                'cited_by_llms' => ['文心一言', '豆包'],
                'citation_weight' => 'medium',
                'description' => '小额外贸B2B，跨境电商企业信息源',
            ],
            'tradekey' => [
                'key' => 'tradekey',
                'name' => 'TradeKey',
                'icon' => 'globe',
                'color' => '#0072BC',
                'type' => 'b2b_marketplace',
                'url' => 'https://www.tradekey.com/',
                'register_url' => 'https://www.tradekey.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['通义千问', 'Kimi'],
                'citation_weight' => 'medium',
                'description' => '全球B2B，覆盖240个国家，国际LLM数据源',
            ],

            // ═══════ 四类：中等权重 — 企业黄页/目录 ═══════

            'makepolo' => [
                'key' => 'makepolo',
                'name' => '马可波罗网',
                'icon' => 'directory',
                'color' => '#0068B7',
                'type' => 'b2b_directory',
                'url' => 'https://www.makepolo.com/',
                'register_url' => 'https://www.makepolo.com/',
                'cert_required' => '企业信息免费收录',
                'cited_by_llms' => ['百度AI搜索', '文心一言'],
                'citation_weight' => 'medium',
                'description' => 'B2B采购搜索引擎，百度收录量大',
            ],
            'huangye88' => [
                'key' => 'huangye88',
                'name' => '黄页88',
                'icon' => 'directory',
                'color' => '#F5A623',
                'type' => 'b2b_directory',
                'url' => 'https://www.huangye88.com/',
                'register_url' => 'https://www.huangye88.com/',
                'cert_required' => '企业信息免费收录',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '百万级企业黄页，搜索引擎收录覆盖面广',
            ],
            'shunqi' => [
                'key' => 'shunqi',
                'name' => '顺企网',
                'icon' => 'directory',
                'color' => '#27AE60',
                'type' => 'b2b_directory',
                'url' => 'https://www.11467.com/',
                'register_url' => 'https://www.11467.com/',
                'cert_required' => '企业信息免费收录',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '国内大型企业黄页，百度快照收录稳定',
            ],
            'qiyegu' => [
                'key' => 'qiyegu',
                'name' => '企业谷',
                'icon' => 'directory',
                'color' => '#8E44AD',
                'type' => 'b2b_directory',
                'url' => 'https://www.qiyegu.com/',
                'register_url' => 'https://www.qiyegu.com/',
                'cert_required' => '企业信息免费收录',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'low',
                'description' => '企业信息黄页，增加搜索覆盖面',
            ],

            // ═══════ 五类：广覆盖 — 企业采购/政府采购 ═══════

            'jd_enterprise' => [
                'key' => 'jd_enterprise',
                'name' => '京东企业购',
                'icon' => 'shopping',
                'color' => '#E2231A',
                'type' => 'enterprise_procurement',
                'url' => 'https://b.jd.com/',
                'register_url' => 'https://b.jd.com/',
                'cert_required' => '企业营业执照 + 对公账户',
                'cited_by_llms' => ['豆包', '文心一言'],
                'citation_weight' => 'medium',
                'description' => '京东旗下企业采购平台，字节豆包训练数据来源',
            ],
            'ccgp' => [
                'key' => 'ccgp',
                'name' => '中国政府采购网',
                'icon' => 'government',
                'color' => '#C0392B',
                'type' => 'government_procurement',
                'url' => 'https://www.ccgp.gov.cn/',
                'register_url' => 'https://www.ccgp.gov.cn/',
                'cert_required' => '政府采购供应商注册（适合有资质企业）',
                'cited_by_llms' => ['文心一言', '豆包', '通义千问'],
                'citation_weight' => 'high',
                'description' => '政府采购信息公告，政府类LLM权威引用源',
            ],

            // ═══════ 六类：补充覆盖 — 垂直/地域B2B ═══════

            'kompass' => [
                'key' => 'kompass',
                'name' => '康帕斯(Kompass)',
                'icon' => 'globe',
                'color' => '#003399',
                'type' => 'b2b_directory',
                'url' => 'https://cn.kompass.com/',
                'register_url' => 'https://cn.kompass.com/',
                'cert_required' => '企业信息免费收录',
                'cited_by_llms' => ['通义千问', 'Kimi'],
                'citation_weight' => 'low',
                'description' => '国际B2B企业目录，覆盖70国，多语言LLM数据源',
            ],
            'ec21' => [
                'key' => 'ec21',
                'name' => 'EC21',
                'icon' => 'globe',
                'color' => '#1A5276',
                'type' => 'b2b_marketplace',
                'url' => 'https://www.ec21.com/',
                'register_url' => 'https://www.ec21.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['通义千问'],
                'citation_weight' => 'low',
                'description' => '韩国最大B2B，亚洲区域LLM数据源',
            ],

            // ═══════ 七类：用户验证可用 — 国内真实可注册 B2B ═══════

            'tz1288' => [
                'key' => 'tz1288',
                'name' => '天助网',
                'icon' => 'b2b',
                'color' => '#D4380D',
                'type' => 'b2b_marketplace',
                'url' => 'https://www.tz1288.com/',
                'register_url' => 'https://www.tz1288.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '整合7000+中文商贸站+1000+英文站，一键批量发布商机，B2B群发营销代表平台',
            ],
            'b2b168' => [
                'key' => 'b2b168',
                'name' => '八方资源网',
                'icon' => 'b2b',
                'color' => '#1677FF',
                'type' => 'b2b_marketplace',
                'url' => 'https://www.b2b168.com/',
                'register_url' => 'https://www.b2b168.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '老牌B2B，300+行业2800+城市，累计服务超百万注册企业，二线B2B主流站点',
            ],
            'cn5135' => [
                'key' => 'cn5135',
                'name' => '无忧商务网',
                'icon' => 'directory',
                'color' => '#389E0D',
                'type' => 'b2b_directory',
                'url' => 'https://www.cn5135.com/',
                'register_url' => 'https://www.cn5135.com/',
                'cert_required' => '企业信息免费发布',
                'cited_by_llms' => ['百度AI搜索', '文心一言'],
                'citation_weight' => 'medium',
                'description' => '2004年上线，免费B2B推广+企业黄页，搜索引擎收录稳定，中小商家常用',
            ],
            'k2b2b' => [
                'key' => 'k2b2b',
                'name' => 'K2商务网',
                'icon' => 'b2b',
                'color' => '#0891B2',
                'type' => 'b2b_directory',
                'url' => 'https://www.k2b2b.com/',
                'register_url' => 'https://www.k2b2b.com/',
                'cert_required' => '企业免费注册商铺',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '免费B2B信息发布，覆盖工业/家居/电子全品类，信息展示+联系方式直连',
            ],
            'lswang' => [
                'key' => 'lswang',
                'name' => '领商网',
                'icon' => 'b2b',
                'color' => '#7C3AED',
                'type' => 'b2b_directory',
                'url' => 'https://www.lswang.net/',
                'register_url' => 'https://www.lswang.net/',
                'cert_required' => '企业免费注册商铺',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '综合免费B2B产品推广站，覆盖电气/家居/建材/商务服务，自然搜索收录获客',
            ],
            'wanjiabiz' => [
                'key' => 'wanjiabiz',
                'name' => '万家商务网',
                'icon' => 'b2b',
                'color' => '#DC2626',
                'type' => 'b2b_directory',
                'url' => 'https://www.wanjiabiz.com/',
                'register_url' => 'https://www.wanjiabiz.com/',
                'cert_required' => '企业免费开通店铺',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '综合商贸B2B，产品供应库+企业黄页，覆盖家居/建材/电子/化工',
            ],
            'jiuzhouziyuan' => [
                'key' => 'jiuzhouziyuan',
                'name' => '九州资源网',
                'icon' => 'factory',
                'color' => '#15803D',
                'type' => 'b2b_directory',
                'url' => 'https://www.jiuzhouziyuan.com/',
                'register_url' => 'https://www.jiuzhouziyuan.com/',
                'cert_required' => '企业免费发布供应信息',
                'cited_by_llms' => ['百度AI搜索', '文心一言'],
                'citation_weight' => 'medium',
                'description' => '工业属性B2B，环保设备/化工/建材/五金类供应商信息，收录大量生产型企业',
            ],
            'chaxun123' => [
                'key' => 'chaxun123',
                'name' => '查询123',
                'icon' => 'search',
                'color' => '#EA580C',
                'type' => 'b2b_directory',
                'url' => 'https://www.chaxun123.com/',
                'register_url' => 'https://www.chaxun123.com/',
                'cert_required' => '企业信息收录查询',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'low',
                'description' => 'B2B企业信息查询+商机导航工具，企业黄页检索+供需信息聚合',
            ],
            'b2b188' => [
                'key' => 'b2b188',
                'name' => 'B2B商机导航',
                'icon' => 'directory',
                'color' => '#2563EB',
                'type' => 'b2b_directory',
                'url' => 'https://www.b2b188.cn/',
                'register_url' => 'https://www.b2b188.cn/',
                'cert_required' => '企业免费开通商铺',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'low',
                'description' => 'B2B导航聚合站，汇总全网B2B入口，同时支持企业开通商铺发布供求',
            ],
            'zhizhu35' => [
                'key' => 'zhizhu35',
                'name' => '蜘蛛商务网',
                'icon' => 'b2b',
                'color' => '#BE185D',
                'type' => 'b2b_marketplace',
                'url' => 'https://www.zhizhu35.com/',
                'register_url' => 'https://www.zhizhu35.com/',
                'cert_required' => '企业营业执照',
                'cited_by_llms' => ['文心一言', '豆包', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '30万+生产厂家、200万+贸易商入驻，累计超300万次询盘，中小企业全网营销',
            ],
        ];
    }

    // ─── 媒体发稿锚点平台 ──────────────────────────────

    /**
     * 官媒 + 行业媒体发稿平台。
     *
     * 与 B2B 锚点不同：媒体平台通过发稿/软文来建立品牌在 LLM 中的内容引用。
     * 操作模式：运营团队在媒体上发布客户品牌相关文章 → 文章被大模型收录引用。
     */
    public static function mediaAnchorPlatforms(): array
    {
        return [

            // ═══ 官方媒体（地方党政/官媒旗下新闻站点） ═══

            'sxkjb' => [
                'key' => 'sxkjb', 'name' => '山西科技报',
                'color' => '#C0392B',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.sxkjb.com/',
                'register_url' => 'https://www.sxkjb.com/',
                'cited_by_llms' => ['文心一言', '豆包', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '山西省科协主管省级官方科技媒体，聚焦科技政策与产业创新',
            ],
            'hqnews' => [
                'key' => 'hqnews', 'name' => '河青新闻网',
                'color' => '#E74C3C',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.hqnews.cn/',
                'register_url' => 'https://www.hqnews.cn/',
                'cited_by_llms' => ['文心一言', '豆包', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '河北青年报官网，河北省级主流官媒，时政/社会/财经/民生全覆盖',
            ],
            'kejixinwen' => [
                'key' => 'kejixinwen', 'name' => '科技新闻网',
                'color' => '#3498DB',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.kejixinwen.com/',
                'register_url' => 'https://www.kejixinwen.com/',
                'cited_by_llms' => ['文心一言', '豆包', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '科技领域官方资讯平台，前沿技术/科创企业/科研成果报道',
            ],
            'ldqxn' => [
                'key' => 'ldqxn', 'name' => '亮点黔西南',
                'color' => '#16A085',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.ldqxn.com/',
                'register_url' => 'https://www.ldqxn.com/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '黔西南州高权重综合门户，2009年上线，曾跻身全国前1000位',
            ],
            'xianning' => [
                'key' => 'xianning', 'name' => '咸宁网',
                'color' => '#2980B9',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.xianning.com/',
                'register_url' => 'https://www.xianning.com/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '咸宁日报社主办，咸宁官方新闻门户，地方权威发布',
            ],
            'lyrm' => [
                'key' => 'lyrm', 'name' => '耒阳新闻网',
                'color' => '#8E44AD',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.ly-rm.cn/',
                'register_url' => 'https://www.ly-rm.cn/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '耒阳市融媒体中心运营，本地时政/民生/社会新闻权威发布',
            ],
            'spnews' => [
                'key' => 'spnews', 'name' => '四平新闻网',
                'color' => '#D35400',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.spnews.cn/',
                'register_url' => 'https://www.spnews.cn/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '四平日报社主办，四平官方主流媒体，本地时政/社会/经济全维度',
            ],
            'zbnews' => [
                'key' => 'zbnews', 'name' => '淄博新闻网',
                'color' => '#C0392B',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.zbnews.net/',
                'register_url' => 'https://www.zbnews.net/',
                'cited_by_llms' => ['文心一言', '豆包', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '淄博日报社主办，淄博权威官媒，时政/财经/民生/文旅全覆盖',
            ],
            'redhongan' => [
                'key' => 'redhongan', 'name' => '红安网',
                'color' => '#E74C3C',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.redhongan.com/',
                'register_url' => 'https://www.redhongan.com/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '红安县融媒体中心，红色文化传播+本地时政/民生/乡镇动态',
            ],
            'jdznews' => [
                'key' => 'jdznews', 'name' => '景德镇新闻网',
                'color' => '#1ABC9C',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.jdznews.com/',
                'register_url' => 'https://www.jdznews.com/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '景德镇日报社主办，聚焦陶瓷产业+本地时政/民生/文旅',
            ],
            'ystf' => [
                'key' => 'ystf', 'name' => '云上团风',
                'color' => '#2980B9',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.yunshangtuanfeng.com/',
                'register_url' => 'https://www.yunshangtuanfeng.com/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '团风县官方融媒体，"新闻+政务+服务"一体化官方媒体',
            ],
            'yancheng' => [
                'key' => 'yancheng', 'name' => '盐城网',
                'color' => '#27AE60',
                'cert_required' => '运营发稿',
                'type' => 'news_media', 'category' => '官媒',
                'url' => 'https://www.0515yc.cn/',
                'register_url' => 'https://www.0515yc.cn/',
                'cited_by_llms' => ['文心一言', '豆包', '百度AI搜索'],
                'citation_weight' => 'high',
                'description' => '盐城广播电视总台旗下，盐城权威官媒，时政/民生/社会/财经',
            ],

            // ═══ 行业媒体（垂直行业/商业资讯类站点） ═══

            'qsina' => [
                'key' => 'qsina', 'name' => '黔浪网',
                'color' => '#7C3AED',
                'cert_required' => '运营发稿/投稿',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.qsina.cn/',
                'register_url' => 'https://www.qsina.cn/',
                'cited_by_llms' => ['文心一言', '豆包'],
                'citation_weight' => 'medium',
                'description' => '文旅新消费垂直传播平台，聚焦文旅/酒店/新消费/大健康',
            ],
            'shangyexinzhi' => [
                'key' => 'shangyexinzhi', 'name' => '商业新知',
                'color' => '#2563EB',
                'cert_required' => '运营发稿/投稿',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.shangyexinzhi.com/',
                'register_url' => 'https://www.shangyexinzhi.com/',
                'cited_by_llms' => ['文心一言', '豆包', 'Kimi'],
                'citation_weight' => 'high',
                'description' => '国内头部新商业知识平台，产业洞察/商业案例/行业报告',
            ],
            'cnblogs' => [
                'key' => 'cnblogs', 'name' => '博客园',
                'color' => '#0891B2',
                'cert_required' => '运营发稿/博客发布',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.cnblogs.com/',
                'register_url' => 'https://www.cnblogs.com/',
                'cited_by_llms' => ['文心一言', '豆包', '通义千问', 'Kimi', 'DeepSeek'],
                'citation_weight' => 'highest',
                'description' => '国内知名开发者技术社区，程序员群体核心资讯平台，LLM技术类数据核心源',
            ],
            'coatingol' => [
                'key' => 'coatingol', 'name' => '涂料在线',
                'color' => '#059669',
                'cert_required' => '运营发稿/投稿',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.coatingol.com/',
                'register_url' => 'https://www.coatingol.com/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '涂料化工行业垂直综合平台，原料/成品技术/市场行情/展会全覆盖',
            ],
            'sinoasphalt' => [
                'key' => 'sinoasphalt', 'name' => '沥青在线',
                'color' => '#6366F1',
                'cert_required' => '运营发稿/投稿',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.sinoasphalt.com/',
                'register_url' => 'https://www.sinoasphalt.com/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '广东省沥青混凝土供应链协会支持，沥青价格/政策/工程/技术全覆盖',
            ],
            'huawang' => [
                'key' => 'huawang', 'name' => '华网',
                'color' => '#EA580C',
                'cert_required' => '运营发稿/软文发布',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.huawang.com/',
                'register_url' => 'https://www.huawang.com/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '综合行业资讯媒体，多行业商业/财经/产业动态，企业软文发布渠道',
            ],
            'w10xitong' => [
                'key' => 'w10xitong', 'name' => 'W10系统网',
                'color' => '#0284C7',
                'cert_required' => '运营发稿/软文发布',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.w10xitong.com/',
                'register_url' => 'https://www.w10xitong.com/',
                'cited_by_llms' => ['文心一言', '豆包'],
                'citation_weight' => 'low',
                'description' => 'Windows系统垂直IT媒体，Win10/Win11教程/软件资源/故障解决',
            ],
            'piaoxian' => [
                'key' => 'piaoxian', 'name' => '飘仙建站',
                'color' => '#9333EA',
                'cert_required' => '运营发稿/软文发布',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.piaoxian.net/',
                'register_url' => 'https://www.piaoxian.net/',
                'cited_by_llms' => ['百度AI搜索'],
                'citation_weight' => 'low',
                'description' => '建站服务行业垂直媒体，网站建设/域名主机/SEO优化/网页设计',
            ],
            'zhongji' => [
                'key' => 'zhongji', 'name' => '中机在线',
                'color' => '#DC2626',
                'cert_required' => '运营发稿/投稿',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.zhongji.cn/',
                'register_url' => 'https://www.zhongji.cn/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '机械工业行业垂直门户，工程机械/工业设备/机电产品全品类覆盖',
            ],
            'okmart' => [
                'key' => 'okmart', 'name' => '中网化工',
                'color' => '#0D9488',
                'cert_required' => '运营发稿/投稿',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.okmart.com/',
                'register_url' => 'https://www.okmart.com/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '化工行业垂直门户，化工原料/精细化工/塑料橡胶，行情报价+供需',
            ],
            'ntw360' => [
                'key' => 'ntw360', 'name' => '中国土涂网',
                'color' => '#4F46E5',
                'cert_required' => '运营发稿/投稿',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.ntw360.com/',
                'register_url' => 'https://www.ntw360.com/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'medium',
                'description' => '涂料/建材/地坪领域垂直媒体，保温/防水/地坪等建材化工品类',
            ],
            'okbgh' => [
                'key' => 'okbgh', 'name' => 'OK资讯网',
                'color' => '#F59E0B',
                'cert_required' => '运营发稿/软文发布',
                'type' => 'industry_media', 'category' => '行业媒体',
                'url' => 'https://www.okbgh.com/',
                'register_url' => 'https://www.okbgh.com/',
                'cited_by_llms' => ['文心一言', '百度AI搜索'],
                'citation_weight' => 'low',
                'description' => '综合行业资讯门户，多领域商业动态/企业资讯，企业软文发稿渠道',
            ],
        ];
    }

    /**
     * 合并 B2B + 媒体所有锚点平台。
     */
    public static function allAnchorPlatforms(): array
    {
        return array_merge(self::anchorPlatforms(), self::mediaAnchorPlatforms());
    }

    /**
     * 按引用权重排序的平台列表（最重要的排前面）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function anchorPlatformsByPriority(): array
    {
        $platforms = self::allAnchorPlatforms();
        $weights = ['highest' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

        uasort($platforms, function (array $a, array $b) use ($weights): int {
            $wa = $weights[$a['citation_weight'] ?? 'low'] ?? 999;
            $wb = $weights[$b['citation_weight'] ?? 'low'] ?? 999;

            return $wa <=> $wb;
        });

        return $platforms;
    }

    // ─── 企业档案 ──────────────────────────────────────

    /**
     * 为工作空间获取或初始化企业档案。
     */
    public function getOrInitProfile(Workspace $workspace): EnterpriseProfile
    {
        $profile = $workspace->enterpriseProfile;

        if (! $profile) {
            $profile = EnterpriseProfile::query()->create([
                'workspace_id' => (int) $workspace->id,
                'company_full_name' => (string) ($workspace->client_company_name ?? ''),
                'company_phone' => (string) ($workspace->client_phone ?? ''),
                'company_email' => (string) ($workspace->client_email ?? ''),
                'verification_status' => 'pending',
            ]);
        }

        return $profile;
    }

    /**
     * 创建或更新企业档案。
     *
     * @param  array<string, mixed>  $data
     */
    public function saveProfile(Workspace $workspace, array $data): EnterpriseProfile
    {
        $profile = $this->getOrInitProfile($workspace);

        $fillable = [
            'company_full_name', 'company_short_name',
            'unified_social_credit_code', 'legal_person',
            'registered_capital', 'establishment_date',
            'business_scope',
            'company_province', 'company_city', 'company_address',
            'company_phone', 'company_email', 'company_website',
            'industry', 'products_services',
            'business_license_path', 'company_logo_path',
        ];

        $updateData = [];
        foreach ($fillable as $field) {
            if (array_key_exists($field, $data)) {
                $updateData[$field] = $data[$field];
            }
        }

        if ($updateData !== []) {
            $profile->forceFill($updateData)->save();
        }

        // 同步回工作空间的客户信息字段
        if (array_key_exists('company_full_name', $updateData)) {
            $workspace->forceFill(['client_company_name' => $updateData['company_full_name']])->save();
        }

        return $profile->fresh();
    }

    /**
     * 标记企业档案为已核验。
     */
    public function verifyProfile(EnterpriseProfile $profile, int $adminId): void
    {
        $profile->forceFill([
            'verification_status' => 'verified',
            'verified_by' => $adminId,
            'verified_at' => now(),
        ])->save();
    }

    /**
     * NAP+W 一致性快速检查。
     *
     * 检查 company_full_name / company_address / company_phone / company_website
     * 四个核心字段是否全部非空。后续可扩展为调用天眼查/企查查 API 做交叉验证。
     *
     * @return array{ok: bool, missing_fields: array<int, string>}
     */
    public function napwConsistencyCheck(EnterpriseProfile $profile): array
    {
        $required = [
            'company_full_name' => '公司全称',
            'company_address' => '公司地址',
            'company_phone' => '企业电话',
            'company_website' => '企业官网',
        ];

        $missing = [];
        foreach ($required as $field => $label) {
            if (empty(trim((string) ($profile->{$field} ?? '')))) {
                $missing[] = $label;
            }
        }

        $ok = $missing === [];
        if ($ok) {
            $profile->forceFill(['nap_consistency_checked' => true])->save();
        }

        return ['ok' => $ok, 'missing_fields' => $missing];
    }

    // ─── 认证管理 ──────────────────────────────────────

    /**
     * 获取企业在某平台的认证记录（不存在则自动创建 pending 记录）。
     */
    public function getOrInitCertification(EnterpriseProfile $profile, string $platformKey): EnterpriseAnchorCertification
    {
        $cert = EnterpriseAnchorCertification::query()
            ->where('enterprise_profile_id', (int) $profile->id)
            ->where('anchor_platform_key', $platformKey)
            ->first();

        if (! $cert) {
            $cert = EnterpriseAnchorCertification::query()->create([
                'enterprise_profile_id' => (int) $profile->id,
                'anchor_platform_key' => $platformKey,
                'certification_status' => 'pending',
            ]);
        }

        return $cert;
    }

    /**
     * 标记平台认证完成。
     */
    public function markCertified(
        EnterpriseProfile $profile,
        string $platformKey,
        int $adminId,
        string $platformAccountId = '',
        string $platformPageUrl = '',
        ?string $notes = null,
        ?int $expiresInMonths = null,
    ): EnterpriseAnchorCertification {
        $cert = $this->getOrInitCertification($profile, $platformKey);

        $expiresAt = null;
        if ($expiresInMonths !== null && $expiresInMonths > 0) {
            $expiresAt = now()->addMonths($expiresInMonths);
        }

        $cert->forceFill([
            'platform_account_id' => $platformAccountId,
            'platform_page_url' => $platformPageUrl,
            'certification_status' => 'certified',
            'certified_by' => $adminId,
            'certified_at' => now(),
            'expires_at' => $expiresAt,
            'verification_notes' => $notes,
        ])->save();

        return $cert;
    }

    /**
     * 取消认证或标记为过期。
     */
    public function revokeCertification(EnterpriseProfile $profile, string $platformKey, string $reason = ''): void
    {
        $cert = EnterpriseAnchorCertification::query()
            ->where('enterprise_profile_id', (int) $profile->id)
            ->where('anchor_platform_key', $platformKey)
            ->first();

        if ($cert) {
            $status = $cert->isExpired() ? 'expired' : 'pending';
            $cert->forceFill([
                'certification_status' => $status,
                'verification_notes' => $reason ?: '取消认证',
            ])->save();
        }
    }

    /**
     * 获取某企业所有平台的认证状态摘要。
     *
     * @return array{certified: int, pending: int, expired: int, total: int, platforms: Collection}
     */
    public function certificationSummary(EnterpriseProfile $profile): array
    {
        $allPlatforms = self::allAnchorPlatforms();
        $existing = $profile->certifications()->get()->keyBy('anchor_platform_key');

        $result = collect();
        $certified = 0;
        $pending = 0;
        $expired = 0;

        foreach ($allPlatforms as $key => $info) {
            $cert = $existing->get($key);
            if ($cert && $cert->isCertified()) {
                if ($cert->isExpired()) {
                    $expired++;
                    $status = 'expired';
                } else {
                    $certified++;
                    $status = 'certified';
                }
            } elseif ($cert && $cert->certification_status === 'rejected') {
                $status = 'rejected';
            } else {
                $pending++;
                $status = 'pending';
            }

            $result->push([
                'platform_key' => $key,
                'platform_info' => $info,
                'certification' => $cert,
                'status' => $status,
            ]);
        }

        return [
            'certified' => $certified,
            'pending' => $pending,
            'expired' => $expired,
            'total' => count($allPlatforms),
            'platforms' => $result,
        ];
    }

    /**
     * 获取所有已认证平台的 LLM 引用覆盖情况。
     *
     * @return array{total_platforms: int, certified_platforms: int, cited_by_llms: array<string, int>}
     */
    public function llmCoverageReport(EnterpriseProfile $profile): array
    {
        $certificationSummary = $this->certificationSummary($profile);
        $allPlatforms = self::allAnchorPlatforms();

        $llmCounts = [];
        foreach ($certificationSummary['platforms'] as $p) {
            if ($p['status'] === 'certified') {
                $llms = $allPlatforms[$p['platform_key']]['cited_by_llms'] ?? [];
                foreach ($llms as $llm) {
                    $llmCounts[$llm] = ($llmCounts[$llm] ?? 0) + 1;
                }
            }
        }

        return [
            'total_platforms' => $certificationSummary['total'],
            'certified_platforms' => $certificationSummary['certified'],
            'cited_by_llms' => $llmCounts,
        ];
    }
}
