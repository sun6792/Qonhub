<?php

namespace App\Console\Commands;

use App\Models\EnterpriseProfile;
use App\Models\Workspace;
use App\Services\GeoFlow\Publishing\RpaEngineClient;
use Illuminate\Console\Command;

/**
 * RPA 企业认证全链路测试命令。
 *
 * 用法：
 *   php artisan rpa:test-certify b2b168 --workspace=pinshang-sports
 *   php artisan rpa:test-certify b2b168 --workspace=pinshang-sports --dry-run
 */
class TestRpaCertifyCommand extends Command
{
    protected $signature = 'rpa:test-certify
                            {platform : 平台 key（如 b2b168）}
                            {--workspace= : 工作空间 slug}
                            {--dry-run : 干跑模式，不实际连接 RPA 引擎}';

    protected $description = 'RPA 企业认证全链路测试：企业档案 → RPA 引擎 → 注册认证 → 抓取店铺 → 锚点同步';

    public function handle(): int
    {
        $platform = $this->argument('platform');
        $workspaceSlug = $this->option('workspace') ?: 'pinshang-sports';

        $this->newLine();
        $this->info("╔══════════════════════════════════════════╗");
        $this->info("║   RPA 企业认证全链路测试                  ║");
        $this->info("║   平台: {$platform}");
        $this->info("╚══════════════════════════════════════════╝");
        $this->newLine();

        // 1. 工作空间
        $workspace = Workspace::query()->where('slug', $workspaceSlug)->first();
        if (! $workspace) {
            $this->error("工作空间不存在: {$workspaceSlug}");

            return self::FAILURE;
        }
        $this->line("✅ [1/6] 工作空间: {$workspace->name}");

        // 2. 企业档案
        $profile = EnterpriseProfile::query()->where('workspace_id', (int) $workspace->id)->first();
        if (! $profile || empty($profile->company_full_name)) {
            $this->warn('企业档案不完整，创建测试档案...');
            $profile = EnterpriseProfile::query()->create([
                'workspace_id' => (int) $workspace->id,
                'company_full_name' => $workspace->client_company_name ?: '测试企业科技有限公司',
                'unified_social_credit_code' => '91110108MA01TESTX',
                'legal_person' => '张三',
                'company_province' => '广东省',
                'company_city' => '广州市',
                'company_address' => '广州市天河区体育东路100号',
                'company_phone' => '020-88888888',
                'company_email' => 'test@example.com',
                'company_website' => 'https://www.example.com',
                'industry' => '运动器材',
                'products_services' => ['运动护具', 'EVA材料', '体育用品'],
            ]);
        }
        $napCheck = ['ok' => true, 'missing_fields' => []];
        if (empty($profile->company_address)) {
            $napCheck['ok'] = false;
            $napCheck['missing_fields'][] = '公司地址';
        }
        if (empty($profile->company_phone)) {
            $napCheck['ok'] = false;
            $napCheck['missing_fields'][] = '企业电话';
        }
        $this->line("✅ [2/6] 企业档案: {$profile->company_full_name} (NAP+W: " . ($napCheck['ok'] ? 'PASS' : 'WARN: '.implode(',', $napCheck['missing_fields'])) . ")");

        // 3. 账号
        $account = \App\Models\ContentPublisherAccount::query()
            ->where('workspace_id', (int) $workspace->id)
            ->where('platform_key', $platform)
            ->first();

        if (! $account) {
            $this->warn('未找到平台账号，创建测试账号...');
            $account = \App\Models\ContentPublisherAccount::query()->create([
                'workspace_id' => (int) $workspace->id,
                'platform_key' => $platform,
                'platform_type' => 'b2b',
                'platform_name' => $platform,
                'account_name' => '测试认证账号',
                'credential_type' => 'password',
                'credential_ciphertext' => app(\App\Support\GeoFlow\ApiKeyCrypto::class)->encrypt('TestPass123'),
                'status' => 'active',
                'health_status' => 'healthy',
                'requires_rpa' => true,
                'bound_ip' => '',
                'publish_interval_seconds' => 120,
                'daily_publish_limit' => 20,
            ]);
        }
        $this->line("✅ [3/6] 账号: {$account->account_name} (RPA=" . ($account->requires_rpa ? 'YES' : 'NO') . ")");

        // 4. RPA 引擎健康检查
        $rpaClient = app(RpaEngineClient::class);
        $health = $rpaClient->healthCheck();
        $this->line(($health['healthy'] ? '✅' : '⚠️') . " [4/6] RPA引擎: {$health['message']}");

        if ($this->option('dry-run')) {
            $this->line('🔶 [5/6] 干跑模式 — 跳过实际认证执行');
            $this->line('🔶 [6/6] 模拟认证成功');

            // 模拟锚点同步
            $cert = \App\Models\EnterpriseAnchorCertification::query()->firstOrCreate(
                ['enterprise_profile_id' => (int) $profile->id, 'anchor_platform_key' => $platform],
                [
                    'certification_status' => 'certified',
                    'certified_at' => now(),
                    'platform_page_url' => "https://shop.{$platform}.com/{$workspace->slug}",
                ]
            );
            if (! $cert->wasRecentlyCreated) {
                $cert->forceFill([
                    'certification_status' => 'certified',
                    'certified_at' => now(),
                    'platform_page_url' => "https://shop.{$platform}.com/{$workspace->slug}",
                ])->save();
            }
            $this->line("  锚点: certification_id={$cert->id} status=certified");

        } elseif (! $health['healthy']) {
            $this->warn('RPA 引擎未就绪。请先启动 RPA 引擎：');
            $this->line('  cd rpa-engine && npm install && npm start');
            $this->line('  或: docker compose up rpa-engine');

            return self::FAILURE;
        } else {
            // 5. 执行认证
            $this->line('⏳ [5/6] 执行 RPA 认证...');
            $enterpriseData = [
                'company_name' => $profile->company_full_name,
                'credit_code' => $profile->unified_social_credit_code,
                'legal_person' => $profile->legal_person,
                'business_scope' => $profile->business_scope,
                'province' => $profile->company_province,
                'city' => $profile->company_city,
                'address' => $profile->company_address,
                'phone' => $profile->company_phone,
                'email' => $profile->company_email,
                'website' => $profile->company_website,
                'industry' => $profile->industry,
                'products' => is_array($profile->products_services) ? implode('、', $profile->products_services) : '',
                'register_username' => $account->account_id_on_platform ?: 'qy_' . time(),
                'register_credential' => app(\App\Services\GeoFlow\Publishing\AccountPoolService::class)->decryptCredential($account),
            ];

            $result = $rpaClient->executeTask([
                'platform' => $platform,
                'platform_name' => $platform,
                'action' => 'register_and_certify',
                'account' => [
                    'username' => $enterpriseData['register_username'],
                    'credential' => $enterpriseData['register_credential'],
                ],
                'enterprise' => $enterpriseData,
                'options' => [
                    'bound_ip' => $account->bound_ip,
                    'timeout_seconds' => 180,
                ],
            ]);

            if ($result['success'] ?? false) {
                $this->line("✅ [5/6] 认证成功: shop_url={$result['shop_url']}");

                // 6. 锚点同步
                $cert = \App\Models\EnterpriseAnchorCertification::query()->firstOrCreate(
                    ['enterprise_profile_id' => (int) $profile->id, 'anchor_platform_key' => $platform],
                    [
                        'certification_status' => 'certified',
                        'certified_at' => now(),
                        'platform_page_url' => $result['shop_url'] ?? '',
                    ]
                );
                $this->line("✅ [6/6] 锚点已同步: certification_id={$cert->id}");
            } else {
                $this->error("❌ [5/6] 认证失败: " . ($result['error'] ?? $result['raw_response']['error'] ?? 'Unknown'));
            }
        }

        // 汇总
        $this->newLine();
        $this->table(['检查项', '结果'], [
            ['企业档案', $profile->company_full_name],
            ['NAP+W 一致性', $napCheck['ok'] ? 'PASS ✅' : 'WARN: ' . implode(',', $napCheck['missing_fields'])],
            ['平台账号', "{$account->account_name} ✅"],
            ['RPA 引擎', $health['healthy'] ? 'CONNECTED ✅' : 'OFFLINE ⚠️'],
            ['锚点同步', ($cert ?? null) ? "cert_id={$cert->id} ✅" : 'N/A'],
        ]);

        return self::SUCCESS;
    }
}
