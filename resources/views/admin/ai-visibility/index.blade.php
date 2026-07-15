@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <h1 class="h3 mb-4">AI引用追踪</h1>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-primary">{{ $globalStats['total_workspaces'] }}</h4>
                    <small class="text-muted">监测工作空间</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h4 class="text-info">{{ $globalStats['total_articles'] }}</h4>
                    <small class="text-muted">今日总查询</small>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body d-flex align-items-center justify-content-between">
                    <div>
                        <strong>检测引擎:</strong>
                        @foreach (\App\Services\GeoFlow\AiVisibilityService::AI_PLATFORMS as $key => $info)
                        <a href="{{ $info['url'] ?? '#' }}" target="_blank" class="badge bg-light text-dark me-1 text-decoration-none">{{ $info['icon'] }} {{ $info['name'] }} ↗</a>
                        @endforeach
                    </div>
                    <form action="{{ route('admin.ai-visibility.check') }}" method="POST" class="d-flex gap-2">
                        @csrf
                        <select name="workspace_id" class="form-select form-select-sm">
                            <option value="">选择空间...</option>
                            @foreach ($workspaces as $ws)
                            <option value="{{ $ws->id }}">{{ $ws->name }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-primary btn-sm">立即检测</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>工作空间 AI 引用概览</strong></div>
        <div class="card-body p-0">
            @if ($workspaces->isNotEmpty())
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>工作空间</th>
                        <th>客户企业</th>
                        <th>最后检测</th>
                        <th>今日查询</th>
                        <th>今日提及</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($workspaces as $ws)
                    <tr>
                        <td><strong>{{ $ws->name }}</strong></td>
                        <td>{{ $ws->client_company_name ?? '-' }}</td>
                        <td>
                            @php
                                $lastCheck = \App\Models\AiVisibilityCheck::query()
                                    ->where('workspace_id', $ws->id)
                                    ->max('checked_at');
                            @endphp
                            {{ $lastCheck ? \Illuminate\Support\Carbon::parse($lastCheck)->format('m-d H:i') : '从未检测' }}
                        </td>
                        <td>
                            @php
                                $todayChecks = \App\Models\AiVisibilityCheck::query()
                                    ->where('workspace_id', $ws->id)->whereDate('checked_at', now())->count();
                            @endphp
                            {{ $todayChecks }}
                        </td>
                        <td>
                            @php
                                $todayMentioned = \App\Models\AiVisibilityCheck::query()
                                    ->where('workspace_id', $ws->id)->whereDate('checked_at', now())->where('mentioned', true)->count();
                            @endphp
                            <span class="badge bg-{{ $todayMentioned > 0 ? 'success' : 'secondary' }}">{{ $todayMentioned }}</span>
                        </td>
                        <td>
                            <a href="{{ route('admin.ai-visibility.show', $ws->id) }}" class="btn btn-sm btn-outline-primary">详情</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div class="text-center py-4 text-muted">暂无活跃工作空间</div>
            @endif
        </div>
    </div>
</div>
@endsection
