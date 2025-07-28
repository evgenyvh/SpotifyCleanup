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

if ($isLoggedIn) {
    // Haal user info op
    $user = spotifyApiCall('https://api.spotify.com/v1/me', $_SESSION['access_token']);
    
    // Haal playlists op
    if ($user) {
        $allPlaylists = [];
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
    <title>Spotify Playlist Cleaner</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

```
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        background-color: #f5f5f5;
        color: #333;
        line-height: 1.6;
    }
    
    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
    }
    
    header {
        background-color: white;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        padding: 1rem 0;
        margin-bottom: 2rem;
    }
    
    .header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .logo {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 1.5rem;
        font-weight: bold;
        color: #1db954;
    }
    
    .user-info {
        display: flex;
        align-items: center;
        gap: 20px;
    }
    
    .btn {
        display: inline-block;
        padding: 10px 20px;
        background-color: #1db954;
        color: white;
        text-decoration: none;
        border-radius: 25px;
        border: none;
        cursor: pointer;
        font-size: 1rem;
        transition: background-color 0.3s;
    }
    
    .btn:hover {
        background-color: #1ed760;
    }
    
    .btn-secondary {
        background-color: #535353;
    }
    
    .btn-secondary:hover {
        background-color: #727272;
    }
    
    .login-container {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 70vh;
    }
    
    .login-box {
        background: white;
        padding: 3rem;
        border-radius: 10px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        text-align: center;
        max-width: 400px;
    }
    
    .login-box h1 {
        margin-bottom: 1rem;
        color: #1db954;
    }
    
    .login-box p {
        margin-bottom: 2rem;
        color: #666;
    }
    
    .action-bar {
        background: white;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 2rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .playlist-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    
    .playlist-card {
        background: white;
        padding: 1.5rem;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
        position: relative;
    }
    
    .playlist-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }
    
    .playlist-card.selected {
        border: 2px solid #1db954;
        background-color: #f0fdf4;
    }
    
    .playlist-card h3 {
        margin-bottom: 0.5rem;
        font-size: 1.1rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    .playlist-stats {
        display: flex;
        justify-content: space-between;
        margin-top: 0.5rem;
        font-size: 0.9rem;
    }
    
    .track-count {
        font-weight: bold;
    }
    
    .track-count.excess {
        color: #ff6b6b;
    }
    
    .remove-count {
        color: #ff6b6b;
        font-weight: bold;
    }
    
    .checkbox {
        position: absolute;
        top: 1rem;
        right: 1rem;
        width: 20px;
        height: 20px;
    }
    
    .message {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .message.success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .message.warning {
        background-color: #fff3cd;
        color: #856404;
        border: 1px solid #ffeaa7;
    }
    
    .icon {
        width: 24px;
        height: 24px;
        display: inline-block;
        vertical-align: middle;
    }
    
    @media (max-width: 768px) {
        .playlist-grid {
            grid-template-columns: 1fr;
        }
        
        .action-bar {
            flex-direction: column;
            align-items: stretch;
        }
    }
</style>
```

</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <svg class="icon" viewBox="0 0 24 24" fill="#1db954">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
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
                <p>Houd je playlists automatisch op 50 tracks door de oudste nummers te verwijderen.</p>
                <a href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>" class="btn">
                    Login met Spotify
                </a>
                <p style="margin-top: 1rem; font-size: 0.8rem; color: #999;">
                    Redirect URI: <?php echo REDIRECT_URI; ?>
                </p>
            </div>
        </div>
    <?php else: ?>
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
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
                <div style="display: flex; gap: 10px;">
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