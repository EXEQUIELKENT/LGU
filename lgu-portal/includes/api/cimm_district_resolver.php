<?php
/**
 * Quezon City district resolver — shared by anything that needs to turn a
 * coordinate pair or a free-text address into "District 1".."District 6",
 * the same 6-district split already used by citizenrepform.php's map picker
 * and rendered everywhere via the established district-badge.d1..d6 /
 * #districtInfo.d1..d6 colour scheme (profile.php, requests.php,
 * current_reports.php, pending_reports.php, archive_reports.php).
 *
 * The barangay centroid list below is ported verbatim from
 * citizenrepform.php's QC_BARANGAYS_COMPREHENSIVE JS array (itself derived
 * from the QuezonCity_Barangays.geojson centroids) so a barangay maps to the
 * same district everywhere in the app.
 */
declare(strict_types=1);

if (!function_exists('cimm_qc_barangay_district_points')) {
    function cimm_qc_barangay_district_points(): array {
        static $points = null;
        if ($points !== null) {
            return $points;
        }
        $points = [
            // District 1
            ['name' => 'Alicia', 'lat' => 14.6616, 'lng' => 121.0247, 'district' => 'District 1'],
            ['name' => 'Bagong Pag-asa', 'lat' => 14.6585, 'lng' => 121.0347, 'district' => 'District 1'],
            ['name' => 'Bahay Toro', 'lat' => 14.6669, 'lng' => 121.0281, 'district' => 'District 1'],
            ['name' => 'Balingasa', 'lat' => 14.6506, 'lng' => 121.0031, 'district' => 'District 1'],
            ['name' => 'Bungad', 'lat' => 14.6503, 'lng' => 121.0246, 'district' => 'District 1'],
            ['name' => 'Damar', 'lat' => 14.6476, 'lng' => 121.0009, 'district' => 'District 1'],
            ['name' => 'Damayan', 'lat' => 14.6384, 'lng' => 121.0145, 'district' => 'District 1'],
            ['name' => 'Del Monte', 'lat' => 14.6434, 'lng' => 121.0147, 'district' => 'District 1'],
            ['name' => 'Katipunan', 'lat' => 14.6559, 'lng' => 121.0172, 'district' => 'District 1'],
            ['name' => 'Lourdes', 'lat' => 14.6256, 'lng' => 121.002, 'district' => 'District 1'],
            ['name' => 'Maharlika', 'lat' => 14.6339, 'lng' => 120.9963, 'district' => 'District 1'],
            ['name' => 'Manresa', 'lat' => 14.6417, 'lng' => 121.0025, 'district' => 'District 1'],
            ['name' => 'Mariblo', 'lat' => 14.6345, 'lng' => 121.0162, 'district' => 'District 1'],
            ['name' => 'Masambong', 'lat' => 14.6417, 'lng' => 121.0095, 'district' => 'District 1'],
            ['name' => 'N.S. Amoranto (Gintong Silahis)', 'lat' => 14.6327, 'lng' => 120.9935, 'district' => 'District 1'],
            ['name' => 'Nayong Kanluran', 'lat' => 14.6403, 'lng' => 121.0251, 'district' => 'District 1'],
            ['name' => 'Paang Bundok', 'lat' => 14.627, 'lng' => 120.9917, 'district' => 'District 1'],
            ['name' => 'Pag-ibig sa Nayon', 'lat' => 14.6475, 'lng' => 120.9975, 'district' => 'District 1'],
            ['name' => 'Paltok', 'lat' => 14.6431, 'lng' => 121.0238, 'district' => 'District 1'],
            ['name' => 'Paraiso', 'lat' => 14.6383, 'lng' => 121.0175, 'district' => 'District 1'],
            ['name' => 'Phil-Am', 'lat' => 14.6478, 'lng' => 121.0317, 'district' => 'District 1'],
            ['name' => 'Project 6', 'lat' => 14.6582, 'lng' => 121.0405, 'district' => 'District 1'],
            ['name' => 'Ramon Magsaysay', 'lat' => 14.66, 'lng' => 121.0237, 'district' => 'District 1'],
            ['name' => 'Saint Peter', 'lat' => 14.6348, 'lng' => 120.9995, 'district' => 'District 1'],
            ['name' => 'Salvacion', 'lat' => 14.6265, 'lng' => 120.9934, 'district' => 'District 1'],
            ['name' => 'San Antonio', 'lat' => 14.6505, 'lng' => 121.0174, 'district' => 'District 1'],
            ['name' => 'San Isidro Labrador', 'lat' => 14.6236, 'lng' => 120.9963, 'district' => 'District 1'],
            ['name' => 'San Jose', 'lat' => 14.64, 'lng' => 120.9934, 'district' => 'District 1'],
            ['name' => 'Santa Cruz', 'lat' => 14.6359, 'lng' => 121.0205, 'district' => 'District 1'],
            ['name' => 'Santa Teresita', 'lat' => 14.6214, 'lng' => 120.999, 'district' => 'District 1'],
            ['name' => 'Santo Cristo', 'lat' => 14.6607, 'lng' => 121.0297, 'district' => 'District 1'],
            ['name' => 'Santo Domingo (Matalahib)', 'lat' => 14.6297, 'lng' => 121.0077, 'district' => 'District 1'],
            ['name' => 'Sienna', 'lat' => 14.6367, 'lng' => 121.0054, 'district' => 'District 1'],
            ['name' => 'Talayan', 'lat' => 14.6359, 'lng' => 121.011, 'district' => 'District 1'],
            ['name' => 'Vasra', 'lat' => 14.6569, 'lng' => 121.0463, 'district' => 'District 1'],
            ['name' => 'Veterans Village', 'lat' => 14.6542, 'lng' => 121.0219, 'district' => 'District 1'],
            ['name' => 'West Triangle', 'lat' => 14.6444, 'lng' => 121.0302, 'district' => 'District 1'],
            // District 2
            ['name' => 'Bagong Silangan', 'lat' => 14.7059, 'lng' => 121.1086, 'district' => 'District 2'],
            ['name' => 'Batasan Hills', 'lat' => 14.6807, 'lng' => 121.0961, 'district' => 'District 2'],
            ['name' => 'Commonwealth', 'lat' => 14.7038, 'lng' => 121.0854, 'district' => 'District 2'],
            ['name' => 'Holy Spirit', 'lat' => 14.6794, 'lng' => 121.0787, 'district' => 'District 2'],
            ['name' => 'Payatas', 'lat' => 14.7123, 'lng' => 121.0972, 'district' => 'District 2'],
            // District 3
            ['name' => 'Amihan', 'lat' => 14.6325, 'lng' => 121.0684, 'district' => 'District 3'],
            ['name' => 'Bagumbayan', 'lat' => 14.607, 'lng' => 121.0788, 'district' => 'District 3'],
            ['name' => 'Bagumbuhay', 'lat' => 14.6252, 'lng' => 121.0647, 'district' => 'District 3'],
            ['name' => 'Bayanihan', 'lat' => 14.6152, 'lng' => 121.0694, 'district' => 'District 3'],
            ['name' => 'Blue Ridge A', 'lat' => 14.6172, 'lng' => 121.0728, 'district' => 'District 3'],
            ['name' => 'Blue Ridge B', 'lat' => 14.6173, 'lng' => 121.0762, 'district' => 'District 3'],
            ['name' => 'Camp Aguinaldo', 'lat' => 14.6102, 'lng' => 121.0621, 'district' => 'District 3'],
            ['name' => 'Claro', 'lat' => 14.6317, 'lng' => 121.0641, 'district' => 'District 3'],
            ['name' => 'Dioquino Zobel', 'lat' => 14.6197, 'lng' => 121.0651, 'district' => 'District 3'],
            ['name' => 'Duyan-Duyan', 'lat' => 14.6300, 'lng' => 121.0671, 'district' => 'District 3'],
            ['name' => 'E. Rodriguez', 'lat' => 14.6264, 'lng' => 121.0521, 'district' => 'District 3'],
            ['name' => 'East Kamias', 'lat' => 14.6323, 'lng' => 121.0557, 'district' => 'District 3'],
            ['name' => 'Escopa I', 'lat' => 14.6241, 'lng' => 121.0737, 'district' => 'District 3'],
            ['name' => 'Escopa II', 'lat' => 14.6241, 'lng' => 121.0744, 'district' => 'District 3'],
            ['name' => 'Escopa III', 'lat' => 14.6271, 'lng' => 121.0732, 'district' => 'District 3'],
            ['name' => 'Escopa IV', 'lat' => 14.6255, 'lng' => 121.0741, 'district' => 'District 3'],
            ['name' => 'Libis', 'lat' => 14.6161, 'lng' => 121.0766, 'district' => 'District 3'],
            ['name' => 'Loyola Heights', 'lat' => 14.6383, 'lng' => 121.0752, 'district' => 'District 3'],
            ['name' => 'Mangga', 'lat' => 14.6255, 'lng' => 121.0623, 'district' => 'District 3'],
            ['name' => 'Marilag', 'lat' => 14.6251, 'lng' => 121.0699, 'district' => 'District 3'],
            ['name' => 'Masagana', 'lat' => 14.6182, 'lng' => 121.0665, 'district' => 'District 3'],
            ['name' => 'Matandang Balara', 'lat' => 14.6643, 'lng' => 121.0834, 'district' => 'District 3'],
            ['name' => 'Milagrosa', 'lat' => 14.6213, 'lng' => 121.0685, 'district' => 'District 3'],
            ['name' => 'Pansol', 'lat' => 14.6502, 'lng' => 121.0807, 'district' => 'District 3'],
            ['name' => 'Quirino 2-A', 'lat' => 14.6298, 'lng' => 121.0595, 'district' => 'District 3'],
            ['name' => 'Quirino 2-B', 'lat' => 14.6318, 'lng' => 121.0623, 'district' => 'District 3'],
            ['name' => 'Quirino 2-C', 'lat' => 14.634, 'lng' => 121.0633, 'district' => 'District 3'],
            ['name' => 'Quirino 3-A', 'lat' => 14.6288, 'lng' => 121.0632, 'district' => 'District 3'],
            ['name' => 'San Roque', 'lat' => 14.6196, 'lng' => 121.0623, 'district' => 'District 3'],
            ['name' => 'Silangan', 'lat' => 14.6284, 'lng' => 121.0593, 'district' => 'District 3'],
            ['name' => 'Socorro', 'lat' => 14.6168, 'lng' => 121.0583, 'district' => 'District 3'],
            ['name' => 'St. Ignatius', 'lat' => 14.6128, 'lng' => 121.0729, 'district' => 'District 3'],
            ['name' => 'Tagumpay', 'lat' => 14.6222, 'lng' => 121.0639, 'district' => 'District 3'],
            ['name' => 'Ugong Norte', 'lat' => 14.5974, 'lng' => 121.0714, 'district' => 'District 3'],
            ['name' => 'Villa Maria Clara', 'lat' => 14.6161, 'lng' => 121.0687, 'district' => 'District 3'],
            ['name' => 'West Kamias', 'lat' => 14.6302, 'lng' => 121.0493, 'district' => 'District 3'],
            ['name' => 'White Plains', 'lat' => 14.6048, 'lng' => 121.0738, 'district' => 'District 3'],
            // District 4
            ['name' => 'Bagong Lipunan ng Crame', 'lat' => 14.6117, 'lng' => 121.0483, 'district' => 'District 4'],
            ['name' => 'Botocan', 'lat' => 14.6364, 'lng' => 121.0621, 'district' => 'District 4'],
            ['name' => 'Central', 'lat' => 14.6484, 'lng' => 121.0495, 'district' => 'District 4'],
            ['name' => 'Damayang Lagi', 'lat' => 14.6173, 'lng' => 121.0232, 'district' => 'District 4'],
            ['name' => 'Don Manuel', 'lat' => 14.617, 'lng' => 121.0054, 'district' => 'District 4'],
            ['name' => 'Doña Aurora', 'lat' => 14.6161, 'lng' => 121.0091, 'district' => 'District 4'],
            ['name' => 'Doña Imelda', 'lat' => 14.6130, 'lng' => 121.0172, 'district' => 'District 4'],
            ['name' => 'Doña Josefa', 'lat' => 14.6193, 'lng' => 121.0069, 'district' => 'District 4'],
            ['name' => 'Horseshoe', 'lat' => 14.6125, 'lng' => 121.0421, 'district' => 'District 4'],
            ['name' => 'Immaculate Conception', 'lat' => 14.6224, 'lng' => 121.0443, 'district' => 'District 4'],
            ['name' => 'Kalusugan', 'lat' => 14.6225, 'lng' => 121.0216, 'district' => 'District 4'],
            ['name' => 'Kamuning', 'lat' => 14.6272, 'lng' => 121.0396, 'district' => 'District 4'],
            ['name' => 'Kaunlaran', 'lat' => 14.6156, 'lng' => 121.0438, 'district' => 'District 4'],
            ['name' => 'Kristong Hari', 'lat' => 14.6248, 'lng' => 121.0321, 'district' => 'District 4'],
            ['name' => 'Krus na Ligas', 'lat' => 14.6437, 'lng' => 121.0634, 'district' => 'District 4'],
            ['name' => 'Laging Handa', 'lat' => 14.6333, 'lng' => 121.0308, 'district' => 'District 4'],
            ['name' => 'Malaya', 'lat' => 14.6354, 'lng' => 121.0558, 'district' => 'District 4'],
            ['name' => 'Mariana', 'lat' => 14.621, 'lng' => 121.0323, 'district' => 'District 4'],
            ['name' => 'Obrero', 'lat' => 14.6276, 'lng' => 121.0299, 'district' => 'District 4'],
            ['name' => 'Old Capitol Site', 'lat' => 14.6506, 'lng' => 121.0529, 'district' => 'District 4'],
            ['name' => 'Paligsahan', 'lat' => 14.6329, 'lng' => 121.0242, 'district' => 'District 4'],
            ['name' => 'Pinagkaisahan', 'lat' => 14.6254, 'lng' => 121.0434, 'district' => 'District 4'],
            ['name' => 'Pinyahan', 'lat' => 14.6377, 'lng' => 121.048, 'district' => 'District 4'],
            ['name' => 'Roxas', 'lat' => 14.6274, 'lng' => 121.0221, 'district' => 'District 4'],
            ['name' => 'Sacred Heart', 'lat' => 14.6325, 'lng' => 121.0391, 'district' => 'District 4'],
            ['name' => 'San Isidro Galas', 'lat' => 14.6129, 'lng' => 121.0083, 'district' => 'District 4'],
            ['name' => 'San Martin de Porres', 'lat' => 14.6165, 'lng' => 121.0493, 'district' => 'District 4'],
            ['name' => 'San Vicente', 'lat' => 14.6527, 'lng' => 121.0559, 'district' => 'District 4'],
            ['name' => 'Santol', 'lat' => 14.6112, 'lng' => 121.0144, 'district' => 'District 4'],
            ['name' => 'Santo Niño', 'lat' => 14.6119, 'lng' => 121.0118, 'district' => 'District 4'],
            ['name' => 'Sikatuna Village', 'lat' => 14.6378, 'lng' => 121.0587, 'district' => 'District 4'],
            ['name' => 'South Triangle', 'lat' => 14.6357, 'lng' => 121.0361, 'district' => 'District 4'],
            ['name' => 'Tatalon', 'lat' => 14.623, 'lng' => 121.0149, 'district' => 'District 4'],
            ['name' => 'Teachers Village East', 'lat' => 14.6453, 'lng' => 121.0587, 'district' => 'District 4'],
            ['name' => 'Teachers Village West', 'lat' => 14.6425, 'lng' => 121.0564, 'district' => 'District 4'],
            ['name' => 'U.P. Campus', 'lat' => 14.6541, 'lng' => 121.0641, 'district' => 'District 4'],
            ['name' => 'U.P. Village', 'lat' => 14.6490, 'lng' => 121.0564, 'district' => 'District 4'],
            ['name' => 'Valencia', 'lat' => 14.6102, 'lng' => 121.0375, 'district' => 'District 4'],
            // District 5
            ['name' => 'Bagbag', 'lat' => 14.6983, 'lng' => 121.0289, 'district' => 'District 5'],
            ['name' => 'Capri', 'lat' => 14.7168, 'lng' => 121.0286, 'district' => 'District 5'],
            ['name' => 'Fairview', 'lat' => 14.7056, 'lng' => 121.0699, 'district' => 'District 5'],
            ['name' => 'Greater Lagro', 'lat' => 14.7247, 'lng' => 121.064, 'district' => 'District 5'],
            ['name' => 'Gulod', 'lat' => 14.7128, 'lng' => 121.0405, 'district' => 'District 5'],
            ['name' => 'Kaligayahan', 'lat' => 14.7299, 'lng' => 121.0423, 'district' => 'District 5'],
            ['name' => 'Nagkaisang Nayon', 'lat' => 14.7164, 'lng' => 121.0292, 'district' => 'District 5'],
            ['name' => 'North Fairview', 'lat' => 14.7121, 'lng' => 121.0602, 'district' => 'District 5'],
            ['name' => 'Novaliches Proper', 'lat' => 14.7195, 'lng' => 121.0365, 'district' => 'District 5'],
            ['name' => 'Pasong Putik Proper', 'lat' => 14.7351, 'lng' => 121.0601, 'district' => 'District 5'],
            ['name' => 'San Agustin', 'lat' => 14.729, 'lng' => 121.0359, 'district' => 'District 5'],
            ['name' => 'San Bartolome', 'lat' => 14.7059, 'lng' => 121.0315, 'district' => 'District 5'],
            ['name' => 'Santa Lucia', 'lat' => 14.7076, 'lng' => 121.0505, 'district' => 'District 5'],
            ['name' => 'Santa Monica', 'lat' => 14.7175, 'lng' => 121.0457, 'district' => 'District 5'],
            // District 6
            ['name' => 'Apolonio Samson', 'lat' => 14.6542, 'lng' => 121.0093, 'district' => 'District 6'],
            ['name' => 'Baesa', 'lat' => 14.6681, 'lng' => 121.0147, 'district' => 'District 6'],
            ['name' => 'Balon Bato', 'lat' => 14.6632, 'lng' => 121.0029, 'district' => 'District 6'],
            ['name' => 'Culiat', 'lat' => 14.6669, 'lng' => 121.0535, 'district' => 'District 6'],
            ['name' => 'New Era', 'lat' => 14.6646, 'lng' => 121.0604, 'district' => 'District 6'],
            ['name' => 'Pasong Tamo', 'lat' => 14.6753, 'lng' => 121.0507, 'district' => 'District 6'],
            ['name' => 'Sangandaan', 'lat' => 14.6742, 'lng' => 121.0211, 'district' => 'District 6'],
            ['name' => 'Sauyo', 'lat' => 14.6942, 'lng' => 121.0434, 'district' => 'District 6'],
            ['name' => 'Talipapa', 'lat' => 14.6824, 'lng' => 121.0238, 'district' => 'District 6'],
            ['name' => 'Tandang Sora', 'lat' => 14.6796, 'lng' => 121.0359, 'district' => 'District 6'],
            ['name' => 'Unang Sigaw', 'lat' => 14.6595, 'lng' => 121.0010, 'district' => 'District 6'],
        ];
        return $points;
    }
}

if (!function_exists('cimm_district_options')) {
    /**
     * The 6 valid Quezon City districts, derived from the barangay-centroid
     * map above — the single source of truth every district picker (Area
     * Engineer's own profile.php section, User Management's edit modal)
     * should read from instead of each hardcoding its own copy of this list.
     */
    function cimm_district_options(): array {
        static $options = null;
        if ($options !== null) {
            return $options;
        }
        $seen = [];
        foreach (cimm_qc_barangay_district_points() as $p) {
            $seen[$p['district']] = true;
        }
        $options = array_keys($seen);
        sort($options, SORT_NATURAL);
        return $options;
    }
}

if (!function_exists('cimm_is_valid_district')) {
    /**
     * Strict membership check against cimm_district_options(). $allowEmpty
     * defaults true because every existing save path treats "no district
     * assigned yet" (empty string) as valid — engineers/area engineers can
     * save their profile before picking a district.
     */
    function cimm_is_valid_district(string $district, bool $allowEmpty = true): bool {
        if ($district === '') {
            return $allowEmpty;
        }
        return in_array($district, cimm_district_options(), true);
    }
}

if (!function_exists('cimm_resolve_district_from_coords')) {
    /** Nearest-centroid match against the 142 barangay points above. */
    function cimm_resolve_district_from_coords(?float $lat, ?float $lng): ?string {
        if ($lat === null || $lng === null || $lat == 0.0 || $lng == 0.0) {
            return null;
        }
        $bestDistrict = null;
        $bestDistSq = null;
        foreach (cimm_qc_barangay_district_points() as $p) {
            $dLat = $p['lat'] - $lat;
            $dLng = $p['lng'] - $lng;
            $distSq = ($dLat * $dLat) + ($dLng * $dLng);
            if ($bestDistSq === null || $distSq < $bestDistSq) {
                $bestDistSq = $distSq;
                $bestDistrict = $p['district'];
            }
        }
        return $bestDistrict;
    }
}

if (!function_exists('cimm_resolve_district_from_text')) {
    /** Longest-barangay-name substring match against a free-text address. */
    function cimm_resolve_district_from_text(string $text): ?string {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $norm = mb_strtolower($text);
        $bestNameLen = 0;
        $bestDistrict = null;
        foreach (cimm_qc_barangay_district_points() as $p) {
            $name = mb_strtolower($p['name']);
            if ($name !== '' && mb_strpos($norm, $name) !== false && strlen($name) > $bestNameLen) {
                $bestNameLen = strlen($name);
                $bestDistrict = $p['district'];
            }
        }
        return $bestDistrict;
    }
}

if (!function_exists('cimm_resolve_district')) {
    /** Coordinates first (more reliable), free-text address as fallback. */
    function cimm_resolve_district(?float $lat, ?float $lng, string $locationText = ''): ?string {
        $byCoords = cimm_resolve_district_from_coords($lat, $lng);
        if ($byCoords !== null) {
            return $byCoords;
        }
        return cimm_resolve_district_from_text($locationText);
    }
}
