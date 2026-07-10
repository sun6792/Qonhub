@extends('client.layout')

@section('content')
<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
    <h3 class="font-bold text-lg mb-4">🤖 AI引用可见度报告</h3>
    <p class="text-gray-500 text-sm mb-4">本报告展示您的品牌在国内主流AI大模型中的引用情况。数据每日凌晨自动更新。</p>

    @php $scores = $visibilityData['latest_scores'] ?? []; @endphp
    @php $platforms = \App\Services\GeoFlow\AiVisibilityService::AI_PLATFORMS; @endphp
    @if (!empty($scores))
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 mb-6">
        @foreach ($platforms as $key => $info)
        @php $data = $scores[$key] ?? ['score' => 0, 'trend' => 'new', 'mentioned' => 0]; @endphp
        <div class="border-2 rounded-xl p-4 text-center {{ $data['score'] > 50 ? 'border-green-200 bg-green-50' : ($data['score'] > 20 ? 'border-yellow-200 bg-yellow-50' : 'border-gray-200 bg-gray-50') }}">
            <div class="text-2xl font-bold {{ $data['score'] > 50 ? 'text-green-600' : ($data['score'] > 20 ? 'text-yellow-600' : 'text-gray-500') }}">
                {{ $data['score'] }}%
            </div>
            <div class="text-xs text-gray-500">可见度</div>
            <div class="text-xs mt-1">
                @if ($data['trend'] === 'up') <span class="text-green-600">↗ 提升</span>
                @elseif ($data['trend'] === 'down') <span class="text-red-600">↘ 下降</span>
                @elseif ($data['trend'] === 'flat') <span class="text-gray-500">→ 持平</span>
                @else <span class="text-blue-500">🆕 新</span>
                @endif
            </div>
            <div class="font-medium text-sm mt-2">{{ $info['icon'] }} {{ $info['name'] }}</div>
            <div class="text-xs text-gray-400">提及: {{ $data['mentioned'] }} 次</div>
        </div>
        @endforeach
    </div>
    @else
    <div class="text-center py-8 text-gray-400">
        <p class="text-4xl mb-3">🔍</p>
        <p>AI引用数据正在收集中</p>
        <p class="text-sm mt-1">系统将在每日凌晨自动检测，请明天再来查看</p>
    </div>
    @endif
</div>

<!-- 趋势说明 -->
<div class="bg-white rounded-xl shadow-sm p-5 mb-6">
    <h3 class="font-bold text-lg mb-4">📊 各平台趋势</h3>
    @php $trends = $visibilityData['trends'] ?? []; @endphp
    @if (!empty($trends))
    <div class="space-y-4">
        @foreach ($platforms as $platform => $info)
        @php $dataPoints = $trends[$platform] ?? []; @endphp
        <div class="border rounded-lg p-4">
            <div class="font-medium text-sm mb-2">{{ $info['icon'] }} {{ $info['name'] }}</div>
            <div class="flex items-end space-x-1 h-16">
                @php $showItems = array_slice($dataPoints, -14); @endphp
                @foreach ($showItems as $point)
                @php $h = max(4, ($point['total'] > 0 ? ($point['mentioned'] / $point['total']) * 100 : 0)); @endphp
                <div class="flex-1 {{ $point['mentioned'] > 0 ? 'bg-' . str_replace('#', '', $info['color']) : 'bg-gray-200' }} rounded-t"
                     style="height: {{ $h }}%; background-color: {{ $point['mentioned'] > 0 ? $info['color'] : '#e5e7eb' }}"
                     title="{{ $point['date'] }}: {{ $point['mentioned'] }}/{{ $point['total'] }}"></div>
                @endforeach
            </div>
            <div class="text-xs text-gray-400 mt-1">近14天趋势</div>
        </div>
        @endforeach
    </div>
    @else
    <div class="text-center py-6 text-gray-400">趋势数据将在积累足够数据后显示</div>
    @endif
</div>

<!-- 如何提升AI可见度 -->
<div class="bg-blue-50 rounded-xl p-5">
    <h3 class="font-bold text-lg mb-3 text-blue-800">💡 如何提升AI可见度？</h3>
    <ul class="space-y-2 text-sm text-blue-700">
        <li>✅ <strong>标题用问句</strong>：AI用户99%用问句提问，标题匹配问句形式更容易被引用</li>
        <li>✅ <strong>文中加FAQ</strong>：Q&A格式是AI最喜欢直接搬运的内容</li>
        <li>✅ <strong>数据加来源</strong>：每个数据后面标注来源，AI引用权重提升115%</li>
        <li>✅ <strong>结论前置</strong>：段落第一句话就说核心结论，AI截取前40-60字作为答案</li>
        <li>✅ <strong>多平台分发</strong>：覆盖知乎、头条、百家号等高引用率平台</li>
    </ul>
</div>
@endsection
