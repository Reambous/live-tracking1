# Arsitektur Sistem - Live Tracking Kereta Api

## 1. Alur Sistem
1. Backend Laravel mengambil data jadwal dari API Gapeka (atau file `gapeka-mock.json` jika API error).
2. Data jadwal disimpan ke database MySQL (Tabel: `trains`, `stations`, `schedules`).
3. Service Laravel menghitung estimasi posisi kereta (Latitude & Longitude) berdasarkan jam saat ini.
4. API Laravel (`GET /api/active-trains`) mengirimkan daftar kereta aktif dan posisi terbarunya.
5. Peta Leaflet.js di frontend meminta data ke API setiap 5 detik lalu menggeser ikon kereta di peta.

## 2. Struktur Tabel Database

### `trains`
- `id`
- `train_code` (Contoh: "KA-123")
- `name` (Contoh: "Argo Parahyangan")

### `stations`
- `id`
- `station_code` (Contoh: "GMR")
- `name` (Contoh: "Gambir")
- `latitude` (Contoh: -6.17539)
- `longitude` (Contoh: 106.82715)

### `schedules`
- `id`
- `train_id`
- `station_id`
- `stop_order` (Urutan stasiun)
- `arrival_time` (Waktu tiba)
- `departure_time` (Waktu berangkat)
