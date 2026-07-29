<?php

namespace App\Services;

use App\Models\Train;
use App\Models\Station;
use App\Models\Schedule;
use Illuminate\Support\Facades\Log;

class TrainTrackingService
{
    private const MOCK_PATH = 'app/gapeka-mock.json';

    private array $excludedTrainNames = [
        'Commuter', 'Feeder', 'Cargo', 'Parcel', 'Tanker', 'Service', 'Ejek',
        'Kamandaka', 'Joglosemarkerto', 'Kaligung', 'Pangrango', 'Siliwangi',
        'Baturraden', 'Pandanwangi', 'Kedung Sepur', 'Batara Kresna',
        'Gadingnambo', 'Kalimas', 'Cilace', 'Bandara',
    ];

    private array $northStations = ['SMC', 'SMT', 'TGL', 'BTH', 'PML', 'BB', 'TG', 'LR', 'SD', 'PNL', 'KNN', 'SL', 'DPL', 'RBG', 'WDU', 'KPA', 'CU', 'TBO', 'KIT', 'BJ', 'KPS', 'BWO', 'BBT', 'GEB', 'PC', 'SBN', 'LMG', 'DD', 'CME', 'BNW', 'KDA', 'TES'];
    private array $southStations = ['BD', 'KYA', 'SPJ', 'BH', 'KRN', 'KDN', 'CKI', 'GDD', 'PSE', 'PDRG', 'CBN', 'NMO'];

    private array $stationCoords;

    private ?array $mockData = null;

    public function __construct()
    {
        $this->stationCoords = $this->buildStationCoords();
    }

    public function getActiveTrains(?string $simulatedTime = null, int $speedMultiplier = 1): array
    {
        $this->seedFromMockIfEmpty();

        $now = $simulatedTime ?? now()->format('H:i:s');
        $nowSec = $this->timeToSec($now);
        $trains = Train::with(['schedules.station'])->get();

        $resultTrains = [];

        foreach ($trains as $train) {
            $schedules = $train->schedules->sortBy('stop_order')->values();

            if ($schedules->isEmpty()) {
                continue;
            }

            $firstDeparture = $schedules->first()->departure_time;
            $lastArrival = $schedules->last()->arrival_time;

            if (!$firstDeparture || !$lastArrival) {
                continue;
            }

            $firstDepSec = $this->timeToSec($firstDeparture);
            $lastArrSec = $this->timeToSec($lastArrival);
            $route = $this->determineRoute($schedules);

            if ($this->isTimeInRange($nowSec, $firstDeparture, $lastArrival)) {
                $result = $this->calculatePosition($schedules, $now);
                if ($result === null) {
                    continue;
                }

                $nextStation = $this->getNextStation($schedules, $now);
                $prevStation = $this->getPrevStation($schedules, $now);
                $progress = $result['progress'] ?? 0;
                $status = $result['status'] ?? 'departing';

                $speed = $this->estimateSpeed(
                    $prevStation ? $prevStation['latitude'] : null,
                    $prevStation ? $prevStation['longitude'] : null,
                    $nextStation ? $nextStation['latitude'] : null,
                    $nextStation ? $nextStation['longitude'] : null,
                    $prevStation ? $prevStation['departure_time'] : null,
                    $nextStation ? $nextStation['arrival_time'] : null,
                );

                $gpsAccuracy = rand(85, 99);

                $pathCoords = [];
                foreach ($schedules as $s) {
                    $lat = (float) $s->station->latitude;
                    $lng = (float) $s->station->longitude;
                    if ($lat != 0 || $lng != 0) {
                        $pathCoords[] = [$lat, $lng];
                    }
                }

                $resultTrains[] = [
                    'id' => $train->id,
                    'train_code' => $train->train_code,
                    'name' => $train->name,
                    'latitude' => $result['latitude'],
                    'longitude' => $result['longitude'],
                    'status' => $status,
                    'progress' => round($progress * 100, 1),
                    'speed' => $speed,
                    'gps_accuracy' => $gpsAccuracy,
                    'route' => $route,
                    'prev_station' => $prevStation ? $prevStation['name'] : null,
                    'next_station' => $nextStation ? $nextStation['name'] : null,
                    'next_arrival' => $nextStation ? $nextStation['arrival_time'] : null,
                    'path' => $pathCoords,
                ];
            } else {
                $isOvernight = $firstDepSec > $lastArrSec;
                $closerToArrival = abs($nowSec - $lastArrSec) < abs($nowSec - $firstDepSec);

                if (!$isOvernight && $nowSec < $firstDepSec) {
                    $status = 'idle';
                    $progress = 0;
                    $prev = null;
                    $nextName = $schedules->first()->station->name;
                    $nextTime = $firstDeparture;
                    $pos = $this->calculatePosition($schedules, $firstDeparture);
                    if (!$pos) { $pos = ['latitude' => (float) $schedules->first()->station->latitude, 'longitude' => (float) $schedules->first()->station->longitude]; }
                } elseif ($isOvernight && !$closerToArrival) {
                    $status = 'idle';
                    $progress = 0;
                    $prev = null;
                    $nextName = $schedules->first()->station->name;
                    $nextTime = $firstDeparture;
                    $pos = $this->calculatePosition($schedules, $firstDeparture);
                    if (!$pos) { $pos = ['latitude' => (float) $schedules->first()->station->latitude, 'longitude' => (float) $schedules->first()->station->longitude]; }
                } else {
                    $status = 'completed';
                    $progress = 100;
                    $prev = null;
                    $nextName = null;
                    $nextTime = null;
                    $pos = $this->calculatePosition($schedules, $now);
                    if (!$pos) { $pos = ['latitude' => (float) $schedules->last()->station->latitude, 'longitude' => (float) $schedules->last()->station->longitude]; }
                }

                $pathCoords = [];
                foreach ($schedules as $s) {
                    $lat = (float) $s->station->latitude;
                    $lng = (float) $s->station->longitude;
                    if ($lat != 0 || $lng != 0) {
                        $pathCoords[] = [$lat, $lng];
                    }
                }

                $resultTrains[] = [
                    'id' => $train->id,
                    'train_code' => $train->train_code,
                    'name' => $train->name,
                    'latitude' => $pos['latitude'],
                    'longitude' => $pos['longitude'],
                    'status' => $status,
                    'progress' => $progress,
                    'speed' => 0,
                    'gps_accuracy' => rand(85, 99),
                    'route' => $route,
                    'prev_station' => $prev,
                    'next_station' => $nextName,
                    'next_arrival' => $nextTime,
                    'path' => $pathCoords,
                ];
            }
        }

        return $resultTrains;
    }

    private function timeToSec(string $time): int
    {
        $parts = explode(':', $time);
        return (int) $parts[0] * 3600 + (int) ($parts[1] ?? 0) * 60 + (int) ($parts[2] ?? 0);
    }

    private function isTimeInRange(int $nowSec, string $startStr, string $endStr): bool
    {
        $startSec = $this->timeToSec($startStr);
        $endSec = $this->timeToSec($endStr);

        if ($endSec >= $startSec) {
            return $nowSec >= $startSec && $nowSec <= $endSec;
        }

        return $nowSec >= $startSec || $nowSec <= $endSec;
    }

    private function calculatePosition($schedules, string $now): ?array
    {
        $schedules = $schedules->values();
        $nowSec = $this->timeToSec($now);

        for ($i = 0; $i < $schedules->count() - 1; $i++) {
            $current = $schedules[$i];
            $next = $schedules[$i + 1];

            $currentArrival = $current->arrival_time;
            $currentDeparture = $current->departure_time;
            $segmentStart = $currentDeparture;
            $segmentEnd = $next->arrival_time;

            if ($currentArrival && $currentDeparture && $this->isTimeInRange($nowSec, $currentArrival, $currentDeparture)) {
                return [
                    'latitude' => (float) $current->station->latitude,
                    'longitude' => (float) $current->station->longitude,
                    'status' => 'stopped',
                    'progress' => 0,
                ];
            }

            if ($segmentStart && $segmentEnd && $this->isTimeInRange($nowSec, $segmentStart, $segmentEnd)) {
                $progress = $this->calculateProgress($segmentStart, $segmentEnd, $now);

                $latFrom = (float) $current->station->latitude;
                $lngFrom = (float) $current->station->longitude;
                $latTo = (float) $next->station->latitude;
                $lngTo = (float) $next->station->longitude;

                $status = $progress > 0.5 ? 'approaching' : 'departing';

                return [
                    'latitude' => $latFrom + ($latTo - $latFrom) * $progress,
                    'longitude' => $lngFrom + ($lngTo - $lngFrom) * $progress,
                    'status' => $status,
                    'progress' => $progress,
                ];
            }
        }

        $lastSchedule = $schedules->last();
        if ($lastSchedule->arrival_time && $nowSec >= $this->timeToSec($lastSchedule->arrival_time)) {
            return [
                'latitude' => (float) $lastSchedule->station->latitude,
                'longitude' => (float) $lastSchedule->station->longitude,
                'status' => 'stopped',
                'progress' => 1.0,
            ];
        }

        $closest = null;
        $closestDiff = PHP_INT_MAX;
        foreach ($schedules as $s) {
            if ($s->arrival_time) {
                $diff = abs($nowSec - $this->timeToSec($s->arrival_time));
                if ($diff < $closestDiff) { $closestDiff = $diff; $closest = $s; }
            }
            if ($s->departure_time) {
                $diff = abs($nowSec - $this->timeToSec($s->departure_time));
                if ($diff < $closestDiff) { $closestDiff = $diff; $closest = $s; }
            }
        }
        if ($closest) {
            return [
                'latitude' => (float) $closest->station->latitude,
                'longitude' => (float) $closest->station->longitude,
                'status' => 'stopped',
                'progress' => 0,
            ];
        }

        return null;
    }

    private function calculateProgress(string $start, string $end, string $now): float
    {
        $startSec = $this->timeToSec($start);
        $endSec = $this->timeToSec($end);
        $nowSec = $this->timeToSec($now);

        if ($endSec <= $startSec) {
            $endSec += 86400;
        }
        if ($nowSec < $startSec) {
            $nowSec += 86400;
        }

        $total = $endSec - $startSec;
        if ($total <= 0) {
            return 0;
        }

        return ($nowSec - $startSec) / $total;
    }

    private function getNextStation($schedules, string $now): ?array
    {
        $nowSec = $this->timeToSec($now);
        foreach ($schedules as $schedule) {
            if ($schedule->arrival_time && !$this->isTimeInRange($nowSec, $schedule->arrival_time, $schedule->arrival_time)) {
                if ($this->timeToSec($schedule->arrival_time) >= $nowSec) {
                    return [
                        'name' => $schedule->station->name,
                        'arrival_time' => $schedule->arrival_time,
                        'latitude' => (float) $schedule->station->latitude,
                        'longitude' => (float) $schedule->station->longitude,
                        'departure_time' => $schedule->departure_time,
                    ];
                }
            }
            if (!$schedule->arrival_time && $schedule->departure_time) {
                $depSec = $this->timeToSec($schedule->departure_time);
                if ($depSec >= $nowSec || abs($depSec - $nowSec) < 3600) {
                    return [
                        'name' => $schedule->station->name,
                        'arrival_time' => null,
                        'latitude' => (float) $schedule->station->latitude,
                        'longitude' => (float) $schedule->station->longitude,
                        'departure_time' => $schedule->departure_time,
                    ];
                }
            }
        }
        return null;
    }

    private function getPrevStation($schedules, string $now): ?array
    {
        $nowSec = $this->timeToSec($now);
        $prev = null;
        foreach ($schedules as $schedule) {
            if ($schedule->arrival_time) {
                $arrSec = $this->timeToSec($schedule->arrival_time);
                if ($arrSec > $nowSec && abs($arrSec - $nowSec) > 3600) {
                    return $prev;
                }
            }
            if ($schedule->departure_time) {
                $depSec = $this->timeToSec($schedule->departure_time);
                if (!$schedule->arrival_time && $depSec > $nowSec && abs($depSec - $nowSec) > 3600) {
                    return $prev;
                }
            }
            if ($schedule->arrival_time || $schedule->departure_time) {
                $prev = [
                    'name' => $schedule->station->name,
                    'arrival_time' => $schedule->arrival_time,
                    'departure_time' => $schedule->departure_time,
                    'latitude' => (float) $schedule->station->latitude,
                    'longitude' => (float) $schedule->station->longitude,
                ];
            }
        }
        return $prev;
    }

    private function estimateSpeed(?float $latFrom, ?float $lngFrom, ?float $latTo, ?float $lngTo, ?string $departure, ?string $arrival): int
    {
        if (!$latFrom || !$lngFrom || !$latTo || !$lngTo || !$departure || !$arrival) {
            return rand(40, 90);
        }

        $distance = $this->haversineDistance($latFrom, $lngFrom, $latTo, $lngTo);

        $depSec = $this->timeToSec($departure);
        $arrSec = $this->timeToSec($arrival);
        if ($arrSec <= $depSec) {
            $arrSec += 86400;
        }
        $timeSec = $arrSec - $depSec;
        if ($timeSec <= 0) {
            return rand(40, 90);
        }

        $speed = ($distance / $timeSec) * 3600;
        return max(20, min(150, (int) round($speed)));
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    private function determineRoute($schedules): string
    {
        $stationCodes = [];
        foreach ($schedules as $s) {
            $stationCodes[] = $s->station->station_code;
        }

        foreach ($stationCodes as $code) {
            if (in_array($code, $this->northStations)) {
                return 'utara';
            }
        }

        foreach ($stationCodes as $code) {
            if (in_array($code, $this->southStations)) {
                return 'selatan';
            }
        }

        return 'tengah';
    }

    private function shouldIncludeTrain(array $trainData): bool
    {
        $name = $trainData['tr_name'] ?? '';
        foreach ($this->excludedTrainNames as $excluded) {
            if (str_contains($name, $excluded)) {
                return false;
            }
        }
        return true;
    }

    private function seedFromMockIfEmpty(): void
    {
        if (Train::count() > 0) {
            return;
        }

        $data = $this->loadMockData();
        if ($data === null || !isset($data['data'])) {
            Log::warning('Invalid mock data format');
            return;
        }

        $filteredData = array_filter($data['data'], [$this, 'shouldIncludeTrain']);

        $allStationCodes = [];
        foreach ($filteredData as $train) {
            foreach ($train['paths'] as $path) {
                $allStationCodes[$path['st_cd']] = true;
            }
        }

        foreach (array_keys($allStationCodes) as $code) {
            $name = $this->getStationName($code);
            $coords = $this->stationCoords[$code] ?? [-7.5, 110.0];
            Station::firstOrCreate(
                ['station_code' => $code],
                [
                    'name' => $name,
                    'latitude' => $coords[0],
                    'longitude' => $coords[1],
                ]
            );
        }

        $stations = Station::pluck('id', 'station_code');

        foreach ($filteredData as $trainData) {
            $trainCode = 'KA-' . $trainData['tr_cd'];
            $train = Train::firstOrCreate(
                ['train_code' => $trainCode],
                ['name' => $trainData['tr_name']]
            );

            $stopOrder = 0;
            foreach ($trainData['paths'] as $path) {
                $stopOrder++;
                $arrival = $this->normalizeTime($path['usr_arriv'] ?? null);
                $departure = $this->normalizeTime($path['usr_depart'] ?? null);

                if ($arrival === null && $departure === null) {
                    continue;
                }

                Schedule::firstOrCreate(
                    [
                        'train_id' => $train->id,
                        'station_id' => $stations[$path['st_cd']],
                    ],
                    [
                        'stop_order' => $stopOrder,
                        'arrival_time' => $arrival,
                        'departure_time' => $departure,
                    ]
                );
            }
        }
    }

    private function normalizeTime(?string $time): ?string
    {
        if ($time === null || $time === '' || $time === 'Ls') {
            return null;
        }
        $parts = explode(':', $time);
        if (count($parts) === 2) {
            return $parts[0] . ':' . $parts[1] . ':00';
        }
        if (count($parts) === 3) {
            return $time;
        }
        return null;
    }

    private function getStationName(string $code): string
    {
        $names = [
            'GMR' => 'Gambir', 'BD' => 'Bandung', 'YK' => 'Yogyakarta',
            'SLO' => 'Solo Balapan', 'SBI' => 'Surabaya Gubeng', 'ML' => 'Malang',
            'PWT' => 'Purwokerto', 'CN' => 'Cirebon', 'CP' => 'Cirebon Prujakan',
            'KYA' => 'Kiaracondong', 'CNP' => 'Cirebon Prujakan', 'SR' => 'Surabaya Pasarturi',
            'KTA' => 'Kutoarjo', 'JNG' => 'Jatinegara', 'MRI' => 'Manggarai',
            'CKI' => 'Cikini', 'GDD' => 'Gondangdia', 'PSE' => 'Pasar Senen',
            'JN' => 'Jombang', 'KT' => 'Klaten', 'NBO' => 'Nganjuk',
            'BJR' => 'Banjaran', 'SGU' => 'Sidoarjo', 'SRJ' => 'Surabaya',
            'WO' => 'Wonokromo', 'MGR' => 'Mojokerto', 'JMS' => 'Jombang',
            'KTL' => 'Kertosono', 'WR' => 'Warungdowo', 'STP' => 'Situbondo',
            'GDG' => 'Gending', 'BJK' => 'Banyuwangi', 'BDR' => 'Bondowoso',
            'PWJ' => 'Panarukan', 'SDA' => 'Sidoarjo', 'TGA' => 'Tanggul',
            'PR' => 'Probolinggo', 'BG' => 'Banyuwangi', 'WN' => 'Wonokromo',
            'SKJ' => 'Sukorejo', 'SN' => 'Senen', 'LW' => 'Lawang',
            'SGS' => 'Sengon', 'BMG' => 'Blimbing', 'MLK' => 'Malang Kotalama',
            'CPD' => 'Cepedak', 'KRAI' => 'Kramat Ireng', 'LL' => 'Lilacap',
            'PWS' => 'Purwosari', 'DL' => 'Delanggu', 'CE' => 'Ceper',
            'SWT' => 'Sawit', 'BBN' => 'Bumiayu', 'MGW' => 'Magelang',
            'LPN' => 'Lempuyangan', 'PTN' => 'Patukan', 'RWL' => 'Rewulu',
            'STL' => 'Sentolo', 'WT' => 'Wates', 'KDG' => 'Kedunggedeh',
            'WJ' => 'Wiji', 'BTH' => 'Batang', 'PRB' => 'Prabugiri',
            'KWN' => 'Kawunganten', 'WNS' => 'Winas', 'KM' => 'Kroya',
            'SRW' => 'Suroso', 'KA' => 'Kajen', 'GB' => 'Gubuk',
            'IJ' => 'Ijo', 'TBK' => 'Tambak', 'SPH' => 'Sumpiuh',
            'KJ' => 'Kebasen', 'PWA' => 'Purwokerto', 'BGR' => 'Bogor',
            'SRD' => 'Serpong', 'CRB' => 'Cirebon', 'BBD' => 'Babadan',
            'MN' => 'Madiun', 'MAG' => 'Magetan', 'GG' => 'Gogor',
            'NGW' => 'Ngawi', 'KG' => 'Kedunggalar', 'WK' => 'Walikukun',
            'KDB' => 'Kedungbanteng', 'KRO' => 'Kroya', 'SMC' => 'Surabaya Pasarturi',
            'SMT' => 'Semarang Tawang', 'ATA' => 'Alastua', 'BBG' => 'Bawen',
            'TGW' => 'Tegowono', 'GUB' => 'Gubug', 'KGT' => 'Karangtengah',
            'SDI' => 'Sedayu', 'GBN' => 'Gambringan', 'JBN' => 'Jabon',
            'PNL' => 'Pengkol', 'KNN' => 'Kunduran', 'SL' => 'Selo',
            'DPL' => 'Doplang', 'RBG' => 'Randublatung', 'WDU' => 'Wadu',
            'KPA' => 'Kepahang', 'CU' => 'Cepu', 'TBO' => 'Tambakromo',
            'KIT' => 'Kita', 'BJ' => 'Bojonegoro', 'KPS' => 'Kapas',
            'BWO' => 'Bower', 'BBT' => 'Babat', 'GEB' => 'Gebang',
            'PC' => 'Paciran', 'SBN' => 'Sumberan', 'LMG' => 'Lamongan',
            'DD' => 'Duduk', 'CME' => 'Cerme', 'BNW' => 'Benowo',
            'KDA' => 'Kandangan', 'TES' => 'Tandes', 'SB' => 'Surabaya',
            'THB' => 'Tanah Abang', 'PDJ' => 'Pondok Jati', 'KBY' => 'Kebayoran',
            'PLM' => 'Palmerah', 'SUD' => 'Sudirman', 'KAT' => 'Karet',
            'SUDB' => 'Sudirman Baru', 'BTT' => 'Batu Tulis',
            'BOP' => 'Bogor Paledang', 'CS' => 'Cisauk', 'MSG' => 'Maseng',
            'CGB' => 'Cigombong', 'CCR' => 'Cicurug', 'CJE' => 'Cijeruk',
            'PRK' => 'Parungkuda', 'CBD' => 'Cibadak', 'KE' => 'Karang Tengah',
            'PON' => 'Pondok Rajeg', 'CSA' => 'Cisaat', 'SI' => 'Sukabumi',
            'SLW' => 'Selawu', 'BLP' => 'Balepanjang', 'MGS' => 'Mangis',
            'TGG' => 'Tagog', 'KEJ' => 'Karangjati', 'PDS' => 'Padalarang',
            'TW' => 'Tegalwaru', 'KSO' => 'Kasomalang', 'GD' => 'Gadog',
            'GPK' => 'Goprak', 'SUM' => 'Sumur', 'SLM' => 'Selaman',
            'KO' => 'Kosambi', 'KDO' => 'Kedungombo', 'KTG' => 'Ketanggungan',
            'GM' => 'Gumilir', 'KKD' => 'Kedokan', 'KH' => 'Karangsuci',
            'MA' => 'Mertasari', 'SKP' => 'Sidangu', 'RDN' => 'Randegan',
            'KBS' => 'Kebasen', 'NTG' => 'Notog', 'KGD' => 'Kedunggede',
            'KRR' => 'Karangrayung', 'PAT' => 'Patuguran', 'KRT' => 'Karang Taliwang',
            'BMA' => 'Bumiayu', 'LG' => 'Legok', 'PPK' => 'Prupuk',
            'SGG' => 'Sigunggong', 'LRA' => 'Larangan', 'KGG' => 'Kedunggalih',
            'CLD' => 'Ciledug', 'SDU' => 'Sindu', 'LWG' => 'Luwung',
            'CNK' => 'Cirebon K', 'BDW' => 'Bandung', 'AWN' => 'Awan',
            'KTM' => 'Kertasemaya', 'JTB' => 'Jatibarang', 'TLS' => 'Telagasari',
            'TIS' => 'Tis', 'KAB' => 'Kandanghaur', 'CLH' => 'Cilegeh',
            'HGL' => 'Haurgeulis', 'CRA' => 'Cikarang', 'PGB' => 'Pegaden Baru',
            'CKM' => 'Cikampek', 'PAS' => 'Pasar Senen', 'PRI' => 'Pondok Rajeg',
            'PAB' => 'Pabrik', 'TJS' => 'Tanjung', 'CKP' => 'Cikupa',
            'DWN' => 'Darangdan', 'KOS' => 'Kosambi', 'KLI' => 'Kalisari',
            'KW' => 'Kedungwungu', 'KDH' => 'Kadokang', 'LMB' => 'Lembang',
            'CKR' => 'Cikarang', 'TLM' => 'Telukjambe', 'CIT' => 'Citarum',
            'TB' => 'Tambun', 'BKST' => 'Bekasi Timur', 'BKS' => 'Bekasi',
            'KRI' => 'Kediri', 'CUK' => 'Cukir', 'KLDB' => 'Kalidadap',
            'BUA' => 'Bua', 'KLD' => 'Kalidadi', 'MTR' => 'Menteng',
            'GW' => 'Gawok', 'NGA' => 'Ngara', 'PSI' => 'Pasarsenen',
            'KPN' => 'Kepanjen', 'NB' => 'Ngabar', 'SBP' => 'Sumberpucung',
            'PGJ' => 'Pogajih', 'KSB' => 'Kesamben', 'WG' => 'Wlingi',
            'TAL' => 'Talun', 'GRM' => 'Garum', 'BL' => 'Buleleng',
            'RJ' => 'Rejoso', 'NT' => 'Ngantang', 'SBL' => 'Sumberlawang',
            'TA' => 'Taman', 'NJG' => 'Ngoro', 'KRS' => 'Kertosono',
            'NDL' => 'Nganjuk', 'KD' => 'Kediri', 'SS' => 'Sumber',
            'MGN' => 'Minggiran', 'PPR' => 'Papar', 'BRN' => 'Baron',
            'SKM' => 'Sukomoro', 'NJ' => 'Nganjuk', 'MSR' => 'Mojosari',
            'KMR' => 'Kemiri', 'PL' => 'Pule', 'SK' => 'Sukorejo',
            'LBG' => 'Lubang', 'JRL' => 'Jeruklegi', 'KWG' => 'Kawunganten',
            'GDM' => 'Gandasuli', 'SDR' => 'Sidoarjo', 'CPI' => 'Cemping',
            'MLW' => 'Mliwis', 'LN' => 'Lon', 'KNP' => 'Krenceng',
            'BJG' => 'Bajangan', 'CI' => 'Ceper', 'MNJ' => 'Manjung',
            'AW' => 'Awan', 'TSM' => 'Tasikmadu', 'IH' => 'Ihan',
            'RJP' => 'Rejasa', 'CAW' => 'Candiwulan', 'CAA' => 'Candi Asri',
            'BMW' => 'Bumi Watu', 'WB' => 'Watu Bara', 'CB' => 'Cabe',
            'LO' => 'Lorok', 'LBJ' => 'Lebak Jaya', 'NG' => 'Nglorok',
            'CCL' => 'Cicel', 'HRP' => 'Harapan', 'RCK' => 'Racak',
            'CMK' => 'Cemaka', 'GDB' => 'Gedebeg', 'KAC' => 'Kacangan',
            'CTH' => 'Cetho', 'SPJ' => 'Sepinggan', 'BH' => 'Bahu',
            'KRN' => 'Karangnongko', 'KDN' => 'Kedungnongko', 'TRK' => 'Trak',
            'MR' => 'Merak', 'CRM' => 'Cermai', 'SBO' => 'Sumberbogo',
            'PTR' => 'Patiro', 'JG' => 'Jenggolo', 'SMB' => 'Sumberbaya',
            'POK' => 'Pogok', 'KMT' => 'Kemantren', 'GST' => 'Gosantren',
            'WDW' => 'Wadung', 'BBK' => 'Babat', 'LOS' => 'Losari',
            'TGN' => 'Tegangan', 'BB' => 'Babat', 'TG' => 'Tegalgondo',
            'LR' => 'Lor', 'SD' => 'Sedayu', 'PML' => 'Pemalang',
            'PTA' => 'Pati Anyar', 'CO' => 'Cokro', 'SRI' => 'Sringin',
            'PK' => 'Pekalongan', 'BTG' => 'Batingan', 'UJN' => 'Ujungnegoro',
            'KRP' => 'Karangpucung', 'PLB' => 'Pulobrang', 'KNS' => 'Kebasen',
            'WLR' => 'Weleri', 'KBD' => 'Kebondalem', 'KLN' => 'Kalinyamatan',
            'MKG' => 'Mangkang', 'JRK' => 'Jerakah', 'NODE_STM' => 'Semarang Tawang',
            'NODE_SB' => 'Surabaya', 'CBR' => 'Cibureum', 'SAD' => 'Sadang',
            'PWK' => 'Purwakarta', 'CA' => 'Cikaum', 'SUT' => 'Sudi',
            'PLD' => 'Pelanduk', 'CG' => 'Ciganea', 'CD' => 'Cidadap',
            'RH' => 'Rahayu', 'MSI' => 'Masis', 'SKT' => 'Sukatani',
            'CLE' => 'Cilembang', 'PDL' => 'Padalarang', 'GK' => 'Gudangkangkung',
            'CMI' => 'Cimahi', 'CMD' => 'Cimindi', 'AND' => 'Andir',
            'CIR' => 'Ciranjang', 'GRT' => 'Garut', 'WNR' => 'Wonorejo',
            'PSJ' => 'Pasirjaya', 'PS' => 'Pesanggrahan', 'RO' => 'Rogojampi',
            'GI' => 'Gending', 'BYM' => 'Bayeman', 'PB' => 'Pembangunan',
            'LEC' => 'Leces', 'MLS' => 'Malangsari', 'RN' => 'Ranji',
            'KK' => 'Kakap', 'RDA' => 'Roda', 'JTR' => 'Jatir',
            'TGL' => 'Tegal', 'BSS' => 'Boss', 'RBP' => 'Rebab',
            'MI' => 'Miri', 'JR' => 'Jari', 'AJ' => 'Anjir',
            'KTK' => 'Ketangkaro', 'KLT' => 'Kalitami', 'LDO' => 'Ledok',
            'SPL' => 'Sepul', 'GRN' => 'Goran', 'MRW' => 'Marawah',
            'KBR' => 'Kubar', 'GLM' => 'Gulama', 'SWD' => 'Sewadu',
            'KSL' => 'Kasilah', 'TGR' => 'Tangerang', 'SGJ' => 'Sigung Jaya',
            'RGP' => 'Rengaspendawa', 'BWI' => 'Bumi Waluya', 'AGO' => 'Anggo',
            'KTG' => 'Ketanggungan', 'KDO' => 'Kedungombo', 'SLM' => 'Selaman',
            'SUM' => 'Sumur', 'GPK' => 'Goprak', 'KSO' => 'Kasomalang',
            'TW' => 'Tegalwaru', 'PDS' => 'Padalarang', 'KEJ' => 'Karangjati',
            'KLM' => 'Kalimanah', 'MST' => 'Masastan', 'BJR' => 'Banjaran',
            'CPD' => 'Cepedak', 'KRAI' => 'Kramat Ireng', 'LL' => 'Lilacap',
            'NBO' => 'Nganjuk', 'BDR' => 'Bondowoso',
            'KTS' => 'Kutaso',
        ];
        return $names[$code] ?? 'Stasiun ' . $code;
    }

    private function loadMockData(): ?array
    {
        if ($this->mockData !== null) {
            return $this->mockData;
        }
        $path = storage_path(self::MOCK_PATH);
        if (!file_exists($path)) {
            Log::warning('Mock data file not found at: ' . $path);
            return null;
        }
        $content = file_get_contents($path);
        $this->mockData = json_decode($content, true);
        return $this->mockData;
    }

    private function buildStationCoords(): array
    {
        return [
            'GMR' => [-6.1765, 106.8227], 'BD' => [-6.9147, 107.6028],
            'YK' => [-7.7889, 110.3634], 'SLO' => [-7.5564, 110.8281],
            'SBI' => [-7.2658, 112.7507], 'ML' => [-7.9786, 112.6307],
            'PWT' => [-7.4244, 109.2343], 'CN' => [-6.7068, 108.5571],
            'CP' => [-6.6933, 108.5584], 'KYA' => [-6.9121, 107.6010],
            'GM' => [-6.6742, 108.5412], 'KKD' => [-6.6788, 108.5298],
            'KH' => [-6.6788, 108.5198], 'MA' => [-6.6874, 108.4991],
            'SKP' => [-6.6901, 108.4891], 'RDN' => [-6.7012, 108.4789],
            'KBS' => [-6.7123, 108.4689], 'NTG' => [-6.7234, 108.4578],
            'KGD' => [-6.7356, 108.4467], 'KRR' => [-6.7478, 108.4356],
            'PAT' => [-6.7590, 108.4245], 'KRT' => [-6.7712, 108.4134],
            'BMA' => [-6.7834, 108.4023], 'LG' => [-6.7956, 108.3912],
            'PPK' => [-6.8078, 108.3801], 'SGG' => [-6.8200, 108.3690],
            'LRA' => [-6.8322, 108.3579], 'KGG' => [-6.8444, 108.3468],
            'CLD' => [-6.8566, 108.3357], 'SDU' => [-6.8688, 108.3246],
            'LWG' => [-6.8810, 108.3135], 'CNP' => [-6.8932, 108.3024],
            'CNK' => [-6.7068, 108.5571], 'BDW' => [-6.9147, 107.6010],
            'PSE' => [-6.1750, 106.8450], 'KTA' => [-7.7177, 109.9131],
            'PWS' => [-7.5600, 110.8200], 'GW' => [-7.5600, 110.7800],
            'DL' => [-7.5700, 110.7400], 'CE' => [-7.5800, 110.7000],
            'KT' => [-7.5900, 110.6600], 'SWT' => [-7.6000, 110.6200],
            'BBN' => [-7.2800, 109.0000], 'MGW' => [-7.4700, 110.2200],
            'LPN' => [-7.7900, 110.3700], 'PTN' => [-7.8000, 110.3500],
            'RWL' => [-7.8100, 110.3300], 'STL' => [-7.8200, 110.3100],
            'WT' => [-7.8300, 110.2900], 'KDG' => [-7.8400, 110.2700],
            'WJ' => [-7.8500, 110.2500], 'BTH' => [-6.9100, 109.7300],
            'PRB' => [-7.0000, 109.6000], 'KWN' => [-7.0500, 109.5000],
            'WNS' => [-7.1000, 109.4000], 'KM' => [-7.1500, 109.3000],
            'SRW' => [-7.2000, 109.2000], 'KA' => [-7.2500, 109.1000],
            'GB' => [-7.3000, 109.0000], 'IJ' => [-7.3500, 108.9000],
            'TBK' => [-7.4000, 108.8000], 'SPH' => [-7.4500, 108.7000],
            'KJ' => [-7.5000, 108.6000], 'PWA' => [-7.4244, 109.2343],
            'BGR' => [-6.5970, 106.7890], 'SRD' => [-6.3200, 106.6700],
            'CRB' => [-6.7068, 108.5571], 'BBD' => [-7.5500, 111.6000],
            'MN' => [-7.6300, 111.5300], 'MAG' => [-7.6500, 111.4600],
            'GG' => [-7.6700, 111.3900], 'NGW' => [-7.6900, 111.3200],
            'KG' => [-7.7100, 111.2500], 'WK' => [-7.7300, 111.1800],
            'KDB' => [-7.7500, 111.1100], 'KRO' => [-7.1500, 109.3000],
            'SR' => [-7.2476, 112.7380], 'SMC' => [-6.9700, 110.4200],
            'SMT' => [-6.9700, 110.4300], 'ATA' => [-6.9500, 110.4500],
            'BBG' => [-6.9300, 110.4700], 'TGW' => [-6.9100, 110.4900],
            'GUB' => [-6.8900, 110.5100], 'KGT' => [-6.8700, 110.5300],
            'SDI' => [-6.8500, 110.5500], 'GBN' => [-6.8300, 110.5700],
            'JBN' => [-6.8100, 110.5900], 'PNL' => [-6.7900, 110.6100],
            'KNN' => [-6.7700, 110.6300], 'SL' => [-6.7500, 110.6500],
            'DPL' => [-6.7300, 110.6700], 'RBG' => [-6.7100, 110.6900],
            'WDU' => [-6.6900, 110.7100], 'KPA' => [-6.6700, 110.7300],
            'CU' => [-6.6500, 110.7500], 'TBO' => [-6.6300, 110.7700],
            'KIT' => [-6.6100, 110.7900], 'BJ' => [-6.5900, 110.8100],
            'KPS' => [-6.5700, 110.8300], 'BWO' => [-6.5500, 110.8500],
            'BBT' => [-6.5300, 110.8700], 'GEB' => [-6.5100, 110.8900],
            'PC' => [-6.4900, 110.9100], 'SBN' => [-6.4700, 110.9300],
            'LMG' => [-6.4500, 110.9500], 'DD' => [-6.4300, 110.9700],
            'CME' => [-6.4100, 110.9900], 'BNW' => [-6.3900, 111.0100],
            'KDA' => [-6.3700, 111.0300], 'TES' => [-6.3500, 111.0500],
            'SB' => [-7.2658, 112.7507], 'SGU' => [-7.4520, 112.7130],
            'THB' => [-6.1880, 106.8120], 'PDJ' => [-6.2600, 106.8200],
            'KBY' => [-6.2400, 106.8000], 'PLM' => [-6.2300, 106.7900],
            'SUD' => [-6.2000, 106.8130], 'KAT' => [-6.2000, 106.8000],
            'SUDB' => [-6.2000, 106.8200], 'JNG' => [-6.2150, 106.8700],
            'MRI' => [-6.2100, 106.8500], 'CKI' => [-6.1900, 106.8400],
            'GDD' => [-6.1800, 106.8300],             'PDRG' => [-6.2850, 106.7920], 'NMO' => [-6.2900, 106.7930],
            'NGA' => [-7.4000, 111.4500], 'BDR' => [-7.8200, 112.5500],
            'PWJ' => [-7.7000, 113.9500], 'WN' => [-7.3800, 112.7200],
            'SN' => [-6.1750, 106.8400], 'PSI' => [-7.6500, 112.6500],
            'KPN' => [-8.1300, 112.5700], 'MSR' => [-7.5000, 112.5000],
            'KMR' => [-7.5500, 112.4000], 'PL' => [-7.6000, 112.3000],
            'SK' => [-7.6500, 112.2000], 'LBG' => [-7.7000, 112.1000],
            'JRL' => [-7.6000, 109.1000], 'KWG' => [-7.6500, 109.0000],
            'GDM' => [-7.5500, 111.8000], 'SDR' => [-7.4520, 112.7130],
            'CPI' => [-7.7500, 111.7000], 'MLW' => [-7.8000, 111.6500],
            'LN' => [-7.8500, 111.6000], 'BJR' => [-7.7500, 110.4925],
            'KNP' => [-7.7245, 110.6089], 'BJG' => [-7.6710, 110.6115],
            'CI' => [-7.6465, 110.6128], 'MNJ' => [-7.6187, 110.6141],
            'AW' => [-7.6050, 110.6150], 'TSM' => [-7.6219, 110.6200],
            'IH' => [-7.6488, 110.6245], 'RJP' => [-7.6757, 110.6290],
            'CAW' => [-7.7026, 110.6335], 'CAA' => [-7.7210, 110.6370],
            'CPD' => [-7.7400, 110.6400], 'BMW' => [-7.7600, 110.6420],
            'WB' => [-7.7790, 110.6440], 'CB' => [-7.7980, 110.6460],
            'LO' => [-7.8170, 110.6480], 'KRAI' => [-7.8360, 110.6500],
            'LL' => [-7.8550, 110.6520], 'LBJ' => [-7.8740, 110.6540],
            'NG' => [-7.8930, 110.6560], 'CCL' => [-7.9120, 110.6580],
            'HRP' => [-7.9310, 110.6600], 'RCK' => [-7.9500, 110.6620],
            'CMK' => [-7.9690, 110.6640], 'GDB' => [-7.9880, 110.6660],
            'KAC' => [-8.0070, 110.6680], 'CTH' => [-8.0260, 110.6700],
            'WDW' => [-6.9000, 110.2000], 'BBK' => [-6.5300, 110.8700],
            'NBO' => [-7.6000, 111.8200], 'SRJ' => [-7.2476, 112.7380],
            'LDO' => [-7.6500, 111.3000],
            'AWN' => [-6.9100, 107.5800], 'KTM' => [-6.9000, 107.5600],
            'JTB' => [-6.8900, 107.5400], 'TLS' => [-6.8800, 107.5200],
            'TIS' => [-6.8700, 107.5000], 'KAB' => [-6.8600, 107.4800],
            'CLH' => [-6.8500, 107.4600], 'HGL' => [-6.8400, 107.4400],
            'CRA' => [-6.8300, 107.4200], 'PGB' => [-6.8200, 107.4000],
            'CKM' => [-6.8100, 107.3800], 'PAS' => [-6.8000, 107.3600],
            'PRI' => [-6.7900, 107.3400], 'PAB' => [-6.7800, 107.3200],
            'TJS' => [-6.7700, 107.3000], 'CKP' => [-6.7600, 107.2800],
            'DWN' => [-6.7500, 107.2600], 'KOS' => [-6.7400, 107.2400],
            'KLI' => [-6.7300, 107.2200], 'KW' => [-6.7200, 107.2000],
            'KDH' => [-6.7100, 107.1800], 'LMB' => [-6.7000, 107.1600],
            'CKR' => [-6.6900, 107.1400], 'TLM' => [-6.6800, 107.1200],
            'CIT' => [-6.6700, 107.1000], 'TB' => [-6.6600, 107.0800],
            'BKST' => [-6.6500, 107.0600], 'BKS' => [-6.6400, 107.0400],
            'KRI' => [-7.8169, 112.0125], 'CUK' => [-7.8000, 112.0300],
            'KLDB' => [-7.7800, 112.0500], 'BUA' => [-7.7600, 112.0700],
            'KLD' => [-7.7400, 112.0900], 'MTR' => [-6.2300, 106.8500],
            'JN' => [-7.5460, 112.2300], 'WO' => [-7.4000, 112.7000],
            'MGR' => [-7.4500, 112.4500], 'JMS' => [-7.5460, 112.2300],
            'KTL' => [-7.6000, 112.0000], 'WR' => [-7.6500, 111.8000],
            'STP' => [-7.7000, 111.6000], 'GDG' => [-7.7500, 111.5500],
            'BJK' => [-7.8000, 111.5000], 'SDA' => [-7.4520, 112.7130],
            'TGA' => [-7.5000, 112.6000], 'PR' => [-7.5500, 112.5000],
            'BG' => [-7.6000, 112.4000], 'SKJ' => [-7.6500, 112.3000],
            'LW' => [-7.8300, 112.6500], 'SGS' => [-7.8500, 112.6400],
            'BMG' => [-7.9300, 112.6200], 'MLK' => [-7.9600, 112.6300],
            'NB' => [-7.8000, 112.5000], 'SBP' => [-7.8500, 112.4500],
            'PGJ' => [-7.8000, 112.4000], 'KSB' => [-7.7500, 112.3500],
            'WG' => [-7.7000, 112.3000], 'TAL' => [-7.6500, 112.2500],
            'GRM' => [-7.6000, 112.2000], 'BL' => [-7.5500, 112.1500],
            'RJ' => [-7.5000, 112.1000], 'NT' => [-7.4500, 112.0500],
            'SBL' => [-7.4000, 112.0000], 'TA' => [-7.3500, 111.9500],
            'NJG' => [-7.3000, 111.9000], 'KRS' => [-7.2500, 111.8500],
            'NDL' => [-7.2000, 111.8000], 'KD' => [-7.1500, 111.7500],
            'SS' => [-7.1000, 111.7000], 'MGN' => [-7.0500, 111.6500],
            'PPR' => [-7.0000, 111.6000], 'KTS' => [-7.4500, 109.2000],
            'BRN' => [-7.5000, 109.1500], 'SKM' => [-7.5500, 109.1000],
            'NJ' => [-7.6000, 109.0500], 'SPJ' => [-6.9130, 107.6030],
            'BH' => [-6.9220, 107.6040], 'KRN' => [-6.9300, 107.6050],
            'KDN' => [-6.9380, 107.6060], 'TRK' => [-6.9460, 107.6070],
            'MR' => [-6.9540, 107.6080], 'CRM' => [-6.9620, 107.6090],
            'SBO' => [-6.9700, 107.6100], 'PTR' => [-6.9780, 107.6110],
            'JG' => [-6.9860, 107.6120], 'SMB' => [-6.9940, 107.6130],
            'POK' => [-7.0020, 107.6140], 'KMT' => [-7.0100, 107.6150],
            'GST' => [-7.0180, 107.6160], 'LOS' => [-6.9800, 110.4000],
            'TGN' => [-6.9900, 110.4100], 'BB' => [-7.0000, 110.4200],
            'TG' => [-7.0100, 110.4300], 'LR' => [-7.0200, 110.4400],
            'SD' => [-7.0300, 110.4500], 'PML' => [-7.0400, 110.4600],
            'PTA' => [-7.0500, 110.4700], 'CO' => [-7.0600, 110.4800],
            'SRI' => [-7.0700, 110.4900], 'PK' => [-7.0800, 110.5000],
            'BTG' => [-7.0900, 110.5100], 'UJN' => [-7.1000, 110.5200],
            'KRP' => [-7.1100, 110.5300], 'PLB' => [-7.7000, 109.0000],
            'KNS' => [-7.7500, 108.8000], 'WLR' => [-7.6000, 109.8000],
            'KBD' => [-7.5000, 109.9000], 'KLN' => [-7.4000, 110.0000],
            'MKG' => [-7.3000, 110.1000], 'JRK' => [-7.2000, 110.2000],
            'NODE_STM' => [-6.9700, 110.4300], 'NODE_SB' => [-7.2476, 112.7380],
            'CBR' => [-7.7950, 106.7180], 'SAD' => [-7.7800, 106.7190],
            'PWK' => [-7.7650, 106.7200], 'CA' => [-7.7500, 106.7210],
            'SUT' => [-7.7350, 106.7220], 'PLD' => [-7.7200, 106.7230],
            'CG' => [-7.7050, 106.7240], 'CD' => [-7.6900, 106.7250],
            'RH' => [-7.6750, 106.7260], 'MSI' => [-7.6600, 106.7270],
            'SKT' => [-7.6450, 106.7280], 'CLE' => [-7.6300, 106.7290],
            'PDL' => [-7.6150, 106.7300], 'GK' => [-7.6000, 106.7310],
            'CMI' => [-7.5850, 106.7320], 'CMD' => [-7.5700, 106.7330],
            'AND' => [-7.5550, 106.7340], 'CIR' => [-7.5400, 106.7350],
            'GRT' => [-7.5250, 106.7360], 'WNR' => [-7.5100, 106.7370],
            'PSJ' => [-7.4950, 106.7380], 'PS' => [-7.4800, 106.7390],
            'RO' => [-7.4650, 106.7400], 'GI' => [-7.4500, 106.7410],
            'BYM' => [-7.4350, 106.7420], 'PB' => [-7.4200, 106.7430],
            'LEC' => [-7.4050, 106.7440], 'MLS' => [-7.3900, 106.7450],
            'RN' => [-7.3750, 106.7460], 'KK' => [-7.3600, 106.7470],
            'RDA' => [-7.3450, 106.7480], 'JTR' => [-7.3300, 106.7490],
            'TGL' => [-7.3150, 106.7500], 'BSS' => [-7.3000, 106.7510],
            'RBP' => [-7.2850, 106.7520], 'MI' => [-7.2700, 106.7530],
            'JR' => [-7.2550, 106.7540], 'AJ' => [-7.2400, 106.7550],
            'KTK' => [-7.2250, 106.7560], 'KLT' => [-7.2100, 106.7570],
            'SPL' => [-7.1950, 106.7580], 'GRN' => [-7.1800, 106.7590],
            'MRW' => [-7.1650, 106.7600], 'KBR' => [-7.1500, 106.7610],
            'GLM' => [-7.1350, 106.7620], 'SWD' => [-7.1200, 106.7630],
            'KSL' => [-7.1050, 106.7640], 'TGR' => [-7.0900, 106.7650],
            'SGJ' => [-7.0750, 106.7660], 'RGP' => [-7.0600, 106.7670],
            'BWI' => [-7.0450, 106.7680], 'AGO' => [-7.0300, 106.7690],
            'KTG' => [-7.0150, 106.7700], 'KDO' => [-7.0000, 106.7710],
            'KO' => [-6.9850, 106.7720], 'SLM' => [-6.9700, 106.7730],
            'SUM' => [-6.9550, 106.7740], 'GPK' => [-6.9400, 106.7750],
            'GD' => [-6.9250, 106.7760], 'KSO' => [-6.9100, 106.7770],
            'TW' => [-6.8950, 106.7780], 'PDS' => [-6.8800, 106.7790],
            'KEJ' => [-6.8650, 106.7800], 'TGG' => [-6.8500, 106.7810],
            'KLM' => [-6.4800, 106.8800], 'MST' => [-6.4900, 106.8850],
        ];
    }
}
