import L from 'leaflet';

const API_URL = window._apiBaseUrl + '/active-trains';

const map = L.map('map', {
    center: [-7.5, 110.0],
    zoom: 8,
    zoomControl: true,
});

L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/">CARTO</a>',
    maxZoom: 18,
}).addTo(map);

let allTrains = [];
let currentRoute = 'all';
let searchQuery = '';
let simulatedMinutes = 8 * 60;
let speedMultiplier = 5;
let simulationInterval = null;
let isSliderDragging = false;


const trainMarkers = {};
const trainPolylines = {};
const animState = {};
const iconCache = {};

const timeSlider = document.getElementById('timeSlider');
const digitalClock = document.getElementById('digitalClock');
const speedBtns = document.querySelectorAll('.speed-btn');
const filterTabs = document.querySelectorAll('.filter-tab');
const searchInput = document.getElementById('searchInput');
const trainListContainer = document.getElementById('train-list-container');

function formatMinutesToTime(minutes) {
    const h = Math.floor(minutes / 60) % 24;
    const m = minutes % 60;
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
}

function updateClock() {
    digitalClock.textContent = formatMinutesToTime(simulatedMinutes);
}

function getSimulatedTime() {
    const h = Math.floor(simulatedMinutes / 60) % 24;
    const m = simulatedMinutes % 60;
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':00';
}

function getOrCreateIcon(status) {
    if (!iconCache[status]) {
        iconCache[status] = L.divIcon({
            className: 'train-marker-wrapper',
            html: `<div class="train-marker-dot ${status}"></div>`,
            iconSize: [14, 14],
            iconAnchor: [7, 7],
            popupAnchor: [0, -10],
        });
    }
    return iconCache[status];
}

function formatTime(timeStr) {
    if (!timeStr) return '-';
    return timeStr.substring(0, 5);
}

const statusLabels = {
    idle: 'Menunggu',
    stopped: 'Berhenti',
    approaching: 'Mendekati',
    departing: 'Lepas Stasiun',
    completed: 'Selesai',
};

const routeLabels = {
    utara: 'Jalur Utara',
    tengah: 'Jalur Tengah',
    selatan: 'Jalur Selatan',
};

const routeColors = {
    utara: '#3b82f6',
    tengah: '#8b5cf6',
    selatan: '#10b981',
};

function updateStatsAndCounts(trains) {
    let idle = 0, stopped = 0, approaching = 0, departing = 0, completed = 0;
    let utara = 0, tengah = 0, selatan = 0;
    for (const t of trains) {
        if (t.status === 'idle') idle++;
        else if (t.status === 'stopped') stopped++;
        else if (t.status === 'approaching') approaching++;
        else if (t.status === 'departing') departing++;
        else if (t.status === 'completed') completed++;
    }
    for (const t of allTrains) {
        if (t.route === 'utara') utara++;
        else if (t.route === 'tengah') tengah++;
        else if (t.route === 'selatan') selatan++;
    }
    document.getElementById('statIdle').textContent = idle;
    document.getElementById('statDeparting').textContent = departing;
    document.getElementById('statApproaching').textContent = approaching;
    document.getElementById('statStopped').textContent = stopped;
    document.getElementById('statCompleted').textContent = completed;

    document.getElementById('countAll').textContent = all > 0 ? `(${all})` : '';
    document.getElementById('countUtara').textContent = utara > 0 ? `(${utara})` : '';
    document.getElementById('countTengah').textContent = tengah > 0 ? `(${tengah})` : '';
    document.getElementById('countSelatan').textContent = selatan > 0 ? `(${selatan})` : '';
}

function matchesSearch(train) {
    if (!searchQuery) return true;
    const q = searchQuery.toLowerCase();
    return train.name.toLowerCase().includes(q) || train.train_code.toLowerCase().includes(q);
}

function getFilteredTrains() {
    let result = allTrains;
    if (currentRoute !== 'all') result = result.filter(t => t.route === currentRoute);
    if (searchQuery) result = result.filter(matchesSearch);
    return result;
}

function renderTrainCards(trains) {
    if (trains.length === 0) {
        trainListContainer.innerHTML = '<div class="no-trains"><div class="no-trains-icon">🚆</div>Tidak ada kereta aktif</div>';
        return;
    }

    let html = '';
    for (const train of trains) {
        const routeCls = train.route || 'tengah';
        html += `
            <div class="train-card" data-train-id="${train.id}">
                <div class="train-card-header">
                    <div>
                        <div class="train-card-name">${highlightMatch(train.name)}</div>
                        <div class="train-card-code">${highlightMatch(train.train_code)}</div>
                    </div>
                    <div class="train-card-status">
                        <span class="status-dot ${train.status}"></span>
                        <span class="status-label ${train.status}">${statusLabels[train.status]}</span>
                    </div>
                </div>
                <div class="train-card-route">
                    <span class="route-badge ${routeCls}">${routeLabels[routeCls]}</span>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" style="width:${train.progress}%"></div>
                </div>
                <div class="train-card-meta">
                    <span>Progress <span class="meta-value">${train.progress}%</span></span>
                    <span>⏱ <span class="meta-value">${train.speed}</span> km/h</span>
                    <span>📡 <span class="meta-value">${train.gps_accuracy}</span>%</span>
                </div>
                <div class="train-card-meta" style="margin-top:4px;padding-top:4px;border-top:1px solid #334155;">
                    <span>${train.prev_station || '-'}</span>
                    <span style="color:#475569;">→</span>
                    <span><strong style="color:#f1f5f9;">${train.next_station || '-'}</strong> ${train.next_arrival ? '(' + formatTime(train.next_arrival) + ')' : ''}</span>
                </div>
            </div>
        `;
    }
    trainListContainer.innerHTML = html;
}

function highlightMatch(text) {
    if (!searchQuery || !text) return text || '';
    const q = searchQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const regex = new RegExp(`(${q})`, 'gi');
    return text.replace(regex, '<mark style="background:#3b82f6;color:#fff;border-radius:2px;padding:0 2px;">$1</mark>');
}

function updateMapPolylines(trains) {
    const activeIds = new Set(trains.map(t => t.id));

    for (const [id, polyline] of Object.entries(trainPolylines)) {
        if (!activeIds.has(Number(id))) {
            map.removeLayer(polyline);
            delete trainPolylines[id];
        }
    }

    for (const train of trains) {
        if (!train.path || train.path.length < 2) continue;
        if (trainPolylines[train.id]) {
            trainPolylines[train.id].setLatLngs(train.path);
        } else {
            trainPolylines[train.id] = L.polyline(train.path, {
                color: routeColors[train.route] || '#3b82f6',
                weight: 2,
                opacity: 0.25,
                smoothFactor: 1,
            }).addTo(map);
        }
    }
}

function updateMapMarkers(trains) {
    const activeIds = new Set(trains.map(t => t.id));

    for (const [id, marker] of Object.entries(trainMarkers)) {
        if (!activeIds.has(Number(id))) {
            map.removeLayer(marker);
            delete trainMarkers[id];
            delete animState[id];
        }
    }

    const fetchIntervalMs = Math.max(200, Math.round(5000 / speedMultiplier));
    const animDuration = Math.min(fetchIntervalMs * 0.85, 4000);

    for (const train of trains) {
        const popupContent = `
            <div style="font-family:Inter,sans-serif;">
                <strong style="font-size:13px;">${train.name}</strong><br>
                <span style="color:#94a3b8;font-size:11px;">${train.train_code}</span>
                <div class="popup-meta">
                    <span>🚦 ${statusLabels[train.status]}</span>
                    <span>⏱ ${train.speed} km/h</span>
                    <span>📡 ${train.gps_accuracy}%</span>
                </div>
                <div style="margin-top:6px;font-size:11px;color:#94a3b8;">
                    ${train.prev_station || '-'} → <strong style="color:#f1f5f9;">${train.next_station || '-'}</strong>
                    ${train.next_arrival ? '<br>Jam tiba: ' + formatTime(train.next_arrival) : ''}
                </div>
            </div>
        `;

        if (trainMarkers[train.id]) {
            const marker = trainMarkers[train.id];
            const prevStatus = marker._trainStatus;

            animState[train.id] = {
                from: marker.getLatLng(),
                to: L.latLng(train.latitude, train.longitude),
                startTime: performance.now(),
                duration: animDuration,
            };

            marker.setPopupContent(popupContent);

            const el = marker.getElement();
            if (el) el.classList.remove('retiring');

            if (prevStatus !== train.status) {
                marker.setIcon(getOrCreateIcon(train.status));
                marker._trainStatus = train.status;
            }
        } else {
            const icon = getOrCreateIcon(train.status);
            const marker = L.marker([train.latitude, train.longitude], { icon }).addTo(map);
            marker.bindPopup(popupContent);
            marker._trainStatus = train.status;

            animState[train.id] = {
                from: L.latLng(train.latitude, train.longitude),
                to: L.latLng(train.latitude, train.longitude),
                startTime: performance.now(),
                duration: animDuration,
            };

            trainMarkers[train.id] = marker;
        }
    }
}

function animateMarkers(now) {
    let hasActive = false;

    for (const [id, state] of Object.entries(animState)) {
        const marker = trainMarkers[id];
        if (!marker) { delete animState[id]; continue; }

        const elapsed = now - state.startTime;
        const t = Math.min(elapsed / state.duration, 1);
        const ease = t < 0.5 ? 2 * t * t : -1 + (4 - 2 * t) * t;

        marker.setLatLng([
            state.from.lat + (state.to.lat - state.from.lat) * ease,
            state.from.lng + (state.to.lng - state.from.lng) * ease,
        ]);

        if (t < 1) hasActive = true;
    }

    if (hasActive) requestAnimationFrame(animateMarkers);
}

function updateUI() {
    const filtered = getFilteredTrains();
    updateStatsAndCounts(filtered);
    renderTrainCards(filtered);
    updateMapPolylines(filtered);
    updateMapMarkers(filtered);
    requestAnimationFrame(animateMarkers);
}

async function fetchActiveTrains() {
    try {
        const time = getSimulatedTime();
        const url = `${API_URL}?time=${encodeURIComponent(time)}&speed=${speedMultiplier}&_t=${Date.now()}`;
        const response = await fetch(url);
        const result = await response.json();

        if (result.success && result.data) {
            allTrains = result.data;
            updateUI();
        }
    } catch (error) {
        console.error('Gagal mengambil data kereta:', error);
    }
}

function startSimulation() {
    if (simulationInterval) clearInterval(simulationInterval);

    const intervalMs = Math.max(300, Math.round(5000 / speedMultiplier));

    simulationInterval = setInterval(() => {
        if (!isSliderDragging) {
            simulatedMinutes = (simulatedMinutes + 1) % 1440;
            updateClock();
            timeSlider.value = simulatedMinutes;
            fetchActiveTrains();
        }
    }, intervalMs);
}

function stopSimulation() {
    if (simulationInterval) {
        clearInterval(simulationInterval);
        simulationInterval = null;
    }
}

timeSlider.addEventListener('input', function () {
    isSliderDragging = true;
    simulatedMinutes = parseInt(this.value);
    updateClock();
    fetchActiveTrains();
});

timeSlider.addEventListener('change', function () {
    isSliderDragging = false;
    if (!simulationInterval) {
        document.querySelector('.speed-btn[data-speed="1"]').click();
    }
});

speedBtns.forEach(btn => {
    btn.addEventListener('click', function () {
        speedBtns.forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        speedMultiplier = parseInt(this.dataset.speed);
        isSliderDragging = false;
        startSimulation();
    });
});

filterTabs.forEach(tab => {
    tab.addEventListener('click', function () {
        filterTabs.forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        currentRoute = this.dataset.route;
        updateUI();
    });
});

searchInput.addEventListener('input', function () {
    searchQuery = this.value.trim();
    updateUI();
});

trainListContainer.addEventListener('click', function (e) {
    const card = e.target.closest('.train-card');
    if (card) {
        const trainId = parseInt(card.dataset.trainId);
        const train = allTrains.find(t => t.id === trainId);
        if (train && trainMarkers[trainId]) {
            map.setView([train.latitude, train.longitude], 11, { animate: true });
            setTimeout(() => {
                if (trainMarkers[trainId]) trainMarkers[trainId].openPopup();
            }, 400);
        }
    }
});

updateClock();
timeSlider.value = simulatedMinutes;
startSimulation();
