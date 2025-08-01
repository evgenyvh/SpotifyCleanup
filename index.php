<?php
session_start();

// ==========================
//  Spotify App Configuratie
// ==========================
define('SPOTIFY_CLIENT_ID', '01b1208bd01340dfab28bf44f3f1628d');
define('SPOTIFY_CLIENT_SECRET', '5cd2e26f09954456be09cf7d529e5729');
define('REDIRECT_URI', 'https://spotifycleanup.onrender.com'); 
define('SCOPES', 'playlist-read-private playlist-modify-public playlist-modify-private');

// Specifieke playlists
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

// Target aantal tracks (configureerbaar)
$TARGET_TRACKS = isset($_POST['target_tracks']) ? intval($_POST['target_tracks']) : 50;

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
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL Error: " . $error);
    }
    
    if ($httpCode === 401) {
        unset($_SESSION['access_token']);
        header('Location: /');
        exit;
    }
    
    if ($httpCode >= 400) {
        $errorData = json_decode($response, true);
        throw new Exception("Spotify API Error: " . ($errorData['error']['message'] ?? 'Unknown error'));
    }
    
    return json_decode($response, true);
}

function getAllPlaylistTracks($playlistId, $accessToken) {
    $tracks = [];
    $url = "https://api.spotify.com/v1/playlists/$playlistId/tracks?fields=items(added_at,track(uri,name,artists(name))),next&limit=100";
    
    while ($url) {
        $data = spotifyApiCall($url, $accessToken);
        if ($data && isset($data['items'])) {
            $tracks = array_merge($tracks, $data['items']);
            $url = $data['next'] ?? null;
        } else {
            break;
        }
    }
    
    // Sorteer op datum toegevoegd (oudste eerst)
    usort($tracks, fn($a, $b) => strtotime($a['added_at']) - strtotime($b['added_at']));
    
    return $tracks;
}

function balancePlaylists($selectedPlaylists, $accessToken, $targetTracks = 50, $dryRun = false) {
    $report = [];
    $trackLog = [];
    $errors = [];
    
    try {
        // Haal tracks op voor alle playlists
        $playlistTracks = [];
        foreach ($selectedPlaylists as $playlist) {
            $tracks = getAllPlaylistTracks($playlist['id'], $accessToken);
            $playlistTracks[$playlist['id']] = $tracks;
            
            $report[$playlist['id']] = [
                'name' => $playlist['name'],
                'before' => count($tracks),
                'after' => count($tracks),
                'moved' => 0,
                'removed' => 0,
                'errors' => []
            ];
        }
        
        // Verwerk elke playlist
        foreach ($playlistTracks as $pid => $tracks) {
            $tracksToProcess = count($tracks) - $targetTracks;
            
            if ($tracksToProcess <= 0) {
                continue; // Deze playlist is al goed
            }
            
            // Verwerk de oudste tracks eerst
            for ($i = 0; $i < $tracksToProcess; $i++) {
                if (!isset($playlistTracks[$pid][$i])) break;
                
                $track = $playlistTracks[$pid][$i];
                $trackUri = $track['track']['uri'];
                $trackName = $track['track']['name'];
                $artistName = $track['track']['artists'][0]['name'] ?? 'Unknown';
                $placed = false;
                
                // Probeer track te verplaatsen naar andere playlists
                foreach ($playlistTracks as $targetId => $targetTracks) {
                    if ($targetId === $pid) continue;
                    
                    // Check of track al bestaat in target playlist
                    $exists = false;
                    foreach ($targetTracks as $t) {
                        if ($t['track']['uri'] === $trackUri) {
                            $exists = true;
                            break;
                        }
                    }
                    
                    if (!$exists && count($playlistTracks[$targetId]) < $targetTracks) {
                        if (!$dryRun) {
                            try {
                                // Voeg toe aan nieuwe playlist
                                spotifyApiCall(
                                    "https://api.spotify.com/v1/playlists/$targetId/tracks",
                                    $accessToken,
                                    'POST',
                                    ['uris' => [$trackUri]]
                                );
                                
                                // Voeg toe aan de lokale array
                                $playlistTracks[$targetId][] = $track;
                            } catch (Exception $e) {
                                $errors[] = "Fout bij verplaatsen van '$trackName': " . $e->getMessage();
                                continue;
                            }
                        }
                        
                        $trackLog[] = [
                            'type' => 'moved',
                            'track' => "$trackName - $artistName",
                            'from' => $report[$pid]['name'],
                            'to' => $report[$targetId]['name'],
                            'timestamp' => date('H:i:s')
                        ];
                        
                        $report[$pid]['moved']++;
                        $report[$targetId]['after']++;
                        $placed = true;
                        break;
                    }
                }
                
                // Als track nergens geplaatst kon worden, markeer voor verwijdering
                if (!$placed) {
                    $trackLog[] = [
                        'type' => 'removed',
                        'track' => "$trackName - $artistName",
                        'from' => $report[$pid]['name'],
                        'timestamp' => date('H:i:s')
                    ];
                    $report[$pid]['removed']++;
                }
                
                $report[$pid]['after']--;
            }
            
            // Voer daadwerkelijke verwijderingen uit
            if (!$dryRun && $report[$pid]['removed'] > 0) {
                $tracksToRemove = [];
                for ($i = 0; $i < $report[$pid]['removed']; $i++) {
                    if (isset($playlistTracks[$pid][$i])) {
                        $tracksToRemove[] = ['uri' => $playlistTracks[$pid][$i]['track']['uri']];
                    }
                }
                
                if (!empty($tracksToRemove)) {
                    try {
                        spotifyApiCall(
                            "https://api.spotify.com/v1/playlists/$pid/tracks",
                            $accessToken,
                            'DELETE',
                            ['tracks' => $tracksToRemove]
                        );
                    } catch (Exception $e) {
                        $errors[] = "Fout bij verwijderen uit '{$report[$pid]['name']}': " . $e->getMessage();
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        $errors[] = "Algemene fout: " . $e->getMessage();
    }
    
    return [
        'report' => $report,
        'trackLog' => $trackLog,
        'errors' => $errors,
        'dryRun' => $dryRun
    ];
}

// OAuth Flow
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
$result = null;

if ($isLoggedIn) {
    try {
        $user = spotifyApiCall('https://api.spotify.com/v1/me', $_SESSION['access_token']);
        
        if ($user) {
            foreach ($MY_PLAYLISTS as $playlistId) {
                try {
                    $playlist = spotifyApiCall(
                        "https://api.spotify.com/v1/playlists/$playlistId?fields=id,name,owner,tracks(total),images",
                        $_SESSION['access_token']
                    );
                    
                    if ($playlist && isset($playlist['id'])) {
                        $playlist['track_count'] = $playlist['tracks']['total'] ?? 0;
                        $playlists[] = $playlist;
                    }
                } catch (Exception $e) {
                    // Skip playlists that can't be accessed
                    continue;
                }
            }
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            $selectedIds = $_POST['playlists'] ?? [];
            $selectedPlaylists = array_filter($playlists, fn($p) => in_array($p['id'], $selectedIds));
            
            if (!empty($selectedPlaylists)) {
                $dryRun = $_POST['action'] === 'preview';
                $result = balancePlaylists($selectedPlaylists, $_SESSION['access_token'], $TARGET_TRACKS, $dryRun);
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>

<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spotify Playlist Cleaner - Smart Balance</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Extra styles voor nieuwe features */
        .preview-mode {
            background: rgba(255, 193, 7, 0.1);
            border: 1px solid #ffc107;
            color: #ffc107;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

```
    .track-log {
        max-height: 400px;
        overflow-y: auto;
        background: var(--bg-elevated);
        border-radius: var(--radius-md);
        padding: var(--space-lg);
    }
    
    .log-entry {
        padding: 8px 0;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .log-entry:last-child {
        border-bottom: none;
    }
    
    .log-timestamp {
        color: var(--text-muted);
        font-size: 0.875rem;
        min-width: 60px;
    }
    
    .log-moved {
        color: var(--success);
    }
    
    .log-removed {
        color: var(--danger);
    }
    
    .error-list {
        background: rgba(226, 33, 52, 0.1);
        border: 1px solid var(--danger);
        color: var(--danger);
        padding: 15px;
        border-radius: 5px;
        margin: 20px 0;
    }
    
    .settings-panel {
        background: var(--bg-elevated);
        padding: var(--space-lg);
        border-radius: var(--radius-md);
        margin-bottom: var(--space-xl);
    }
    
    .setting-row {
        display: flex;
        align-items: center;
        gap: var(--space-md);
        margin-bottom: var(--space-md);
    }
    
    .setting-label {
        min-width: 150px;
        color: var(--text-secondary);
    }
    
    .setting-input {
        background: var(--bg-highlight);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
        padding: 8px 12px;
        border-radius: var(--radius-sm);
        width: 80px;
    }
    
    .stats-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: var(--space-md);
        margin: var(--space-xl) 0;
    }
    
    .stat-box {
        background: var(--bg-elevated);
        padding: var(--space-md);
        border-radius: var(--radius-md);
        text-align: center;
    }
    
    .stat-box h4 {
        color: var(--text-secondary);
        font-size: 0.875rem;
        margin-bottom: var(--space-sm);
    }
    
    .stat-box .number {
        font-size: 2rem;
        font-weight: 700;
    }
    
    .select-all-wrapper {
        margin-bottom: var(--space-md);
        display: flex;
        align-items: center;
        gap: var(--space-sm);
    }
</style>
```

</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-brand">
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 6v6l4 2"/>
                    </svg>
                </div>
                <span class="brand-text">Playlist Cleaner</span>
            </div>

```
        <?php if ($isLoggedIn && $user): ?>
        <div class="nav-user">
            <span class="user-name"><?php echo htmlspecialchars($user['display_name'] ?? 'Gebruiker'); ?></span>
            <div class="user-avatar">
                <?php echo strtoupper(substr($user['display_name'] ?? 'U', 0, 1)); ?>
            </div>
            <a href="?logout=1" class="logout-btn" title="Uitloggen">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>
                </svg>
            </a>
        </div>
        <?php endif; ?>
    </div>
</nav>

<main class="main-content">
    <?php if (!$isLoggedIn): ?>
    <!-- Hero section -->
    <div class="hero-section">
        <div class="hero-card">
            <div class="hero-icon">
                <svg viewBox="0 0 24 24" fill="var(--spotify-green)">
                    <circle cx="12" cy="12" r="10"/>
                    <path fill="white" d="M12 6v6l4 2"/>
                </svg>
            </div>
            
            <h1 class="hero-title">Smart Playlist Balance</h1>
            <p class="hero-subtitle">
                Houd je Spotify playlists automatisch op het perfecte aantal tracks. 
                Verplaats of verwijder oudste nummers intelligent.
            </p>
            
            <div class="feature-badges">
                <div class="badge">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z"/>
                    </svg>
                    <span>Veilig & Betrouwbaar</span>
                </div>
                <div class="badge">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                    <span>Smart Balance</span>
                </div>
                <div class="badge">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                    </svg>
                    <span>Installeerbaar</span>
                </div>
            </div>
            
            <a href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>" class="spotify-login-btn">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zM9.5 14.67c-2.13-.77-4.84-1.3-6-1.49.36-2.82 2.43-5.04 5.05-5.51-.07.44-.11.89-.11 1.33 0 1.52.42 2.94 1.14 4.16-.23.52-.36 1.09-.36 1.68 0 .27.03.54.08.83zm6.47 1.48c-1.06.52-2.24.82-3.47.82-.45 0-.88-.05-1.3-.13.55-1.51 2.01-2.58 3.7-2.58.71 0 1.37.2 1.94.53-.29.48-.65.93-1.07 1.36zm1.14-3.93c-.36-.55-.79-1.05-1.29-1.48.15-.48.24-.98.24-1.5 0-.34-.04-.68-.11-1.01 2.12.47 3.75 2.32 3.86 4.55-1.01-.08-1.95-.25-2.7-.56z"/>
                </svg>
                Verbind met Spotify
            </a>
        </div>
    </div>
    
    <?php else: ?>
    
    <?php if (isset($error)): ?>
    <div class="alert alert-warning">
        <div class="alert-content">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/>
            </svg>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" id="cleanupForm">
        <!-- Settings Panel -->
        <div class="settings-panel">
            <h3 style="margin-bottom: var(--space-md);">Instellingen</h3>
            <div class="setting-row">
                <label class="setting-label">Target aantal tracks:</label>
                <input type="number" name="target_tracks" value="<?php echo $TARGET_TRACKS; ?>" 
                       min="10" max="200" class="setting-input">
                <span style="color: var(--text-secondary); font-size: 0.875rem;">
                    (standaard: 50)
                </span>
            </div>
        </div>

        <!-- Control Panel -->
        <div class="control-panel">
            <div class="stats-card">
                <div class="stat-item">
                    <div class="stat-number"><?php echo count($playlists); ?></div>
                    <div class="stat-label">Playlists</div>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <div class="stat-number" id="selectedCount">0</div>
                    <div class="stat-label">Geselecteerd</div>
                </div>
                <div class="stat-divider"></div>
                <div class="stat-item">
                    <div class="stat-number" id="totalTracks">0</div>
                    <div class="stat-label">Te verwerken</div>
                </div>
            </div>
            
            <div class="action-buttons">
                <button type="submit" name="action" value="preview" class="btn-secondary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 6v6l4 2"/>
                    </svg>
                    Preview
                </button>
                <button type="submit" name="action" value="execute" class="btn-primary" 
                        id="cleanButton" disabled>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    Clean & Balance
                </button>
            </div>
        </div>

        <!-- Select All -->
        <div class="select-all-wrapper">
            <input type="checkbox" id="selectAll" class="playlist-checkbox">
            <label for="selectAll" style="cursor: pointer; color: var(--text-secondary);">
                Selecteer alle playlists
            </label>
        </div>

        <!-- Playlists -->
        <div class="playlists-container">
            <?php foreach ($playlists as $index => $playlist): ?>
            <label class="playlist-item" for="playlist-<?php echo $playlist['id']; ?>">
                <input type="checkbox" 
                       name="playlists[]" 
                       value="<?php echo $playlist['id']; ?>" 
                       id="playlist-<?php echo $playlist['id']; ?>"
                       class="playlist-checkbox playlist-select"
                       data-tracks="<?php echo $playlist['track_count']; ?>">
                
                <div class="playlist-content">
                    <div class="playlist-icon" style="--color-index: <?php echo $index % 6; ?>">
                        <svg viewBox="0 0 24 24" fill="currentColor">
                            <path d="M15 6H3v2h12V6zm0 4H3v2h12v-2zM3 16h8v-2H3v2zM17 6v8.18c-.31-.11-.65-.18-1-.18-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3V8h3V6h-5z"/>
                        </svg>
                    </div>
                    
                    <div class="playlist-info">
                        <div class="playlist-name"><?php echo htmlspecialchars($playlist['name']); ?></div>
                        <div class="playlist-meta">
                            <span class="track-count <?php echo $playlist['track_count'] > $TARGET_TRACKS ? 'excess' : 'optimal'; ?>">
                                <?php echo $playlist['track_count']; ?> tracks
                            </span>
                            <?php if ($playlist['track_count'] > $TARGET_TRACKS): ?>
                            <span class="separator">•</span>
                            <span class="remove-count">
                                <?php echo $playlist['track_count'] - $TARGET_TRACKS; ?> te verwijderen
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="playlist-status">
                        <?php if ($playlist['track_count'] <= $TARGET_TRACKS): ?>
                        <span class="status-badge optimal">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                            </svg>
                            Optimaal
                        </span>
                        <?php else: ?>
                        <span class="status-badge needs-cleanup">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                            </svg>
                            Opschonen
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </label>
            <?php endforeach; ?>
        </div>
    </form>

    <!-- Results -->
    <?php if ($result): ?>
    <div style="margin-top: var(--space-xl);">
        <?php if ($result['dryRun']): ?>
        <div class="preview-mode">
            <strong>Preview Mode:</strong> Dit is een preview van wat er zou gebeuren. 
            Klik op "Clean & Balance" om de wijzigingen door te voeren.
        </div>
        <?php endif; ?>

        <?php if (!empty($result['errors'])): ?>
        <div class="error-list">
            <h3>Fouten opgetreden:</h3>
            <ul>
                <?php foreach ($result['errors'] as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <!-- Summary Stats -->
        <div class="stats-summary">
            <?php 
            $totalMoved = array_sum(array_column($result['report'], 'moved'));
            $totalRemoved = array_sum(array_column($result['report'], 'removed'));
            $totalProcessed = count(array_filter($result['report'], fn($r) => $r['moved'] > 0 || $r['removed'] > 0));
            ?>
            <div class="stat-box">
                <h4>Playlists Verwerkt</h4>
                <div class="number"><?php echo $totalProcessed; ?></div>
            </div>
            <div class="stat-box">
                <h4>Tracks Verplaatst</h4>
                <div class="number" style="color: var(--success);"><?php echo $totalMoved; ?></div>
            </div>
            <div class="stat-box">
                <h4>Tracks Verwijderd</h4>
                <div class="number" style="color: var(--danger);"><?php echo $totalRemoved; ?></div>
            </div>
        </div>

        <!-- Detailed Report -->
        <h3 style="margin-bottom: var(--space-md);">Gedetailleerd Rapport</h3>
        <table style="width: 100%; background: var(--bg-elevated); border-radius: var(--radius-md); overflow: hidden;">
            <thead>
                <tr style="background: var(--bg-highlight);">
                    <th style="padding: var(--space-md); text-align: left;">Playlist</th>
                    <th style="padding: var(--space-md); text-align: center;">Voor</th>
                    <th style="padding: var(--space-md); text-align: center;">Na</th>
                    <th style="padding: var(--space-md); text-align: center;">Verplaatst</th>
                    <th style="padding: var(--space-md); text-align: center;">Verwijderd</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['report'] as $pid => $data): ?>
                <tr style="border-top: 1px solid var(--border-color);">
                    <td style="padding: var(--space-md);"><?php echo htmlspecialchars($data['name']); ?></td>
                    <td style="padding: var(--space-md); text-align: center;"><?php echo $data['before']; ?></td>
                    <td style="padding: var(--space-md); text-align: center;"><?php echo $data['after']; ?></td>
                    <td style="padding: var(--space-md); text-align: center; color: var(--success);"><?php echo $data['moved']; ?></td>
                    <td style="padding: var(--space-md); text-align: center; color: var(--danger);"><?php echo $data['removed']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Track Log -->
        <?php if (!empty($result['trackLog'])): ?>
        <h3 style="margin: var(--space-xl) 0 var(--space-md) 0;">Track Log</h3>
        <div class="track-log">
            <?php foreach ($result['trackLog'] as $log): ?>
            <div class="log-entry">
                <span class="log-timestamp"><?php echo $log['timestamp']; ?></span>
                <?php if ($log['type'] === 'moved'): ?>
                <svg viewBox="0 0 24 24" fill="var(--success)" width="20" height="20">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                </svg>
                <span class="log-moved">
                    <strong><?php echo htmlspecialchars($log['track']); ?></strong> 
                    verplaatst van <?php echo htmlspecialchars($log['from']); ?> 
                    naar <?php echo htmlspecialchars($log['to']); ?>
                </span>
                <?php else: ?>
                <svg viewBox="0 0 24 24" fill="var(--danger)" width="20" height="20">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
                <span class="log-removed">
                    <strong><?php echo htmlspecialchars($log['track']); ?></strong> 
                    verwijderd uit <?php echo htmlspecialchars($log['from']); ?>
                </span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php endif; ?>
</main>

<script>
    // Update selection stats
    function updateStats() {
        const checkboxes = document.querySelectorAll('.playlist-select:checked');
        const targetTracks = parseInt(document.querySelector('[name="target_tracks"]').value) || 50;
        let totalToProcess = 0;
        
        checkboxes.forEach(cb => {
            const tracks = parseInt(cb.dataset.tracks);
            if (tracks > targetTracks) {
                totalToProcess += tracks - targetTracks;
            }
        });
        
        document.getElementById('selectedCount').textContent = checkboxes.length;
        document.getElementById('totalTracks').textContent = totalToProcess;
        document.getElementById('cleanButton').disabled = checkboxes.length === 0;
    }
    
    // Select all functionality
    document.getElementById('selectAll')?.addEventListener('change', function() {
        document.querySelectorAll('.playlist-select').forEach(cb => {
            cb.checked = this.checked;
        });
        updateStats();
    });
    
    // Individual checkbox change
    document.querySelectorAll('.playlist-select').forEach(cb => {
        cb.addEventListener('change', updateStats);
    });
    
    // Target tracks change
    document.querySelector('[name="target_tracks"]')?.addEventListener('change', updateStats);
    
    // Initial stats
    updateStats();
    
    // Prevent accidental navigation during processing
    let processing = false;
    document.getElementById('cleanupForm')?.addEventListener('submit', function(e) {
        if (processing) {
            e.preventDefault();
            return false;
        }
        
        if (e.submitter.value === 'execute') {
            if (!confirm('Weet je zeker dat je de geselecteerde playlists wilt opschonen? Dit kan niet ongedaan gemaakt worden.')) {
                e.preventDefault();
                return false;
            }
            processing = true;
            e.submitter.innerHTML = '<svg class="animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" stroke-dasharray="60" stroke-dashoffset="15"/></svg> Bezig...';
            e.submitter.disabled = true;
        }
    });
    
    // Add playlist item click to toggle checkbox
    document.querySelectorAll('.playlist-item').forEach(item => {
        item.addEventListener('click', function(e) {
            if (e.target.type !== 'checkbox') {
                const checkbox = this.querySelector('.playlist-checkbox');
                checkbox.checked = !checkbox.checked;
                updateStats();
            }
        });
    });
</script>
```

</body>
</html>