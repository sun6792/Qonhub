<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\EnterpriseProfile;
use App\Models\Workspace;

/**
 * llms.txt + JSON-LD Schema 自动生成器。
 *
 * 基于 geoskills 规范，为目标站点自动生成 AI 友好的元文件：
 *   - llms.txt（Markdown 格式，AI 爬虫入口）
 *   - llms-full.txt（全文版）
 *   - JSON-LD Schema（Organization + Article + FAQPage + BreadcrumbList）
 *
 * 生成的文件直接写入目标站点的 public 目录。
 */
class GeoSiteBuilder
{
    /**
     * 为一个工作空间生成全部 AI 友好文件。
     *
     * @return array{llms_txt:string, llms_full_txt:string, schema_jsonld:string}
     */
    public function buildAll(Workspace $workspace): array
    {
        $articles = $this->getArticles($workspace);
        $profile = $workspace->enterpriseProfile;

        return [
            'llms_txt' => $this->buildLlmsTxt($workspace, $articles),
            'llms_full_txt' => $this->buildLlmsFullTxt($workspace, $articles),
            'schema_jsonld' => $this->buildSchema($workspace, $profile, $articles),
        ];
    }

    // ── llms.txt ─────────────────────────────────────────

    /**
     * 生成符合 llmstxt.org 规范的 AI 爬虫入口文件。
     * 格式：Markdown，H1 + 摘要 + 链接列表。
     */
    public function buildLlmsTxt(Workspace $workspace, ?\Illuminate\Support\Collection $articles = null): string
    {
        $articles = $articles ?: $this->getArticles($workspace);
        $siteUrl = rtrim((string) config('app.url'), '/');
        $siteName = $workspace->name;
        $profile = $workspace->enterpriseProfile;

        $lines = [];

        // H1
        $lines[] = "# {$siteName}";

        // 摘要
        $desc = $profile?->company_full_name
            ? "{$profile->company_full_name}——{$profile->industry}领域的专业企业，提供" . (is_array($profile->products_services) ? implode('、', array_slice($profile->products_services, 0, 3)) : '优质产品与服务') . "。"
            : "{$siteName}——企业官方网站。";
        $lines[] = "> {$desc}";
        $lines[] = '';

        // 文章链接
        if ($articles->isNotEmpty()) {
            $lines[] = '## 文章';
            foreach ($articles->take(20) as $article) {
                $slug = $article->slug ?: $article->id;
                $title = mb_substr((string) $article->title, 0, 50);
                $lines[] = "- [{$title}]({$siteUrl}/article/{$slug}): " . mb_substr((string) ($article->excerpt ?: strip_tags((string) $article->content)), 0, 80);
            }
            $lines[] = '';
        }

        // 核心页面
        $lines[] = '## 核心页面';
        $lines[] = "- [首页]({$siteUrl})";
        $lines[] = "- [文章归档]({$siteUrl}/archive)";

        if ($profile) {
            $lines[] = "- [关于我们]({$siteUrl}/about): {$profile->company_full_name}，{$profile->industry}";
        }

        $lines[] = '';

        // Optional 部分（收录但不重要）
        $lines[] = '## Optional';
        $lines[] = '- [联系信息](' . $siteUrl . '/contact)';

        return implode("\n", $lines);
    }

    /**
     * 生成全文版 llms-full.txt。
     */
    public function buildLlmsFullTxt(Workspace $workspace, ?\Illuminate\Support\Collection $articles = null): string
    {
        $articles = $articles ?: $this->getArticles($workspace);
        $profile = $workspace->enterpriseProfile;
        $siteName = $workspace->name;

        $lines = [];
        $lines[] = "# {$siteName} — 全文";
        $lines[] = '';

        if ($profile) {
            $lines[] = "## 企业信息";
            $lines[] = "公司：{$profile->company_full_name}";
            if ($profile->company_address) {
                $lines[] = "地址：{$profile->company_address}";
            }
            if ($profile->company_phone) {
                $lines[] = "电话：{$profile->company_phone}";
            }
            $lines[] = '';
        }

        foreach ($articles as $article) {
            $lines[] = "## {$article->title}";
            $content = strip_tags((string) $article->content);
            $content = preg_replace('/\n{3,}/', "\n\n", (string) $content);
            $lines[] = $content ?: '(无正文)';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // ── JSON-LD Schema ───────────────────────────────────

    /**
     * 生成 JSON-LD 结构化数据。
     * 包含：Organization、WebSite、BreadcrumbList、Article 列表。
     */
    public function buildSchema(Workspace $workspace, ?EnterpriseProfile $profile = null, ?\Illuminate\Support\Collection $articles = null): string
    {
        $profile = $profile ?: $workspace->enterpriseProfile;
        $articles = $articles ?: $this->getArticles($workspace);
        $siteUrl = rtrim((string) config('app.url'), '/');

        $schemas = [];

        // 1. Organization
        if ($profile?->company_full_name) {
            $sameAs = [];
            if ($profile->company_website) {
                $sameAs[] = $profile->company_website;
            }

            $org = [
                '@context' => 'https://schema.org',
                '@type' => 'Organization',
                'name' => $profile->company_full_name,
                'url' => $siteUrl,
                'description' => mb_substr((string) ($profile->business_scope ?: "{$profile->industry}领域专业企业"), 0, 200),
                'foundingDate' => $profile->establishment_date?->toDateString(),
            ];

            if (! empty($sameAs)) {
                $org['sameAs'] = $sameAs;
            }

            if ($profile->company_address) {
                $org['address'] = [
                    '@type' => 'PostalAddress',
                    'addressLocality' => $profile->company_city ?: '',
                    'addressRegion' => $profile->company_province ?: '',
                    'streetAddress' => $profile->company_address,
                ];
            }

            if ($profile->company_phone) {
                $org['contactPoint'] = [
                    '@type' => 'ContactPoint',
                    'telephone' => $profile->company_phone,
                    'contactType' => 'customer service',
                ];
            }

            $schemas[] = $org;
        }

        // 2. WebSite
        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $workspace->name,
            'url' => $siteUrl,
        ];

        // 3. BreadcrumbList
        $schemas[] = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => $siteUrl],
                ['@type' => 'ListItem', 'position' => 2, 'name' => '文章', 'item' => "{$siteUrl}/archive"],
            ],
        ];

        // 4. LocalBusiness（如果有地址信息）
        if ($profile && $profile->company_address) {
            $localBiz = [
                '@context' => 'https://schema.org',
                '@type' => 'LocalBusiness',
                'name' => $profile->company_full_name,
                'url' => $siteUrl,
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressLocality' => $profile->company_city ?: '',
                    'addressRegion' => $profile->company_province ?: '',
                    'streetAddress' => $profile->company_address,
                    'addressCountry' => 'CN',
                ],
            ];
            if ($profile->company_phone) {
                $localBiz['telephone'] = $profile->company_phone;
            }
            if ($profile->company_website) {
                $localBiz['sameAs'] = [$profile->company_website];
            }
            $schemas[] = $localBiz;
        }

        // 5. SoftwareApplication / Product（根据行业）
        if ($profile && ! empty($profile->products_services)) {
            $products = is_array($profile->products_services) ? $profile->products_services : [$profile->products_services];
            foreach (array_slice($products, 0, 3) as $product) {
                $schemas[] = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => (string) $product,
                    'description' => "{$profile->company_full_name} 提供的 " . (string) $product,
                    'brand' => ['@type' => 'Brand', 'name' => $profile->company_full_name],
                    'manufacturer' => ['@type' => 'Organization', 'name' => $profile->company_full_name],
                ];
            }
        }

        // 6. 最新文章的 Article schema（最多 5 篇）
        foreach ($articles->take(5) as $article) {
            $schemas[] = [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $article->title,
                'datePublished' => $article->published_at?->toIso8601String() ?: '',
                'dateModified' => $article->updated_at?->toIso8601String() ?: '',
                'description' => mb_substr((string) ($article->excerpt ?: strip_tags((string) $article->content)), 0, 160),
                'url' => "{$siteUrl}/article/" . ($article->slug ?: $article->id),
            ];
        }

        // 输出 JSON-LD
        $json = json_encode($schemas, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return (string) $json;
    }

    // ── 辅助 ─────────────────────────────────────────────

    private function getArticles(Workspace $workspace): \Illuminate\Support\Collection
    {
        return Article::query()
            ->where('status', 'published')
            ->whereIn('id', function ($query) use ($workspace) {
                $query->select('assignable_id')
                    ->from('workspace_assignments')
                    ->where('workspace_id', (int) $workspace->id)
                    ->where('assignable_type', Article::class);
            })
            ->orderByDesc('published_at')
            ->limit(50)
            ->get();
    }
}
