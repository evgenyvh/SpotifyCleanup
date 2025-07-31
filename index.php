<?php
session_start();

// ==========================
// Spotify App Configuratie
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
// Helper functie API Call
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
// Nieuwe Balancing functie
// ==========================
function autoBalancePlaylists($playlists, $accessToken) {
    $playlistTracks = [];
    foreach ($playlists as $p) {
        $tracks = [];
        $url = "https://api.spotify.com/v1/playlists/{$p['id']}/tracks?fields=items(added_at,track(uri)),next";
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

    // Maak een grote pool van alle tracks
    $allTracks = [];
    foreach ($playlistTracks as $pid => $tracks) {
        foreach ($tracks as $t) {
            $allTracks[] = [
                'pid' => $pid,
                'uri' => $t['track']['uri'],
                'added_at' => $t['added_at']
            ];
        }
    }

    // Sorteren op datum (nieuwste laatst)
    usort($allTracks, fn($a,$b) => strtotime($b['added_at']) - strtotime($a['added_at']));

    // Unieke tracks bijhouden
    $uniqueUris = [];
    $finalPlaylists = [];
    foreach ($playlists as $p) {
        $finalPlaylists[$p['id']] = [];
    }

    // Verdeeld tracks over alle playlists (max 50, zonder duplicates)
    $index = 0;
    foreach ($allTracks as $track) {
        if (in_array($track['uri'], $uniqueUris)) continue;
        $targetPid = array_keys($finalPlaylists)[$index % count($playlists)];
        if (count($finalPlaylists[$targetPid]) < 50) {
            $finalPlaylists[$targetPid][] = $track['uri'];
            $uniqueUris[] = $track['uri'];
        }
        $index++;
    }

    // Synchroniseer met Spotify (overschrijven bestaande inhoud)
    foreach ($finalPlaylists as $pid => $uris) {
        // Clear playlist
        spotifyApiCall("https://api.spotify.com/v1/playlists/$pid/tracks", $accessToken, 'PUT', ['uris' => []]);

        // Voeg nieuwe tracks toe in batches van 100
        for ($i=0; $i<count($uris); $i+=100) {
            $batch = array_slice($uris, $i, 100);
            spotifyApiCall("https://api.spotify.com/v1/playlists/$pid/tracks", $accessToken, 'POST', ['uris' => $batch]);
        }
    }
}

// ==========================
// OAuth Callback Handler
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
$user = null;
$playlists = [];

if ($isLoggedIn) {
    $user = spotifyApiCall('https://api.spotify.com/v1/me', $_SESSION['access_token']);
    foreach ($MY_PLAYLISTS as $playlistId) {
        $playlist = spotifyApiCall(
            "https://api.spotify.com/v1/playlists/$playlistId?fields=id,name,tracks(total)",
            $_SESSION['access_token']
        );
        if ($playlist && isset($playlist['id'])) {
            $playlist['track_count'] = $playlist['tracks']['total'] ?? 0;
            $playlists[] = $playlist;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    autoBalancePlaylists($playlists, $_SESSION['access_token']);
    header("Location: /?message=Playlists%20succesvol%20gebalanceerd!");
    exit;
}
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spotify Playlist Cleaner</title>
    <style>
        body { font-family: Arial, sans-serif; margin:0; background:#121212; color:#fff; }
        header { padding:20px; text-align:center; background:#1DB954; font-size:24px; font-weight:bold; }
        .container { padding:20px; max-width:600px; margin:auto; }
        .playlist { background:#181818; padding:15px; margin-bottom:10px; border-radius:6px; display:flex; justify-content:space-between; align-items:center; }
        .playlist-name { font-size:16px; }
        .badge { padding:3px 8px; border-radius:4px; font-size:12px; }
        .ok { background:#1DB954; }
        .warn { background:#e63946; }
        .button { width:100%; padding:15px; margin-top:15px; background:#1DB954; color:#fff; border:none; border-radius:6px; font-size:16px; cursor:pointer; }
        .button:hover { background:#1aa34a; }
        .login-btn { display:block; text-align:center; background:#1DB954; color:#fff; padding:15px; margin-top:30px; border-radius:6px; text-decoration:none; font-size:18px; }
    </style>
</head>
<body>
<header>Spotify Playlist Cleaner</header>
<div class="container">
<?php if(!$isLoggedIn): ?>
    <p style="text-align:center;">Automatische balans en opschoning van je playlists (max 50 tracks).</p>
    <a class="login-btn" href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>">🔗 Verbind met Spotify</a>
<?php else: ?>
    <h2>Welkom, <?php echo htmlspecialchars($user['display_name'] ?? 'User'); ?> 👋</h2>
    <?php foreach ($playlists as $p): ?>
        <div class="playlist">
            <span class="playlist-name"><?php echo htmlspecialchars($p['name']); ?></span>
            <?php if($p['track_count']>50): ?>
                <span class="badge warn"><?php echo $p['track_count']; ?> tracks</span>
            <?php else: ?>
                <span class="badge ok"><?php echo $p['track_count']; ?> tracks</span>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <form method="POST">
        <button type="submit" class="button">⚡ Clean & Balance Alle Playlists</button>
    </form>
    <p style="text-align:center; margin-top:20px;"><a href="?logout=1" style="color:#ccc;">Uitloggen</a></p>
<?php endif; ?>
</div>
</body>
</html>