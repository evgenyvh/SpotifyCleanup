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
//   Balancing functie
// ==========================
function balancePlaylists($playlists, $accessToken) {
    $totalTracks = array_sum(array_map(fn($p) => $p['track_count'], $playlists));
    $playlistCount = count($playlists);
    $target = min(50, floor($totalTracks / $playlistCount)); // gelijkmatig verdelen

    // Tracks ophalen per playlist
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

    // Surplus en tekort bepalen
    $surplus = [];
    $deficit = [];
    foreach ($playlists as $p) {
        $count = $p['track_count'];
        if ($count > $target) {
            $surplus[$p['id']] = $count - $target;
        } elseif ($count < $target) {
            $deficit[$p['id']] = $target - $count;
        }
    }

    // Verplaatsen
    foreach ($surplus as $pid => $extra) {
        $tracksToMove = array_splice($playlistTracks[$pid], 0, $extra);
        foreach ($deficit as $did => $needed) {
            if ($needed <= 0) continue;
            $moveBatch = array_splice($tracksToMove, 0, $needed);
            $uris = array_map(fn($t) => $t['track']['uri'], $moveBatch);

            if (!empty($uris)) {
                // Toevoegen
                spotifyApiCall("https://api.spotify.com/v1/playlists/$did/tracks", $accessToken, 'POST', [
                    'uris' => $uris
                ]);
                // Verwijderen
                $deleteData = ['tracks' => array_map(fn($u) => ['uri' => $u], $uris)];
                spotifyApiCall("https://api.spotify.com/v1/playlists/$pid/tracks", $accessToken, 'DELETE', $deleteData);

                $deficit[$did] -= count($uris);
                $extra -= count($uris);
            }
            if ($extra <= 0) break;
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
$message = '';
$messageType = '';
$hasSpecificPlaylists = !empty($MY_PLAYLISTS);

if ($isLoggedIn) {
    $user = spotifyApiCall('https://api.spotify.com/v1/me', $_SESSION['access_token']);
    if ($user) {
        $allPlaylists = [];
        foreach ($MY_PLAYLISTS as $playlistId) {
            if (empty($playlistId)) continue;
            $playlist = spotifyApiCall(
                "https://api.spotify.com/v1/playlists/$playlistId?fields=id,name,owner,tracks(total)",
                $_SESSION['access_token']
            );
            if ($playlist && isset($playlist['id']) && $playlist['owner']['id'] === $user['id']) {
                $playlist['track_count'] = $playlist['tracks']['total'] ?? 0;
                $playlist['tracks_to_remove'] = max(0, $playlist['track_count'] - 50);
                $allPlaylists[] = $playlist;
            }
        }
        $playlists = $allPlaylists;
    }
}

// ==========================
//   POST Request Handler
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['playlists']) && $isLoggedIn) {
    // 1️⃣ Eerst balanceren
    balancePlaylists($playlists, $_SESSION['access_token']);

    // 2️⃣ Daarna standaard cleanup
    $selectedPlaylists = $_POST['playlists'];
    $cleanedCount = 0;
    $errors = [];

    foreach ($selectedPlaylists as $playlistId) {
        $playlist = null;
        foreach ($playlists as $p) {
            if ($p['id'] === $playlistId) { $playlist = $p; break; }
        }
        if (!$playlist || $playlist['track_count'] <= 50) continue;

        try {
            $allTracks = [];
            $url = "https://api.spotify.com/v1/playlists/$playlistId/tracks?fields=items(added_at,track(uri)),next";
            while ($url) {
                $data = spotifyApiCall($url, $_SESSION['access_token']);
                if ($data && isset($data['items'])) {
                    $allTracks = array_merge($allTracks, $data['items']);
                    $url = $data['next'] ?? null;
                } else break;
            }
            usort($allTracks, fn($a,$b) => strtotime($a['added_at']) - strtotime($b['added_at']));
            $tracksToRemove = count($allTracks) - 50;
            if ($tracksToRemove <= 0) continue;
            $tracksToDelete = array_slice($allTracks, 0, $tracksToRemove);
            for ($i = 0; $i < count($tracksToDelete); $i += 100) {
                $batch = array_slice($tracksToDelete, $i, 100);
                $deleteData = [
                    'tracks' => array_map(fn($item) => ['uri' => $item['track']['uri']], $batch)
                ];
                spotifyApiCall("https://api.spotify.com/v1/playlists/$playlistId/tracks", $_SESSION['access_token'], 'DELETE', $deleteData);
            }
            $cleanedCount++;
        } catch (Exception $e) {
            $errors[] = $playlist['name'];
        }
    }
    if ($cleanedCount > 0) $message = "$cleanedCount playlist(s) succesvol opgeschoond!";
    if (!empty($errors)) {
        $message .= " Fouten bij: " . implode(', ', $errors);
        $messageType = 'warning';
    }
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
    <meta name="theme-color" content="#1db954">
    <title>Spotify Playlist Cleaner</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">Playlist Cleaner</div>
                <?php if ($isLoggedIn && $user): ?>
                    <div class="user-info">
                        <span>Hallo, <?php echo htmlspecialchars($user['display_name']); ?></span>
                        <a href="?logout=1" class="btn btn-secondary">Uitloggen</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

<div class="container">
<?php if (!$isLoggedIn): ?>
    <div class="login-container">
        <div class="login-box">
            <h1>Spotify Playlist Cleaner</h1>
            <p>Houd je playlists automatisch op 50 tracks door de oudste nummers te verwijderen en eerlijk te verdelen.</p>
            <a href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>" class="btn">
                Login met Spotify
            </a>
        </div>
    </div>
<?php else: ?>
    <?php if ($message): ?>
        <div class="message <?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="action-bar">
            <div>
                <p><span id="selectedCount">0</span> playlist(s) geselecteerd</p>
                <p style="font-size: 0.875rem; color: #666;">
                    Playlists met meer dan 50 tracks: <?php echo count(array_filter($playlists, fn($p) => $p['track_count'] > 50)); ?>
                </p>
            </div>
            <div class="action-buttons" style="display: flex; gap: 10px;">
                <button type="button" onclick="selectAllExcess()" class="btn btn-secondary">Selecteer alle 50+</button>
                <button type="submit" class="btn" id="cleanButton" disabled>Schoon op</button>
            </div>
        </div>
        <div class="playlist-grid">
            <?php foreach ($playlists as $playlist): ?>
                <div class="playlist-card" onclick="togglePlaylist('<?php echo $playlist['id']; ?>')">
                    <input type="checkbox" name="playlists[]" value="<?php echo $playlist['id']; ?>" id="playlist-<?php echo $playlist['id']; ?>" class="checkbox" data-tracks-to-remove="<?php echo $playlist['tracks_to_remove']; ?>" onclick="event.stopPropagation()">
                    <h3><?php echo htmlspecialchars($playlist['name']); ?></h3>
                    <div class="playlist-stats">
                        <div><span>Tracks: </span><span class="track-count <?php echo $playlist['track_count'] > 50 ? 'excess' : ''; ?>"><?php echo $playlist['track_count']; ?></span></div>
                        <?php if ($playlist['tracks_to_remove'] > 0): ?>
                            <div><span>Te verwijderen: </span><span class="remove-count"><?php echo $playlist['tracks_to_remove']; ?></span></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($playlist['track_count'] <= 50): ?>
                        <p style="margin-top: 0.5rem; color: #1db954; font-size: 0.875rem;">✓ Op ideale grootte</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </form>
<?php endif; ?>
</div>

<script>
function togglePlaylist(playlistId) {
    const checkbox = document.getElementById('playlist-' + playlistId);
    const card = checkbox.closest('.playlist-card');
    checkbox.checked = !checkbox.checked;
    card.classList.toggle('selected', checkbox.checked);
    updateSelectedCount();
}
function selectAllExcess() {
    document.querySelectorAll('.playlist-card').forEach(card => {
        const hasExcess = card.querySelector('.remove-count');
        const checkbox = card.querySelector('input[type="checkbox"]');
        checkbox.checked = !!hasExcess;
        card.classList.toggle('selected', !!hasExcess);
    });
    updateSelectedCount();
}
function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"]:checked');
    document.getElementById('selectedCount').textContent = checkboxes.length;
    document.getElementById('cleanButton').disabled = checkboxes.length === 0;
}
</script>
</body>
</html>