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
//   Automatische Balance
// ==========================
function balancePlaylists($playlists, $accessToken) {
    $MAX_TRACKS = 50;
    $playlistTracks = [];
    $surplusTracks = [];

    // 1️⃣ Tracks ophalen per playlist
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

        // verzamel overschot
        if (count($tracks) > $MAX_TRACKS) {
            $extra = array_splice($playlistTracks[$p['id']], 0, count($tracks) - $MAX_TRACKS);
            foreach ($extra as $t) {
                $t['source'] = $p['id'];
                $surplusTracks[] = $t;
            }
        }
    }

    if (empty($surplusTracks)) return;

    // 2️⃣ Round-robin verdelen
    $targetPlaylists = array_column($playlists, 'id');
    $i = 0;
    foreach ($surplusTracks as $track) {
        $placed = false;
        $attempts = 0;
        while (!$placed && $attempts < count($targetPlaylists)) {
            $targetPid = $targetPlaylists[$i % count($targetPlaylists)];
            $i++;
            $attempts++;

            // geen terugplaatsing in bron
            if ($targetPid === $track['source']) continue;

            // duplicaatcheck
            $exists = array_map(fn($t) => $t['track']['uri'], $playlistTracks[$targetPid]);
            if (in_array($track['track']['uri'], $exists)) continue;

            // ruimte maken indien vol
            if (count($playlistTracks[$targetPid]) >= $MAX_TRACKS) {
                $oldest = array_shift($playlistTracks[$targetPid]);
                spotifyApiCall("https://api.spotify.com/v1/playlists/$targetPid/tracks", $accessToken, 'DELETE', [
                    'tracks' => [['uri' => $oldest['track']['uri']]]
                ]);
            }

            // toevoegen
            spotifyApiCall("https://api.spotify.com/v1/playlists/$targetPid/tracks", $accessToken, 'POST', [
                'uris' => [$track['track']['uri']]
            ]);
            $playlistTracks[$targetPid][] = $track;
            $placed = true;
        }

        // niet geplaatst -> verwijderen
        if (!$placed) {
            spotifyApiCall("https://api.spotify.com/v1/playlists/{$track['source']}/tracks", $accessToken, 'DELETE', [
                'tracks' => [['uri' => $track['track']['uri']]]
            ]);
        }
    }
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
$message = '';
$messageType = '';

if ($isLoggedIn) {
    $user = spotifyApiCall('https://api.spotify.com/v1/me', $_SESSION['access_token']);
    if ($user) {
        foreach ($MY_PLAYLISTS as $playlistId) {
            $playlist = spotifyApiCall(
                "https://api.spotify.com/v1/playlists/$playlistId?fields=id,name,owner,tracks(total)",
                $_SESSION['access_token']
            );
            if ($playlist && isset($playlist['id']) && $playlist['owner']['id'] === $user['id']) {
                $playlist['track_count'] = $playlist['tracks']['total'] ?? 0;
                $playlist['tracks_to_remove'] = max(0, $playlist['track_count'] - 50);
                $playlists[] = $playlist;
            }
        }
    }
}

// ==========================
//   POST: Automatische Clean & Balance
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    balancePlaylists($playlists, $_SESSION['access_token']);
    $message = "Playlists succesvol gebalanceerd en opgeschoond!";
    $messageType = 'success';
    header("Location: /?message=" . urlencode($message) . "&type=$messageType");
    exit;
}

if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $messageType = $_GET['type'] ?? 'info';
}
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Playlist Cleaner - Spotify Tool</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-brand">
                <span class="brand-text">Playlist Cleaner</span>
            </div>
            <?php if ($isLoggedIn && $user): ?>
                <div class="nav-user">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user['display_name'] ?? 'U', 0, 1)); ?>
                    </div>
                    <span class="user-name"><?php echo htmlspecialchars($user['display_name'] ?? 'User'); ?></span>
                    <a href="?logout=1" class="logout-btn">Uitloggen</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>

<main class="main-content">
<?php if (!$isLoggedIn): ?>
    <div class="hero-section">
        <h1>Spotify Playlist Cleaner</h1>
        <p>Automatische balans en opschoning van je playlists (max 50 tracks).</p>
        <a href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>" class="spotify-login-btn">Verbind met Spotify</a>
    </div>
<?php else: ?>
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    <form method="POST" id="cleanupForm">
        <div class="control-panel">
            <button type="submit" class="btn-primary" id="cleanButton">Clean & Balance</button>
        </div>
        <div class="playlists-container">
            <?php foreach ($playlists as $playlist): ?>
                <div class="playlist-item">
                    <div class="playlist-info">
                        <h3><?php echo htmlspecialchars($playlist['name']); ?></h3>
                        <div><?php echo $playlist['track_count']; ?> tracks</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </form>
<?php endif; ?>
</main>
</body>
</html>