<?php
session_start();

// ==========================
//  Spotify App Configuratie
// ==========================
define('SPOTIFY_CLIENT_ID', '01b1208bd01340dfab28bf44f3f1628d');
define('SPOTIFY_CLIENT_SECRET', '5cd2e26f09954456be09cf7d529e5729');
define('REDIRECT_URI', 'https://spotifycleanup.onrender.com'); 
define('SCOPES', 'playlist-read-private playlist-modify-public playlist-modify-private');
define('TARGET_TRACKS', 50);

// Alle playlists die meedoen
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

function getAllPlaylistTracks($playlistId, $accessToken) {
    $tracks = [];
    $url = "https://api.spotify.com/v1/playlists/$playlistId/tracks?fields=items(added_at,track(uri,name,artists(name))),next&limit=100";
    
    while ($url) {
        $data = spotifyApiCall($url, $accessToken);
        if ($data && isset($data['items'])) {
            foreach ($data['items'] as $item) {
                if ($item['track']) { // Skip null tracks
                    $tracks[] = $item;
                }
            }
            $url = $data['next'] ?? null;
        } else {
            break;
        }
    }
    
    return $tracks;
}

function reorderPlaylistAsEscalator($playlist, $accessToken) {
    $playlistId = $playlist['id'];
    $playlistName = $playlist['name'];
    $tracks = getAllPlaylistTracks($playlistId, $accessToken);
    $originalCount = count($tracks);
    
    // Als playlist leeg is of minder dan 2 tracks heeft, skip
    if ($originalCount < 2) {
        return [
            'name' => $playlistName,
            'status' => 'skipped',
            'message' => 'Te weinig tracks om te herordenen',
            'track_count' => $originalCount
        ];
    }
    
    // Sorteer tracks op added_at datum (nieuwste eerst)
    usort($tracks, fn($a, $b) => strtotime($b['added_at']) - strtotime($a['added_at']));
    
    // Als er meer dan TARGET_TRACKS zijn, behoud alleen de nieuwste
    $tracksToKeep = array_slice($tracks, 0, TARGET_TRACKS);
    $tracksToRemove = array_slice($tracks, TARGET_TRACKS);
    
    // Keer de volgorde om zodat oudste bovenaan staat
    $tracksToKeep = array_reverse($tracksToKeep);
    
    // Verzamel track URIs
    $urisToKeep = array_map(fn($t) => $t['track']['uri'], $tracksToKeep);
    
    // Stap 1: Verwijder ALLE tracks uit de playlist
    $allUris = array_map(fn($t) => ['uri' => $t['track']['uri']], $tracks);
    if (!empty($allUris)) {
        spotifyApiCall(
            "https://api.spotify.com/v1/playlists/$playlistId/tracks",
            $accessToken,
            'DELETE',
            ['tracks' => $allUris]
        );
    }
    
    // Stap 2: Voeg tracks terug toe in de juiste volgorde (oudste eerst)
    if (!empty($urisToKeep)) {
        // Spotify API limiet is 100 tracks per keer
        $chunks = array_chunk($urisToKeep, 100);
        foreach ($chunks as $chunk) {
            spotifyApiCall(
                "https://api.spotify.com/v1/playlists/$playlistId/tracks",
                $accessToken,
                'POST',
                ['uris' => $chunk]
            );
        }
    }
    
    $result = [
        'name' => $playlistName,
        'status' => 'success',
        'original_count' => $originalCount,
        'new_count' => count($tracksToKeep),
        'removed_count' => count($tracksToRemove),
        'removed_tracks' => []
    ];
    
    // Voeg info toe over verwijderde tracks
    foreach ($tracksToRemove as $track) {
        $result['removed_tracks'][] = [
            'name' => $track['track']['name'],
            'artist' => $track['track']['artists'][0]['name'] ?? 'Unknown',
            'added_at' => $track['added_at']
        ];
    }
    
    return $result;
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
$results = null;

if ($isLoggedIn) {
    $user = spotifyApiCall('https://api.spotify.com/v1/me', $_SESSION['access_token']);
    
    if ($user) {
        // Haal alle playlists op
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
        
        // Process form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reorder'])) {
            $results = [];
            foreach ($playlists as $playlist) {
                $results[] = reorderPlaylistAsEscalator($playlist, $_SESSION['access_token']);
            }
        }
    }
}
?>

<!DOCTYPE html>

<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spotify Playlist Escalator - Curator Tool</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

```
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
        background: #0a0a0a;
        color: #fff;
        line-height: 1.6;
        min-height: 100vh;
    }
    
    .container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
    }
    
    .header {
        background: rgba(18, 18, 18, 0.8);
        backdrop-filter: blur(10px);
        padding: 20px 0;
        margin: -20px -20px 30px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    .header-content {
        max-width: 1000px;
        margin: 0 auto;
        padding: 0 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .logo {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .logo-icon {
        width: 40px;
        height: 40px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
    }
    
    h1 {
        font-size: 24px;
        font-weight: 600;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    
    .user-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .user-name {
        color: #b3b3b3;
        font-size: 14px;
    }
    
    .btn {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
        border: none;
        padding: 12px 24px;
        border-radius: 500px;
        font-weight: 600;
        cursor: pointer;
        font-size: 16px;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-block;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
    }
    
    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
    }
    
    .btn:active {
        transform: translateY(0);
    }
    
    .btn-logout {
        background: transparent;
        border: 1px solid #535353;
        padding: 8px 16px;
        font-size: 14px;
        box-shadow: none;
    }
    
    .btn-logout:hover {
        border-color: #fff;
        background: transparent;
        box-shadow: none;
    }
    
    .hero {
        text-align: center;
        padding: 60px 0;
    }
    
    .hero h2 {
        font-size: 48px;
        margin-bottom: 20px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    
    .hero p {
        font-size: 20px;
        color: #b3b3b3;
        margin-bottom: 40px;
    }
    
    .info-section {
        background: rgba(18, 18, 18, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 12px;
        padding: 30px;
        margin: 40px 0;
    }
    
    .info-title {
        font-size: 20px;
        margin-bottom: 20px;
        color: #fff;
    }
    
    .escalator-visual {
        background: rgba(102, 126, 234, 0.1);
        border: 1px solid rgba(102, 126, 234, 0.3);
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
    }
    
    .escalator-step {
        display: flex;
        align-items: center;
        gap: 15px;
        margin: 10px 0;
        padding: 10px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 4px;
        transition: all 0.3s ease;
    }
    
    .escalator-step:hover {
        background: rgba(255, 255, 255, 0.1);
    }
    
    .step-position {
        font-weight: 700;
        color: #667eea;
        min-width: 30px;
    }
    
    .step-arrow {
        color: #764ba2;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin: 40px 0;
    }
    
    .stat-card {
        background: rgba(18, 18, 18, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.1);
        padding: 25px;
        border-radius: 12px;
        text-align: center;
        transition: all 0.3s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        border-color: rgba(102, 126, 234, 0.5);
    }
    
    .stat-number {
        font-size: 36px;
        font-weight: 700;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        display: block;
        margin-bottom: 5px;
    }
    
    .stat-label {
        color: #b3b3b3;
        font-size: 14px;
    }
    
    .playlists-grid {
        display: grid;
        gap: 15px;
        margin: 40px 0;
    }
    
    .playlist-card {
        background: rgba(18, 18, 18, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.1);
        padding: 20px;
        border-radius: 12px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.3s ease;
    }
    
    .playlist-card:hover {
        border-color: rgba(102, 126, 234, 0.5);
        transform: translateY(-2px);
    }
    
    .playlist-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .playlist-icon {
        width: 48px;
        height: 48px;
        background: rgba(102, 126, 234, 0.2);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
    }
    
    .playlist-details h3 {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 4px;
    }
    
    .playlist-meta {
        color: #b3b3b3;
        font-size: 14px;
    }
    
    .track-count {
        font-weight: 600;
        padding: 6px 16px;
        border-radius: 100px;
        font-size: 14px;
        background: rgba(102, 126, 234, 0.2);
        color: #667eea;
    }
    
    .action-section {
        text-align: center;
        margin: 60px 0;
        padding: 40px;
        background: rgba(18, 18, 18, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 12px;
    }
    
    .action-section h2 {
        margin-bottom: 20px;
        font-size: 28px;
    }
    
    .action-section p {
        color: #b3b3b3;
        margin-bottom: 30px;
        font-size: 16px;
    }
    
    .result-section {
        margin: 40px 0;
    }
    
    .result-card {
        background: rgba(18, 18, 18, 0.6);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 20px;
    }
    
    .result-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }
    
    .result-title {
        font-size: 18px;
        font-weight: 600;
    }
    
    .result-status {
        padding: 6px 16px;
        border-radius: 100px;
        font-size: 14px;
        font-weight: 600;
    }
    
    .status-success {
        background: rgba(29, 185, 84, 0.2);
        color: #1DB954;
    }
    
    .status-skipped {
        background: rgba(255, 193, 7, 0.2);
        color: #ffc107;
    }
    
    .result-stats {
        display: flex;
        gap: 30px;
        margin-top: 15px;
    }
    
    .result-stat {
        font-size: 14px;
        color: #b3b3b3;
    }
    
    .result-stat strong {
        color: #fff;
    }
    
    .removed-tracks {
        margin-top: 20px;
        background: rgba(255, 255, 255, 0.05);
        border-radius: 8px;
        padding: 15px;
    }
    
    .removed-tracks h4 {
        font-size: 14px;
        color: #b3b3b3;
        margin-bottom: 10px;
    }
    
    .removed-track {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 14px;
    }
    
    .removed-track:last-child {
        border-bottom: none;
    }
    
    .track-info {
        flex: 1;
    }
    
    .track-date {
        color: #666;
        font-size: 12px;
    }
    
    .loading {
        display: none;
        text-align: center;
        padding: 40px;
    }
    
    .loading.active {
        display: block;
    }
    
    .spinner {
        width: 40px;
        height: 40px;
        border: 3px solid rgba(102, 126, 234, 0.3);
        border-top-color: #667eea;
        border-radius: 50%;
        margin: 0 auto 20px;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    @media (max-width: 768px) {
        .hero h2 {
            font-size: 32px;
        }
        
        .hero p {
            font-size: 16px;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .result-stats {
            flex-direction: column;
            gap: 10px;
        }
    }
</style>
```

</head>
<body>
    <?php if (!$isLoggedIn): ?>
    <div class="container">
        <div class="hero">
            <h2>Spotify Playlist Escalator</h2>
            <p>De perfecte tool voor playlist curators</p>

```
        <div class="info-section">
            <h3 class="info-title">🎵 Hoe het werkt:</h3>
            <div class="escalator-visual">
                <div class="escalator-step">
                    <span class="step-position">#50</span>
                    <span class="step-arrow">→</span>
                    <span>Nieuwe submission komt binnen (onderaan)</span>
                </div>
                <div class="escalator-step">
                    <span class="step-position">#25</span>
                    <span class="step-arrow">→</span>
                    <span>Track klimt elke dag een positie</span>
                </div>
                <div class="escalator-step">
                    <span class="step-position">#1</span>
                    <span class="step-arrow">→</span>
                    <span>Top positie! Maximale exposure</span>
                </div>
                <div class="escalator-step">
                    <span class="step-position">✅</span>
                    <span class="step-arrow">→</span>
                    <span>Na 50 dagen wordt track verwijderd</span>
                </div>
            </div>
            <p style="text-align: center; color: #b3b3b3; margin-top: 20px;">
                Elke artiest krijgt exact 50 dagen exposure, van onderaan naar bovenaan!
            </p>
        </div>
        
        <a href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>" class="btn">
            🚀 Verbind met Spotify
        </a>
    </div>
</div>
<?php else: ?>
<div class="header">
    <div class="header-content">
        <div class="logo">
            <div class="logo-icon">↗️</div>
            <h1>Playlist Escalator</h1>
        </div>
        <div class="user-info">
            <span class="user-name">👤 <?php echo htmlspecialchars($user['display_name'] ?? 'Gebruiker'); ?></span>
            <a href="?logout=1" class="btn btn-logout">Uitloggen</a>
        </div>
    </div>
</div>

<div class="container">
    <?php 
    $totalTracks = array_sum(array_column($playlists, 'track_count'));
    $totalToRemove = 0;
    
    foreach ($playlists as $playlist) {
        if ($playlist['track_count'] > TARGET_TRACKS) {
            $totalToRemove += $playlist['track_count'] - TARGET_TRACKS;
        }
    }
    ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-number"><?php echo count($playlists); ?></span>
            <span class="stat-label">Curator Playlists</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?php echo $totalTracks; ?></span>
            <span class="stat-label">Totaal Submissions</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?php echo $totalToRemove; ?></span>
            <span class="stat-label">Tracks te Verwijderen</span>
        </div>
    </div>
    
    <div class="playlists-grid">
        <?php foreach ($playlists as $index => $playlist): ?>
        <div class="playlist-card">
            <div class="playlist-info">
                <div class="playlist-icon">
                    <?php echo ['🎵', '🎸', '🎹', '🎤', '🎧', '🎼', '🎺', '🥁'][$index % 8]; ?>
                </div>
                <div class="playlist-details">
                    <h3><?php echo htmlspecialchars($playlist['name']); ?></h3>
                    <div class="playlist-meta">
                        <?php if ($playlist['track_count'] > TARGET_TRACKS): ?>
                        <span style="color: #f87171;">
                            <?php echo $playlist['track_count'] - TARGET_TRACKS; ?> tracks op positie 1 klaar voor verwijdering
                        </span>
                        <?php elseif ($playlist['track_count'] == TARGET_TRACKS): ?>
                        <span style="color: #1DB954;">
                            Perfect! Playlist is vol
                        </span>
                        <?php else: ?>
                        <span style="color: #ffc107;">
                            <?php echo TARGET_TRACKS - $playlist['track_count']; ?> plekken beschikbaar
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <span class="track-count">
                <?php echo $playlist['track_count']; ?> tracks
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    
    <form method="POST" id="reorderForm">
        <div class="action-section">
            <h2>🎯 Start Dagelijkse Rotatie</h2>
            <p>
                Deze actie zal alle playlists herordenen volgens het escalator principe:<br>
                <strong>Oudste tracks bovenaan → Nieuwste tracks onderaan</strong>
            </p>
            <?php if ($totalToRemove > 0): ?>
            <p style="color: #f87171; margin-top: 20px;">
                ⚠️ Er zullen <strong><?php echo $totalToRemove; ?> tracks</strong> verwijderd worden (die al 50 dagen exposure hebben gehad)
            </p>
            <?php endif; ?>
            <button type="submit" name="reorder" value="1" class="btn" onclick="showLoading()">
                ↗️ Start Escalator Rotatie
            </button>
        </div>
    </form>
    
    <div class="loading" id="loading">
        <div class="spinner"></div>
        <p>Playlists worden geherordend...</p>
        <p style="color: #b3b3b3; font-size: 14px;">Oudste tracks naar boven, nieuwste naar beneden</p>
    </div>
    
    <?php if ($results): ?>
    <div class="result-section">
        <h2 style="text-align: center; margin-bottom: 30px;">✅ Rotatie Compleet!</h2>
        
        <?php foreach ($results as $result): ?>
        <div class="result-card">
            <div class="result-header">
                <h3 class="result-title"><?php echo htmlspecialchars($result['name']); ?></h3>
                <span class="result-status <?php echo $result['status'] === 'success' ? 'status-success' : 'status-skipped'; ?>">
                    <?php echo $result['status'] === 'success' ? '✓ Geroteerd' : '⏭ Overgeslagen'; ?>
                </span>
            </div>
            
            <?php if ($result['status'] === 'success'): ?>
            <div class="result-stats">
                <span class="result-stat">Voor: <strong><?php echo $result['original_count']; ?></strong></span>
                <span class="result-stat">Na: <strong><?php echo $result['new_count']; ?></strong></span>
                <span class="result-stat">Verwijderd: <strong><?php echo $result['removed_count']; ?></strong></span>
            </div>
            
            <?php if (!empty($result['removed_tracks'])): ?>
            <div class="removed-tracks">
                <h4>Verwijderde tracks (hadden 50 dagen exposure):</h4>
                <?php foreach ($result['removed_tracks'] as $track): ?>
                <div class="removed-track">
                    <div class="track-info">
                        <strong><?php echo htmlspecialchars($track['name']); ?></strong> - 
                        <?php echo htmlspecialchars($track['artist']); ?>
                    </div>
                    <div class="track-date">
                        Toegevoegd: <?php echo date('d-m-Y', strtotime($track['added_at'])); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php else: ?>
            <p style="color: #b3b3b3; font-size: 14px; margin-top: 10px;">
                <?php echo htmlspecialchars($result['message']); ?>
            </p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
    function showLoading() {
        document.getElementById('loading').classList.add('active');
        document.getElementById('reorderForm').style.display = 'none';
    }
</script>
```

</body>
</html>