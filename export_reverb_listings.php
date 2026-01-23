<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * CONFIG
 */
$startUrl   = 'https://api.reverb.com/api/my/listings?state=all';
$outputFile = __DIR__ . '/ReverbCityMusic.xlsx';

/**
 * 🔑 API TOKEN (from GitHub Actions secret)
 */
$token = getenv('REVERB_TOKEN');
if (!$token) {
    throw new RuntimeException('Missing REVERB_TOKEN environment variable');
}

/**
 * HTTP GET helper
 */
function httpGetJson(string $url, string $token): array
{
    $headers = [
        'Content-Type: application/hal+json',
        'Accept: application/hal+json',
        'Accept-Version: 3.0',
        'Authorization: Bearer ' . $token,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }

    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException("HTTP {$httpCode}: {$response}");
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON response');
    }

    return $json;
}

/**
 * Helper for nested values
 */
function getNested(array $arr, array $path, $default = '')
{
    foreach ($path as $key) {
        if (!isset($arr[$key])) {
            return $default;
        }
        $arr = $arr[$key];
    }
    return $arr;
}

/**
 * Spreadsheet setup
 */
$headers = [
    'SKU',
    'Channel Item Id',
    'Title',
    'Description',
    'Price',
    'Quantity',
    'Status',
    'State',
    'Condition',
    'Condition Description',
    'Currency',
    'Category',
    'Shipping Profile',
    'Shipping Cost',
    'Main Image',
    'Brand',
    'Model',
    'Finish',
    'Year',
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Reverb Listings');

/**
 * Header row
 */
foreach ($headers as $i => $header) {
    $cell = Coordinate::stringFromColumnIndex($i + 1) . '1';
    $sheet->setCellValue($cell, $header);
}

$sheet->freezePane('A2');
$sheet->getStyle('1:1')->getFont()->setBold(true);

$rowNum = 2;
$url    = $startUrl;

/**
 * Fetch listings
 */
while ($url) {
    $data = httpGetJson($url, $token);

    foreach ($data['listings'] as $listing) {

        $categories = [];
        foreach ($listing['categories'] ?? [] as $cat) {
            $categories[] = $cat['full_name'] ?? '';
        }

        $row = [
            $listing['sku'] ?? '',
            $listing['id'] ?? '',
            $listing['title'] ?? '',
            $listing['description'] ?? '',
            getNested($listing, ['price', 'amount'], ''),
            $listing['inventory'] ?? '',
            !empty($listing['offers_enabled']) ? 'true' : 'false',
            getNested($listing, ['state', 'description'], ''),
            getNested($listing, ['condition', 'display_name'], ''),
            getNested($listing, ['condition', 'description'], ''),
            $listing['listing_currency'] ?? '',
            implode(' | ', $categories),
            $listing['shipping_profile_id'] ?? '',
            getNested($listing, ['shipping', 'rates', 0, 'rate', 'amount'], ''),
            getNested($listing, ['photos', 0, '_links', 'full', 'href'], ''),
            $listing['make'] ?? '',
            $listing['model'] ?? '',
            $listing['finish'] ?? '',
            $listing['year'] ?? '',
        ];

        foreach ($row as $col => $value) {
            $cell = Coordinate::stringFromColumnIndex($col + 1) . $rowNum;
            $sheet->setCellValue($cell, $value);
        }

        $rowNum++;
    }

    $url = $data['_links']['next']['href'] ?? null;

    // Be nice to the API
    usleep(150000);
}

/**
 * Auto-size columns
 */
foreach (range(1, count($headers)) as $col) {
    $sheet->getColumnDimension(
        Coordinate::stringFromColumnIndex($col)
    )->setAutoSize(true);
}

/**
 * Save file
 */
$writer = new Xlsx($spreadsheet);
$writer->save($outputFile);

echo "✅ Export complete: {$outputFile}\n";
