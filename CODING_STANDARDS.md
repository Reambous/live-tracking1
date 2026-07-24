# Aturan Penulisan Kode

1. **Aturan Umum:**
   - Gunakan standar penulisan Laravel (PSR-12).
   - Buat kode yang simpel dan mudah dibaca.

2. **Aturan Backend (PHP/Laravel):**
   - **Controller:** Hanya mengurus permintaan (request) dan jawaban (response).
   - **Service:** Tempatkan logika perhitungan matematika/posisi kereta di file terpisah (`App\Services\TrainTrackingService.php`).
   - **Model & Variable:** Gunakan Bahasa Inggris (`$activeTrains`, `$currentLocation`).

3. **Aturan Frontend (JavaScript):**
   - Gunakan JavaScript modern (ES6+).
   - Pisahkan logika peta agar tidak menumpuk di file HTML Blade.
