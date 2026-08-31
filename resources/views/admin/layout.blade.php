<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SamChats - Admin Dashboard</title>
    @vite(['resources/css/admin.css'])
</head>
<body>

<div class="admin-layout">
    <aside class="sidebar">
        <div class="sidebar-header">
            <svg class="logo-icon" viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg" width="32" height="32">
                <rect width="56" height="56" rx="16" fill="#6366f1"/>
                <path d="M18 20C18 18.8954 18.8954 18 20 18H36C37.1046 18 38 18.8954 38 20V32C38 33.1046 37.1046 34 36 34H26L22 38V34H20C18.8954 34 18 33.1046 18 32V20Z" fill="#ffffff"/>
                <circle cx="25" cy="26" r="1.5" fill="#6366f1"/>
                <circle cx="31" cy="26" r="1.5" fill="#6366f1"/>
            </svg>
            <h2>AdminPanel</h2>
        </div>
        <nav class="sidebar-nav">
            <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('admin.users') }}" class="{{ request()->routeIs('admin.users') ? 'active' : '' }}">Users</a>
            <a href="{{ route('admin.tokens') }}" class="{{ request()->routeIs('admin.tokens') ? 'active' : '' }}">API Tokens</a>
            <a href="/app" class="btn-return">Back to App</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-title">
                @yield('header_title', 'Dashboard')
            </div>
            <div class="topbar-actions">
                <span class="admin-name">{{ auth()->user()->first_name }}</span>
            </div>
        </header>

        <div class="content-wrapper">
            @if(session('success'))
                <div class="alert alert-success">
                    {{ session('success') }}
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger">
                    {{ session('error') }}
                </div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger">
                    <ul style="margin: 0; padding-left: 1.5rem;">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</div>

</body>
</html>
