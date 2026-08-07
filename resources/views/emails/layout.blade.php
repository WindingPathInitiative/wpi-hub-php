<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
</head>
<body style="font-family: -apple-system, Segoe UI, Helvetica, Arial, sans-serif; line-height: 1.5; color: #1c1c1e;">
    <h1 style="font-size: 18px; margin: 0 0 4px;">@yield('heading')</h1>
    <p style="margin: 0 0 18px; color: #666; font-size: 13px;">@yield('subheading')</p>

    @yield('body')

    <p style="margin: 24px 0 0; color: #666; font-size: 13px;">
        @yield('footer')
    </p>
</body>
</html>
