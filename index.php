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
    <meta name="theme-color" content="#8b5cf6">
    <title>Playlist Magic ✨ - Spotify Cleanup Tool</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <div class="background-animation">
        <div class="gradient-sphere sphere-1"></div>
        <div class="gradient-sphere sphere-2"></div>
        <div class="gradient-sphere sphere-3"></div>
    </div>

```
<nav class="navbar">
    <div class="nav-container">
        <div class="nav-brand">
            <div class="logo-icon">
                <svg viewBox="0 0 24 24" fill="none">
                    <path d="M9 19C9 20.1 8.1 21 7 21S5 20.1 5 19 5.9 17 7 17 9 17.9 9 19ZM12 3V13L19 16V6L12 3Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M5 12L12 15V3L5 6V12Z" fill="currentColor" opacity="0.3"/>
                </svg>
            </div>
            <span class="brand-text">Playlist Magic</span>
        </div>
        <?php if ($isLoggedIn && $user): ?>
            <div class="nav-user">
                <div class="user-avatar">
                    <?php echo strtoupper(substr($user['display_name'], 0, 1)); ?>
                </div>
                <span class="user-name"><?php echo htmlspecialchars($user['display_name']); ?></span>
                <a href="?logout=1" class="logout-btn">
                    <svg viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/>
                    </svg>
                </a>
            </div>
        <?php endif; ?>
    </div>
</nav>

<main class="main-content">
    <?php if (!$isLoggedIn): ?>
        <div class="hero-section">
            <div class="hero-card">
                <div class="hero-icon">
                    <svg viewBox="0 0 48 48" fill="none">
                        <circle cx="24" cy="24" r="20" stroke="url(#gradient)" stroke-width="3"/>
                        <path d="M18 30V18L30 24L18 30Z" fill="url(#gradient)"/>
                        <defs>
                            <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color:#ec4899"/>
                                <stop offset="50%" style="stop-color:#8b5cf6"/>
                                <stop offset="100%" style="stop-color:#3b82f6"/>
                            </linearGradient>
                        </defs>
                    </svg>
                </div>
                <h1 class="hero-title">Playlist Magic ✨</h1>
                <p class="hero-subtitle">
                    Houd je Spotify playlists fris en georganiseerd.<br>
                    Automatisch op 50 tracks, eerlijk verdeeld.
                </p>
                <div class="feature-badges">
                    <div class="badge">
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/>
                            <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 1 1 0 000 2H6a2 2 0 00-2 2v6a2 2 0 002 2h2a1 1 0 100 2H6a4 4 0 01-4-4V5a4 4 0 014-4z" clip-rule="evenodd"/>
                        </svg>
                        Auto-cleanup
                    </div>
                    <div class="badge">
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path d="M2 10a8 8 0 018-8v8h8a8 8 0 11-16 0z"/>
                            <path d="M12 2.252A8.014 8.014 0 0117.748 8H12V2.252z"/>
                        </svg>
                        Balancering
                    </div>
                    <div class="badge">
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/>
                        </svg>
                        Snel & Simpel
                    </div>
                </div>
                <a href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>" class="spotify-login-btn">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                    </svg>
                    Verbind met Spotify
                </a>
            </div>
        </div>
    <?php else: ?>
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <div class="alert-content">
                    <?php if ($messageType === 'success'): ?>
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                        </svg>
                    <?php else: ?>
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                    <?php endif; ?>
                    <span><?php echo htmlspecialchars($message); ?></span>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" id="cleanupForm">
            <div class="control-panel">
                <div class="stats-card">
                    <div class="stat-item">
                        <div class="stat-number" id="selectedCount">0</div>
                        <div class="stat-label">Geselecteerd</div>
                    </div>
                    <div class="stat-divider"></div>
                    <div class="stat-item">
                        <div class="stat-number"><?php echo count(array_filter($playlists, fn($p) => $p['track_count'] > 50)); ?></div>
                        <div class="stat-label">Boven 50 tracks</div>
                    </div>
                </div>
                <div class="action-buttons">
                    <button type="button" onclick="selectAllExcess()" class="btn-secondary">
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/>
                            <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 1 1 0 000 2H6a2 2 0 00-2 2v6a2 2 0 002 2h2a1 1 0 100 2H6a4 4 0 01-4-4V5a4 4 0 014-4z" clip-rule="evenodd"/>
                        </svg>
                        Selecteer 50+
                    </button>
                    <button type="submit" class="btn-primary" id="cleanButton" disabled>
                        <svg viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/>
                        </svg>
                        <span>Clean & Balance</span>
                    </button>
                </div>
            </div>

            <div class="playlists-container">
                <?php foreach ($playlists as $index => $playlist): ?>
                    <div class="playlist-item" onclick="togglePlaylist('<?php echo $playlist['id']; ?>')">
                        <input type="checkbox" 
                               name="playlists[]" 
                               value="<?php echo $playlist['id']; ?>" 
                               id="playlist-<?php echo $playlist['id']; ?>" 
                               class="playlist-checkbox" 
                               onclick="event.stopPropagation()">
                        
                        <div class="playlist-content">
                            <div class="playlist-icon" style="--color-index: <?php echo $index % 6; ?>">
                                <svg viewBox="0 0 24 24" fill="none">
                                    <path d="M9 19C9 20.1 8.1 21 7 21S5 20.1 5 19 5.9 17 7 17 9 17.9 9 19ZM19 19C19 20.1 18.1 21 17 21S15 20.1 15 19 15.9 17 17 17 19 17.9 19 19Z" stroke="currentColor" stroke-width="2"/>
                                    <path d="M9 19V6L19 3V16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                            </div>
                            
                            <div class="playlist-info">
                                <h3 class="playlist-name"><?php echo htmlspecialchars($playlist['name']); ?></h3>
                                <div class="playlist-meta">
                                    <span class="track-count <?php echo $playlist['track_count'] > 50 ? 'excess' : 'optimal'; ?>">
                                        <?php echo $playlist['track_count']; ?> tracks
                                    </span>
                                    <?php if ($playlist['tracks_to_remove'] > 0): ?>
                                        <span class="separator">•</span>
                                        <span class="remove-count"><?php echo $playlist['tracks_to_remove']; ?> te verwijderen</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="playlist-status">
                                <?php if ($playlist['track_count'] <= 50): ?>
                                    <div class="status-badge optimal">
                                        <svg viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                        </svg>
                                        Perfect
                                    </div>
                                <?php else: ?>
                                    <div class="status-badge needs-cleanup">
                                        <svg viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                        </svg>
                                        Cleanup nodig
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </form>
    <?php endif; ?>
</main>

<script>
function togglePlaylist(playlistId) {
    const checkbox = document.getElementById('playlist-' + playlistId);
    const item = checkbox.closest('.playlist-item');
    checkbox.checked = !checkbox.checked;
    item.classList.toggle('selected', checkbox.checked);
    updateSelectedCount();
}

function selectAllExcess() {
    document.querySelectorAll('.playlist-item').forEach(item => {
        const hasExcess = item.querySelector('.remove-count');
        const checkbox = item.querySelector('input[type="checkbox"]');
        checkbox.checked = !!hasExcess;
        item.classList.toggle('selected', !!hasExcess);
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"]:checked');
    const count = checkboxes.length;
    document.getElementById('selectedCount').textContent = count;
    
    const button = document.getElementById('cleanButton');
    button.disabled = count === 0;
    
    // Animate count change
    const countElement = document.getElementById('selectedCount');
    countElement.style.transform = 'scale(1.2)';
    setTimeout(() => {
        countElement.style.transform = 'scale(1)';
    }, 200);
}

// Add loading state to form submission
document.getElementById('cleanupForm')?.addEventListener('submit', function(e) {
    const button = document.getElementById('cleanButton');
    button.disabled = true;
    button.innerHTML = '<svg class="animate-spin" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg><span>Bezig...</span>';
});
</script>
```

</body>
</html>