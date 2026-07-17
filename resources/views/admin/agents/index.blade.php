@extends('admin.layouts.app')
@section('content')
<div class="p-6">
  <div class="flex justify-between items-center mb-6">
    <div>
      <h2 class="text-2xl font-bold text-gray-800">智能体工作流</h2>
      <p class="text-sm text-gray-500 mt-1">v2.6.0 — 五智能体全链路自动化</p>
    </div>
    <div class="flex gap-2">
      <span class="px-3 py-1.5 bg-indigo-100 text-indigo-700 rounded-lg text-sm font-medium">
        {{ $toolCount }} 个已注册工具
      </span>
    </div>
  </div>

  {{-- 统计卡片 --}}
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200">
      <span class="text-2xl font-bold text-gray-800">{{ $stats['total_executions'] }}</span>
      <p class="text-sm text-gray-500">总执行次数</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200">
      <span class="text-2xl font-bold text-green-600">{{ $stats['completed'] }}</span>
      <p class="text-sm text-gray-500">已完成</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200">
      <span class="text-2xl font-bold text-blue-600">{{ $stats['in_progress'] }}</span>
      <p class="text-sm text-gray-500">进行中</p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200">
      <span class="text-2xl font-bold text-red-600">{{ $stats['failed'] }}</span>
      <p class="text-sm text-gray-500">失败</p>
    </div>
  </div>

  {{-- 启动工作流 --}}
  <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 mb-6">
    <h3 class="text-lg font-semibold text-gray-800 mb-4">启动新工作流</h3>
    <form action="{{ route('admin.agents.start') }}" method="POST">
      @csrf
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">工作空间 *</label>
          <select name="workspace_id" id="wsSelect" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <option value="">选择工作空间</option>
            @foreach($workspaces as $ws)
              <option value="{{ $ws->id }}" data-name="{{ $ws->name }}">{{ $ws->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">品牌名称</label>
          <input type="text" name="brand_name" id="brandName" maxlength="100" placeholder="选择工作空间后自动填充" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">
            关键词（一行一个或用逗号分隔）
            <span id="kwSource" class="text-xs text-gray-400 ml-2"></span>
            <span id="kwLoading" class="text-xs text-indigo-500 ml-2 hidden">⏳ 智能提取中...</span>
          </label>
          <textarea name="keywords" id="kwInput" rows="3" placeholder="选择工作空间后，系统将自动从知识库和关键词库提取关键词..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">关联任务ID（可选）</label>
          <input type="number" name="task_id" min="1" placeholder="可选" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">内容数量</label>
          <input type="number" name="content_count" value="3" min="1" max="10" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
        </div>
      </div>
      <button type="submit" class="mt-4 px-6 py-2.5 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 transition">
        🚀 启动智能体工作流
      </button>
      <p class="text-xs text-gray-400 mt-3">
        ⚠️ 启动前请确保已通过
        <a href="{{ config('geoflow.rpa_engine_url', 'http://127.0.0.1:9901') }}" target="_blank" class="text-indigo-500 hover:underline font-medium">🖥️ 运营助手 (RPA)</a>
        完成平台授权登录，否则分发步骤将超时
      </p>
    </form>
  </div>

  {{-- 最近执行 --}}
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100">
      <h3 class="text-lg font-semibold text-gray-800">最近执行记录</h3>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500">
          <tr>
            <th class="text-left px-6 py-3">ID</th>
            <th class="text-left px-6 py-3">工作空间</th>
            <th class="text-left px-6 py-3">状态</th>
            <th class="text-left px-6 py-3">当前阶段</th>
            <th class="text-left px-6 py-3">触发方式</th>
            <th class="text-left px-6 py-3">时间</th>
            <th class="text-left px-6 py-3">操作</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          @forelse($recentExecutions as $exec)
            @php $stateColors = ['idle'=>'text-gray-500','scouting'=>'text-blue-600','planning'=>'text-indigo-600','writing'=>'text-purple-600','deploying'=>'text-orange-600','reviewing'=>'text-teal-600','completed'=>'text-green-600','failed'=>'text-red-600']; @endphp
            <tr class="hover:bg-gray-50">
              <td class="px-6 py-3">#{{ $exec->id }}</td>
              <td class="px-6 py-3">{{ $exec->workspace->name ?? 'N/A' }}</td>
              <td class="px-6 py-3">
                <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $stateColors[$exec->current_state] ?? 'text-gray-500' }} bg-{{ $exec->current_state === 'completed' ? 'green' : ($exec->current_state === 'failed' ? 'red' : 'gray') }}-100">
                  {{ $exec->current_state }}
                </span>
              </td>
              <td class="px-6 py-3 text-gray-500">{{ $exec->current_agent ?? '-' }}</td>
              <td class="px-6 py-3 text-gray-500">{{ $exec->trigger_type === 'manual' ? '手动' : '自动' }}</td>
              <td class="px-6 py-3 text-gray-500">{{ $exec->created_at->format('m-d H:i') }}</td>
              <td class="px-6 py-3">
                <a href="{{ route('admin.agents.show', $exec->id) }}" class="text-indigo-600 hover:text-indigo-800">详情</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="px-6 py-8 text-center text-gray-400">暂无执行记录</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
const wsSelect = document.getElementById('wsSelect');
const kwInput = document.getElementById('kwInput');
const brandInput = document.getElementById('brandName');
const kwSource = document.getElementById('kwSource');
const kwLoading = document.getElementById('kwLoading');

wsSelect.addEventListener('change', async function() {
    const wsId = this.value;
    if (!wsId) {
        kwInput.value = '';
        brandInput.value = '';
        kwSource.textContent = '';
        return;
    }

    kwLoading.classList.remove('hidden');
    kwSource.textContent = '';
    kwInput.value = '';
    kwInput.placeholder = '正在从知识库和关键词库智能提取...';

    try {
        const resp = await fetch(`/geo_admin/agents/suggest-keywords/${wsId}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        if (!resp.ok) {
            throw new Error('HTTP ' + resp.status);
        }
        const data = await resp.json();

        if (data.ok && data.keywords.length > 0) {
            kwInput.value = data.keywords.join('\n');
            kwSource.textContent = `✅ 已从${data.sources.join('、')}提取 ${data.keywords.length} 个关键词`;
            kwSource.className = 'text-xs text-green-500 ml-2';
            if (data.brand_name) {
                brandInput.value = data.brand_name;
            }
        } else {
            kwInput.placeholder = '未找到关联的关键词库或知识库，请手动输入';
            kwSource.textContent = '⚠️ 暂无自动提取数据，请手动输入';
            kwSource.className = 'text-xs text-amber-500 ml-2';
            // 至少用客户名做品牌词
            const selected = wsSelect.options[wsSelect.selectedIndex];
            if (selected && selected.dataset.name) {
                brandInput.value = selected.dataset.name;
            }
        }
    } catch(e) {
        console.error('关键词提取失败:', e);
        kwInput.placeholder = '提取失败，请手动输入关键词';
        kwSource.textContent = '❌ 提取失败: ' + (e.message || '网络错误');
        kwSource.className = 'text-xs text-red-500 ml-2';
    } finally {
        kwLoading.classList.add('hidden');
    }
});
</script>
@stop
