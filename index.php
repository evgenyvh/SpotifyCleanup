<?php
session_start();

// ==========================
//  Spotify App Configuratie
// ==========================
define('SPOTIFY_CLIENT_ID', '01b1208bd01340dfab28bf44f3f1628d');
define('SPOTIFY_CLIENT_SECRET', '5cd2e26f09954456be09cf7d529e5729');
define('REDIRECT_URI', 'https://spotifycleanup.onrender.com'); 
define('SCOPES', 'playlist-read-private playlist-modify-public playlist-modify-private');

// Specifieke playlists die geladen moeten worden
$MY_PLAYLISTS = [
    '4NowFcgobU419IvwzO30UU', // New Talents
    '7lVoiUPCS6ybdyM2N4ft3y', // Next Best
    '35vAphzyCEvVNjmfFSrZ3w', // That Radio Song
    '2b0mMUJSxpCMthgYhlzsu8', // Unique Vibes
    '2jHC7HxtpRcuQ7JBEdxLK4', // Daily Drive
    '4chcAHApol5NtOOaxrw1KL', // Rising Stars
    '3WkmShRLy44QT1SeOCYBqZ', // Early Morning Coffee
    '36d0oGY8XUWU0fkZdLL3Sw'  // Music Roulette
];


// ==========================
//   Helper functie API Call
// ==========================
function spotifyApiCall($url, $accessToken, $method = 'GET', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bear ' . $accessToken,
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
//   Balancing functie
// ==========================
function balancePlaylists($playlists, $accessToken, &$log) {
    $playlistTracks = [];

    // Haal tracks per playlist op
    foreach ($playlists as $p) {
        $tracks = [];
        $url = "https://api.spotify.com/v1/playlists/{$p['id']}/tracks?fields=items(added_at,track(uri,name)),next";
        while ($url) {
            $data = spotifyApiCall($url, $accessToken);
            if ($data && isset($data['items'])) {
                $tracks = array_merge($tracks, $data['items']);
                $url = $data['next'] ?? null;
            } else break;
        }
        usort($tracks, fn($a,$b) => strtotime($a['added_at']) - strtotime($b['added_at']));
        $playlistTracks[$p['id']] = $tracks;
    }

    // Verwerk overschotten
    foreach ($playlistTracks as $pid => $tracks) {
        while (count($tracks) > 50) {
            $track = array_shift($tracks); // oudste track verwijderen/verplaatsen
            $trackUri = $track['track']['uri'];
            $trackName = $track['track']['name'];
            $sourceName = $playlists[$pid]['name'];

            $placed = false;
            foreach ($playlistTracks as $targetId => &$targetTracks) {
                if ($targetId === $pid) continue;
                $existingUris = array_map(fn($t) => $t['track']['uri'], $targetTracks);
                if (count($targetTracks) < 50 && !in_array($trackUri, $existingUris)) {
                    spotifyApiCall("https://api.spotify.com/v1/playlists/$targetId/tracks", $accessToken, 'POST', [
                        'uris' => [$trackUri]
                    ]);
                    $targetTracks[] = $track;
                    $log[] = "➕ **{$trackName}** verplaatst van **{$sourceName}** naar **{$playlists[$targetId]['name']}**";
                    $placed = true;
                    break;
                }
            }

            // Verwijder uit bron
            spotifyApiCall("https://api.spotify.com/v1/playlists/$pid/tracks", $accessToken, 'DELETE', [
                'tracks' => [['uri' => $trackUri]]
            ]);

            if (!$placed) {
                $log[] = "🗑️ **{$trackName}** verwijderd uit **{$sourceName}** (geen plek in andere playlists)";
            }
        }
    }
}


// ==========================
//   OAuth Callback Handler
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
        $_SESSION['refresh_token'] = $tokenData['refresh_token'] ?? null;
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
$user = null;
$playlists = [];
$log = [];

if ($isLoggedIn) {
    $user = spotifyApiCall('https://api.spotify.com/v1/me', $_SESSION['access_token']);
    if ($user) {
        foreach ($MY_PLAYLISTS as $playlistId) {
            $playlist = spotifyApiCall("https://api.spotify.com/v1/playlists/$playlistId?fields=id,name,owner,tracks(total)", $_SESSION['access_token']);
            if ($playlist && isset($playlist['id']) && $playlist['owner']['id'] === $user['id']) {
                $playlist['track_count'] = $playlist['tracks']['total'] ?? 0;
                $playlists[$playlistId] = $playlist;
            }
        }

        // ✅ Automatische balans uitvoeren
        balancePlaylists($playlists, $_SESSION['access_token'], $log);
    }
}
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1DB954">
    <title>Playlist Cleaner - Spotify Tool</title>
    <style>
        body { font-family: Arial, sans-serif; background:#111; color:#fff; margin:0; padding:20px; }
        h1 { color:#1DB954; }
        .log-container { background:#222; padding:15px; border-radius:8px; margin-top:20px; }
        .log-entry { padding:5px 0; border-bottom:1px solid #333; }
        .log-entry:last-child { border:none; }
        .success { color:#1DB954; }
        .removed { color:#ff4d4d; }
    </style>
</head>
<body>
    <h1>🎵 Playlist Cleaner Rapport</h1>

    <?php if (!$isLoggedIn): ?>
        <p><a href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>">👉 Verbind met Spotify</a></p>
    <?php else: ?>
        <div class="log-container">
            <h2>Uitgevoerde acties</h2>
            <?php if (empty($log)): ?>
                <p>✅ Geen wijzigingen nodig, alles staat goed!</p>
            <?php else: ?>
                <?php foreach ($log as $entry): ?>
                    <div class="log-entry"><?php echo $entry; ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>