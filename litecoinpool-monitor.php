#!/usr/bin/php
<?php

// SETTINGS: Enter your LitecoinPool API Key between the quotes below
// You can find your API key on your LitecoinPool.org account page:
//  https://www.litecoinpool.org/account
$apiKey = 'ENTER_YOUR_API_KEY_HERE';

// Low hash rate threshold (percent); if a worker falls below these thresholds
// (current vs 24 average) a corresponding warning level will be triggered
$lowRate = [
    'low' => 10,
    'medium' => 15,
    'high' => 20,
];

// The lowest alert level color to show in the menu bar text; until this level
// is reached, the menu bar text will be the system default (ex: black or white)
//
// 4 = disable color (always system color)
// 3 = only show high (red)
// 2 = show medium (orange) and greater
// 1 = show low (yellow) and greater
// 0 = show all (green when no alert)
$menuBarMinAlert = 0;

////////////////////////////////////////////////////////////////////////////////
//                       DO NOT EDIT BELOW THIS LINE                          //
////////////////////////////////////////////////////////////////////////////////

// <bitbar.title>LitecoinPool.org Monitor</bitbar.title>
// <bitbar.version>v1.0</bitbar.version>
// <bitbar.author>Justin Ryan</bitbar.author>
// <bitbar.author.github>jstnryan</bitbar.author.github>
// <bitbar.desc>'At a glance' monitoring of your LitecoinPool workers</bitbar.desc>
// <bitbar.image></bitbar.image>
// <bitbar.dependencies>php,curl</bitbar.dependencies>
// <bitbar.abouturl>https://github.com/jstnryan/litecoinpool-monitor-bitbar</bitbar.abouturl>

if ($apiKey === 'ENTER_YOUR_API_KEY_HERE') {
    echo "Unconfigured | color=yellow\n";
    echo "---\n";
    echo "LitecoinPool API Key not provided.\n";
    echo "Please edit the plugin file settings.\n";
    die;
}

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_URL => 'https://www.litecoinpool.org/api?api_key=' . $apiKey,
]);
$response = curl_exec($curl);
curl_close($curl);

/**
 * Error urgency level
 *
 * 0 = no fault (green)
 * 1 = notice (yellow)
 * 2 = warning (organge)
 * 3 = critical (red)
 */
$fault = 0;
if ($response === false || empty($response)) {
    echo "Unavailable | color=yellow\n";
    echo "---\n";
    echo "Failed to get a response from server.\n";
    die;
} else {
    $obj = json_decode($response, JSON_OBJECT_AS_ARRAY);
}

if ($obj === null) {
    echo "Unavailable | color=yellow\n";
    echo "---\n";
    echo "Unable to parse response from server.\n";
    die;
}

$maxCol = [0,0,0,0,0];
$workers = [];
if (isset($obj['workers']) && !empty($obj['workers'])) {
    foreach ($obj['workers'] as $name => $worker) {
        $w['fault'] = setFault(threshold($worker['hash_rate'], $worker['hash_rate_24h']));
        if (!$worker['connected']) {
            $w['fault'] = setFault(3);
        }

        $w['name'] = $name;
        if (strlen($name) > $maxCol[0]) {
            $maxCol[0] = strlen($name);
        }
        $w['rate'] = number_format($worker['hash_rate'], 1, '.', ',') . 'kH/s';
        if (strlen($w['rate']) > $maxCol[1]) {
            $maxCol[1] = strlen($w['rate']);
        }
        $w['ave'] = number_format($worker['hash_rate_24h'], 1, '.', ',') . 'kH/s';
        if (strlen($w['ave']) > $maxCol[2]) {
            $maxCol[2] = strlen($w['ave']);
        }
        $w['stale'] = number_format(round(($worker['stale_shares'] / $worker['valid_shares']) * 100, 2), 2, '.', ',') . '%';
        if (strlen($w['stale']) > $maxCol[4]) {
            $maxCol[3] = strlen($w['stale']);
        }
        $w['invalid'] = number_format(round(($worker['invalid_shares'] / $worker['valid_shares']) * 100, 2), 2, '.', ',') . '%';
        if (strlen($w['invalid']) > $maxCol[4]) {
            $maxCol[4] = strlen($w['invalid']);
        }
        $workers[] = $w;
    }
}
$workerLines = str_pad('Worker', $maxCol[0]) . '  ' . str_pad('Speed', $maxCol[1]) . '  ' . str_pad('Av.Spd', $maxCol[2]) . '  ' . str_pad('Stale', $maxCol[3]) . '  ' . str_pad('Inval', $maxCol[4]) . " | font=Courier\n";
foreach ($workers as $worker) {
    $workerLines .= str_pad($worker['name'], $maxCol[0]) . '  ' . str_pad($worker['rate'], $maxCol[1]) . '  ' . str_pad($worker['ave'], $maxCol[2]) . '  ' . str_pad($worker['stale'], $maxCol[3]) . '  ' . str_pad($worker['invalid'], $maxCol[4]);
    $workerLines .= ' | font=Courier' . lineColor($worker['fault'], 1, ' ') . "\n";
}

echo number_format($obj['user']['hash_rate'], 1, '.', ',') . 'kH/s' . lineColor($fault, $menuBarMinAlert) ."\n";
echo '---' . "\n";
echo $workerLines;
echo 'Go to account page | href="https://www.litecoinpool.org/account"' . "\n";

/**
 * Elevate global fault level, to highest
 */
function setFault($level) {
    global $fault;

    if ($level > $fault) {
        $fault = $level;
    }
    return $level;
}

/**
 * Return a line color modifier string based on the input error level
 *
 * Color names are CSS3 (CSS Color Module Level 4)
 * They are defined in BitBar code here:
 *  https://github.com/matryer/bitbar/blob/e140612a1c93dc9cc9611875178451d7ec6e8b44/App/BitBar/NSColor%2BHex.m#L18
 */
function lineColor($level, $minimum = 0, $prefix = ' | ') {
    if ($level < $minimum) {
        return $prefix;
    }
    $color = $prefix . 'color=';
    switch ($level) {
        case 3:
            $color .= 'red';
            break;
        case 2:
            $color .= '"orange red"';
            break;
        case 1:
            $color .= 'yellow';
            break;
        case 0:
            $color .= 'green';
            break;
        default:
            return $prefix;
    }
    return $color;
}

/**
 * Return current speed PERCENT BELOW (less than) 24h efficiency average
 */
function speedFactor($dividend, $divisor, $precision = 0) {
    if ($divisor == 0) {
        //not ===, could be string
        return 0; //special case for no previous data
    }
    if ($dividend > $divisor) {
        return 0; //more efficient
    }
    return round(100 - (($dividend / $divisor) * 100), $precision);
}

/**
 * Return the worker error level based on speedFactor
 */
function threshold($speed, $average) {
    global $lowRate;

    $factor = speedFactor($speed, $average, 1);
    if ($factor < $lowRate['low']) {
        return 0;
    } elseif ($factor < $lowRate['medium']) {
        return 1;
    } elseif ($factor < $lowRate['high']) {
        return 2;
    }
    return 3;
}
