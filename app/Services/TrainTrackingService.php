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
        $trains = Train::with(['schedules.station'])->get();

        $activeTrains = [];

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

            if ($now < $firstDeparture || $now > $lastArrival) {
                continue;
            }

            $result = $this->calculatePosition($schedules, $now);
            if ($result === null) {
                continue;
            }

            $nextStation = $this->getNextStation($schedules, $now);
            $prevStation = $this->getPrevStation($schedules, $now);
            $route = $this->determineRoute($schedules);

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

            $activeTrains[] = [
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
            ];
        }

        return $activeTrains;
    }

    private function calculatePosition($schedules, string $now): ?array
    {
        $schedules = $schedules->values();

        for ($i = 0; $i < $schedules->count() - 1; $i++) {
            $current = $schedules[$i];
            $next = $schedules[$i + 1];

            $currentArrival = $current->arrival_time;
            $currentDeparture = $current->departure_time;
            $segmentStart = $currentDeparture;
            $segmentEnd = $next->arrival_time;

            if ($currentArrival && $currentDeparture && $now >= $currentArrival && $now < $currentDeparture) {
                return [
                    'latitude' => (float) $current->station->latitude,
                    'longitude' => (float) $current->station->longitude,
                    'status' => 'stopped',
                    'progress' => 0,
                ];
            }

            if ($segmentStart && $segmentEnd && $now >= $segmentStart && $now <= $segmentEnd) {
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
        if ($lastSchedule->arrival_time && $now >= $lastSchedule->arrival_time) {
            return [
                'latitude' => (float) $lastSchedule->station->latitude,
                'longitude' => (float) $lastSchedule->station->longitude,
                'status' => 'stopped',
                'progress' => 1.0,
            ];
        }

        return null;
    }

    private function calculateProgress(string $start, string $end, string $now): float
    {
        $startSec = strtotime($start);
        $endSec = strtotime($end);
        $nowSec = strtotime($now);

        if ($endSec === $startSec) {
            return 0;
        }

        return ($nowSec - $startSec) / ($endSec - $startSec);
    }

    private function getNextStation($schedules, string $now): ?array
    {
        foreach ($schedules as $schedule) {
            if ($schedule->arrival_time && $now < $schedule->arrival_time) {
                return [
                    'name' => $schedule->station->name,
                    'arrival_time' => $schedule->arrival_time,
                    'latitude' => (float) $schedule->station->latitude,
                    'longitude' => (float) $schedule->station->longitude,
                    'departure_time' => $schedule->departure_time,
                ];
            }
            if (!$schedule->arrival_time && $schedule->departure_time && $now < $schedule->departure_time) {
                return [
                    'name' => $schedule->station->name,
                    'arrival_time' => null,
                    'latitude' => (float) $schedule->station->latitude,
                    'longitude' => (float) $schedule->station->longitude,
                    'departure_time' => $schedule->departure_time,
                ];
            }
        }
        return null;
    }

    private function getPrevStation($schedules, string $now): ?array
    {
        $prev = null;
        foreach ($schedules as $schedule) {
            if ($schedule->arrival_time && $now < $schedule->arrival_time) {
                return $prev;
            }
            if (!$schedule->arrival_time && $schedule->departure_time && $now < $schedule->departure_time) {
                return $prev;
            }
            $prev = [
                'name' => $schedule->station->name,
                'arrival_time' => $schedule->arrival_time,
                'departure_time' => $schedule->departure_time,
                'latitude' => (float) $schedule->station->latitude,
                'longitude' => (float) $schedule->station->longitude,
            ];
        }
        return $prev;
    }

    private function estimateSpeed(?float $latFrom, ?float $lngFrom, ?float $latTo, ?float $lngTo, ?string $departure, ?string $arrival): int
    {
        if (!$latFrom || !$lngFrom || !$latTo || !$lngTo || !$departure || !$arrival) {
            return rand(40, 90);
        }

        $distance = $this->haversineDistance($latFrom, $lngFrom, $latTo, $lngTo);

        $timeSec = abs(strtotime($arrival) - strtotime($departure));
        if ($timeSec === 0) {
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
            'JN' => 'Jombang', 'KT' => 'Kertosono', 'NBO' => 'Nganjuk',
            'BJR' => 'Banjaran', 'SGU' => 'Sidoarjo', 'SRJ' => 'Surabaya',
            'WO' => 'Wonokromo', 'MGR' => 'Mojokerto', 'JMS' => 'Jombang',
            'KTL' => 'Kertosono', 'WR' => 'Waleran', 'STP' => 'Situbondo',
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
            'SUDB' => 'Sudirman Baru', 'CLD' => 'Ciledug', 'KRR' => 'Karangsari',
            'RCK' => 'Racak', 'CMK' => 'Cimangkok', 'GDB' => 'Gedebage',
            'KAC' => 'Kacangan', 'CTH' => 'Cetho', 'BTT' => 'Batu Tulis',
            'BOP' => 'Bogor Paledang', 'CS' => 'Cisauk', 'MSG' => 'Maseng',
            'CGB' => 'Cigombong', 'CCR' => 'Cicurug', 'CJE' => 'Cijeruk',
            'PRK' => 'Parungkuda', 'CBD' => 'Cibadak', 'KE' => 'Karang Tengah',
            'PON' => 'Pondok Rajeg', 'CSA' => 'Cisaat', 'SI' => 'Sukabumi',
            'SLW' => 'Selawu', 'BLP' => 'Balepanjang', 'MGS' => 'Mangis',
            'TGG' => 'Tagog', 'KEJ' => 'Karangjati', 'PDS' => 'Padalarang',
            'TW' => 'Tegalwaru', 'KSO' => 'Kasomalang', 'GD' => 'Gadog',
            'GPK' => 'Goprak', 'SUM' => 'Sumur', 'SLM' => 'Selaman',
            'KO' => 'Kosambi', 'KDO' => 'Kedungombo', 'KTG' => 'Ketanggungan',
            'AGO' => 'Anggo', 'BWI' => 'Bumi Waluya', 'RGP' => 'Rengaspendawa',
            'SGJ' => 'Sigung Jaya', 'TGR' => 'Tangerang', 'KSL' => 'Kasilah',
            'SWD' => 'Sewadu', 'GLM' => 'Gulama', 'KBR' => 'Kubar',
            'MRW' => 'Marawah', 'GRN' => 'Goran', 'SPL' => 'Sepul',
            'KLT' => 'Kalitami', 'KTK' => 'Katangkaro', 'AJ' => 'Anjir',
            'JR' => 'Jari', 'MI' => 'Miri', 'RBP' => 'Rebab',
            'BSS' => 'Boss', 'TGL' => 'Tegal', 'JTR' => 'Jatir',
            'RDA' => 'Roda', 'KK' => 'Kakap', 'RN' => 'Ranji',
            'MLS' => 'Malangsari', 'LEC' => 'Leces', 'PB' => 'Pembangunan',
            'BYM' => 'Bayeman', 'GI' => 'Gending', 'RO' => 'Rogojampi',
            'PS' => 'Pesanggrahan', 'PSJ' => 'Pasir Jaya', 'WNR' => 'Wonorejo',
            'GRT' => 'Garut', 'CIR' => 'Ciranji', 'AND' => 'Andir',
            'CMD' => 'Cimindi', 'CMI' => 'Cimahi', 'GK' => 'Gudang Kangkung',
            'PDL' => 'Padalarang', 'CLE' => 'Cilembang', 'SKT' => 'Sukatani',
            'MSI' => 'Masis', 'RH' => 'Rancah', 'CD' => 'Cidadap',
            'CG' => 'Ciganea', 'PLD' => 'Pelanduk', 'SUT' => 'Sudi',
            'CA' => 'Cikaum', 'PWK' => 'Purwakarta', 'SAD' => 'Sadang',
            'CBR' => 'Cibarang',
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
            'GDD' => [-6.1800, 106.8300], 'PDRG' => [-6.2850, 106.7920],
            'NMO' => [-6.2900, 106.7930],
        ];
    }
}
