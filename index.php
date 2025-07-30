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

    // Alle bestaande nummers per playlist verzamelen (voor duplicate-check)
    $existingTracks = [];
    foreach ($playlistTracks as $pid => $tracks) {
        $existingTracks[$pid] = array_map(fn($t) => $t['track']['uri'], $tracks);
    }

    // Verplaatsen zonder duplicaten
    foreach ($surplus as $pid => $extra) {
        $tracksToMove = array_splice($playlistTracks[$pid], 0, $extra);
        foreach ($deficit as $did => $needed) {
            if ($needed <= 0) continue;

            // Alleen tracks toevoegen die nog niet bestaan in doelplaylist
            $filteredTracks = array_filter($tracksToMove, function($t) use ($existingTracks, $did) {
                return !in_array($t['track']['uri'], $existingTracks[$did]);
            });

            if (empty($filteredTracks)) continue;

            // Voeg URIs toe aan bestaande lijst zodat we later niet dubbel toevoegen
            foreach ($filteredTracks as $ft) {
                $existingTracks[$did][] = $ft['track']['uri'];
            }

            // Beperk tot aantal dat nodig is
            $moveBatch = array_slice($filteredTracks, 0, $needed);
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
    
    if ($cleanedCount > 0) {
        $message = "$cleanedCount playlist(s) succesvol opgeschoond!";
        $messageType = 'success';
    }
    if (!empty($errors)) {
        $message .= " Fouten bij: " . implode(', ', $errors);
        $messageType = 'warning';
    }
    header("Location: /?message=" . urlencode($message) . "&type=$messageType");
    exit;
}

if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $messageType = $_GET['type'] ?? 'success';
}

$excessCount = count(array_filter($playlists, fn($p) => $p['track_count'] > 50));
$totalTracks = array_sum(array_map(fn($p) => $p['track_count'], $playlists));
?>

<!DOCTYPE html>

<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#1db954">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Playlist Cleaner">
    <title>Spotify Playlist Cleaner</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="apple-touch-icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' fill='%231db954'/><text y='50' x='50' text-anchor='middle' dominant-baseline='middle' font-size='60'>🎵</text></svg>">
    <style>
        /* Critical CSS for immediate load */
        body { 
            background: #121212 !important; 
            color: #ffffff !important; 
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        }
        .logo { color: #ffffff !important; }
        .btn-primary { 
            background: #1db954 !important; 
            color: #000000 !important; 
            text-decoration: none !important;
        }
    </style>
</head>
<body style="background: #121212 !important; color: #ffffff !important; visibility: hidden;">
    <header style="background: #121212 !important;">
        <div class="container">
            <div class="header-content">
                <div class="logo" style="color: #ffffff !important;">
                    <span style="color: #ffffff !important;">Playlist Cleaner</span>
                </div>
                <?php if ($isLoggedIn && $user): ?>
                    <div class="user-info">
                        <span>Hallo, <?php echo htmlspecialchars($user['display_name']); ?></span>
                        <a href="?logout=1" class="btn btn-secondary">Uitloggen</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

```
<main>
    <div class="container">
        <?php if (!$isLoggedIn): ?>
            <div class="login-container">
                <div class="login-box">
                    <h1>Spotify Playlist Cleaner</h1>
                    <p>Houd je playlists automatisch op 50 tracks door de oudste nummers te verwijderen en eerlijk te verdelen tussen je playlists.</p>
                    <a href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>" 
                       class="btn btn-primary" 
                       style="background: #1db954 !important; color: #000000 !important; text-decoration: none !important;">
                        Inloggen met Spotify
                    </a>
                </div>
            </div>
        <?php else: ?>
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($playlists)): ?>
                <div class="message warning">
                    <h3>Geen playlists gevonden</h3>
                    <p>Er zijn geen van jouw playlists gevonden in de configuratie. Controleer of de playlist IDs correct zijn ingesteld.</p>
                </div>
            <?php else: ?>
                <form method="POST" id="cleanupForm">
                    <div class="action-bar">
                        <div class="action-bar-content">
                            <div class="action-info">
                                <p><span id="selectedCount">0</span> playlist(s) geselecteerd</p>
                                <p>
                                    <?php echo $excessCount; ?> playlists hebben meer dan 50 tracks • 
                                    <?php echo $totalTracks; ?> totale tracks
                                </p>
                            </div>
                            <div class="action-buttons">
                                <button type="button" onclick="selectAllExcess()" class="btn btn-secondary">
                                    Selecteer alle 50+
                                </button>
                                <button type="submit" class="btn btn-primary" id="cleanButton" disabled>
                                    <span class="btn-text">Schoon op</span>
                                    <div class="loading" id="loadingSpinner" style="display: none;"></div>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="playlist-grid">
                        <?php foreach ($playlists as $index => $playlist): ?>
                            <div class="playlist-card" onclick="togglePlaylist('<?php echo $playlist['id']; ?>')" style="animation-delay: <?php echo ($index * 0.05); ?>s">
                                <input 
                                    type="checkbox" 
                                    name="playlists[]" 
                                    value="<?php echo $playlist['id']; ?>" 
                                    id="playlist-<?php echo $playlist['id']; ?>" 
                                    class="checkbox" 
                                    data-tracks-to-remove="<?php echo $playlist['tracks_to_remove']; ?>" 
                                    onclick="event.stopPropagation()"
                                    aria-label="Selecteer <?php echo htmlspecialchars($playlist['name']); ?>"
                                >
                                
                                <h3><?php echo htmlspecialchars($playlist['name']); ?></h3>
                                
                                <div class="playlist-stats">
                                    <div>
                                        <span>Tracks</span>
                                        <span class="track-count <?php echo $playlist['track_count'] > 50 ? 'excess' : ''; ?>">
                                            <?php echo $playlist['track_count']; ?>
                                        </span>
                                    </div>
                                    <?php if ($playlist['tracks_to_remove'] > 0): ?>
                                        <div>
                                            <span>Te verwijderen</span>
                                            <span class="remove-count"><?php echo $playlist['tracks_to_remove']; ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($playlist['track_count'] <= 50): ?>
                                    <p>✓ Op ideale grootte</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<script>
    let isSubmitting = false;

    function togglePlaylist(playlistId) {
        if (isSubmitting) return;
        
        const checkbox = document.getElementById('playlist-' + playlistId);
        const card = checkbox.closest('.playlist-card');
        
        checkbox.checked = !checkbox.checked;
        card.classList.toggle('selected', checkbox.checked);
        
        // Add haptic feedback on iOS
        if (window.navigator && window.navigator.vibrate) {
            window.navigator.vibrate(10);
        }
        
        updateSelectedCount();
    }

    function selectAllExcess() {
        if (isSubmitting) return;
        
        const cards = document.querySelectorAll('.playlist-card');
        let selectedAny = false;
        
        cards.forEach(card => {
            const hasExcess = card.querySelector('.remove-count');
            const checkbox = card.querySelector('input[type="checkbox"]');
            
            if (hasExcess) {
                checkbox.checked = true;
                card.classList.add('selected');
                selectedAny = true;
            }
        });
        
        if (selectedAny && window.navigator && window.navigator.vibrate) {
            window.navigator.vibrate([10, 50, 10]);
        }
        
        updateSelectedCount();
    }

    function updateSelectedCount() {
        const checkboxes = document.querySelectorAll('input[type="checkbox"]:checked');
        const count = checkboxes.length;
        
        document.getElementById('selectedCount').textContent = count;
        
        const cleanButton = document.getElementById('cleanButton');
        cleanButton.disabled = count === 0 || isSubmitting;
        
        // Update button text based on selection
        const btnText = document.querySelector('.btn-text');
        if (count === 0) {
            btnText.textContent = 'Schoon op';
        } else if (count === 1) {
            btnText.textContent = 'Schoon 1 playlist op';
        } else {
            btnText.textContent = `Schoon ${count} playlists op`;
        }
    }

    // Form submission with loading state
    document.getElementById('cleanupForm')?.addEventListener('submit', function(e) {
        if (isSubmitting) {
            e.preventDefault();
            return;
        }
        
        const selectedCount = document.querySelectorAll('input[type="checkbox"]:checked').length;
        if (selectedCount === 0) {
            e.preventDefault();
            return;
        }
        
        isSubmitting = true;
        
        // Show loading state
        const cleanButton = document.getElementById('cleanButton');
        const btnText = document.querySelector('.btn-text');
        const loadingSpinner = document.getElementById('loadingSpinner');
        
        btnText.style.display = 'none';
        loadingSpinner.style.display = 'block';
        cleanButton.disabled = true;
        
        // Disable all checkboxes and cards
        document.querySelectorAll('.playlist-card').forEach(card => {
            card.style.pointerEvents = 'none';
            card.style.opacity = '0.6';
        });
        
        // Add visual feedback
        if (window.navigator && window.navigator.vibrate) {
            window.navigator.vibrate([100, 50, 100]);
        }
    });

    // Initialize page
    document.addEventListener('DOMContentLoaded', function() {
        // Hide any loading indicators
        document.body.style.visibility = 'visible';
        
        updateSelectedCount();
        
        // Add smooth scroll behavior
        document.documentElement.style.scrollBehavior = 'smooth';
        
        // Prevent zoom on iOS
        document.addEventListener('gesturestart', function(e) {
            e.preventDefault();
        });
        
        // Add touch feedback to cards
        document.querySelectorAll('.playlist-card').forEach(card => {
            card.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.98)';
            });
            
            card.addEventListener('touchend', function() {
                this.style.transform = '';
            });
        });
        
        // Ensure proper styling is applied
        document.body.style.backgroundColor = '#121212';
        document.body.style.color = '#ffffff';
    });

    // Auto-refresh after successful cleanup
    if (window.location.search.includes('message=')) {
        setTimeout(() => {
            const url = new URL(window.location);
            url.searchParams.delete('message');
            url.searchParams.delete('type');
            window.history.replaceState({}, '', url);
        }, 5000);
    }
</script>
```

</body>
</html>