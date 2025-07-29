<?php
session_start();

// Spotify App Configuratie
define('SPOTIFY_CLIENT_ID', '01b1208bd01340dfab28bf44f3f1628d');
define('SPOTIFY_CLIENT_SECRET', '5cd2e26f09954456be09cf7d529e5729');
define('REDIRECT_URI', 'https://spotifycleanup.onrender.com'); // Vervang met je EXACTE Render URL!
define('SCOPES', 'playlist-read-private playlist-modify-public playlist-modify-private');

// Helper functie voor API calls
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
        // Token verlopen
        unset($_SESSION['access_token']);
        header('Location: /');
        exit;
    }
    
    return json_decode($response, true);
}

// OAuth Callback Handler
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    
    // Wissel code voor access token
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

// Logout handler
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /');
    exit;
}

// Check of gebruiker is ingelogd
$isLoggedIn = isset($_SESSION['access_token']);
$user = null;
$playlists = [];
$message = '';
$messageType = '';
$hasSpecificPlaylists = false;

if ($isLoggedIn) {
    // Haal user info op
    $user = spotifyApiCall('https://api.spotify.com/v1/me', $_SESSION['access_token']);
    
    // Haal playlists op
    if ($user) {
        $allPlaylists = [];
        
        // Check of we specifieke playlists moeten laden
        $hasSpecificPlaylists = false;
        foreach ($MY_PLAYLISTS as $id) {
            if (!empty($id) && strpos($id, 'PLAYLIST_ID') === false) {
                $hasSpecificPlaylists = true;
                break;
            }
        }
        
        if ($hasSpecificPlaylists) {
            // Laad alleen specifieke playlists (VEEL SNELLER!)
            foreach ($MY_PLAYLISTS as $playlistId) {
                // Skip lege entries of placeholder IDs
                if (empty($playlistId) || strpos($playlistId, 'PLAYLIST_ID') !== false) continue;
                
                // Haal playlist info op
                $playlist = spotifyApiCall(
                    "https://api.spotify.com/v1/playlists/$playlistId?fields=id,name,owner,tracks(total)",
                    $_SESSION['access_token']
                );
                
                if ($playlist && isset($playlist['id'])) {
                    // Check of je de eigenaar bent
                    if ($playlist['owner']['id'] === $user['id']) {
                        $playlist['track_count'] = $playlist['tracks']['total'] ?? 0;
                        $playlist['tracks_to_remove'] = max(0, $playlist['track_count'] - 50);
                        $allPlaylists[] = $playlist;
                    }
                } else {
                    // Playlist niet gevonden of geen toegang
                    error_log("Playlist niet gevonden of geen toegang: $playlistId");
                }
            }
        } else {
            // Laad alle playlists (langzamer)
            $url = 'https://api.spotify.com/v1/me/playlists?limit=50';
            
            while ($url) {
                $data = spotifyApiCall($url, $_SESSION['access_token']);
                if ($data && isset($data['items'])) {
                    // Filter alleen playlists waar gebruiker eigenaar van is
                    foreach ($data['items'] as $playlist) {
                        if ($playlist['owner']['id'] === $user['id']) {
                            // Haal track count op
                            $tracksInfo = spotifyApiCall(
                                "https://api.spotify.com/v1/playlists/{$playlist['id']}/tracks?fields=total",
                                $_SESSION['access_token']
                            );
                            
                            $playlist['track_count'] = $tracksInfo['total'] ?? 0;
                            $playlist['tracks_to_remove'] = max(0, $playlist['track_count'] - 50);
                            $allPlaylists[] = $playlist;
                        }
                    }
                    
                    $url = $data['next'] ?? null;
                } else {
                    break;
                }
            }
        }
        
        $playlists = $allPlaylists;
    }
}

// Handle playlist cleaning
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['playlists']) && $isLoggedIn) {
    $selectedPlaylists = $_POST['playlists'];
    $cleanedCount = 0;
    $errors = [];
    
    foreach ($selectedPlaylists as $playlistId) {
        // Zoek playlist in onze lijst
        $playlist = null;
        foreach ($playlists as $p) {
            if ($p['id'] === $playlistId) {
                $playlist = $p;
                break;
            }
        }
        
        if (!$playlist || $playlist['track_count'] <= 50) {
            continue;
        }
        
        try {
            // Haal alle tracks op
            $allTracks = [];
            $url = "https://api.spotify.com/v1/playlists/$playlistId/tracks?fields=items(added_at,track(uri)),next";
            
            while ($url) {
                $data = spotifyApiCall($url, $_SESSION['access_token']);
                if ($data && isset($data['items'])) {
                    $allTracks = array_merge($allTracks, $data['items']);
                    $url = $data['next'] ?? null;
                } else {
                    break;
                }
            }
            
            // Sorteer op datum toegevoegd (oudste eerst)
            usort($allTracks, function($a, $b) {
                return strtotime($a['added_at']) - strtotime($b['added_at']);
            });
            
            // Bepaal hoeveel tracks te verwijderen
            $tracksToRemove = count($allTracks) - 50;
            if ($tracksToRemove <= 0) continue;
            
            // Verwijder oudste tracks (max 100 per keer)
            $tracksToDelete = array_slice($allTracks, 0, $tracksToRemove);
            
            for ($i = 0; $i < count($tracksToDelete); $i += 100) {
                $batch = array_slice($tracksToDelete, $i, 100);
                $deleteData = [
                    'tracks' => array_map(function($item) {
                        return ['uri' => $item['track']['uri']];
                    }, $batch)
                ];
                
                spotifyApiCall(
                    "https://api.spotify.com/v1/playlists/$playlistId/tracks",
                    $_SESSION['access_token'],
                    'DELETE',
                    $deleteData
                );
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
    
    // Herlaad playlists voor nieuwe counts
    header("Location: /?message=" . urlencode($message) . "&type=$messageType");
    exit;
}

// Check voor berichten
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
                <div class="logo">
                    <svg class="icon" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                    </svg>
                    Playlist Cleaner
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
<div class="container">
    <?php if (!$isLoggedIn): ?>
        <div class="login-container">
            <div class="login-box">
                <h1>Spotify Playlist Cleaner</h1>
                <p>Houd je playlists automatisch op 50 tracks door de oudste nummers te verwijderen wanneer je nieuwe toevoegt.</p>
                <a href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>" class="btn">
                    Login met Spotify
                </a>
            </div>
        </div>
    <?php else: ?>
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (empty($playlists) && !$hasSpecificPlaylists && $user): ?>
            <div style="text-align: center; padding: 40px;">
                <p style="color: #b3b3b3;">Geen playlists gevonden waar je eigenaar van bent.</p>
                <p style="color: #666; font-size: 14px; margin-top: 10px;">Tip: Voeg specifieke playlist IDs toe in index.php voor snellere laadtijden.</p>
            </div>
        <?php endif; ?>
        
        <?php if (empty($playlists) && $hasSpecificPlaylists): ?>
            <div class="message warning">
                <p><strong>Geen playlists gevonden!</strong> Mogelijke oorzaken:</p>
                <ul style="margin: 10px 0 0 20px; font-size: 14px; list-style-type: disc;">
                    <li>De playlist IDs zijn niet correct</li>
                    <li>Je bent niet de eigenaar van deze playlists</li>
                    <li>De playlists zijn verwijderd of privé</li>
                </ul>
                <p style="margin-top: 10px;"><strong>Hoe vind je je eigen playlist IDs:</strong></p>
                <ol style="margin: 10px 0 0 20px; font-size: 14px;">
                    <li>Open Spotify → Ga naar jouw playlist</li>
                    <li>Klik op ••• → Delen → Link naar playlist kopiëren</li>
                    <li>Je krijgt: https://open.spotify.com/playlist/<strong>4hOKQuZbraPDIfaGbM3lKI</strong>?si=xxx</li>
                    <li>Kopieer het vetgedrukte deel (het playlist ID)</li>
                    <li>Plak het ID in de $MY_PLAYLISTS array bovenin index.php</li>
                </ol>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="action-bar">
                <div>
                    <p><span id="selectedCount">0</span> playlist(s) geselecteerd</p>
                    <p style="font-size: 0.875rem; color: #666;">
                        Playlists met meer dan 50 tracks: <?php echo count(array_filter($playlists, function($p) { return $p['track_count'] > 50; })); ?>
                    </p>
                </div>
                <div class="action-buttons" style="display: flex; gap: 10px;">
                    <button type="button" onclick="selectAllExcess()" class="btn btn-secondary">
                        Selecteer alle 50+
                    </button>
                    <button type="submit" class="btn" id="cleanButton" disabled>
                        Schoon op
                    </button>
                </div>
            </div>
            
            <div class="playlist-grid">
                <?php foreach ($playlists as $playlist): ?>
                    <div class="playlist-card" onclick="togglePlaylist('<?php echo $playlist['id']; ?>')">
                        <input type="checkbox" 
                               name="playlists[]" 
                               value="<?php echo $playlist['id']; ?>" 
                               id="playlist-<?php echo $playlist['id']; ?>"
                               class="checkbox"
                               onclick="event.stopPropagation()">
                        
                        <h3><?php echo htmlspecialchars($playlist['name']); ?></h3>
                        
                        <div class="playlist-stats">
                            <div>
                                <span>Tracks: </span>
                                <span class="track-count <?php echo $playlist['track_count'] > 50 ? 'excess' : ''; ?>">
                                    <?php echo $playlist['track_count']; ?>
                                </span>
                            </div>
                            <?php if ($playlist['tracks_to_remove'] > 0): ?>
                                <div>
                                    <span>Te verwijderen: </span>
                                    <span class="remove-count"><?php echo $playlist['tracks_to_remove']; ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($playlist['track_count'] <= 50): ?>
                            <p style="margin-top: 0.5rem; color: #1db954; font-size: 0.875rem;">
                                ✓ Op ideale grootte
                            </p>
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
        
        if (checkbox.checked) {
            card.classList.add('selected');
        } else {
            card.classList.remove('selected');
        }
        
        updateSelectedCount();
    }
    
    function selectAllExcess() {
        const cards = document.querySelectorAll('.playlist-card');
        
        cards.forEach(card => {
            const hasExcess = card.querySelector('.remove-count');
            const checkbox = card.querySelector('input[type="checkbox"]');
            
            if (hasExcess) {
                checkbox.checked = true;
                card.classList.add('selected');
            } else {
                checkbox.checked = false;
                card.classList.remove('selected');
            }
        });
        
        updateSelectedCount();
    }
    
    function updateSelectedCount() {
        const checkboxes = document.querySelectorAll('input[type="checkbox"]:checked');
        const count = checkboxes.length;
        document.getElementById('selectedCount').textContent = count;
        document.getElementById('cleanButton').disabled = count === 0;
    }
    
    // Initialize checkboxes
    document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
        checkbox.addEventListener('change', function(e) {
            e.stopPropagation();
            const card = this.closest('.playlist-card');
            if (this.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
            updateSelectedCount();
        });
    });
</script>
```

</body>
</html>