<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Command Center - Live Tracking Kereta Api</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="sidebar">
        <div class="sidebar-header">
            <h1>Command Center</h1>
            <div class="digital-clock" id="digitalClock">00:00</div>
            <div class="clock-label">Waktu Simulasi</div>

            <div class="time-slider-container">
                <span style="font-size:10px;color:#475569;min-width:32px;">00:00</span>
                <input type="range" id="timeSlider" min="0" max="1439" value="480" step="1">
                <span style="font-size:10px;color:#475569;min-width:32px;text-align:right;">23:59</span>
            </div>

            <div class="speed-controls">
                <button class="speed-btn" data-speed="1">1x</button>
                <button class="speed-btn active" data-speed="5">5x</button>
                <button class="speed-btn" data-speed="10">10x</button>
                <button class="speed-btn" data-speed="30">30x</button>
                <button class="speed-btn" data-speed="60">60x</button>
            </div>
        </div>

        <div class="filter-tabs">
            <button class="filter-tab active" data-route="all">Semua</button>
            <button class="filter-tab" data-route="utara">Utara</button>
            <button class="filter-tab" data-route="tengah">Tengah</button>
            <button class="filter-tab" data-route="selatan">Selatan</button>
        </div>

        <div class="stats-row">
            <div class="stat-box stat-stopped">
                <div class="stat-value" id="statStopped">0</div>
                <div class="stat-label">Berhenti</div>
            </div>
            <div class="stat-box stat-approaching">
                <div class="stat-value" id="statApproaching">0</div>
                <div class="stat-label">Mendekati</div>
            </div>
            <div class="stat-box stat-departing">
                <div class="stat-value" id="statDeparting">0</div>
                <div class="stat-label">Lepas Stasiun</div>
            </div>
        </div>

        <div id="train-list-container"></div>
    </div>

    <div id="map"></div>

    <div class="map-legend">
        <h4>Legenda</h4>
        <div class="legend-item"><span class="legend-dot stopped"></span> Berhenti</div>
        <div class="legend-item"><span class="legend-dot approaching"></span> Mendekati</div>
        <div class="legend-item"><span class="legend-dot departing"></span> Lepas Stasiun</div>
    </div>

    <script>
        window._apiBaseUrl = '{{ url('/api') }}';
    </script>
</body>
</html>
