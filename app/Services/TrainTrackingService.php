<?php

namespace App\Services;

use App\Models\Train;
use App\Models\Station;
use App\Models\Schedule;
use Illuminate\Support\Facades\Log;

class TrainTrackingService
{
    private const MOCK_PATH = 'gapeka-mock.json';

    private array $stationCoords = [
        'GMR' => [-6.1765, 106.8227], 'BD' => [-6.9147, 107.6028],
        'YK' => [-7.7889, 110.3634], 'SLO' => [-7.5564, 110.8281],
        'SBI' => [-7.2658, 112.7507], 'ML' => [-7.9786, 112.6307],
        'PWT' => [-7.4244, 109.2343], 'CN' => [-6.7068, 108.5571],
        'CP' => [-6.6933, 108.5584], 'KYA' => [-6.9121, 107.6010],
        'GM' => [-6.6742, 108.5412], 'KH' => [-6.6788, 108.5198],
        'MA' => [-6.6874, 108.4991], 'SKP' => [-6.6901, 108.4891],
        'RDN' => [-6.7012, 108.4789], 'KBS' => [-6.7123, 108.4689],
        'NTG' => [-6.7234, 108.4578], 'KGD' => [-6.7356, 108.4467],
        'KRR' => [-6.7478, 108.4356], 'PAT' => [-6.7590, 108.4245],
        'KRT' => [-6.7712, 108.4134], 'BMA' => [-6.7834, 108.4023],
        'LG' => [-6.7956, 108.3912], 'PPK' => [-6.8078, 108.3801],
        'SGG' => [-6.8200, 108.3690], 'LRA' => [-6.8322, 108.3579],
        'KGG' => [-6.8444, 108.3468], 'CLD' => [-6.8566, 108.3357],
        'SDU' => [-6.8688, 108.3246], 'LWG' => [-6.8810, 108.3135],
        'CNP' => [-6.8932, 108.3024], 'CNK' => [-6.7068, 108.5571],
        'BDW' => [-6.9121, 107.6010], 'KTA' => [-7.7177, 109.9131],
        'YK' => [-7.7889, 110.3634], 'KMT' => [-7.7177, 109.9131],
        'PWS' => [-7.5564, 110.8281], 'SLO' => [-7.5564, 110.8281],
        'SR' => [-7.2476, 112.7380], 'SGU' => [-7.2658, 112.7507],
        'BJR' => [-7.7500, 110.4925], 'KNP' => [-7.7245, 110.6089],
        'BJG' => [-7.6710, 110.6115], 'CI' => [-7.6465, 110.6128],
        'MNJ' => [-7.6187, 110.6141], 'AW' => [-7.6050, 110.6150],
        'TSM' => [-7.6219, 110.6200], 'IH' => [-7.6488, 110.6245],
        'RJP' => [-7.6757, 110.6290], 'CAW' => [-7.7026, 110.6335],
        'CAA' => [-7.7210, 110.6370], 'CPD' => [-7.7400, 110.6400],
        'BMW' => [-7.7600, 110.6420], 'WB' => [-7.7790, 110.6440],
        'CB' => [-7.7980, 110.6460], 'LO' => [-7.8170, 110.6480],
        'KRAI' => [-7.8360, 110.6500], 'LL' => [-7.8550, 110.6520],
        'LBJ' => [-7.8740, 110.6540], 'NG' => [-7.8930, 110.6560],
        'CCL' => [-7.9120, 110.6580], 'HRP' => [-7.9310, 110.6600],
        'RCK' => [-7.9500, 110.6620], 'CMK' => [-7.9690, 110.6640],
        'GDB' => [-7.9880, 110.6660], 'KAC' => [-8.0070, 110.6680],
        'CTH' => [-8.0260, 110.6700], 'BD' => [-6.9147, 107.6028],
        'SPJ' => [-6.9200, 107.6000], 'BH' => [-6.9250, 107.5980],
        'KRN' => [-6.9300, 107.5960], 'KDN' => [-6.9350, 107.5940],
        'TRK' => [-6.9400, 107.5920], 'MR' => [-6.9450, 107.5900],
        'CRM' => [-6.9500, 107.5880], 'SBO' => [-6.9550, 107.5860],
        'PTR' => [-6.9600, 107.5840], 'JG' => [-6.9650, 107.5820],
        'SMB' => [-6.9700, 107.5800], 'POK' => [-6.9750, 107.5780],
        'PDJ' => [-6.2765, 106.8227], 'KBY' => [-6.2500, 106.8227],
        'PLM' => [-6.2250, 106.8227], 'PDRG' => [-6.2300, 106.8227],
        'JAKK' => [-6.1500, 106.8227], 'JAY' => [-6.1700, 106.8227],
        'MGB' => [-6.1800, 106.8227], 'SW' => [-6.2000, 106.8227],
        'JUA' => [-6.2150, 106.8227], 'CW' => [-6.2300, 106.8227],
        'TEB' => [-6.2450, 106.8227], 'DRN' => [-6.2600, 106.8227],
        'PSMB' => [-6.2750, 106.8227], 'PSM' => [-6.2900, 106.8227],
        'TNT' => [-6.3050, 106.8227], 'LNA' => [-6.3200, 106.8227],
        'UP' => [-6.3350, 106.8227], 'UI' => [-6.3500, 106.8227],
        'POC' => [-6.3650, 106.8227], 'DPB' => [-6.3800, 106.8227],
        'DP' => [-6.3950, 106.8227], 'CTA' => [-6.4100, 106.8227],
        'BJD' => [-6.4250, 106.8227], 'CLT' => [-6.4400, 106.8227],
        'BST' => [-6.4550, 106.8227], 'BPR' => [-6.4700, 106.8227],
        'PI' => [-6.4850, 106.8227], 'KDS' => [-6.5000, 106.8227],
        'RW' => [-6.5150, 106.8227], 'BOI' => [-6.5300, 106.8227],
        'TKO' => [-6.5450, 106.8227], 'PSG' => [-6.5600, 106.8227],
        'GRG' => [-6.5750, 106.8227], 'DU' => [-6.5900, 106.8227],
        'THB' => [-6.1750, 106.7920], 'KAT' => [-6.1500, 106.7920],
        'SUDB' => [-6.1550, 106.7920], 'SUD' => [-6.1850, 106.8230],
        'STA' => [-6.1750, 106.8220], 'SKH' => [-6.1850, 106.8230],
        'PNT' => [-6.1950, 106.8230], 'WNG' => [-6.2050, 106.8230],
        'IDO' => [-6.2150, 106.8230], 'TLN' => [-6.2250, 106.8230],
        'RI' => [-6.2350, 106.8230], 'GDS' => [-6.2450, 106.8230],
        'CRG' => [-6.2550, 106.8230], 'LP' => [-6.2650, 106.8230],
        'SSI' => [-6.2750, 106.8230], 'CBB' => [-6.2850, 106.8230],
        'CIK' => [-6.2950, 106.8230], 'CJ' => [-6.3050, 106.8230],
        'MLB' => [-6.3150, 106.8230], 'TPR' => [-6.3250, 106.8230],
        'SLJ' => [-6.3350, 106.8230], 'CRJ' => [-6.3450, 106.8230],
        'CPY' => [-6.3550, 106.8230], 'RM' => [-6.3650, 106.8230],
        'CPT' => [-6.3750, 106.8230], 'RK' => [-6.3850, 106.8230],
        'JBU' => [-6.3950, 106.8230], 'CT' => [-6.4050, 106.8230],
        'CKL' => [-6.4150, 106.8230], 'WLT' => [-6.4250, 106.8230],
        'SG' => [-6.4350, 106.8230], 'KRA' => [-6.4450, 106.8230],
        'TOJB' => [-6.4550, 106.8230], 'CLG' => [-6.4650, 106.8230],
        'KEN' => [-6.4750, 106.8230], 'MER' => [-6.4850, 106.8230],
        'SMO' => [-6.4950, 106.8230], 'YIA' => [-7.7900, 110.3630],
        'SB' => [-7.2658, 112.7507], 'BOO' => [-6.5950, 106.7970],
        'BOP' => [-6.6100, 106.7970], 'BTT' => [-6.6250, 106.7960],
        'CS' => [-6.6400, 106.7950], 'MSG' => [-6.6550, 106.7940],
        'CGB' => [-6.6700, 106.7930], 'CCR' => [-6.6850, 106.7920],
        'CJE' => [-6.7000, 106.7910], 'PRK' => [-6.7150, 106.7900],
        'CBD' => [-6.7300, 106.7890], 'KE' => [-6.7450, 106.7880],
        'PON' => [-6.7600, 106.7870], 'CSA' => [-6.7750, 106.7860],
        'SI' => [-6.7900, 106.7850], 'SLW' => [-6.8050, 106.7840],
        'BLP' => [-6.8200, 106.7830], 'MGS' => [-6.8350, 106.7820],
        'TGG' => [-6.8500, 106.7810], 'KEJ' => [-6.8650, 106.7800],
        'PDS' => [-6.8800, 106.7790], 'TW' => [-6.8950, 106.7780],
        'KSO' => [-6.9100, 106.7770], 'GD' => [-6.9250, 106.7760],
        'GPK' => [-6.9400, 106.7750], 'SUM' => [-6.9550, 106.7740],
        'SLM' => [-6.9700, 106.7730], 'KO' => [-6.9850, 106.7720],
        'KDO' => [-7.0000, 106.7710], 'KTG' => [-7.0150, 106.7700],
        'AGO' => [-7.0300, 106.7690], 'BWI' => [-7.0450, 106.7680],
        'RGP' => [-7.0600, 106.7670], 'SGJ' => [-7.0750, 106.7660],
        'TGR' => [-7.0900, 106.7650], 'KSL' => [-7.1050, 106.7640],
        'SWD' => [-7.1200, 106.7630], 'GLM' => [-7.1350, 106.7620],
        'KBR' => [-7.1500, 106.7610], 'MRW' => [-7.1650, 106.7600],
        'GRN' => [-7.1800, 106.7590], 'SPL' => [-7.1950, 106.7580],
        'KLT' => [-7.2100, 106.7570], 'KTK' => [-7.2250, 106.7560],
        'AJ' => [-7.2400, 106.7550], 'JR' => [-7.2550, 106.7540],
        'MI' => [-7.2700, 106.7530], 'RBP' => [-7.2850, 106.7520],
        'BSS' => [-7.3000, 106.7510], 'TGL' => [-7.3150, 106.7500],
        'JTR' => [-7.3300, 106.7490], 'RDA' => [-7.3450, 106.7480],
        'KK' => [-7.3600, 106.7470], 'RN' => [-7.3750, 106.7460],
        'MLS' => [-7.3900, 106.7450], 'LEC' => [-7.4050, 106.7440],
        'PB' => [-7.4200, 106.7430], 'BYM' => [-7.4350, 106.7420],
        'GI' => [-7.4500, 106.7410], 'RO' => [-7.4650, 106.7400],
        'PS' => [-7.4800, 106.7390], 'PSJ' => [-7.4950, 106.7380],
        'WNR' => [-7.5100, 106.7370], 'GRT' => [-7.5250, 106.7360],
        'CIR' => [-7.5400, 106.7350], 'AND' => [-7.5550, 106.7340],
        'CMD' => [-7.5700, 106.7330], 'CMI' => [-7.5850, 106.7320],
        'GK' => [-7.6000, 106.7310], 'PDL' => [-7.6150, 106.7300],
        'CLE' => [-7.6300, 106.7290], 'SKT' => [-7.6450, 106.7280],
        'MSI' => [-7.6600, 106.7270], 'RH' => [-7.6750, 106.7260],
        'CD' => [-7.6900, 106.7250], 'CG' => [-7.7050, 106.7240],
        'PLD' => [-7.7200, 106.7230], 'SUT' => [-7.7350, 106.7220],
        'CA' => [-7.7500, 106.7210], 'PWK' => [-7.7650, 106.7200],
        'SAD' => [-7.7800, 106.7190], 'CBR' => [-7.7950, 106.7180],
    ];

    private ?array $mockData = null;

    public function getActiveTrains(): array
    {
        $this->seedFromMockIfEmpty();

        $now = now()->format('H:i:s');
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

            $position = $this->calculatePosition($schedules, $now);
            if ($position === null) {
                continue;
            }

            $nextStation = $this->getNextStation($schedules, $now);

            $activeTrains[] = [
                'id' => $train->id,
                'train_code' => $train->train_code,
                'name' => $train->name,
                'latitude' => $position['latitude'],
                'longitude' => $position['longitude'],
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

            $segmentStart = $current->departure_time;
            $segmentEnd = $next->arrival_time;

            if ($now >= $current->arrival_time && $now < $current->departure_time) {
                return [
                    'latitude' => $current->station->latitude,
                    'longitude' => $current->station->longitude,
                ];
            }

            if ($now >= $segmentStart && $now <= $segmentEnd) {
                $progress = $this->calculateProgress($segmentStart, $segmentEnd, $now);

                $latFrom = (float) $current->station->latitude;
                $lngFrom = (float) $current->station->longitude;
                $latTo = (float) $next->station->latitude;
                $lngTo = (float) $next->station->longitude;

                return [
                    'latitude' => $latFrom + ($latTo - $latFrom) * $progress,
                    'longitude' => $lngFrom + ($lngTo - $lngFrom) * $progress,
                ];
            }
        }

        $lastSchedule = $schedules->last();
        if ($now >= $lastSchedule->arrival_time) {
            return [
                'latitude' => (float) $lastSchedule->station->latitude,
                'longitude' => (float) $lastSchedule->station->longitude,
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
            if ($now < $schedule->arrival_time || ($schedule->arrival_time === null && $now < $schedule->departure_time)) {
                return [
                    'name' => $schedule->station->name,
                    'arrival_time' => $schedule->arrival_time,
                ];
            }
        }

        return null;
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

        $allStationCodes = [];
        foreach ($data['data'] as $train) {
            foreach ($train['paths'] as $path) {
                $allStationCodes[$path['st_cd']] = true;
            }
        }

        foreach (array_keys($allStationCodes) as $code) {
            $name = $this->getStationName($code);
            $coords = $this->stationCoords[$code] ?? [0, 0];
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

        foreach ($data['data'] as $trainData) {
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
            'KYA' => 'Kiaracondong', 'GM' => 'Gumilir', 'KH' => 'Karangsuci',
            'MA' => 'Mertasari', 'SKP' => 'Sidangu', 'KTA' => 'Kutoarjo',
            'SR' => 'Surabaya Pasarturi', 'BDW' => 'Bandung',
            'JAKK' => 'Jakarta Kota', 'PDRG' => 'Pasar Minggu',
            'BOO' => 'Bogor', 'BOP' => 'Bogor Paledang',
            'THB' => 'Tanah Abang', 'SUD' => 'Sudirman',
            'MRI' => 'Manggarai', 'CKI' => 'Cikini', 'GDD' => 'Gondangdia',
            'JNG' => 'Jatinegara', 'BKS' => 'Bekasi', 'BKST' => 'Bekasi Timur',
            'CKR' => 'Cikarang', 'KRI' => 'Kediri', 'BGR' => 'Bogor',
            'SRD' => 'Serpong', 'CRB' => 'Cirebon', 'BBN' => 'Bumiayu',
            'MGW' => 'Magelang', 'LPN' => 'Lempuyangan', 'KTG' => 'Ketanggungan',
            'DO' => 'Doplang', 'KDO' => 'Kedungombo', 'SMO' => 'Semabung',
            'MER' => 'Merak', 'CLG' => 'Cilegon', 'KEN' => 'Krenceng',
            'PI' => 'Pasar Ibu', 'BPR' => 'Buper', 'BST' => 'Batu Sember',
            'BJ' => 'Banjaran', 'KPS' => 'Kepuh', 'SRJ' => 'Surabaya',
            'BWO' => 'Bower', 'BBT' => 'Babat', 'GEB' => 'Gerbang',
            'PC' => 'Paciran', 'SBN' => 'Sumberan', 'LMG' => 'Lamongan',
            'DD' => 'Duduk', 'CME' => 'Cerme', 'BNW' => 'Benowo',
            'KDA' => 'Kandangan', 'TES' => 'Tandes', 'NODE_SB' => 'Surabaya',
            'NODE_STM' => 'Stasiun Utama', 'BJR' => 'Banjaran',
            'KNP' => 'Kroncong', 'BJG' => 'Bajangan', 'CI' => 'Ceper',
            'MNJ' => 'Manjung', 'AW' => 'Awan', 'TSM' => 'Tasikmadu',
            'IH' => 'Ihan', 'RJP' => 'Rejasa', 'CAW' => 'Candiwulan',
            'CAA' => 'Candi Asri', 'CPD' => 'Cepedak', 'BMW' => 'Bumi Watu',
            'WB' => 'Watu Bara', 'CB' => 'Cabe', 'LO' => 'Lorok',
            'KRAI' => 'Kramat Ireng', 'LL' => 'Lilacap', 'LBJ' => 'Lebak Jaya',
            'NG' => 'Nglorok', 'CCL' => 'Cicel', 'HRP' => 'Harapan',
            'RCK' => 'Racak', 'CMK' => 'Cemaka', 'GDB' => 'Gedebeg',
            'KAC' => 'Kacangan', 'CTH' => 'Cetho', 'PDJ' => 'Pondok Jati',
            'KBY' => 'Kebayoran', 'PLM' => 'Palmerah',
        ];

        return $names[$code] ?? 'Stasiun ' . $code;
    }

    private function loadMockData(): ?array
    {
        if ($this->mockData !== null) {
            return $this->mockData;
        }

        $path = base_path(self::MOCK_PATH);

        if (!file_exists($path)) {
            Log::warning('Mock data file not found at: ' . $path);
            return null;
        }

        $content = file_get_contents($path);

        $this->mockData = json_decode($content, true);

        return $this->mockData;
    }
}
