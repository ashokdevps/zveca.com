<?php
/**
 * Face-verified punch in / out with live location.
 *
 * The browser (dashboard/assets/js/face-punch.js, face-api.js models in assets/vendor/face-api) finds one live face,
 * checks liveness (a blink or a head turn) and sends a 128-number face descriptor plus a small snapshot. The server
 * never sends the enrolled template to the browser: it compares the two descriptors itself (Euclidean distance,
 * Settings → face_match_threshold) and stores the snapshot with the punch so HR can review it.
 *
 * This is a strong deterrent with a full audit trail (photo, match score, GPS, device, IP), not a certified biometric
 * system: a determined person with a video of a colleague could still try to fool a browser camera.
 *
 * Settings: face_punch (off | optional | required), face_match_threshold, punch_require_location,
 *           office_lat / office_lng / geofence_radius (metres; blank = no geofence).
 */

const FACE_DIMS = 128;
const FACE_MAX_FAILS = 8; // failed matches per employee per 15 minutes

function face_mode(): string
{
    $m = (string) setting('face_punch', 'off');
    return in_array($m, ['optional', 'required'], true) ? $m : 'off';
}

function face_threshold(): float
{
    $t = (float) setting('face_match_threshold', '0.50');
    return $t >= 0.3 && $t <= 0.7 ? $t : 0.5;
}

function face_profile(int $employeeId): ?array
{
    return one('SELECT * FROM hrm_face_profiles WHERE employee_id = ?', [$employeeId]);
}

/** A posted descriptor (JSON array of 128 numbers), or null if it isn't one. */
function face_parse_descriptor($raw): ?array
{
    $d = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($d) || count($d) !== FACE_DIMS) return null;
    $out = [];
    foreach (array_values($d) as $v) {
        if (!is_numeric($v) || !is_finite((float) $v) || abs((float) $v) > 2) return null;
        $out[] = round((float) $v, 6);
    }
    // An all-zero (or constant) vector is not a face.
    return max($out) - min($out) > 0.05 ? $out : null;
}

function face_distance(array $a, array $b): float
{
    $s = 0.0;
    for ($i = 0; $i < FACE_DIMS; $i++) $s += ($a[$i] - $b[$i]) ** 2;
    return sqrt($s);
}

/** "92%" style similarity for people (1 - distance, clamped). */
function face_similarity(?float $distance): string
{
    return $distance === null ? '—' : max(0, min(100, (int) round((1 - $distance) * 100))) . '%';
}

/**
 * Saves a JPEG data URL (from the camera) under uploads/faces/ as e<employee>_<kind>_<date>_<rand>.jpg.
 * The e<id>_ prefix is what file.php uses to let an employee see their own photos. Returns the path or null.
 */
function face_save_snapshot(?string $dataUrl, int $employeeId, string $kind): ?string
{
    if (!$dataUrl || !preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) return null;
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) < 1000 || strlen($bin) > 600 * 1024 || substr($bin, 0, 3) !== "\xFF\xD8\xFF") return null;
    $info = @getimagesizefromstring($bin);
    if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] > 1280 || $info[1] > 1280) return null;
    $dir = STORAGE_DIR . '/uploads/faces';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'e' . $employeeId . '_' . preg_replace('/[^a-z]/', '', $kind) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.jpg';
    return file_put_contents("$dir/$name", $bin) ? "faces/$name" : null;
}

/** Office location for the geofence, or null when not configured. [lat, lng, radius metres] */
function office_geofence(): ?array
{
    $lat = setting('office_lat', '');
    $lng = setting('office_lng', '');
    $r = (int) setting('geofence_radius', '');
    if (!is_numeric($lat) || !is_numeric($lng) || $r <= 0) return null;
    return [(float) $lat, (float) $lng, $r];
}

/** Great-circle distance in metres. */
function geo_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): int
{
    $r = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
}

function format_distance(?int $m): string
{
    if ($m === null) return '—';
    return $m < 1000 ? $m . ' m' : number_format($m / 1000, $m < 10000 ? 1 : 0) . ' km';
}

/** Failed face matches for an employee in the last 15 minutes (from the activity log). */
function face_recent_failures(int $employeeId): int
{
    return (int) val("SELECT COUNT(*) FROM hrm_activity_log WHERE action = 'face_mismatch' AND entity = 'attendance' AND entity_id = ? AND created_at >= ?",
        [$employeeId, date('Y-m-d H:i:s', time() - 900)]);
}

/** Config the punch dialog needs (no secrets: the enrolled template stays on the server). */
function face_client_config(int $employeeId): array
{
    $fence = office_geofence();
    return [
        'mode' => face_mode(), 'enrolled' => (bool) face_profile($employeeId), 'requireLocation' => setting('punch_require_location', '0') === '1',
        'fence' => $fence ? ['lat' => $fence[0], 'lng' => $fence[1], 'radius' => $fence[2]] : null,
        'models' => base_path() . '/dashboard/assets/vendor/face-api/model', 'lib' => asset('vendor/face-api/face-api.js'),
        'punchUrl' => url('attendance', ['a' => 'punch']), 'setupUrl' => url('attendance', ['a' => 'face_setup']),
    ];
}
