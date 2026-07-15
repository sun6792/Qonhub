@extends('admin.layouts.app')
@section('content')
<div class="p-6 max-w-4xl mx-auto">
  <div class="flex items-center justify-between mb-6">
    <div>
      <a href="{{ route('admin.agents.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800">← 返回列表</a>
      <h2 class="text-2xl font-bold text-gray-800 mt-1">工作流 #{{ $execution->id }}</h2>
    </div>
    @if($execution->isFailed())
      <form action="{{ route('admin.agents.retry', $execution->id) }}" method="POST" class="inline">
        @csrf
        <button type="submit" class="px-4 py-2 bg-orange-600 text-white rounded-lg text-sm font-semibold hover:bg-orange-700">重新启动</button>
      </form>
    @endif
  </div>

  {{-- 状态时间线 --}}
  @php
    $stages = [
      'idle' => '等待中',
      'scouting' => '侦察中',
      'planning' => '策略规划',
      'writing' => '内容生产',
      'deploying' => '分发执行',
      'reviewing' => '复盘分析',
      'completed' => '已完成',
    ];
  @endphp
  <div class="bg-white rounded-xl p-6 shadow-sm border border-gray-200 mb-6">
    <h3 class="font-semibold text-gray-800 mb-4">工作流状态</h3>
    <div class="flex items-center gap-2 flex-wrap">
      @foreach($stages as $state => $label)
        @php
          $stateOrder = array_search($state, array_keys($stages));
          $currentOrder = array_search($execution->current_state, array_keys($stages));
          $isDone = $stateOrder < $currentOrder;
          $isCurrent = $state === $execution->current_state;
          $isFailed = $execution->isFailed() && $stateOrder === $currentOrder;
          $colorClass = $isDone ? 'bg-green-500' : ($isCurrent ? ($isFailed ? 'bg-red-500' : 'bg-indigo-500') : 'bg-gray-300');
        @endphp
        <div class="flex items-center">
          <div class="w-3 h-3 rounded-full {{ $colorClass }}"></div>
          <span class="ml-2 text-xs {{ $isCurrent ? 'font-bold text-indigo-700' : 'text-gray-500' }}">{{ $label }}</span>
        </div>
        @if(!$loop->last)
          <div class="w-6 h-px bg-gray-300"></div>
        @endif
      @endforeach
    </div>
  </div>

  {{-- 详细信息 --}}
  <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200">
      <h4 class="font-semibold text-gray-700 mb-2">基本信息</h4>
      <dl class="text-sm space-y-2">
        <div class="flex justify-between"><dt class="text-gray-500">工作空间</dt><dd>{{ $execution->workspace->name ?? 'N/A' }}</dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">当前状态</dt><dd class="font-medium">{{ $stateLabels[$execution->current_state]['label'] ?? $execution->current_state }}</dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">触发方式</dt><dd>{{ $execution->trigger_type === 'manual' ? '手动' : '自动' }}</dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">重试次数</dt><dd>{{ $execution->retry_count }} / {{ $execution->max_retries }}</dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">开始时间</dt><dd>{{ $execution->started_at?->format('Y-m-d H:i:s') ?? '-' }}</dd></div>
        <div class="flex justify-between"><dt class="text-gray-500">完成时间</dt><dd>{{ $execution->completed_at?->format('Y-m-d H:i:s') ?? '-' }}</dd></div>
      </dl>
    </div>

    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-200">
      <h4 class="font-semibold text-gray-700 mb-2">各阶段输出</h4>
      <dl class="text-sm space-y-2">
        @foreach(['scout','strategy','content','deploy','review'] as $agent)
          @php $output = $execution->{$agent.'_output'}; @endphp
          <div>
            <dt class="text-gray-500">{{ ucfirst($agent) }}</dt>
            <dd class="text-xs text-gray-600 truncate max-w-xs">
              @if($output)
                {{ is_array($output) ? json_encode(array_keys($output)) : substr((string)$output, 0, 80) }}
              @else
                <span class="text-gray-300">-</span>
              @endif
            </dd>
          </div>
        @endforeach
      </dl>
    </div>
  </div>

  {{-- 错误信息 --}}
  @if($execution->error_data)
    <div class="bg-red-50 rounded-xl p-4 border border-red-200 mb-6">
      <h4 class="font-semibold text-red-800 mb-2">错误信息</h4>
      <pre class="text-sm text-red-600 whitespace-pre-wrap">{{ json_encode($execution->error_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
    </div>
  @endif
</div>
@stop
