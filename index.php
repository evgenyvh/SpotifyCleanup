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

// Alle playlists die meedoen in de verdeling
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

function distributeTracksEvenly($allPlaylists, $accessToken) {
    $report = [];
    $log = [];
    $allTracks = [];
    $playlistContents = [];
    
    // Stap 1: Haal alle tracks op van alle playlists
    foreach ($allPlaylists as $playlist) {
        $tracks = getAllPlaylistTracks($playlist['id'], $accessToken);
        $playlistContents[$playlist['id']] = [
            'info' => $playlist,
            'tracks' => $tracks,
            'uris' => array_map(fn($t) => $t['track']['uri'], $tracks)
        ];
        
        $report[$playlist['id']] = [
            'name' => $playlist['name'],
            'before' => count($tracks),
            'after' => count($tracks),
            'added' => 0,
            'removed' => 0
        ];
    }
    
    // Stap 2: Verzamel alle overtollige tracks
    $excessTracks = [];
    foreach ($playlistContents as $pid => $content) {
        $trackCount = count($content['tracks']);
        if ($trackCount > TARGET_TRACKS) {
            // Sorteer op datum (oudste eerst)
            usort($content['tracks'], fn($a, $b) => strtotime($a['added_at']) - strtotime($b['added_at']));
            
            // Pak de oudste tracks die weg moeten
            $toRemove = array_slice($content['tracks'], 0, $trackCount - TARGET_TRACKS);
            foreach ($toRemove as $track) {
                $excessTracks[] = [
                    'track' => $track,
                    'from_playlist' => $pid,
                    'from_name' => $content['info']['name']
                ];
            }
        }
    }
    
    // Stap 3: Verdeel excess tracks eerlijk (round-robin)
    $playlistIds = array_keys($playlistContents);
    $currentIndex = 0;
    $redistributed = [];
    $toDelete = [];
    
    foreach ($excessTracks as $excess) {
        $placed = false;
        $attempts = 0;
        $trackUri = $excess['track']['track']['uri'];
        $trackName = $excess['track']['track']['name'];
        $artistName = $excess['track']['track']['artists'][0]['name'] ?? 'Unknown';
        
        // Probeer de track te plaatsen in een andere playlist
        while (!$placed && $attempts < count($playlistIds)) {
            $targetId = $playlistIds[$currentIndex % count($playlistIds)];
            $currentIndex++;
            $attempts++;
            
            // Skip de originele playlist
            if ($targetId === $excess['from_playlist']) {
                continue;
            }
            
            $targetContent = $playlistContents[$targetId];
            $wouldHaveAfterRemoval = count($targetContent['tracks']) - 
                count(array_filter($redistributed, fn($r) => $r['from'] === $targetId));
            $wouldHaveAfterAddition = $wouldHaveAfterRemoval + 
                count(array_filter($redistributed, fn($r) => $r['to'] === $targetId));
            
            // Check of de playlist ruimte heeft en de track nog niet bevat
            if ($wouldHaveAfterAddition < TARGET_TRACKS && !in_array($trackUri, $targetContent['uris'])) {
                // Plan de herverdeling
                $redistributed[] = [
                    'track' => $excess['track'],
                    'from' => $excess['from_playlist'],
                    'to' => $targetId,
                    'uri' => $trackUri
                ];
                
                // Update de lokale kopie
                $playlistContents[$targetId]['uris'][] = $trackUri;
                
                $log[] = [
                    'action' => 'redistribute',
                    'track' => "$trackName - $artistName",
                    'from' => $excess['from_name'],
                    'to' => $targetContent['info']['name']
                ];
                
                $placed = true;
            }
        }
        
        // Als niet geplaatst kan worden, markeer voor verwijdering
        if (!$placed) {
            $toDelete[] = $excess;
            $log[] = [
                'action' => 'delete',
                'track' => "$trackName - $artistName",
                'from' => $excess['from_name'],
                'reason' => 'Geen ruimte in andere playlists'
            ];
        }
    }
    
    // Stap 4: Voer de wijzigingen uit
    // Eerst toevoegingen
    foreach ($redistributed as $item) {
        spotifyApiCall(
            "https://api.spotify.com/v1/playlists/{$item['to']}/tracks",
            $accessToken,
            'POST',
            ['uris' => [$item['uri']]]
        );
        
        $report[$item['to']]['added']++;
        $report[$item['to']]['after']++;
    }
    
    // Dan verwijderingen per playlist
    foreach ($playlistContents as $pid => $content) {
        $tracksToRemove = [];
        
        // Verzamel tracks die uit deze playlist verwijderd moeten worden
        foreach (array_merge($redistributed, $toDelete) as $item) {
            if (($item['from'] ?? $item['from_playlist']) === $pid) {
                $tracksToRemove[] = ['uri' => $item['track']['track']['uri'] ?? $item['uri']];
            }
        }
        
        if (!empty($tracksToRemove)) {
            spotifyApiCall(
                "https://api.spotify.com/v1/playlists/$pid/tracks",
                $accessToken,
                'DELETE',
                ['tracks' => $tracksToRemove]
            );
            
            $report[$pid]['removed'] = count($tracksToRemove);
            $report[$pid]['after'] -= count($tracksToRemove);
        }
    }
    
    return ['report' => $report, 'log' => $log];
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
$result = null;

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
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['distribute'])) {
            $result = distributeTracksEvenly($playlists, $_SESSION['access_token']);
        }
    }
}
?>

<!DOCTYPE html>

<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spotify Playlist Distributor</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

```
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
        background: #121212;
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
        background: #282828;
        padding: 20px 0;
        margin: -20px -20px 30px;
        border-bottom: 1px solid #333;
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
        background: #1DB954;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
    }
    
    h1 {
        font-size: 24px;
        font-weight: 600;
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
        background: #1DB954;
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
    }
    
    .btn:hover {
        background: #1ed760;
        transform: scale(1.05);
    }
    
    .btn:active {
        transform: scale(0.98);
    }
    
    .btn-logout {
        background: transparent;
        border: 1px solid #535353;
        padding: 8px 16px;
        font-size: 14px;
    }
    
    .btn-logout:hover {
        border-color: #fff;
        background: transparent;
    }
    
    .hero {
        text-align: center;
        padding: 60px 0;
    }
    
    .hero h2 {
        font-size: 48px;
        margin-bottom: 20px;
        background: linear-gradient(135deg, #1DB954 0%, #1ed760 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    
    .hero p {
        font-size: 20px;
        color: #b3b3b3;
        margin-bottom: 40px;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin: 40px 0;
    }
    
    .stat-card {
        background: #282828;
        padding: 20px;
        border-radius: 8px;
        text-align: center;
        transition: transform 0.2s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
    }
    
    .stat-number {
        font-size: 36px;
        font-weight: 700;
        color: #1DB954;
        display: block;
        margin-bottom: 5px;
    }
    
    .stat-label {
        color: #b3b3b3;
        font-size: 14px;
    }
    
    .playlists-section {
        margin: 40px 0;
    }
    
    .playlists-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .playlists-grid {
        display: grid;
        gap: 12px;
    }
    
    .playlist-card {
        background: #181818;
        padding: 16px 20px;
        border-radius: 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.2s ease;
        border: 2px solid transparent;
    }
    
    .playlist-card:hover {
        background: #282828;
    }
    
    .playlist-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .playlist-icon {
        width: 48px;
        height: 48px;
        background: #282828;
        border-radius: 4px;
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
        padding: 4px 12px;
        border-radius: 100px;
        font-size: 14px;
    }
    
    .optimal {
        background: rgba(29, 185, 84, 0.2);
        color: #1DB954;
    }
    
    .excess {
        background: rgba(248, 113, 113, 0.2);
        color: #f87171;
    }
    
    .action-section {
        text-align: center;
        margin: 60px 0;
        padding: 40px;
        background: #181818;
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
    
    .warning {
        background: rgba(248, 113, 113, 0.1);
        border: 1px solid rgba(248, 113, 113, 0.3);
        color: #f87171;
        padding: 12px 20px;
        border-radius: 8px;
        margin: 20px 0;
        font-size: 14px;
    }
    
    .result-section {
        margin: 40px 0;
        padding: 30px;
        background: #181818;
        border-radius: 12px;
    }
    
    .result-header {
        text-align: center;
        margin-bottom: 30px;
    }
    
    .result-stats {
        display: flex;
        justify-content: center;
        gap: 40px;
        margin-bottom: 40px;
        flex-wrap: wrap;
    }
    
    .result-stat {
        text-align: center;
    }
    
    .result-stat-number {
        font-size: 48px;
        font-weight: 700;
        display: block;
    }
    
    .result-stat-label {
        color: #b3b3b3;
        font-size: 14px;
    }
    
    .result-table {
        width: 100%;
        background: #282828;
        border-radius: 8px;
        overflow: hidden;
        margin-bottom: 30px;
    }
    
    .result-table th {
        background: #333;
        padding: 12px 16px;
        text-align: left;
        font-weight: 600;
        font-size: 14px;
        color: #b3b3b3;
    }
    
    .result-table td {
        padding: 12px 16px;
        border-top: 1px solid #333;
    }
    
    .result-table tr:hover {
        background: #333;
    }
    
    .log-section {
        margin-top: 30px;
    }
    
    .log-header {
        margin-bottom: 20px;
    }
    
    .log-entries {
        background: #282828;
        border-radius: 8px;
        padding: 20px;
        max-height: 400px;
        overflow-y: auto;
    }
    
    .log-entry {
        padding: 10px 0;
        border-bottom: 1px solid #333;
        font-size: 14px;
        display: flex;
        align-items: start;
        gap: 10px;
    }
    
    .log-entry:last-child {
        border-bottom: none;
    }
    
    .log-icon {
        flex-shrink: 0;
        width: 20px;
        height: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .log-redistribute {
        color: #1DB954;
    }
    
    .log-delete {
        color: #f87171;
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
        border: 4px solid #333;
        border-top-color: #1DB954;
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
            gap: 20px;
        }
    }
</style>
```

</head>
<body>
    <?php if (!$isLoggedIn): ?>
    <div class="container">
        <div class="hero">
            <h2>Spotify Playlist Distributor</h2>
            <p>Verdeel tracks intelligent over al je playlists</p>
            <a href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>" class="btn">
                🎵 Verbind met Spotify
            </a>
        </div>
    </div>
    <?php else: ?>
    <div class="header">
        <div class="header-content">
            <div class="logo">
                <div class="logo-icon">🎵</div>
                <h1>Playlist Distributor</h1>
            </div>
            <div class="user-info">
                <span class="user-name">👤 <?php echo htmlspecialchars($user['display_name'] ?? 'Gebruiker'); ?></span>
                <a href="?logout=1" class="btn btn-logout">Uitloggen</a>
            </div>
        </div>
    </div>

```
<div class="container">
    <?php 
    $totalTracks = array_sum(array_column($playlists, 'track_count'));
    $excessTracks = 0;
    $optimalPlaylists = 0;
    
    foreach ($playlists as $playlist) {
        if ($playlist['track_count'] > TARGET_TRACKS) {
            $excessTracks += $playlist['track_count'] - TARGET_TRACKS;
        } else {
            $optimalPlaylists++;
        }
    }
    ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-number"><?php echo count($playlists); ?></span>
            <span class="stat-label">Totaal Playlists</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?php echo $totalTracks; ?></span>
            <span class="stat-label">Totaal Tracks</span>
        </div>
        <div class="stat-card">
            <span class="stat-number"><?php echo $excessTracks; ?></span>
            <span class="stat-label">Te Verdelen Tracks</span>
        </div>
    </div>
    
    <div class="playlists-section">
        <div class="playlists-header">
            <h2>Playlist Overzicht</h2>
            <span style="color: #b3b3b3; font-size: 14px;">Target: <?php echo TARGET_TRACKS; ?> tracks per playlist</span>
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
                                <?php echo $playlist['track_count'] - TARGET_TRACKS; ?> tracks te veel
                            </span>
                            <?php else: ?>
                            <span style="color: #1DB954;">
                                <?php echo TARGET_TRACKS - $playlist['track_count']; ?> plekken vrij
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <span class="track-count <?php echo $playlist['track_count'] > TARGET_TRACKS ? 'excess' : 'optimal'; ?>">
                    <?php echo $playlist['track_count']; ?> tracks
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <?php if ($excessTracks > 0): ?>
    <form method="POST" id="distributeForm">
        <div class="action-section">
            <h2>Klaar om te verdelen?</h2>
            <p>
                Er zijn <strong><?php echo $excessTracks; ?> tracks</strong> die intelligent verdeeld kunnen worden over je playlists.
                De oudste tracks worden eerst verplaatst naar playlists met ruimte.
            </p>
            <div class="warning">
                ⚠️ Let op: Deze actie kan niet ongedaan gemaakt worden. Tracks worden verplaatst of verwijderd.
            </div>
            <button type="submit" name="distribute" value="1" class="btn" onclick="showLoading()">
                🚀 Start Verdeling
            </button>
        </div>
    </form>
    
    <div class="loading" id="loading">
        <div class="spinner"></div>
        <p>Tracks worden verdeeld over je playlists...</p>
        <p style="color: #b3b3b3; font-size: 14px;">Dit kan even duren bij veel tracks</p>
    </div>
    <?php else: ?>
    <div class="action-section">
        <h2>✨ Alles is perfect!</h2>
        <p>Al je playlists hebben <?php echo TARGET_TRACKS; ?> tracks of minder. Er is niets te verdelen.</p>
    </div>
    <?php endif; ?>
    
    <?php if ($result): ?>
    <div class="result-section">
        <div class="result-header">
            <h2>✅ Verdeling Compleet!</h2>
        </div>
        
        <?php
        $totalAdded = array_sum(array_column($result['report'], 'added'));
        $totalRemoved = array_sum(array_column($result['report'], 'removed'));
        $totalRedistributed = count(array_filter($result['log'], fn($l) => $l['action'] === 'redistribute'));
        ?>
        
        <div class="result-stats">
            <div class="result-stat">
                <span class="result-stat-number" style="color: #1DB954;"><?php echo $totalRedistributed; ?></span>
                <span class="result-stat-label">Tracks Verplaatst</span>
            </div>
            <div class="result-stat">
                <span class="result-stat-number" style="color: #f87171;"><?php echo $totalRemoved - $totalRedistributed; ?></span>
                <span class="result-stat-label">Tracks Verwijderd</span>
            </div>
        </div>
        
        <table class="result-table">
            <thead>
                <tr>
                    <th>Playlist</th>
                    <th style="text-align: center;">Voor</th>
                    <th style="text-align: center;">Na</th>
                    <th style="text-align: center;">Toegevoegd</th>
                    <th style="text-align: center;">Verwijderd</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['report'] as $pid => $data): ?>
                <tr>
                    <td><?php echo htmlspecialchars($data['name']); ?></td>
                    <td style="text-align: center;"><?php echo $data['before']; ?></td>
                    <td style="text-align: center; font-weight: 600;"><?php echo $data['after']; ?></td>
                    <td style="text-align: center; color: #1DB954;"><?php echo $data['added'] ?: '-'; ?></td>
                    <td style="text-align: center; color: #f87171;"><?php echo $data['removed'] ?: '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="log-section">
            <h3 class="log-header">📋 Gedetailleerd Logboek</h3>
            <div class="log-entries">
                <?php foreach ($result['log'] as $entry): ?>
                <div class="log-entry">
                    <div class="log-icon">
                        <?php if ($entry['action'] === 'redistribute'): ?>
                        <span class="log-redistribute">↻</span>
                        <?php else: ?>
                        <span class="log-delete">×</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($entry['action'] === 'redistribute'): ?>
                        <strong><?php echo htmlspecialchars($entry['track']); ?></strong> 
                        verplaatst van <em><?php echo htmlspecialchars($entry['from']); ?></em> 
                        naar <em><?php echo htmlspecialchars($entry['to']); ?></em>
                        <?php else: ?>
                        <strong><?php echo htmlspecialchars($entry['track']); ?></strong> 
                        verwijderd uit <em><?php echo htmlspecialchars($entry['from']); ?></em>
                        <span style="color: #b3b3b3;"> - <?php echo htmlspecialchars($entry['reason']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
    function showLoading() {
        document.getElementById('loading').classList.add('active');
        document.getElementById('distributeForm').style.display = 'none';
    }
</script>
```

</body>
</html>