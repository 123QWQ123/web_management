<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Web Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #00a72c;
            --primary-green-dark: #008823;
            --primary-green-light: #00c936;
            --bg-main: #f8f9fa;
            --bg-card: #ffffff;
            --text-primary: #1a1a1a;
            --text-secondary: #6c757d;
            --border-color: #e9ecef;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.07);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        body {
            background: var(--bg-main);
            color: var(--text-primary);
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 260px;
            background: linear-gradient(180deg, #00a72c 0%, #008823 100%);
            padding: 2rem 0;
            box-shadow: var(--shadow-lg);
            z-index: 1000;
        }
        
        .sidebar-brand {
            padding: 0 1.5rem;
            margin-bottom: 3rem;
        }
        
        .sidebar-brand h3 {
            color: white;
            font-weight: 700;
            font-size: 1.5rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .sidebar-brand-icon {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .sidebar-nav {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-nav-item {
            margin: 0.25rem 1rem;
        }
        
        .sidebar-nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .sidebar-nav-link:hover,
        .sidebar-nav-link.active {
            background: rgba(255,255,255,0.15);
            color: white;
        }
        
        .sidebar-nav-icon {
            font-size: 1.25rem;
            width: 24px;
            text-align: center;
        }
        
        /* Main content */
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            padding: 2rem;
        }
        
        /* Header */
        .page-header {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        
        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
            color: var(--text-primary);
        }
        
        .page-header p {
            color: var(--text-secondary);
            margin: 0;
            font-size: 0.95rem;
        }
        
        /* Buttons */
        .btn-primary {
            background: var(--primary-green) !important;
            border-color: var(--primary-green) !important;
            font-weight: 600;
            padding: 0.65rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,167,44,0.2);
            transition: all 0.2s;
        }
        
        .btn-primary:hover {
            background: var(--primary-green-dark) !important;
            border-color: var(--primary-green-dark) !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,167,44,0.25);
        }
        
        .btn-success {
            background: var(--primary-green) !important;
            border-color: var(--primary-green) !important;
        }
        
        /* Cards & Tables */
        .card {
            background: var(--bg-card);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead {
            background: var(--bg-main);
        }
        
        .table thead th {
            border-bottom: 2px solid var(--border-color);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            padding: 1rem;
        }
        
        .table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: all 0.2s;
        }
        
        .table tbody tr:hover {
            background: #f8fdf9;
        }
        
        .table tbody td {
            padding: 1.25rem 1rem;
            vertical-align: middle;
        }
        
        /* Badges */
        .badge {
            font-weight: 600;
            padding: 0.4em 0.8em;
            border-radius: 6px;
            font-size: 0.8rem;
        }
        
        .badge.bg-success {
            background: var(--primary-green) !important;
        }
        
        /* Alerts */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 1rem 1.25rem;
        }
        
        .alert-success {
            background: #e6f7ec;
            color: var(--primary-green-dark);
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-main);
        }
        
        ::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
    </style>
</head>
<body>
<div class="sidebar">
    <div class="sidebar-brand">
        <h3>
            <span class="sidebar-brand-icon">🌐</span>
            <span>Web Mgmt</span>
        </h3>
    </div>
    <ul class="sidebar-nav">
        <li class="sidebar-nav-item">
            <a class="sidebar-nav-link {{ request()->routeIs('admin.domains.*') ? 'active' : '' }}" href="{{ route('admin.domains.index') }}">
                <span class="sidebar-nav-icon">📋</span>
                <span>Домены</span>
            </a>
        </li>
        <li class="sidebar-nav-item">
            <a class="sidebar-nav-link" href="{{ route('admin.domains.create') }}">
                <span class="sidebar-nav-icon">➕</span>
                <span>Добавить домен</span>
            </a>
        </li>
        <li class="sidebar-nav-item">
            <a class="sidebar-nav-link {{ request()->routeIs('admin.settings.*') ? 'active' : '' }}" href="{{ route('admin.settings.index') }}">
                <span class="sidebar-nav-icon">⚙️</span>
                <span>Настройки</span>
            </a>
        </li>
    </ul>
</div>

<div class="main-content">
    @yield('content')
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
