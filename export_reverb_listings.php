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
$limit      = 100000;

/**
 * API TOKEN
 */
$token = 'a2dba2cc7cbfdff03e241b2d0d8fbf733877aa2e5cda63727d2de9b6691e5d2e';

/**
 * FTP CONFIG
 */
$ftpHost = 'tooltest.pentagonhosting.co.uk';
$ftpUser = 'tooltest_pentagon';
$ftpPass = 'Ve11vwoG5N1MlmBY';
$ftpPath = '/Bobby/Reverb/ReverbCityMusic.xlsx';

/**
 * HTTP GET with retry
 */
function httpGetJson(string $url, string $token, int $retries = 3): array
{
    $headers = [
        'Content-Type: application/hal+json',
        'Accept: application/hal+json',
        'Accept-Version: 3.0',
        'Authorization: Bearer ' . $token,
    ];

    $attempt = 0;

    while ($attempt < $retries) {

        echo "🌐 Request: {$url}\n";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($errno !== 0) {
            echo "⚠ cURL error {$errno}: {$error}\n";
            $attempt++;
            usleep(500000);
            continue;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $json = json_decode((string)$response, true);
            if (!is_array($json)) {
                throw new RuntimeException('Invalid JSON response');
            }
            return $json;
        }

        if ($httpCode >= 500) {
            echo "⚠ HTTP {$httpCode}, retrying...\n";
            $attempt++;
            usleep(500000);
            continue;
        }

        throw new RuntimeException("HTTP {$httpCode}: {$response}");
    }

    throw new RuntimeException("Failed after retries: {$url}");
}

/**
 * Helpers
 */
function getNested(array $arr, array $path, $default = '')
{
    foreach ($path as $key) {
        if (!isset($arr[$key])) return $default;
        $arr = $arr[$key];
    }
    return $arr;
}

function trimReverbImageUrl(string $url): string
{
    if (!$url) return '';
    return preg_replace('#/quality=.*?/#', '/', $url);
}

/**
 * Condition mapping
 */
function mapCondition(string $condition): int
{
    $map = [
        'brand new' => 1000,
        'mint' => 3000,
        'excellent' => 3000,
        'very good' => 3000,
        'good' => 3000,
        'fair' => 3000,
        'poor' => 3000,
        'non functioning' => 7000,
    ];

    return $map[strtolower(trim($condition))] ?? 1000;
}

/**
 * Fetch images
 */
function getListingImages(int $listingId, string $token): array
{
    $url = "https://api.reverb.com/api/listings/{$listingId}/images";

    try {
        $data = httpGetJson($url, $token);
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), '404')) {
            echo "ℹ No images for {$listingId}\n";
            return [];
        }

        echo "⚠ Images failed for {$listingId}\n";
        return [];
    }

    $images = [];

    foreach ($data['images'] ?? [] as $img) {
        $images[] = trimReverbImageUrl($img['url'] ?? '');
    }

    return $images;
}

/**
 * FTP upload
 */
function uploadToFtp(string $localFile, string $remotePath, string $host, string $user, string $pass): void
{
    echo "🌐 Connecting to FTP...\n";

    $conn = ftp_connect($host, 21, 30);
    if (!$conn) {
        throw new RuntimeException('FTP connect failed');
    }

    if (!ftp_login($conn, $user, $pass)) {
        ftp_close($conn);
        throw new RuntimeException('FTP login failed');
    }

    ftp_pasv($conn, true);

    echo "📤 Uploading file...\n";

    if (!ftp_put($conn, $remotePath, $localFile, FTP_BINARY)) {
        ftp_close($conn);
        throw new RuntimeException('FTP upload failed');
    }

    ftp_close($conn);

    echo "✅ FTP upload complete\n";
}

/**
 * Headers
 */
$headers = [
    'SKU','Channel Item Id','Title','Description','Price','Quantity',
    'Status','State','Condition','Condition Description','Currency',
    'Category','Shipping Profile','Shipping Cost','Main Image',
    'Brand','Model','Finish','Year'
];

for ($i = 1; $i <= 30; $i++) {
    $headers[] = "Image{$i}";
}

/**
 * Spreadsheet
 */
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

foreach ($headers as $i => $header) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $header);
}

$sheet->getStyle('1:1')->getFont()->setBold(true);

$rowNum = 2;
$url = $startUrl;
$processed = 0;

/**
 * MAIN LOOP
 */
while ($url) {

    $data = httpGetJson($url, $token);

    foreach ($data['listings'] ?? [] as $listing) {

        if ($processed >= $limit) break 2;

        $listingId = (int)($listing['id'] ?? 0);
        echo "➡ {$listingId}\n";

        $categories = [];
        foreach ($listing['categories'] ?? [] as $cat) {
            $categories[] = $cat['full_name'] ?? '';
        }

        $mainImage = trimReverbImageUrl(
            getNested($listing, ['photos', 0, '_links', 'full', 'href'], '')
        );

        $images = $listingId > 0 ? getListingImages($listingId, $token) : [];
        usleep(300000);

        $images = array_pad(array_slice($images, 0, 30), 30, '');

        $row = [
            $listing['sku'] ?? '',
            $listing['id'] ?? '',
            $listing['title'] ?? '',
            $listing['description'] ?? '',
            getNested($listing, ['price', 'amount'], ''),
            $listing['inventory'] ?? '',
            !empty($listing['offers_enabled']) ? 'true' : 'false',
            getNested($listing, ['state', 'description'], ''),
            mapCondition(getNested($listing, ['condition', 'display_name'], '')),
            getNested($listing, ['condition', 'description'], ''),
            $listing['listing_currency'] ?? '',
            implode(' | ', $categories),
            $listing['shipping_profile_id'] ?? '',
            getNested($listing, ['shipping', 'rates', 0, 'rate', 'amount'], ''),
            $mainImage,
            $listing['make'] ?? '',
            $listing['model'] ?? '',
            $listing['finish'] ?? '',
            $listing['year'] ?? '',
        ];

        $row = array_merge($row, $images);

        foreach ($row as $col => $val) {
            $sheet->setCellValue(
                Coordinate::stringFromColumnIndex($col + 1) . $rowNum,
                $val
            );
        }

        $rowNum++;
        $processed++;
    }

    $url = $data['_links']['next']['href'] ?? null;
}

/**
 * Save file
 */
$writer = new Xlsx($spreadsheet);
$writer->save($outputFile);

echo "✅ File created\n";

/**
 * Upload to FTP
 */
uploadToFtp($outputFile, $ftpPath, $ftpHost, $ftpUser, $ftpPass);

echo "✅ DONE ({$processed} listings)\n";