
<?php

if (isset($_GET['url'])) {

    $url = urlencode($_GET['url']);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    header('Content-Type: application/json');
    echo($url);
    echo $response;
} else {
    echo json_encode(['error' => 'Invalid parameters.']);
}
?>