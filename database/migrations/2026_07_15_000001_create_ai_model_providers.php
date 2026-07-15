<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.6.0 Phase 1: 模型供应商管理 + 租户 Token 额度
     */
    public function up(): void
    {
        // ── ai_model_providers: 模型供应商表 ──────────────────
        Schema::create('ai_model_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_code', 30)->unique();     // 'deepseek','qwen','ernie','volcengine','kimi','zhipu','siliconflow'
            $table->string('provider_name', 50);                // 显示名称
            $table->string('api_base_url', 500)->default('');   // API Base URL
            $table->string('adapter_class', 200)->default('');  // 对应 Adapter 类名
            $table->boolean('is_active')->default(true);
            $table->integer('failover_priority')->default(100); // 降级优先级
            $table->json('config_json')->nullable();             // 厂商特定配置
            $table->timestamps();
        });

        // ── ai_models: 新增 provider_id 外键 ──────────────────
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->bigInteger('provider_id')->nullable()->after('id')
                ->references('id')->on('ai_model_providers')->nullOnDelete();
        });

        // ── workspace_ai_token_quotas: 租户 Token 额度 ───────
        Schema::create('workspace_ai_token_quotas', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->string('provider_code', 30);                // 限制到哪个供应商
            $table->bigInteger('quota_monthly')->default(0);    // 月额度(token), 0=不限
            $table->bigInteger('used_this_month')->default(0);  // 本月已用
            $table->timestamp('reset_at')->useCurrent();        // 额度重置时间
            $table->timestamps();
            $table->unique(['workspace_id', 'provider_code'], 'waiq_ws_provider_unique');
        });

        // ── 预置默认供应商数据 ───────────────────────────────
        $now = now();
        $compatAdapter = 'App\\Services\\AI\\Adapters\\OpenAiCompatibleAdapter';
        $ernieAdapter = 'App\\Services\\AI\\Adapters\\ErnieQianfanAdapter';
        DB::table('ai_model_providers')->insert([
            [
                'provider_code' => 'deepseek',
                'provider_name' => 'DeepSeek',
                'api_base_url' => 'https://api.deepseek.com',
                'adapter_class' => $compatAdapter,
                'failover_priority' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider_code' => 'volcengine',
                'provider_name' => '火山方舟（豆包）',
                'api_base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
                'adapter_class' => $compatAdapter,
                'failover_priority' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider_code' => 'qwen',
                'provider_name' => '通义千问',
                'api_base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                'adapter_class' => $compatAdapter,
                'failover_priority' => 30,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider_code' => 'ernie',
                'provider_name' => '文心一言',
                'api_base_url' => 'https://aip.baidubce.com/rpc/2.0/ai_custom/v1/wenxinworkshop/chat',
                'adapter_class' => $ernieAdapter,
                'failover_priority' => 90,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider_code' => 'kimi',
                'provider_name' => 'Kimi (Moonshot)',
                'api_base_url' => 'https://api.moonshot.cn/v1',
                'adapter_class' => $compatAdapter,
                'failover_priority' => 40,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider_code' => 'zhipu',
                'provider_name' => '智谱 GLM',
                'api_base_url' => 'https://open.bigmodel.cn/api/paas/v4',
                'adapter_class' => $compatAdapter,
                'failover_priority' => 50,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'provider_code' => 'siliconflow',
                'provider_name' => '硅基流动',
                'api_base_url' => 'https://api.siliconflow.cn/v1',
                'adapter_class' => $compatAdapter,
                'failover_priority' => 60,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_ai_token_quotas');
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->dropForeign(['provider_id']);
            $table->dropColumn('provider_id');
        });
        Schema::dropIfExists('ai_model_providers');
    }
};
