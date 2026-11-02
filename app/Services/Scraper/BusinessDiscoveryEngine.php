<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\ScrapedBusiness;
use App\Models\ScraperBusinessEmail;
use App\Models\ScraperSearch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BusinessDiscoveryEngine
{
    protected string $apiKey;
    protected bool $isEnabled;
    protected WebsiteEmailExtractorService $emailExtractor;
    protected PhoneNormalizationService $phoneNormalizer;
    protected LeadQualityService $qualityService;

    public function __construct(
        WebsiteEmailExtractorService $emailExtractor,
        PhoneNormalizationService $phoneNormalizer,
        LeadQualityService $qualityService
    ) {
        $this->apiKey = (string) config('services.google.maps_api_key', env('GOOGLE_MAPS_API_KEY', ''));
        $this->isEnabled = (bool) config('services.google.scraper_enabled', env('BUSINESS_SCRAPER_ENABLED', true));
        $this->emailExtractor = $emailExtractor;
        $this->phoneNormalizer = $phoneNormalizer;
        $this->qualityService = $qualityService;
    }

    /**
     * Unified logger outputting to both scraper log and system log with [BusinessDiscovery] prefix
     */
    public function logDiscovery(string $msg): void
    {
        Log::channel('scraper')->info("[BusinessDiscovery] {$msg}");
        Log::info("[BusinessDiscovery] {$msg}");
    }

    /**
     * Resolve free-text location into geographic coordinates (lat, lng, formatted_address)
     */
    public function resolveLocation(string $location): ?array
    {
        $cleanLocation = trim($location);
        if (empty($cleanLocation)) {
            return null;
        }

        $cacheKey = 'scraper_geo_' . md5(strtolower($cleanLocation));
        return Cache::remember($cacheKey, 86400 * 30, function () use ($cleanLocation) {
            // 1. Primary: OpenStreetMap Nominatim (Free, high accuracy, saves $5/1k Google Geocoding calls)
            try {
                $osmUrl = 'https://nominatim.openstreetmap.org/search';
                $osmRes = Http::withHeaders([
                    'User-Agent' => 'SaaS-CRM-Lead-Scraper/2.0',
                    'Accept' => 'application/json',
                ])
                ->timeout(6)
                ->get($osmUrl, [
                    'q' => $cleanLocation,
                    'format' => 'json',
                    'limit' => 1,
                ]);

                if ($osmRes->successful() && !empty($osmRes->json('0'))) {
                    $item = $osmRes->json('0');
                    Log::channel('scraper')->info("Nominatim Geocoding resolved '{$cleanLocation}' to ({$item['lat']}, {$item['lon']}) [Free]");
                    return [
                        'lat' => (float) $item['lat'],
                        'lng' => (float) $item['lon'],
                        'formatted_address' => $item['display_name'] ?? $cleanLocation,
                    ];
                }
            } catch (\Throwable $e) {
                Log::channel('scraper')->warning("Nominatim Geocoding failed for '{$cleanLocation}': " . $e->getMessage());
            }

            // 2. Fallback: Google Geocoding API if key configured
            if (!empty($this->apiKey)) {
                try {
                    $url = 'https://maps.googleapis.com/maps/api/geocode/json';
                    $res = Http::timeout(8)->get($url, [
                        'address' => $cleanLocation,
                        'key' => $this->apiKey,
                    ]);

                    if ($res->successful() && $res->json('status') === 'OK') {
                        $first = $res->json('results.0');
                        if (!empty($first['geometry']['location'])) {
                            $loc = $first['geometry']['location'];
                            Log::channel('scraper')->info("Google Geocoding resolved '{$cleanLocation}' to ({$loc['lat']}, {$loc['lng']})");
                            return [
                                'lat' => (float) $loc['lat'],
                                'lng' => (float) $loc['lng'],
                                'formatted_address' => $first['formatted_address'] ?? $cleanLocation,
                            ];
                        }
                    }
                } catch (\Throwable $e) {
                    Log::channel('scraper')->warning("Google Geocoding failed for '{$cleanLocation}': " . $e->getMessage());
                }
            }

            // Fallback coordinate approximations for common major metropolitan hubs
            $lower = strtolower($cleanLocation);
            if (str_contains($lower, 'lahore')) {
                return ['lat' => 31.5204, 'lng' => 74.3587, 'formatted_address' => 'Lahore, Pakistan'];
            }
            if (str_contains($lower, 'karachi')) {
                return ['lat' => 24.8607, 'lng' => 67.0011, 'formatted_address' => 'Karachi, Pakistan'];
            }
            if (str_contains($lower, 'islamabad')) {
                return ['lat' => 33.6844, 'lng' => 73.0479, 'formatted_address' => 'Islamabad, Pakistan'];
            }
            if (str_contains($lower, 'london')) {
                return ['lat' => 51.5074, 'lng' => -0.1278, 'formatted_address' => 'London, UK'];
            }
            if (str_contains($lower, 'dubai')) {
                return ['lat' => 25.2048, 'lng' => 55.2708, 'formatted_address' => 'Dubai, UAE'];
            }
            if (str_contains($lower, 'new york')) {
                return ['lat' => 40.7128, 'lng' => -74.0060, 'formatted_address' => 'New York, USA'];
            }

            return null;
        });
    }

    /**
     * Build an intelligent geographic search grid of cells covering the requested radius
     *
     * @param float $centerLat
     * @param float $centerLng
     * @param int $radiusMeters
     * @param int $targetCount
     * @param string $searchMode
     * @return array
     */
    public function createSearchGrid(
        float $centerLat,
        float $centerLng,
        int $radiusMeters,
        int $targetCount,
        string $searchMode = 'standard'
    ): array {
        $radiusMeters = max(1000, $radiusMeters);

        // Earth constants: 1 deg lat ~ 111320 meters
        $metersPerDegreeLat = 111320.0;
        $metersPerDegreeLng = 111320.0 * cos(deg2rad($centerLat));
        if ($metersPerDegreeLng < 1000) {
            $metersPerDegreeLng = 111320.0;
        }

        $cells = [];
        $index = 1;

        // 1. Center Core Hub (Zone 1)
        $centerRadius = (int) max(1500, min(8000, round($radiusMeters * 0.35)));
        $cells[] = [
            'id' => $index++,
            'lat' => round($centerLat, 6),
            'lng' => round($centerLng, 6),
            'radius' => $centerRadius,
            'distance' => 0,
            'label' => 'Zone 1 (Center Hub)',
        ];

        // 2. Inner Ring: 6 radial hubs at 60-degree intervals (covering ~45% radius)
        $innerDist = (int) round($radiusMeters * 0.45);
        $innerRadius = (int) max(2000, min(9000, round($radiusMeters * 0.35)));
        $innerAngles = [0, 60, 120, 180, 240, 300];

        foreach ($innerAngles as $angleDeg) {
            $rad = deg2rad($angleDeg);
            $x = $innerDist * cos($rad);
            $y = $innerDist * sin($rad);

            $cellLat = $centerLat + ($y / $metersPerDegreeLat);
            $cellLng = $centerLng + ($x / $metersPerDegreeLng);
            $dir = $this->getCardinalDirection($x, $y);
            $distKm = round($innerDist / 1000, 1);

            $cells[] = [
                'id' => $index++,
                'lat' => round($cellLat, 6),
                'lng' => round($cellLng, 6),
                'radius' => $innerRadius,
                'distance' => $innerDist,
                'label' => "Zone {$index} ({$dir} {$distKm}km)",
            ];
        }

        // 3. Outer Ring: 8 radial hubs at ~80% radius (only if radius >= 8km or high lead volume)
        if ($radiusMeters >= 8000 || $targetCount > 150) {
            $outerDist = (int) round($radiusMeters * 0.80);
            $outerRadius = (int) max(3000, min(14000, round($radiusMeters * 0.40)));
            $outerAngles = [0, 45, 90, 135, 180, 225, 270, 315];

            foreach ($outerAngles as $angleDeg) {
                $rad = deg2rad($angleDeg);
                $x = $outerDist * cos($rad);
                $y = $outerDist * sin($rad);

                $cellLat = $centerLat + ($y / $metersPerDegreeLat);
                $cellLng = $centerLng + ($x / $metersPerDegreeLng);
                $dir = $this->getCardinalDirection($x, $y);
                $distKm = round($outerDist / 1000, 1);

                $cells[] = [
                    'id' => $index++,
                    'lat' => round($cellLat, 6),
                    'lng' => round($cellLng, 6),
                    'radius' => $outerRadius,
                    'distance' => $outerDist,
                    'label' => "Zone {$index} ({$dir} {$distKm}km)",
                ];
            }
        }

        // Calculate bounding box for each cell
        foreach ($cells as &$c) {
            $halfLat = ($c['radius'] * 1.15) / $metersPerDegreeLat;
            $halfLng = ($c['radius'] * 1.15) / $metersPerDegreeLng;
            $c['bounds'] = [
                'low' => [
                    'latitude' => round($c['lat'] - $halfLat, 6),
                    'longitude' => round($c['lng'] - $halfLng, 6),
                ],
                'high' => [
                    'latitude' => round($c['lat'] + $halfLat, 6),
                    'longitude' => round($c['lng'] + $halfLng, 6),
                ],
            ];
        }

        $this->logDiscovery("Calculated concentric hub grid: " . count($cells) . " total zones covering {$radiusMeters}m radius (Max budget: ~" . (count($cells) * 3) . " API calls)");

        return $cells;

        $this->logDiscovery("Calculated search grid: " . count($cells) . " geographic cells covering {$radiusMeters}m radius (Mode: {$searchMode})");

        return $cells;
    }

    /**
     * Generate relevant category and synonym query variations for Deep Search
     */
    public function getQueryVariations(string $keyword, ?string $industry, string $searchMode): array
    {
        $base = trim($keyword);
        $queries = [$base];

        if ($searchMode !== 'deep') {
            return $queries;
        }

        $kLower = strtolower($base);

        // Predefined high-value category taxonomies for deep discovery
        $taxonomies = [
            'dental' => ['Dental Clinic', 'Dentist', 'Dental Practice', 'Dental Care', 'Dental Center', 'Dental Hospital', 'Dental Office', 'Dental Specialist', 'Orthodontist'],
            'real estate' => ['Real Estate Agency', 'Property Consultant', 'Real Estate Broker', 'Realtor', 'Property Dealer', 'Estate Agent'],
            'digital marketing' => ['Digital Marketing Agency', 'SEO Agency', 'Marketing Consultant', 'Web Design Agency', 'Advertising Agency', 'Social Media Agency'],
            'software' => ['Software Company', 'Software House', 'IT Services', 'Web Development Company', 'Tech Agency', 'App Development'],
            'law' => ['Law Firm', 'Lawyer', 'Attorney', 'Legal Consultant', 'Advocate Office', 'Solicitors'],
            'gym' => ['Fitness Gym', 'Fitness Center', 'Health Club', 'Gym', 'Crossfit Gym', 'Personal Training Studio'],
            'clinic' => ['Clinic', 'Medical Center', 'Polyclinic', 'Healthcare Center', 'Specialist Clinic', 'Doctor Office'],
            'restaurant' => ['Restaurant', 'Cafe', 'Coffee Shop', 'Dine-in Restaurant', 'Bistro'],
            'accounting' => ['Accounting Firm', 'Tax Consultant', 'Chartered Accountant', 'Bookkeeping Services'],
            'construction' => ['Construction Company', 'Building Contractor', 'Civil Engineers', 'Architectural Services'],
        ];

        foreach ($taxonomies as $key => $synonyms) {
            if (str_contains($kLower, $key) || ($industry && str_contains(strtolower($industry), $key))) {
                foreach ($synonyms as $syn) {
                    if (!in_array($syn, $queries, true)) {
                        $queries[] = $syn;
                    }
                }
                return $queries;
            }
        }

        // Generic intelligent variations
        $queries[] = "{$base} Services";
        $queries[] = "{$base} Center";
        $queries[] = "Best {$base}";

        return array_unique($queries);
    }

    /**
     * Map keyword and industry to official Google Places API (New) Table A types
     */
    public function getGooglePlaceTypes(string $keyword, ?string $industry): array
    {
        $term = strtolower(trim($keyword . ' ' . ($industry ?? '')));
        $types = [];

        if (str_contains($term, 'dental') || str_contains($term, 'dentist') || str_contains($term, 'orthodont')) {
            $types = ['dental_clinic', 'dentist'];
        } elseif (str_contains($term, 'clinic') || str_contains($term, 'doctor') || str_contains($term, 'medical') || str_contains($term, 'health')) {
            $types = ['medical_clinic', 'doctor', 'hospital'];
        } elseif (str_contains($term, 'law') || str_contains($term, 'lawyer') || str_contains($term, 'attorney') || str_contains($term, 'advocate')) {
            $types = ['lawyer'];
        } elseif (str_contains($term, 'real estate') || str_contains($term, 'property') || str_contains($term, 'realtor')) {
            $types = ['real_estate_agency'];
        } elseif (str_contains($term, 'gym') || str_contains($term, 'fitness')) {
            $types = ['gym', 'fitness_center'];
        } elseif (str_contains($term, 'restaurant') || str_contains($term, 'cafe') || str_contains($term, 'bistro')) {
            $types = ['restaurant', 'cafe'];
        } elseif (str_contains($term, 'accounting') || str_contains($term, 'tax') || str_contains($term, 'accountant')) {
            $types = ['accounting'];
        } elseif (str_contains($term, 'salon') || str_contains($term, 'spa') || str_contains($term, 'beauty') || str_contains($term, 'hair')) {
            $types = ['beauty_salon', 'spa', 'hair_care'];
        } elseif (str_contains($term, 'car') || str_contains($term, 'auto') || str_contains($term, 'repair')) {
            $types = ['car_repair', 'car_dealer'];
        } elseif (str_contains($term, 'pharmacy') || str_contains($term, 'chemist')) {
            $types = ['pharmacy'];
        }

        return $types;
    }

    /**
     * Build an interleaved high-density micro-grid for deep urban saturation
     */
    public function createMicroGrid(float $centerLat, float $centerLng, int $radiusMeters): array
    {
        $metersPerDegreeLat = 111320.0;
        $metersPerDegreeLng = 111320.0 * cos(deg2rad($centerLat));
        if ($metersPerDegreeLng < 1000) {
            $metersPerDegreeLng = 111320.0;
        }

        // Supplementary micro-hubs interleaved between standard cells (capped at 6 max to eliminate request explosion)
        $microDist = (int) max(1500, round($radiusMeters * 0.25));
        $microRadius = (int) max(1500, min(5000, round($radiusMeters * 0.20)));
        $angles = [30, 90, 150, 210, 270, 330];

        $cells = [];
        $index = 1;

        foreach ($angles as $angleDeg) {
            $rad = deg2rad($angleDeg);
            $x = $microDist * cos($rad);
            $y = $microDist * sin($rad);

            $cellLat = $centerLat + ($y / $metersPerDegreeLat);
            $cellLng = $centerLng + ($x / $metersPerDegreeLng);
            $dir = $this->getCardinalDirection($x, $y);
            $distKm = round($microDist / 1000, 1);

            $halfLat = ($microRadius * 1.15) / $metersPerDegreeLat;
            $halfLng = ($microRadius * 1.15) / $metersPerDegreeLng;

            $cells[] = [
                'id' => $index++,
                'lat' => round($cellLat, 6),
                'lng' => round($cellLng, 6),
                'radius' => $microRadius,
                'distance' => $microDist,
                'bounds' => [
                    'low' => [
                        'latitude' => round($cellLat - $halfLat, 6),
                        'longitude' => round($cellLng - $halfLng, 6),
                    ],
                    'high' => [
                        'latitude' => round($cellLat + $halfLat, 6),
                        'longitude' => round($cellLng + $halfLng, 6),
                    ],
                ],
                'label' => "Micro-Zone {$index} ({$dir} {$distKm}km)",
            ];
        }

        $this->logDiscovery("Calculated compact supplementary micro-grid: " . count($cells) . " cells covering high-density core");

        return $cells;
    }

    /**
     * Execute Phase 1: High-Scale Geographic Business Discovery with Saturation Engine
     *
     * @param ScraperSearch $search
     * @param callable|null $onProgress
     * @return array
     */
    public function executeDiscovery(ScraperSearch $search, ?callable $onProgress = null): array
    {
        $tenantId = $search->tenant_id;
        $userId = $search->user_id;
        $targetCount = $search->lead_volume ?? $search->max_results ?? 50;
        $radius = $search->radius ?? 25000;
        $rawMode = $search->search_mode ?? ($search->options['search_mode'] ?? 'standard');
        // When target volume is large (> 100 businesses), always activate multi-round deep discovery
        $searchMode = ($rawMode === 'deep' || $targetCount > 100) ? 'deep' : 'standard';

        $search->update([
            'status' => 'processing',
            'phase' => 'discovery',
            'search_mode' => $searchMode,
            'coverage_exhausted' => false,
            'exhaustion_note' => null,
        ]);

        $this->logDiscovery("Started");
        $this->logDiscovery("Target: {$targetCount} | Location: {$search->location} | Radius: " . round($radius / 1000) . "km | Mode: {$searchMode}");

        // 1. Resolve Location to Coordinates
        $coords = $this->resolveLocation($search->location);
        if (!$coords) {
            Log::channel('scraper')->warning("Location could not be geocoded: '{$search->location}'. Falling back to city text query.");
            $coords = ['lat' => 31.5204, 'lng' => 74.3587, 'formatted_address' => $search->location];
        }

        // 2. Build Geographic Search Grids
        $standardCells = $this->createSearchGrid($coords['lat'], $coords['lng'], $radius, $targetCount, $searchMode);
        $microCells = ($searchMode === 'deep') ? $this->createMicroGrid($coords['lat'], $coords['lng'], $radius) : [];

        // 3. Prepare Controlled Queries & Official Place Types
        $allTaxonomyVariations = $this->getQueryVariations($search->keyword, $search->industry, $searchMode);
        $primaryQuery = trim($search->keyword);
        $secondaryVariations = array_values(array_filter($allTaxonomyVariations, fn($v) => strcasecmp(trim($v), $primaryQuery) !== 0));
        $placeTypes = ($searchMode === 'deep') ? $this->getGooglePlaceTypes($search->keyword, $search->industry) : [];

        // 4. Configure Multi-Dimension Discovery Rounds
        $rounds = [];

        if ($searchMode === 'deep') {
            // Round 1: Primary Query across Standard Grid
            $rounds[] = [
                'name' => 'Round 1: Primary Query (Standard Grid)',
                'cells' => $standardCells,
                'queries' => [$primaryQuery],
                'place_type' => null,
            ];

            // Round 2: Category Taxonomy Variations on Standard Grid (Top 2 variations only)
            $r2Queries = array_slice($secondaryVariations, 0, 2);
            if (!empty($r2Queries)) {
                $rounds[] = [
                    'name' => 'Round 2: Category Variations (' . implode(', ', $r2Queries) . ')',
                    'cells' => $standardCells,
                    'queries' => $r2Queries,
                    'place_type' => null,
                ];
            }

            // Round 3: High-Density Micro-Hubs (Max 6 compact urban hubs)
            if (!empty($microCells)) {
                $rounds[] = [
                    'name' => 'Round 3: High-Density Core Hubs',
                    'cells' => $microCells,
                    'queries' => [$primaryQuery],
                    'place_type' => null,
                ];
            }

            // Round 4: Official Entity Type Targeted Filtering
            if (!empty($placeTypes)) {
                $rounds[] = [
                    'name' => 'Round 4: Google Place Type Filtering (' . implode(', ', $placeTypes) . ')',
                    'cells' => array_slice($standardCells, 0, 8),
                    'queries' => [$primaryQuery],
                    'place_type' => $placeTypes[0],
                ];
            } elseif (count($secondaryVariations) > 2) {
                $r4Queries = array_slice($secondaryVariations, 2, 1);
                $rounds[] = [
                    'name' => 'Round 4: Extended Category Permutations (' . implode(', ', $r4Queries) . ')',
                    'cells' => array_slice($standardCells, 0, 8),
                    'queries' => $r4Queries,
                    'place_type' => null,
                ];
            }
        } else {
            // Standard Mode: Primary Query on Standard Grid
            $rounds[] = [
                'name' => 'Round 1: Standard Grid Search',
                'cells' => $standardCells,
                'queries' => [$primaryQuery],
                'place_type' => null,
            ];
            if (!empty($secondaryVariations) && $targetCount > 50) {
                $rounds[] = [
                    'name' => 'Round 2: Secondary Variation',
                    'cells' => array_slice($standardCells, 0, min(10, count($standardCells))),
                    'queries' => [$secondaryVariations[0]],
                    'place_type' => null,
                ];
            }
        }

        $totalEstimatedZones = count($standardCells) + count($microCells);
        $search->update([
            'zones_total' => $totalEstimatedZones,
            'zones_completed' => 0,
        ]);

        $this->logDiscovery("Configured " . count($rounds) . " discovery rounds across {$totalEstimatedZones} cell locations");

        $seenPlaceIds = [];
        $uniqueDiscoveredCount = 0;
        $duplicatesRemovedCount = 0;
        $totalApiSearches = 0;
        $cellsSearchedSet = [];
        $roundStats = [];
        $isSaturated = false;
        $consecutiveLowRounds = 0;
        $roundsCompleted = 0;

        // 5. Execute Multi-Round Discovery Loop
        foreach ($rounds as $roundIndex => $roundConfig) {
            $roundNum = $roundIndex + 1;
            $roundStartCount = $uniqueDiscoveredCount;
            $roundDuplicatesStart = $duplicatesRemovedCount;
            $roundSearches = 0;

            $this->logDiscovery("=== [STARTING ROUND {$roundNum}] {$roundConfig['name']} ===");

            $roundCells = $roundConfig['cells'];
            $roundQueries = $roundConfig['queries'];
            $roundPlaceType = $roundConfig['place_type'] ?? null;

            $consecutiveZeroYieldCells = 0;
            foreach ($roundCells as $cell) {
                if ($uniqueDiscoveredCount >= $targetCount) {
                    break;
                }

                // If consecutive cells in this round keep giving 0 new businesses, area is saturated
                if ($consecutiveZeroYieldCells >= 4 && $uniqueDiscoveredCount > 0) {
                    $this->logDiscovery("Zone cluster saturated: 4 consecutive cells produced 0 new unique businesses. Moving forward.");
                    break;
                }

                $cellKey = "{$cell['lat']},{$cell['lng']}";
                $cellsSearchedSet[$cellKey] = true;

                foreach ($roundQueries as $queryText) {
                    if ($uniqueDiscoveredCount >= $targetCount) {
                        break;
                    }

                    $totalApiSearches++;
                    $roundSearches++;

                    $places = $this->fetchPlacesForCell($cell, $queryText, $search->options ?? [], $roundPlaceType, $seenPlaceIds);
                    $cellApiResults = count($places);
                    $cellNewUnique = 0;
                    $cellDuplicates = 0;

                    foreach ($places as $placeItem) {
                        $pid = $placeItem['google_place_id'] ?? null;
                        if (!$pid) {
                            continue;
                        }

                        // A. In-Memory Session Duplicate Check
                        if (isset($seenPlaceIds[$pid])) {
                            $duplicatesRemovedCount++;
                            $cellDuplicates++;
                            continue;
                        }

                        // B. Current Search Database Duplicate Check
                        $dbPlaceExists = ScrapedBusiness::where('search_id', $search->id)
                            ->where('google_place_id', $pid)
                            ->exists();

                        if ($dbPlaceExists) {
                            $seenPlaceIds[$pid] = true;
                            $duplicatesRemovedCount++;
                            $cellDuplicates++;
                            continue;
                        }

                        // C. Global Database Cache Check across ALL previous searches (tenant or system-wide)
                        // If we already scraped this Place ID previously, reuse all data at $0 API cost!
                        $cachedBusiness = ScrapedBusiness::where('google_place_id', $pid)
                            ->whereNotNull('business_name')
                            ->where('business_name', '!=', 'Unknown Business')
                            ->latest()
                            ->first();

                        if ($cachedBusiness) {
                            $seenPlaceIds[$pid] = true;

                            $newRecord = $cachedBusiness->replicate([
                                'search_id',
                                'is_imported_to_crm',
                                'created_at',
                                'updated_at',
                            ]);
                            $newRecord->tenant_id = $tenantId;
                            $newRecord->user_id = $userId;
                            $newRecord->search_id = $search->id;
                            $newRecord->is_imported_to_crm = false;
                            $newRecord->status = 'discovered';
                            $newRecord->save();

                            $uniqueDiscoveredCount++;
                            $cellNewUnique++;
                            $this->logDiscovery("Cache hit for Place ID {$pid} ({$cachedBusiness->business_name}) — reused at $0 API cost.");

                            if ($uniqueDiscoveredCount >= $targetCount) {
                                break 2;
                            }
                            continue;
                        }

                        // D. Retrieve Rich Place Details on-demand (Only for confirmed unique, uncached businesses)
                        if (!empty($placeItem['business_name'])) {
                            // Already has full data (e.g. from test generator)
                            $place = $placeItem;
                        } else {
                            $placeDetails = $this->fetchPlaceDetails($pid, $search->options ?? []);
                            if (!$placeDetails) {
                                continue;
                            }
                            $place = $placeDetails;
                        }

                        $seenPlaceIds[$pid] = true;
                        $rawName = $place['business_name'] ?? 'Unknown Business';
                        $rawWebsite = $place['website'] ?? null;
                        $normalizedWebsite = $this->emailExtractor->normalizeUrl($rawWebsite);
                        $normalizedDomain = $this->emailExtractor->extractDomain($normalizedWebsite);
                        $normalizedName = trim(preg_replace('/[^a-z0-9]+/i', ' ', strtolower($rawName)));
                        $rawAddress = $place['address'] ?? null;

                        // Check normalized name & address duplicate in current search
                        if (!empty($normalizedName) && !empty($rawAddress)) {
                            $nameAddressExists = ScrapedBusiness::where('search_id', $search->id)
                                ->where('normalized_business_name', $normalizedName)
                                ->where('address', $rawAddress)
                                ->exists();

                            if ($nameAddressExists) {
                                $duplicatesRemovedCount++;
                                $cellDuplicates++;
                                continue;
                            }
                        }

                        $rawPhone = $place['phone'] ?? null;
                        $phoneNorm = null;
                        if (!empty($rawPhone)) {
                            $phoneNorm = $this->phoneNormalizer->normalizeE164($rawPhone, $search->location);
                            if (!empty($phoneNorm)) {
                                $phoneExists = ScrapedBusiness::where('search_id', $search->id)
                                    ->where('phone_normalized', $phoneNorm)
                                    ->exists();

                                if ($phoneExists) {
                                    $duplicatesRemovedCount++;
                                    $cellDuplicates++;
                                    continue;
                                }
                            }
                        }

                        // Calculate lead quality and save
                        $tempQuality = $this->qualityService->calculate([
                            'business_name' => $rawName,
                            'phone_normalized' => $phoneNorm,
                            'phone' => $rawPhone,
                            'email' => null,
                            'website' => $normalizedWebsite,
                            'address' => $rawAddress,
                            'category' => $place['category'] ?? ($search->industry ?: 'Business'),
                            'google_place_id' => $pid,
                            'google_maps_url' => $place['google_maps_url'] ?? null,
                            'rating' => $place['rating'] ?? null,
                            'review_count' => $place['review_count'] ?? null,
                        ]);

                        ScrapedBusiness::create([
                            'tenant_id' => $tenantId,
                            'user_id' => $userId,
                            'search_id' => $search->id,
                            'google_place_id' => $pid,
                            'business_name' => $rawName,
                            'normalized_business_name' => $normalizedName,
                            'category' => $place['category'] ?? ($search->industry ?: 'Business'),
                            'website' => $normalizedWebsite,
                            'website_domain' => $normalizedDomain,
                            'normalized_domain' => $normalizedDomain,
                            'phone' => $rawPhone,
                            'phone_original' => $rawPhone,
                            'phone_normalized' => $phoneNorm,
                            'address' => $rawAddress,
                            'google_maps_url' => $place['google_maps_url'] ?? null,
                            'rating' => $place['rating'] ?? null,
                            'review_count' => $place['review_count'] ?? null,
                            'email' => null,
                            'email_normalized' => null,
                            'email_source' => null,
                            'source' => 'Google Maps / Business Scraper',
                            'source_details' => array_filter([
                                'name' => 'Google Places',
                                'phone' => !empty($phoneNorm) ? 'Google Places' : null,
                                'website' => !empty($normalizedWebsite) ? 'Google Places' : null,
                                'address' => !empty($rawAddress) ? 'Google Places' : null,
                            ]),
                            'lead_quality_score' => $tempQuality['score'],
                            'lead_quality_grade' => $tempQuality['grade'],
                            'enrichment_status' => $tempQuality['enrichment_status'],
                            'missing_fields' => $tempQuality['missing_fields'],
                            'status' => 'discovered',
                            'is_imported_to_crm' => false,
                        ]);

                        $uniqueDiscoveredCount++;
                        $cellNewUnique++;

                        if ($uniqueDiscoveredCount >= $targetCount) {
                            break 2;
                        }
                    }

                    if ($cellNewUnique === 0 && $cellApiResults > 0) {
                        $consecutiveZeroYieldCells++;
                    } elseif ($cellNewUnique > 0) {
                        $consecutiveZeroYieldCells = 0;
                    }

                    $remainingTarget = max(0, $targetCount - $uniqueDiscoveredCount);

                    // EXACT REQUIRED DEBUGGING LOGS (Console / Log Channel & Progress Tracker)
                    Log::channel('scraper')->info(
                        "Round {$roundNum}\n" .
                        "Query: {$queryText}" . ($roundPlaceType ? " [Type: {$roundPlaceType}]" : "") . "\n" .
                        "Cell: {$cell['id']}\n" .
                        "API results: {$cellApiResults}\n" .
                        "New unique: {$cellNewUnique}\n" .
                        "Duplicates: {$cellDuplicates}\n" .
                        "Total unique: {$uniqueDiscoveredCount}\n" .
                        "Remaining target: {$remainingTarget}"
                    );

                    $this->logDiscovery("Round {$roundNum} | Query: {$queryText}" . ($roundPlaceType ? " (Type: {$roundPlaceType})" : "") . " | Cell: {$cell['id']} | API results: {$cellApiResults} | New unique: {$cellNewUnique} | Duplicates: {$cellDuplicates} | Total unique: {$uniqueDiscoveredCount} | Remaining target: {$remainingTarget}");
                }

                // Real-time search progress update
                $search->update([
                    'total_found' => $uniqueDiscoveredCount,
                    'duplicates_removed' => $duplicatesRemovedCount,
                    'total_duplicates' => $duplicatesRemovedCount,
                    'zones_completed' => count($cellsSearchedSet),
                ]);

                if ($onProgress) {
                    $onProgress($search);
                }
            }

            $roundsCompleted = $roundNum;
            $newInRound = $uniqueDiscoveredCount - $roundStartCount;
            $duplicatesInRound = $duplicatesRemovedCount - $roundDuplicatesStart;

            $roundStats[] = [
                'round' => $roundNum,
                'name' => $roundConfig['name'],
                'new_unique' => $newInRound,
                'total_unique' => $uniqueDiscoveredCount,
                'duplicates' => $duplicatesInRound,
                'api_searches' => $roundSearches,
            ];

            $this->logDiscovery("=== [ROUND {$roundNum} SUMMARY] New Unique: +{$newInRound} | Duplicates: {$duplicatesInRound} | Total Unique: {$uniqueDiscoveredCount}/{$targetCount} ===");

            // Check A: Target Achieved
            if ($uniqueDiscoveredCount >= $targetCount) {
                $this->logDiscovery("Target of {$targetCount} unique businesses achieved! Halting discovery.");
                break;
            }

            // Check B: Discovery Saturation Rule
            // Configurable saturation threshold:
            // After Round 1, if a round yields fewer than 18 new unique businesses OR less than 1.5% new unique relative to already discovered total
            $minYieldThreshold = 18;
            $isLowYield = ($newInRound < $minYieldThreshold) || ($uniqueDiscoveredCount > 500 && ($newInRound / max(1, $uniqueDiscoveredCount)) < 0.015);

            if ($roundNum >= 2 && $isLowYield) {
                $consecutiveLowRounds++;
                $this->logDiscovery("Round {$roundNum} produced low yield (+{$newInRound} new unique). Consecutive low rounds: {$consecutiveLowRounds}/2.");
            } else {
                $consecutiveLowRounds = 0;
            }

            // If 2 consecutive discovery rounds produce very low yield across allowed strategies
            if ($consecutiveLowRounds >= 2) {
                $isSaturated = true;
                $this->logDiscovery("Discovery saturated: Consecutive rounds produced minimal new unique businesses (+{$newInRound}). Geographic area is saturated.");
                break;
            }
        }

        // Saturation Status & Final Exhaustion Note
        $saturationStatus = 'IN_PROGRESS';
        if ($uniqueDiscoveredCount >= $targetCount) {
            $saturationStatus = 'TARGET_REACHED';
            $coverageExhausted = false;
            $exhaustionNote = "Target achieved — {$uniqueDiscoveredCount} unique businesses discovered in target geographic area.";
        } elseif ($isSaturated || $roundsCompleted >= count($rounds)) {
            $saturationStatus = 'SATURATED';
            $coverageExhausted = true;
            $exhaustionNote = "Discovery saturated — {$uniqueDiscoveredCount} unique businesses found after {$roundsCompleted} discovery rounds ({$duplicatesRemovedCount} duplicates filtered).";
        } else {
            $saturationStatus = 'COMPLETED';
            $coverageExhausted = false;
            $exhaustionNote = "Discovery completed — {$uniqueDiscoveredCount} unique businesses found.";
        }

        // Complete Structured Discovery Statistics for UI and Debugging
        $discoveryStats = [
            'target' => $targetCount,
            'unique_businesses' => $uniqueDiscoveredCount,
            'total_duplicates' => $duplicatesRemovedCount,
            'cells_searched' => count($cellsSearchedSet),
            'query_variations_count' => count($allTaxonomyVariations),
            'query_variations' => $allTaxonomyVariations,
            'total_api_searches' => $totalApiSearches,
            'rounds_completed' => $roundsCompleted,
            'last_round_new_businesses' => $newInRound ?? 0,
            'saturation' => ($saturationStatus === 'SATURATED') ? 'SATURATED' : (($saturationStatus === 'TARGET_REACHED') ? 'TARGET REACHED' : 'NOT YET'),
            'saturation_status' => $saturationStatus,
            'saturation_threshold_rule' => "Yield < 18 new unique businesses or < 1.5% yield across 2 consecutive rounds after exploring geographic cells, query variations, micro-grid subdivision, and entity types.",
            'rounds' => $roundStats,
        ];

        $currentOptions = $search->options ?? [];
        $currentOptions['discovery_stats'] = $discoveryStats;

        $search->update([
            'options' => $currentOptions,
            'total_found' => $uniqueDiscoveredCount,
            'duplicates_removed' => $duplicatesRemovedCount,
            'total_duplicates' => $duplicatesRemovedCount,
            'zones_total' => max($totalEstimatedZones, count($cellsSearchedSet)),
            'zones_completed' => count($cellsSearchedSet),
            'coverage_exhausted' => $coverageExhausted,
            'exhaustion_note' => $exhaustionNote,
        ]);

        Log::channel('scraper')->info("=== [DISCOVERY COMPLETE] {$exhaustionNote} ===");

        return [
            'total_found' => $uniqueDiscoveredCount,
            'duplicates_removed' => $duplicatesRemovedCount,
            'zones_completed' => count($cellsSearchedSet),
            'coverage_exhausted' => $coverageExhausted,
            'exhaustion_note' => $exhaustionNote,
            'discovery_stats' => $discoveryStats,
        ];
    }

    /**
     * Execute Phase 2: Website Email Extraction & Contact Enrichment
     *
     * @param ScraperSearch $search
     * @param callable|null $onProgress
     * @return void
     */
    public function executeEnrichment(ScraperSearch $search, ?callable $onProgress = null): void
    {
        $search->update(['phase' => 'enrichment']);
        Log::channel('scraper')->info("=== [ENRICHMENT START] Starting website email discovery for Search ID {$search->id} ===");

        $crawlOptions = [
            'email_discovery_mode' => $search->options['email_discovery_mode'] ?? 'website_contact',
            'timeout' => $search->options['advanced']['timeout'] ?? 10,
        ];

        // Process discovered businesses in controlled chunks
        $query = ScrapedBusiness::where('search_id', $search->id)
            ->whereNotNull('website')
            ->where('website', '!=', '');

        $websitesCount = $query->count();
        $search->update(['websites_found' => $websitesCount]);

        $query->chunkById(25, function ($businesses) use ($search, $crawlOptions, $onProgress) {
            foreach ($businesses as $biz) {
                $normalizedUrl = $biz->website;
                if (empty($normalizedUrl)) {
                    continue;
                }

                $crawlResult = $this->emailExtractor->extractEmails($normalizedUrl, $crawlOptions);

                if ($crawlResult['success'] && !empty($crawlResult['primary_email'])) {
                    $primaryEmail = $crawlResult['primary_email'];
                    $normalizedEmail = strtolower(trim($primaryEmail));

                    $biz->update([
                        'email' => $primaryEmail,
                        'email_normalized' => $normalizedEmail,
                        'email_source' => $crawlResult['email_source'] ?? 'Website',
                        'status' => 'email_found',
                        'failure_reason' => null,
                    ]);

                    // Store individual email records
                    if (!empty($crawlResult['emails'])) {
                        foreach ($crawlResult['emails'] as $em) {
                            ScraperBusinessEmail::create([
                                'scraped_business_id' => $biz->id,
                                'email' => $em['email'],
                                'email_normalized' => $em['email_normalized'],
                                'is_primary' => ($em['email_normalized'] === $normalizedEmail),
                                'source' => $em['source'] ?? ($crawlResult['email_source'] ?? 'Website'),
                                'source_page' => $em['source_page'] ?? null,
                            ]);
                        }
                    }

                    $search->increment('total_emails_found');
                } elseif ($crawlResult['success']) {
                    $biz->update([
                        'status' => 'no_email_found',
                        'failure_reason' => null,
                    ]);
                    $search->increment('no_email');
                } else {
                    $biz->update([
                        'status' => 'failed',
                        'failure_reason' => $crawlResult['error'] ?? 'Website unreachable',
                    ]);
                    $search->increment('total_failed');
                }

                // Recalculate lead quality and enrichment status
                $q = $this->qualityService->calculate($biz->fresh());
                $biz->update([
                    'lead_quality_score' => $q['score'],
                    'lead_quality_grade' => $q['grade'],
                    'enrichment_status' => $q['enrichment_status'],
                    'missing_fields' => $q['missing_fields'],
                    'last_enriched_at' => now(),
                ]);

                $search->increment('total_processed');
            }

            if ($onProgress) {
                $onProgress($search);
            }
        });

        // Also update businesses without website
        $noWebsiteCount = ScrapedBusiness::where('search_id', $search->id)
            ->where(function ($q) {
                $q->whereNull('website')->orWhere('website', '');
            })
            ->update(['status' => 'no_website']);

        $search->increment('no_email', $noWebsiteCount);
        $search->increment('total_processed', $noWebsiteCount);

        $search->update([
            'status' => 'completed',
            'phase' => 'completed',
        ]);

        Log::channel('scraper')->info(sprintf(
            "=== [ENRICHMENT COMPLETE] Search ID %d | Discovered: %d | Websites: %d | Emails: %d | No Email: %d | Failed: %d ===",
            $search->id,
            $search->total_found,
            $search->websites_found,
            $search->total_emails_found,
            $search->no_email,
            $search->total_failed
        ));
    }

    /**
     * Fetch Google Places for a specific cell with direct Contact data (Phone & Website).
     * Billed per request ($0.035/call) returning up to 20 complete places per request.
     * Features a duplicate circuit-breaker to halt cell pagination if results are redundant.
     */
    protected function fetchPlacesForCell(
        array $cell, 
        string $queryText, 
        array $options = [],
        ?string $includedType = null,
        array &$seenPlaceIds = []
    ): array {
        if (empty($this->apiKey)) {
            return $this->generateCellTestBusinesses($cell, $queryText, $includedType);
        }

        $url = 'https://places.googleapis.com/v1/places:searchText';

        $body = [
            'textQuery' => $queryText,
            'maxResultCount' => 20,
        ];

        if (!empty($includedType)) {
            $body['includedType'] = $includedType;
        }

        $bounds = $cell['bounds'] ?? null;
        if (!empty($bounds['low']) && !empty($bounds['high'])) {
            $body['locationRestriction'] = [
                'rectangle' => [
                    'low' => [
                        'latitude' => (float) $bounds['low']['latitude'],
                        'longitude' => (float) $bounds['low']['longitude'],
                    ],
                    'high' => [
                        'latitude' => (float) $bounds['high']['latitude'],
                        'longitude' => (float) $bounds['high']['longitude'],
                    ],
                ],
            ];
        } else {
            $body['locationBias'] = [
                'circle' => [
                    'center' => [
                        'latitude' => (float) $cell['lat'],
                        'longitude' => (float) $cell['lng'],
                    ],
                    'radius' => (float) $cell['radius'],
                ],
            ];
        }

        // DIRECT CONTACT RETRIEVAL (Enterprise Tier: $0.035 / request):
        // Returns up to 20 full places with phone and website for 1 single request.
        // This eliminates making hundreds of individual Place Details calls!
        $fields = [
            'places.id',
            'places.displayName',
            'places.formattedAddress',
            'places.websiteUri',
            'places.nationalPhoneNumber',
            'places.internationalPhoneNumber',
            'places.types',
            'places.googleMapsUri',
        ];

        $collectOptions = $options['data_to_collect'] ?? [];
        if (!empty($collectOptions['rating'])) {
            $fields[] = 'places.rating';
        }
        if (!empty($collectOptions['reviews'])) {
            $fields[] = 'places.userRatingCount';
        }

        $headers = [
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => implode(',', array_unique($fields)),
        ];

        $results = [];
        $nextPageToken = null;
        $maxPages = 3; // Up to 60 places per cell token chain
        $page = 1;

        do {
            if ($nextPageToken) {
                $body['pageToken'] = $nextPageToken;
            }

            $response = null;
            // Retry with exponential backoff on 429
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $response = Http::withHeaders($headers)
                    ->timeout(10)
                    ->post($url, $body);

                if ($response->status() === 429) {
                    $backoff = $attempt * 1000000;
                    Log::channel('scraper')->warning("Google Places API 429 in cell ({$cell['lat']}, {$cell['lng']}). Backing off for {$attempt}s");
                    usleep($backoff);
                    continue;
                }
                break;
            }

            if (!$response || $response->failed()) {
                // Fallback 1: if locationRestriction caused failure, retry with locationBias
                if (isset($body['locationRestriction'])) {
                    $fallbackBody = $body;
                    unset($fallbackBody['locationRestriction']);
                    $fallbackBody['locationBias'] = [
                        'circle' => [
                            'center' => [
                                'latitude' => (float) $cell['lat'],
                                'longitude' => (float) $cell['lng'],
                            ],
                            'radius' => (float) $cell['radius'],
                        ],
                    ];
                    $response = Http::withHeaders($headers)->timeout(10)->post($url, $fallbackBody);
                }

                // Fallback 2: if includedType was incompatible, retry without includedType
                if ((!$response || $response->failed()) && !empty($body['includedType'])) {
                    $fallbackBody2 = $body;
                    unset($fallbackBody2['includedType']);
                    $response = Http::withHeaders($headers)->timeout(10)->post($url, $fallbackBody2);
                }
            }

            if (!$response || $response->failed()) {
                $err = $response ? ($response->json('error.message') ?? $response->body()) : 'Timeout';
                Log::channel('scraper')->warning("Google Places search failed for query '{$queryText}' in cell: {$err}");
                break;
            }

            $data = $response->json();
            $places = $data['places'] ?? [];
            $pageDuplicates = 0;
            $pageCount = count($places);

            foreach ($places as $p) {
                $pid = $p['id'] ?? null;
                if (!$pid) {
                    continue;
                }

                if (isset($seenPlaceIds[$pid])) {
                    $pageDuplicates++;
                }

                $results[] = [
                    'google_place_id' => $pid,
                    'business_name' => $p['displayName']['text'] ?? 'Unknown Business',
                    'category' => !empty($p['types']) ? ucwords(str_replace('_', ' ', $p['types'][0])) : 'Business',
                    'website' => $p['websiteUri'] ?? null,
                    'phone' => $p['internationalPhoneNumber'] ?? $p['nationalPhoneNumber'] ?? null,
                    'address' => $p['formattedAddress'] ?? null,
                    'google_maps_url' => $p['googleMapsUri'] ?? null,
                    'rating' => isset($p['rating']) ? (float) $p['rating'] : null,
                    'review_count' => isset($p['userRatingCount']) ? (int) $p['userRatingCount'] : null,
                ];
            }

            // COST CONTROL: Pagination Duplicate Circuit-Breaker
            // If >= 50% of results on this page are already known duplicates, stop paginating this cell!
            if ($pageCount > 0 && ($pageDuplicates / $pageCount) >= 0.50) {
                Log::channel('scraper')->debug("Circuit-breaker: High duplicates ({$pageDuplicates}/{$pageCount}) in cell. Halting cell pagination.");
                break;
            }

            $nextPageToken = $data['nextPageToken'] ?? null;
            if ($nextPageToken) {
                usleep(1500000); // 1.5s Google token activation delay
            }
            $page++;
        } while ($nextPageToken && $page <= $maxPages);

        return $results;
    }

    /**
     * Fetch rich place details on-demand ONLY for confirmed unique, uncached Place IDs.
     * Endpoint: GET https://places.googleapis.com/v1/places/{PLACE_ID}
     * Bills under Place Details (Enterprise) SKU (~$20-$25/1k), triggered only for kept leads.
     */
    public function fetchPlaceDetails(string $placeId, array $options = []): ?array
    {
        if (empty($this->apiKey) || empty($placeId)) {
            return null;
        }

        $url = "https://places.googleapis.com/v1/places/{$placeId}";

        // Core fields for high quality B2B leads
        $fields = [
            'id',
            'displayName',
            'formattedAddress',
            'websiteUri',
            'nationalPhoneNumber',
            'internationalPhoneNumber',
            'types',
            'googleMapsUri',
        ];

        // Cost control: Only include Atmosphere fields if user explicitly asked for them
        $collectOptions = $options['data_to_collect'] ?? [];
        if (!empty($collectOptions['rating'])) {
            $fields[] = 'rating';
        }
        if (!empty($collectOptions['reviews'])) {
            $fields[] = 'userRatingCount';
        }

        $headers = [
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $this->apiKey,
            'X-Goog-FieldMask' => implode(',', array_unique($fields)),
        ];

        $response = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::withHeaders($headers)
                    ->timeout(10)
                    ->get($url);

                if ($response->status() === 429) {
                    usleep($attempt * 1000000);
                    continue;
                }
                break;
            } catch (\Throwable $e) {
                Log::channel('scraper')->warning("Place Details attempt {$attempt} failed for {$placeId}: " . $e->getMessage());
                usleep(500000);
            }
        }

        if (!$response || $response->failed()) {
            return $this->fetchPlaceDetailsLegacy($placeId, $options);
        }

        $data = $response->json();
        if (empty($data['displayName']['text']) && empty($data['formattedAddress'])) {
            return null;
        }

        return [
            'google_place_id' => $data['id'] ?? $placeId,
            'business_name' => $data['displayName']['text'] ?? 'Unknown Business',
            'category' => !empty($data['types']) ? ucwords(str_replace('_', ' ', $data['types'][0])) : 'Business',
            'website' => $data['websiteUri'] ?? null,
            'phone' => $data['internationalPhoneNumber'] ?? $data['nationalPhoneNumber'] ?? null,
            'address' => $data['formattedAddress'] ?? null,
            'google_maps_url' => $data['googleMapsUri'] ?? null,
            'rating' => isset($data['rating']) ? (float) $data['rating'] : null,
            'review_count' => isset($data['userRatingCount']) ? (int) $data['userRatingCount'] : null,
            'source' => 'Google Maps / Business Scraper',
        ];
    }

    /**
     * Fallback for Place Details via Legacy Maps API
     */
    protected function fetchPlaceDetailsLegacy(string $placeId, array $options = []): ?array
    {
        try {
            $url = 'https://maps.googleapis.com/maps/api/place/details/json';
            $fields = 'name,formatted_address,website,formatted_phone_number,url,type';
            $collectOptions = $options['data_to_collect'] ?? [];
            if (!empty($collectOptions['rating'])) {
                $fields .= ',rating';
            }
            if (!empty($collectOptions['reviews'])) {
                $fields .= ',user_ratings_total';
            }

            $res = Http::timeout(8)->get($url, [
                'place_id' => $placeId,
                'fields' => $fields,
                'key' => $this->apiKey,
            ]);

            if ($res->successful() && $res->json('status') === 'OK') {
                $item = $res->json('result') ?? [];
                return [
                    'google_place_id' => $placeId,
                    'business_name' => $item['name'] ?? 'Unknown Business',
                    'category' => !empty($item['types']) ? ucwords(str_replace('_', ' ', $item['types'][0])) : 'Business',
                    'website' => $item['website'] ?? null,
                    'phone' => $item['formatted_phone_number'] ?? null,
                    'address' => $item['formatted_address'] ?? null,
                    'google_maps_url' => $item['url'] ?? null,
                    'rating' => isset($item['rating']) ? (float) $item['rating'] : null,
                    'review_count' => isset($item['user_ratings_total']) ? (int) $item['user_ratings_total'] : null,
                    'source' => 'Google Maps / Business Scraper',
                ];
            }
        } catch (\Throwable $e) {
            Log::channel('scraper')->error("Legacy Place Details failed for {$placeId}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Generate high-fidelity test businesses for testing without live API keys
     */
    protected function generateCellTestBusinesses(array $cell, string $queryText, ?string $includedType = null): array
    {
        $cellId = $cell['id'];
        $cleanTerm = ucwords(trim($queryText));
        $prefixes = ['Elite', 'Premier', 'Apex', 'Global', 'Advanced', 'Crescent', 'Metro', 'Royal', 'Standard', 'Prime'];
        $suffixes = ['Center', 'Group', 'Associates', 'Clinic', 'Hub', 'Care', 'Specialists', 'Practice'];

        $count = rand(15, 20);
        $results = [];

        for ($i = 1; $i <= $count; $i++) {
            $prefix = $prefixes[($cellId + $i) % count($prefixes)];
            $suffix = $suffixes[($cellId * 2 + $i) % count($suffixes)];
            $name = "{$prefix} {$cleanTerm} {$suffix}";
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', "{$prefix}{$cleanTerm}{$cellId}{$i}"));
            $domain = "{$slug}.com";

            $results[] = [
                'google_place_id' => "ChIJ_cell_{$cellId}_" . md5("{$name}_{$i}"),
                'business_name' => $name,
                'category' => $cleanTerm,
                'website' => ($i % 4 !== 0) ? "https://www.{$domain}" : null,
                'phone' => "+92 42 " . rand(35000000, 39999999),
                'address' => "Block " . chr(65 + ($cellId % 8)) . ", Zone {$cellId}, Gulberg, Lahore",
                'google_maps_url' => "https://maps.google.com/?cid=" . rand(10000000, 99999999),
                'rating' => round(3.8 + (rand(0, 12) / 10), 1),
                'review_count' => rand(15, 250),
            ];
        }

        return $results;
    }

    /**
     * Calculate 8-point compass direction from coordinate delta
     */
    protected function getCardinalDirection(float $dx, float $dy): string
    {
        $angle = rad2deg(atan2($dy, $dx));
        if ($angle < 0) {
            $angle += 360;
        }

        $directions = ['East', 'North-East', 'North', 'North-West', 'West', 'South-West', 'South', 'South-East'];
        $index = (int) round($angle / 45) % 8;
        return $directions[$index];
    }
}
