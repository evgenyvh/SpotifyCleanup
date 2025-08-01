<?php
session_start();

// ==========================
//  Spotify Config
// ==========================
define('SPOTIFY_CLIENT_ID', '01b1208bd01340dfab28bf44f3f1628d');
define('SPOTIFY_CLIENT_SECRET', '5cd2e26f09954456be09cf7d529e5729');
define('REDIRECT_URI', 'https://spotifycleanup.onrender.com');
define('SCOPES', 'playlist-read-private playlist-modify-public playlist-modify-private');

$MY_PLAYLISTS = [
    '4NowFcgobU419IvwzO30UU', 
    '7lVoiUPCS6ybdyM2N4ft3y',
    '35vAphzyCEvVNjmfFSrZ3w',
    '2b0mMUJSxpCMthgYhlzsu8',
    '2jHC7HxtpRcuQ7JBEdxLK4',
    '4chcAHApol5NtOOaxrw1KL',
    '3WkmShRLy44QT1SeOCYBqZ',
    '36d0oGY8XUWU0fkZdLL3Sw'
];

// ==========================
//   Helper functie API Call
// ==========================
function spotifyApiCall($url, $accessToken, $method = 'GET', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 401) {
        unset($_SESSION['access_token']);
        header('Location: /');
        exit;
    }

    return json_decode($response, true);
}

// ==========================
//   Tracks ophalen
// ==========================
function getPlaylistTracks($playlistId, $accessToken) {
    $tracks = [];
    $url = "https://api.spotify.com/v1/playlists/$playlistId/tracks?fields=items(added_at,track(id,uri)),next";
    while ($url) {
        $data = spotifyApiCall($url, $accessToken);
        if ($data && isset($data['items'])) {
            $tracks = array_merge($tracks, $data['items']);
            $url = $data['next'] ?? null;
        } else break;
    }
    return $tracks;
}

// ==========================
//   Verbeterde Balancing
// ==========================
function balancePlaylists($playlists, $accessToken) {
    $MAX_TRACKS = 50;
    $trackIndex = [];
    $duplicates = [];
    $overflowTracks = [];

    // 1️⃣ Verzamel alle tracks en duplicates
    foreach ($playlists as $p) {
        $tracks = getPlaylistTracks($p['id'], $accessToken);
        foreach ($tracks as $t) {
            $tid = $t['track']['id'];
            $added = strtotime($t['added_at']);
            if (isset($trackIndex[$tid])) {
                $duplicates[] = ['pid' => $p['id'], 'tid' => $tid];
            } else {
                $trackIndex[$tid] = ['pid' => $p['id'], 'added' => $added];
            }
        }
    }

    // 2️⃣ Verwijder duplicates
    foreach ($duplicates as $dup) {
        spotifyApiCall("https://api.spotify.com/v1/playlists/{$dup['pid']}/tracks", $accessToken, 'DELETE', [
            'tracks' => [['uri' => 'spotify:track:' . $dup['tid']]]
        ]);
    }

    // 3️⃣ Verwerk overschot per playlist
    foreach ($playlists as $p) {
        $tracks = getPlaylistTracks($p['id'], $accessToken);
        if (count($tracks) > $MAX_TRACKS) {
            usort($tracks, fn($a,$b) => strtotime($a['added_at']) - strtotime($b['added_at']));
            $extra = array_splice($tracks, $MAX_TRACKS);
            foreach ($extra as $e) {
                $overflowTracks[] = [
                    'tid' => $e['track']['id'],
                    'from' => $p['id']
                ];
                spotifyApiCall("https://api.spotify.com/v1/playlists/{$p['id']}/tracks", $accessToken, 'DELETE', [
                    'tracks' => [['uri' => 'spotify:track:' . $e['track']['id']]]
                ]);
            }
        }
    }

    // 4️⃣ Verspreid overtollige tracks
    foreach ($overflowTracks as $t) {
        $added = false;
        foreach ($playlists as $p) {
            $tracks = getPlaylistTracks($p['id'], $accessToken);
            $trackIds = array_map(fn($tr) => $tr['track']['id'], $tracks);
            if (count($tracks) < $MAX_TRACKS && !in_array($t['tid'], $trackIds)) {
                spotifyApiCall("https://api.spotify.com/v1/playlists/{$p['id']}/tracks", $accessToken, 'POST', [
                    'uris' => ['spotify:track:' . $t['tid']]
                ]);
                $added = true;
                break;
            }
        }
        if (!$added) {
            // Geen plek in andere playlist → verwijderd
            spotifyApiCall("https://api.spotify.com/v1/playlists/{$t['from']}/tracks", $accessToken, 'DELETE', [
                'tracks' => [['uri' => 'spotify:track:' . $t['tid']]]
            ]);
        }
    }

    return "✅ Balancing voltooid!";
}

// ==========================
//   OAuth Callback
// ==========================
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => REDIRECT_URI,
        'client_id' => SPOTIFY_CLIENT_ID,
        'client_secret' => SPOTIFY_CLIENT_SECRET
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $tokenData = json_decode($response, true);
    if (isset($tokenData['access_token'])) {
        $_SESSION['access_token'] = $tokenData['access_token'];
        header('Location: /');
        exit;
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

$isLoggedIn = isset($_SESSION['access_token']);
$message = '';

if ($isLoggedIn) {
    $user = spotifyApiCall('https://api.spotify.com/v1/me', $_SESSION['access_token']);
    $allPlaylists = [];

    foreach ($MY_PLAYLISTS as $pid) {
        $pl = spotifyApiCall("https://api.spotify.com/v1/playlists/$pid?fields=id,name,tracks(total)", $_SESSION['access_token']);
        if ($pl && isset($pl['id'])) {
            $allPlaylists[] = $pl;
        }
    }

    $message = balancePlaylists($allPlaylists, $_SESSION['access_token']);
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Playlist Auto-Balancer</title>
    <style>
        body { font-family: Arial, sans-serif; background: #121212; color: white; text-align: center; margin: 0; padding: 0; }
        .container { padding: 40px; }
        .btn { background: #1DB954; color: white; padding: 12px 24px; border: none; border-radius: 25px; cursor: pointer; font-size: 18px; text-decoration: none; display: inline-block; }
        .btn:hover { background: #1ed760; }
        .message { margin-top: 30px; padding: 15px; background: #282828; border-radius: 5px; display: inline-block; }
        h1 { font-size: 28px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!$isLoggedIn): ?>
            <h1>Spotify Playlist Auto-Balancer</h1>
            <p>Automatisch opruimen en balanceren van je ingestuurde nummers.<br> Altijd maximaal 50 tracks per playlist, nieuwste nummers eerst.</p>
            <a class="btn" href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>">🔗 Verbinden met Spotify</a>
        <?php else: ?>
            <h1>✅ Balancing voltooid!</h1>
            <div class="message"><?php echo $message; ?></div><br><br>
            <a href="?logout=1" class="btn">Log uit</a>
        <?php endif; ?>
    </div>
</body>
</html>