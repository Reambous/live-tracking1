import L from 'leaflet';

const API_URL = window._apiBaseUrl + '/active-trains';

const map = L.map('map', {
    center: [-7.5, 110.0],
    zoom: 8,
    zoomControl: true,
});

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 18,
}).addTo(map);

const trainMarkers = {};

function createTrainIcon() {
    return L.divIcon({
        className: 'train-marker-wrapper',
        html: '<div class="train-marker-icon"></div>',
        iconSize: [16, 16],
        iconAnchor: [8, 8],
        popupAnchor: [0, -12],
    });
}

function formatTime(timeStr) {
    if (!timeStr) return '-';
    return timeStr.substring(0, 5);
}

function updateTrainList(trains) {
    const container = document.getElementById('train-list');

    if (trains.length === 0) {
        container.innerHTML = '<p style="color: #6b7280; font-size: 13px; text-align: center; padding: 12px 0;">Tidak ada kereta aktif saat ini</p>';
        return;
    }

    let html = '';
    for (const train of trains) {
        html += `
            <div class="train-item">
                <div>
                    <span class="train-name">${train.name}</span>
                    <span class="train-code">${train.train_code}</span>
                </div>
                <div class="train-info">
                    ${train.next_station ? 'Stasiun berikutnya: <strong>' + train.next_station + '</strong>' : 'Stasiun akhir' }
                    ${train.next_arrival ? ' (' + formatTime(train.next_arrival) + ')' : ''}
                </div>
            </div>
        `;
    }
    container.innerHTML = html;
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
        const popupContent = `
            <div style="font-family: Inter, sans-serif;">
                <strong>${train.name}</strong><br>
                <span style="color: #6b7280; font-size: 12px;">${train.train_code}</span><br><br>
                ${train.next_station
                    ? 'Stasiun berikutnya: <strong>' + train.next_station + '</strong><br>Jam tiba: ' + formatTime(train.next_arrival)
                    : 'Stasiun akhir'
                }
            </div>
        `;

        if (trainMarkers[train.id]) {
            const marker = trainMarkers[train.id];
            marker.setLatLng([train.latitude, train.longitude]);
            marker.setPopupContent(popupContent);
        } else {
            const marker = L.marker([train.latitude, train.longitude], {
                icon: createTrainIcon(),
            }).addTo(map);

            marker.bindPopup(popupContent);
            trainMarkers[train.id] = marker;
        }
    }
}

async function fetchActiveTrains() {
    try {
        const response = await fetch(API_URL);
        const result = await response.json();

        if (result.success && result.data) {
            updateMapMarkers(result.data);
            updateTrainList(result.data);
        }
    } catch (error) {
        console.error('Gagal mengambil data kereta:', error);
    }
}

fetchActiveTrains();
setInterval(fetchActiveTrains, 5000);
