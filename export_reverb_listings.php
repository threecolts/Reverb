<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * CONFIG
 */
$startUrl  = 'https://api.reverb.com/api/my/listings?state=all';
$outputFile = __DIR__ . '/ReverbCityMusic.xlsx';

/**
 * 🔑 HARD-CODED TOKEN (as requested)
 */
$token = 'a2dba2cc7cbfdff03e241b2d0d8fbf733877aa2e5cda63727d2de9b6691e5d2e';

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
 * Create XLSX
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

// Header row
foreach ($headers as $i => $header) {
    $sheet->setCellValueByColumnAndRow($i + 1, 1, $header);
}
$sheet->freezePane('A2');
$sheet->getStyle('1:1')->getFont()->setBold(true);

$rowNum = 2;
$url = $startUrl;

while ($url) {
    $data = httpGetJson($url, $token);

    foreach ($data['listings'] as $listing) {

        // Categories
        $categories = [];
        foreach ($listing['categories'] ?? [] as $cat) {
            $categories[] = $cat['full_name'] ?? '';
        }

        // Shipping cost (first rate)
        $shippingCost = getNested($listing, ['shipping','rates',0,'rate','amount'], '');

        // Main image (first photo)
        $mainImage = getNested($listing, ['photos',0,'_links','full','href'], '');

        $row = [
            $listing['sku'] ?? '',
            $listing['id'] ?? '',
            $listing['title'] ?? '',
            $listing['description'] ?? '',
            getNested($listing, ['price','amount'], ''),
            $listing['inventory'] ?? '',
            $listing['offers_enabled'] ? 'true' : 'false',
            getNested($listing, ['state','description'], ''),
            getNested($listing, ['condition','display_name'], ''),
            getNested($listing, ['condition','description'], ''),
            $listing['listing_currency'] ?? '',
            implode(' | ', $categories),
            $listing['shipping_profile_id'] ?? '',
            $shippingCost,
            $mainImage,
            $listing['make'] ?? '',
            $listing['model'] ?? '',
            $listing['finish'] ?? '',
            $listing['year'] ?? '',
        ];

        foreach ($row as $col => $value) {
            $sheet->setCellValueByColumnAndRow($col + 1, $rowNum, $value);
        }

        $rowNum++;
    }

    // Pagination
    $url = $data['_links']['next']['href'] ?? null;

    // Small delay to be API-friendly
    usleep(150000);
}

$writer = new Xlsx($spreadsheet);
$writer->save($outputFile);

echo "✅ Export complete: {$outputFile}\n";
