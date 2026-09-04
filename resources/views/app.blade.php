<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name', 'Label Extraction Agent') }}</title>
    {{-- Must come before @vite: @vitejs/plugin-react injects a React Refresh preamble in dev, and
         without it the entry module throws before anything mounts. Production builds ignore it. --}}
    @viteReactRefresh
    @vite(['resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="h-full bg-slate-50 text-slate-900 antialiased">
    @inertia
</body>
</html>
