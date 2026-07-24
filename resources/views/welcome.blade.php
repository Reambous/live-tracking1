<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Live Tracking Kereta Api</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="map"></div>

    <div id="info-panel">
        <h1>Live Tracking Kereta Api</h1>
        <div id="train-list"></div>
    </div>

    <script>
        window._apiBaseUrl = '{{ url('/api') }}';
    </script>
</body>
</html>
