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

const trainMarkers = {};
let allTrains = [];
let currentRoute = 'all';
let simulatedMinutes = 8 * 60;
let speedMultiplier = 5;
let isSimulationRunning = true;
let simulationInterval = null;
let lastFetchTime = Date.now();

const timeSlider = document.getElementById('timeSlider');
const digitalClock = document.getElementById('digitalClock');
const speedBtns = document.querySelectorAll('.speed-btn');
const filterTabs = document.querySelectorAll('.filter-tab');
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

function createTrainIcon(status) {
    const cls = status === 'stopped' ? 'stopped' : status === 'approaching' ? 'approaching' : 'departing';
    return L.divIcon({
        className: 'train-marker-wrapper',
        html: `<div class="train-marker-dot ${cls}"></div>`,
        iconSize: [14, 14],
        iconAnchor: [7, 7],
        popupAnchor: [0, -10],
    });
}

function formatTime(timeStr) {
    if (!timeStr) return '-';
    return timeStr.substring(0, 5);
}

const statusLabels = {
    stopped: 'Berhenti',
    approaching: 'Mendekati',
    departing: 'Lepas Stasiun',
};

const routeLabels = {
    utara: 'Jalur Utara',
    tengah: 'Jalur Tengah',
    selatan: 'Jalur Selatan',
};

function updateStats(trains) {
    const stopped = trains.filter(t => t.status === 'stopped').length;
    const approaching = trains.filter(t => t.status === 'approaching').length;
    const departing = trains.filter(t => t.status === 'departing').length;
    document.getElementById('statStopped').textContent = stopped;
    document.getElementById('statApproaching').textContent = approaching;
    document.getElementById('statDeparting').textContent = departing;
}

function renderTrainCards(trains) {
    if (trains.length === 0) {
        trainListContainer.innerHTML = `
            <div class="no-trains">
                <div class="no-trains-icon">🚆</div>
                Tidak ada kereta aktif
            </div>
        `;
        return;
    }

    let html = '';
    for (const train of trains) {
        const statusCls = train.status === 'stopped' ? 'stopped' : train.status === 'approaching' ? 'approaching' : 'departing';
        const routeCls = train.route === 'utara' ? 'utara' : train.route === 'selatan' ? 'selatan' : 'tengah';

        html += `
            <div class="train-card" data-train-id="${train.id}">
                <div class="train-card-header">
                    <div>
                        <div class="train-card-name">${train.name}</div>
                        <div class="train-card-code">${train.train_code}</div>
                    </div>
                    <div class="train-card-status">
                        <span class="status-dot ${statusCls}"></span>
                        <span class="status-label ${statusCls}">${statusLabels[train.status]}</span>
                    </div>
                </div>
                <div class="train-card-route">
                    <span class="route-badge ${routeCls}">${routeLabels[train.route || 'tengah']}</span>
                </div>
                <div class="progress-bar-container">
                    <div class="progress-bar-fill" style="width:${train.progress}%"></div>
                </div>
                <div class="train-card-meta">
                    <span>Progress <span class="meta-value">${train.progress}%</span></span>
                    <span>⏱ ${train.speed} km/h</span>
                    <span>📡 ${train.gps_accuracy}%</span>
                </div>
                <div class="train-card-meta" style="margin-top:4px;padding-top:4px;border-top:1px solid #334155;">
                    <span>${train.prev_station || '-'}</span>
                    <span>→</span>
                    <span><strong style="color:#f1f5f9;">${train.next_station || '-'}</strong> ${train.next_arrival ? '(' + formatTime(train.next_arrival) + ')' : ''}</span>
                </div>
            </div>
        `;
    }
    trainListContainer.innerHTML = html;
}

function updateMapMarkers(trains) {
    const activeIds = new Set(trains.map(t => t.id));

    for (const [id, marker] of Object.entries(trainMarkers)) {
        if (!activeIds.has(Number(id))) {
            map.removeLayer(marker);
            delete trainMarkers[id];
        }
    }

    for (const train of trains) {
        const statusCls = train.status === 'stopped' ? 'stopped' : train.status === 'approaching' ? 'approaching' : 'departing';

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
            marker.setLatLng([train.latitude, train.longitude]);
            marker.setPopupContent(popupContent);
            marker.setIcon(createTrainIcon(train.status));
        } else {
            const marker = L.marker([train.latitude, train.longitude], {
                icon: createTrainIcon(train.status),
            }).addTo(map);
            marker.bindPopup(popupContent);
            trainMarkers[train.id] = marker;
        }
    }
}

function getFilteredTrains() {
    if (currentRoute === 'all') return allTrains;
    return allTrains.filter(t => t.route === currentRoute);
}

function updateUI() {
    const filtered = getFilteredTrains();
    updateStats(filtered);
    renderTrainCards(filtered);
    updateMapMarkers(filtered);
}

async function fetchActiveTrains() {
    try {
        const time = getSimulatedTime();
        const url = `${API_URL}?time=${encodeURIComponent(time)}&speed=${speedMultiplier}`;
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

    const intervalMs = Math.max(100, Math.round(5000 / speedMultiplier));

    simulationInterval = setInterval(() => {
        simulatedMinutes = (simulatedMinutes + 1) % 1440;
        updateClock();
        timeSlider.value = simulatedMinutes;
        fetchActiveTrains();
    }, intervalMs);
}

function stopSimulation() {
    if (simulationInterval) {
        clearInterval(simulationInterval);
        simulationInterval = null;
    }
}

timeSlider.addEventListener('input', function () {
    simulatedMinutes = parseInt(this.value);
    updateClock();
    stopSimulation();
    isSimulationRunning = false;
    fetchActiveTrains();
});

speedBtns.forEach(btn => {
    btn.addEventListener('click', function () {
        speedBtns.forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        speedMultiplier = parseInt(this.dataset.speed);
        if (!isSimulationRunning) {
            isSimulationRunning = true;
        }
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

trainListContainer.addEventListener('click', function (e) {
    const card = e.target.closest('.train-card');
    if (card) {
        const trainId = parseInt(card.dataset.trainId);
        const train = allTrains.find(t => t.id === trainId);
        if (train && trainMarkers[trainId]) {
            map.setView([train.latitude, train.longitude], 12, { animate: true });
            trainMarkers[trainId].openPopup();
        }
    }
});

updateClock();
timeSlider.value = simulatedMinutes;
startSimulation();
