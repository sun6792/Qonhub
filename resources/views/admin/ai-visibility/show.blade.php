@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <a href="{{ route('admin.ai-visibility.index') }}" class="text-muted text-decoration-none">← AI引用追踪</a>
    <div class="d-flex justify-content-between align-items-center mt-1 mb-4">
        <h1 class="h3">{{ $workspace->name }} - AI引用报告</h1>
        <form action="{{ route('admin.ai-visibility.check') }}" method="POST">
            @csrf
            <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
            <button class="btn btn-primary">立即检测</button>
        </form>
    </div>

    @php $scores = $visibilityData['latest_scores'] ?? []; @endphp

    <div class="row g-3 mb-4">
        @foreach ($platforms as $key => $info)
        @php $data = $scores[$key] ?? ['score' => 0, 'trend' => 'new', 'mentioned' => 0]; @endphp
        <div class="col-md-4 col-lg-2">
            <div class="card text-center h-100">
                <div class="card-body">
                    <h3 class="{{ $data['score'] > 50 ? 'text-success' : ($data['score'] > 20 ? 'text-warning' : 'text-muted') }}">{{ $data['score'] }}%</h3>
                    <div class="fw-bold">{{ $info['icon'] }} {{ $info['name'] }}</div>
                    <small class="text-muted">提及 {{ $data['mentioned'] }} 次</small>
                    <div>
                        @if ($data['trend'] === 'up') <span class="badge bg-success">↗ 提升</span>
                        @elseif ($data['trend'] === 'down') <span class="badge bg-danger">↘ 下降</span>
                        @elseif ($data['trend'] === 'flat') <span class="badge bg-secondary">→ 持平</span>
                        @else <span class="badge bg-info">🆕 新建</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header"><strong>最近 30 条检测记录</strong></div>
        <div class="card-body p-0">
            @if ($recentChecks->isNotEmpty())
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>时间</th><th>平台</th><th>关键词</th><th>是否提及</th><th>内容片段</th></tr>
                </thead>
                <tbody>
                    @foreach ($recentChecks as $check)
                    <tr>
                        <td class="text-nowrap small">{{ $check->checked_at->format('m-d H:i') }}</td>
                        <td>{{ $platforms[$check->ai_platform]['icon'] ?? '🤖' }} {{ $check->ai_platform }}</td>
                        <td>{{ $check->query_keyword }}</td>
                        <td>
                            <span class="badge bg-{{ $check->mentioned ? 'success' : 'secondary' }}">
                                {{ $check->mentioned ? '✅ 是' : '❌ 否' }}
                            </span>
                        </td>
                        <td class="small text-muted">{{ $check->response_snippet ? Str::limit($check->response_snippet, 80) : '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div class="text-center py-4 text-muted">暂无检测记录，点击"立即检测"开始</div>
            @endif
        </div>
    </div>
</div>
@endsection
