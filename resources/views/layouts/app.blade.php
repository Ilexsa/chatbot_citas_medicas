<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Fundasen — Panel') </title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="bg-gray-100 min-h-screen font-sans antialiased">

    <!-- Navbar -->
    <nav class="bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-green-600 flex items-center justify-center">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-3 3-3-3z"/>
                </svg>
            </div>
            <span class="font-semibold text-gray-800 text-lg">Fundasen Bot</span>
        </div>
        <div class="flex items-center gap-6 text-sm text-gray-600">
            <a href="/dashboard/costos" class="hover:text-green-600 transition-colors font-medium">Costos</a>
        </div>
    </nav>

    <!-- Contenido -->
    <main class="px-4 py-8 max-w-7xl mx-auto">
        @yield('content')
    </main>

</body>
</html>
