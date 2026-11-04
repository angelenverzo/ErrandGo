<?php
// Shared ErrandGo settings (services, areas, statuses, helpers).

const ERRANDGO_TAGLINE = 'One App, Every Errand';

// Services from the business profile (Chapter I, VIII).
const SERVICES = [
    'Food Delivery',
    'Grocery Shopping',
    'Parcel/Document Delivery',
    'Office-Supply Purchasing',
    'Moving Assistance',
    'Other Errands',
];

// Proposed service area: Mandaluyong City barangays (pickup area -> "demand by location").
const PICKUP_AREAS = [
    'Addition Hills', 'Barangka Drive', 'Barangka Ibaba', 'Barangka Ilaya', 'Barangka Itaas',
    'Buayang Bato', 'Burol', 'Daang Bakal', 'Hagdang Bato Itaas', 'Hagdang Bato Libis',
    'Harapin ang Bukas', 'Highway Hills', 'Hulo', 'Mabini-J. Rizal', 'Malamig', 'Mauway',
    'Namayan', 'New Zañiga', 'Old Zañiga', 'Pleasant Hills', 'Poblacion', 'San Jose',
    'Vergara', 'Wack-Wack Greenhills',
];

// Payment preference is only recorded (survey: cash 81%, e-wallet 75.9%); no payment processing yet.
const PAYMENT_METHODS = ['Cash', 'E-wallet'];

const VEHICLE_TYPES = ['None / On foot', 'Bicycle', 'Motorcycle', 'Car', 'Van', 'Truck'];

// ---- Update 6 settings ----
const ACCEPT_TIMEOUT_MINUTES = 15;   // accepted but not started within this time -> released to other workers
const ETL_INTERVAL_MINUTES = 5;      // the warehouse refreshes itself at most this often
const MAX_ERRANDS_PER_BOOKING = 5;   // different errands in one request
const MAX_RIDERS = 4;                // lead rider + up to 3 additional riders
const MAX_ITEMS_PER_ERRAND = 30;
const MAX_WEIGHT_KG = 300;
const BULK_ITEM_COUNT = 5;           // a shopping list this long counts as a bulk errand
const BULK_WEIGHT_KG = 15;           // heavier than this counts as a bulk errand
const BULK_SERVICES = ['Moving Assistance'];
const NO_DROPOFF_SERVICES = ['Other Errands'];                    // drop-off is optional for these
const WEIGHT_REQUIRED_SERVICES = ['Parcel/Document Delivery', 'Moving Assistance'];   // weight is required for these

// Rating checkboxes (what went well / what could be better).
const RATING_TAGS_GOOD = ['On time', 'Friendly', 'Careful handling', 'Good communication', 'Followed instructions', 'Neat and presentable'];
const RATING_TAGS_BAD = ['Late', 'Rude or unprofessional', 'Item damaged or wrong item'];

// Distance is ESTIMATED from the centre of the pickup barangay to the centre of the drop-off barangay
// (straight line x ROAD_FACTOR, minimum MIN_BILLABLE_KM). The coordinates below are APPROXIMATE PLACEHOLDERS
// typed without map access: check each barangay on a map (Google Maps > right-click > copy lat, lng) and replace them.
const ROAD_FACTOR = 1.3;
const MIN_BILLABLE_KM = 1.0;
const AREA_COORDS = [
    'Addition Hills' => [14.5835, 121.0310], 'Barangka Drive' => [14.5715, 121.0360],
    'Barangka Ibaba' => [14.5690, 121.0335], 'Barangka Ilaya' => [14.5705, 121.0385],
    'Barangka Itaas' => [14.5735, 121.0380], 'Buayang Bato' => [14.5725, 121.0325],
    'Burol' => [14.5855, 121.0350], 'Daang Bakal' => [14.5745, 121.0440],
    'Hagdang Bato Itaas' => [14.5700, 121.0415], 'Hagdang Bato Libis' => [14.5675, 121.0395],
    'Harapin ang Bukas' => [14.5880, 121.0455], 'Highway Hills' => [14.5790, 121.0505],
    'Hulo' => [14.5775, 121.0330], 'Mabini-J. Rizal' => [14.5800, 121.0305],
    'Malamig' => [14.5790, 121.0415], 'Mauway' => [14.5765, 121.0455],
    'Namayan' => [14.5745, 121.0295], 'New Zañiga' => [14.5775, 121.0405],
    'Old Zañiga' => [14.5790, 121.0385], 'Pleasant Hills' => [14.5855, 121.0420],
    'Poblacion' => [14.5815, 121.0345], 'San Jose' => [14.5895, 121.0325],
    'Vergara' => [14.5770, 121.0480], 'Wack-Wack Greenhills' => [14.5905, 121.0500],
];

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function status_label(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

// ---- Worker matching (service type, vehicle, capability, availability) ----
const MOVING_VEHICLES = ['Car', 'Van', 'Truck'];

// Vehicles allowed for a heavy load (null = any vehicle).
function required_vehicles_for_weight(?float $kg): ?array
{
    if ($kg === null) {
        return null;
    }
    if ($kg > 100) {
        return ['Van', 'Truck'];
    }
    if ($kg > 30) {
        return ['Car', 'Van', 'Truck'];
    }
    if ($kg > BULK_WEIGHT_KG) {
        return ['Motorcycle', 'Car', 'Van', 'Truck'];
    }
    return null;
}

function worker_can_do(array $worker, string $service, ?float $weightKg = null): bool
{
    $caps = array_filter(array_map('trim', explode(',', (string) ($worker['capabilities'] ?? ''))));
    if (count($caps) > 0 && !in_array($service, $caps, true)) {
        return false;
    }
    if ($service === 'Moving Assistance' && !in_array($worker['vehicle_type'] ?? '', MOVING_VEHICLES, true)) {
        return false;
    }
    $need = required_vehicles_for_weight($weightKg);
    if ($need !== null && !in_array($worker['vehicle_type'] ?? '', $need, true)) {
        return false;
    }
    return true;
}

function fetch_worker(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT id, full_name, vehicle_type, capabilities, verification_status, is_available
                           FROM users WHERE id = ? AND role = 'worker'");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// ---- In-app notifications ----
function notify(PDO $pdo, int $userId, string $message, ?string $link = null): void
{
    try {
        $pdo->prepare('INSERT INTO notifications (user_id, message, link) VALUES (?, ?, ?)')
            ->execute([$userId, mb_substr($message, 0, 255), $link]);
    } catch (Throwable $ex) {
        error_log('ErrandGo notify error: ' . $ex->getMessage()); // never break the main action
    }
}

function notify_admins(PDO $pdo, string $message, ?string $link = null): void
{
    try {
        foreach ($pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll() as $a) {
            notify($pdo, (int) $a['id'], $message, $link);
        }
    } catch (Throwable $ex) {
        error_log('ErrandGo notify_admins error: ' . $ex->getMessage());
    }
}

// Tell every verified, available worker who can do this service type (and carry this weight).
function notify_matching_workers(PDO $pdo, string $service, string $message, ?float $weightKg = null, array $excludeIds = []): void
{
    try {
        $rows = $pdo->query("SELECT id, vehicle_type, capabilities FROM users
                             WHERE role = 'worker' AND verification_status = 'verified' AND is_available = 1")->fetchAll();
        foreach ($rows as $w) {
            if (in_array((int) $w['id'], $excludeIds, true)) {
                continue;
            }
            if (worker_can_do($w, $service, $weightKg)) {
                notify($pdo, (int) $w['id'], $message, 'worker_dashboard.php');
            }
        }
    } catch (Throwable $ex) {
        error_log('ErrandGo notify_matching_workers error: ' . $ex->getMessage());
    }
}

// ---- Fees (current flat rate = base, plus a fee per km, plus weight and extra-rider fees) ----
function peso($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

// Returns [service_type => ['service_fee'=>float,'delivery_fee'=>float,'worker_share_pct'=>float]]
function get_pricing(PDO $pdo): array
{
    $out = [];
    try {
        foreach ($pdo->query('SELECT service_type, service_fee, delivery_fee, per_km_fee, worker_share_pct FROM pricing_rules')->fetchAll() as $r) {
            $out[$r['service_type']] = [
                'service_fee' => (float) $r['service_fee'],
                'delivery_fee' => (float) $r['delivery_fee'],
                'per_km_fee' => (float) $r['per_km_fee'],
                'worker_share_pct' => (float) $r['worker_share_pct'],
            ];
        }
    } catch (Throwable $ex) {
        error_log('ErrandGo pricing error: ' . $ex->getMessage());
    }
    return $out;
}
