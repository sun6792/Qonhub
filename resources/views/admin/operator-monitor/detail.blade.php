@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <a href="{{ route('admin.operator-monitor.index') }}" class="text-muted text-decoration-none">← 运营监控台</a>
    <h1 class="h3 mt-1 mb-4">{{ $operator->name }} - 运营详情</h1>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><strong>基本信息</strong></div>
                <div class="card-body">
                    <p><strong>姓名:</strong> {{ $operator->name }}</p>
                    <p><strong>邮箱:</strong> {{ $operator->email ?? '-' }}</p>
                    <p><strong>角色:</strong> {{ $operator->isSuperAdmin() ? '超级管理员' : '运营人员' }}</p>
                    <p><strong>状态:</strong> {{ $operator->status === 'active' ? '活跃' : '禁用' }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header"><strong>汇总统计</strong></div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <h4>{{ $workspaces->count() }}</h4>
                            <small class="text-muted">负责空间</small>
                        </div>
                        <div class="col-4">
                            <h4>{{ $workspaces->sum('task_count') }}</h4>
                            <small class="text-muted">关联任务</small>
                        </div>
                        <div class="col-4">
                            <h4>{{ $workspaces->sum('article_count') }}</h4>
                            <small class="text-muted">关联文章</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>工作空间列表</strong></div>
        <div class="card-body p-0">
            @if ($workspaces->isNotEmpty())
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>名称</th>
                        <th>Slug</th>
                        <th>状态</th>
                        <th>任务</th>
                        <th>文章</th>
                        <th>最近活动</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($workspaces as $ws)
                    <tr>
                        <td><strong>{{ $ws->name }}</strong></td>
                        <td><code>{{ $ws->slug }}</code></td>
                        <td>
                            <span class="badge bg-{{ $ws->status === 'active' ? 'success' : ($ws->status === 'paused' ? 'warning' : 'secondary') }}">
                                {{ $ws->status }}
                            </span>
                        </td>
                        <td>{{ $ws->task_count }}</td>
                        <td>{{ $ws->article_count }}</td>
                        <td class="text-muted small">{{ $ws->last_activity_at?->diffForHumans() ?? '-' }}</td>
                        <td>
                            <a href="{{ route('admin.workspaces.show', $ws->slug) }}" class="btn btn-sm btn-outline-primary">查看</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div class="text-center py-4 text-muted">暂无工作空间</div>
            @endif
        </div>
    </div>
</div>
@endsection
