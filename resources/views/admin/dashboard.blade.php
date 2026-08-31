@extends('admin.layout')

@section('header_title', 'Dashboard')

@section('content')
<div class="stats-grid">
    <div class="stat-card">
        <h3>Total Users</h3>
        <div class="stat-value">{{ number_format($stats['total_users']) }}</div>
    </div>
    <div class="stat-card">
        <h3>Total Chats</h3>
        <div class="stat-value">{{ number_format($stats['total_chats']) }}</div>
    </div>
    <div class="stat-card">
        <h3>Total Messages</h3>
        <div class="stat-value">{{ number_format($stats['total_messages']) }}</div>
    </div>
</div>
@endsection
