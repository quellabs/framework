<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'Default Title')</title>
</head>
<body>

    {{-- Layout header --}}
    <header>
        <p>Site: {{ $site_name }} (global variable)</p>
    </header>

    {{-- Page content is injected here by child templates --}}
    <main>
        @yield('content')
    </main>

    <footer>
        @yield('footer', 'Default footer')
    </footer>

</body>
</html>