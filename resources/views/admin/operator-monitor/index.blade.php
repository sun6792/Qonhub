@extends('admin.layouts.app')

@section('content')
<div class="container-fluid">
    <h1 class="h3 mb-4">运营监控台</h1>

    <!-- 全局统计 -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center border-primary">
                <div class="card-body">
                    <h3 class="text-primary">{{ $globalStats['total_workspaces'] }}</h3>
                    <small class="text-muted">总工作空间</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-success">
                <div class="card-body">
                    <h3 class="text-success">{{ $globalStats['active_workspaces'] }}</h3>
                    <small class="text-muted">活跃空间</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-info">
                <div class="card-body">
                    <h3 class="text-info">{{ $globalStats['total_operators'] }}</h3>
                    <small class="text-muted">运营人员</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-warning">
                <div class="card-body">
                    <h3 class="text-warning">{{ $globalStats['total_articles'] }}</h3>
                    <small class="text-muted">已发布文章</small>
                </div>
            </div>
        </div>
    </div>

    <!-- 运营人员列表 -->
    @foreach ($operators as $op)
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>
                {{ $op['name'] }}
                @if ($op['is_super'])
                <span class="badge bg-danger">超管</span>
                @endif
                <small class="text-muted ms-2">{{ $op['email'] }}</small>
            </strong>
            <div>
                <span class="me-3">📁 {{ $op['workspace_count'] }} 个空间</span>
                <span class="me-3">📝 {{ $op['total_articles'] }} 篇文章</span>
                <span>⚙️ {{ $op['active_tasks'] }} 个任务</span>
                <a href="{{ route('admin.operator-monitor.detail', $op['id']) }}" class="btn btn-sm btn-outline-primary ms-3">详情</a>
            </div>
        </div>
        <div class="card-body p-0">
            @if (!empty($op['workspaces']))
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>工作空间</th>
                        <th>状态</th>
                        <th>任务</th>
                        <th>文章</th>
                        <th>最近活动</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($op['workspaces'] as $ws)
                    <tr>
                        <td>{{ $ws['name'] }}</td>
                        <td>
                            <span class="badge bg-{{ $ws['status'] === 'active' ? 'success' : ($ws['status'] === 'paused' ? 'warning' : 'secondary') }}">
                                {{ $ws['status'] === 'active' ? '活跃' : ($ws['status'] === 'paused' ? '暂停' : '归档') }}
                            </span>
                        </td>
                        <td>{{ $ws['task_count'] }}</td>
                        <td>{{ $ws['article_count'] }}</td>
                        <td class="text-muted small">{{ $ws['last_activity'] }}</td>
                        <td>
                            <a href="{{ route('admin.workspaces.show', $ws['slug']) }}" class="btn btn-sm btn-outline-secondary">查看</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @else
            <div class="text-center py-3 text-muted">暂无分配的工作空间</div>
            @endif
        </div>
    </div>
    @endforeach
</div>
@endsection
