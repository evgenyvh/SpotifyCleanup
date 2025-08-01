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

function balancePlaylists($playlists, $accessToken) {
    $report = [];
    $trackLog = [];

    // Tracks ophalen
    $playlistTracks = [];
    foreach ($playlists as $p) {
        $tracks = [];
        $url = "https://api.spotify.com/v1/playlists/{$p['id']}/tracks?fields=items(added_at,track(uri,name)),next";
        while ($url) {
            $data = spotifyApiCall($url, $accessToken);
            if ($data && isset($data['items'])) {
                $tracks = array_merge($tracks, $data['items']);
                $url = $data['next'] ?? null;
            } else break;
        }
        usort($tracks, fn($a,$b) => strtotime($a['added_at']) - strtotime($b['added_at']));
        $playlistTracks[$p['id']] = $tracks;

        $report[$p['id']] = [
            'name' => $p['name'],
            'before' => count($tracks),
            'after' => count($tracks),
            'moved' => 0,
            'removed' => 0
        ];
    }

    // Verplaatsen / verdelen
    $allPlaylistIds = array_keys($playlistTracks);

    foreach ($playlistTracks as $pid => $tracks) {
        while (count($playlistTracks[$pid]) > 50) {
            $track = array_shift($playlistTracks[$pid]);
            $trackUri = $track['track']['uri'];
            $trackName = $track['track']['name'];
            $placed = false;

            foreach ($allPlaylistIds as $targetId) {
                if ($targetId === $pid) continue;
                $alreadyExists = in_array($trackUri, array_column($playlistTracks[$targetId], 'track.uri'));
                if (!$alreadyExists && count($playlistTracks[$targetId]) < 50) {
                    $playlistTracks[$targetId][] = $track;

                    spotifyApiCall("https://api.spotify.com/v1/playlists/$targetId/tracks", $accessToken, 'POST', [
                        'uris' => [$trackUri]
                    ]);
                    $trackLog[] = "✅ **$trackName** verplaatst van '{$report[$pid]['name']}' naar '{$report[$targetId]['name']}'";

                    $report[$pid]['moved']++;
                    $report[$targetId]['after']++;
                    $placed = true;
                    break;
                }
            }

            // Als nergens geplaatst → verwijderen
            if (!$placed) {
                spotifyApiCall("https://api.spotify.com/v1/playlists/$pid/tracks", $accessToken, 'DELETE', [
                    'tracks' => [['uri' => $trackUri]]
                ]);
                $trackLog[] = "❌ **$trackName** verwijderd uit '{$report[$pid]['name']}' (geen andere lijst beschikbaar)";
                $report[$pid]['removed']++;
            }
            $report[$pid]['after']--;
        }
    }
    return ['report' => $report, 'trackLog' => $trackLog];
}

// ==========================
//   OAuth
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
        foreach ($MY_PLAYLISTS as $playlistId) {
            $playlist = spotifyApiCall(
                "https://api.spotify.com/v1/playlists/$playlistId?fields=id,name,owner,tracks(total)",
                $_SESSION['access_token']
            );
            if ($playlist && isset($playlist['id'])) {
                $playlist['track_count'] = $playlist['tracks']['total'] ?? 0;
                $playlists[] = $playlist;
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = balancePlaylists($playlists, $_SESSION['access_token']);
    }
}
?>

<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Playlist Cleaner - Auto Balance</title>
    <style>
        body { font-family: Arial, sans-serif; background:#121212; color:#fff; padding:20px; }
        .btn { padding:12px 20px; background:#1DB954; border:none; color:#fff; font-weight:bold; cursor:pointer; border-radius:5px; }
        .report-table { width:100%; margin-top:20px; border-collapse:collapse; }
        .report-table th, .report-table td { padding:8px 12px; border-bottom:1px solid #333; }
        .report-table th { text-align:left; background:#1f1f1f; }
        .badge-green { color:#0f0; font-weight:bold; }
        .badge-red { color:#f33; font-weight:bold; }
        .log { background:#1e1e1e; padding:15px; margin-top:20px; border-radius:5px; }
    </style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
    <a class="btn" href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID; ?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>">Verbind met Spotify</a>
<?php else: ?>
    <form method="POST">
        <button type="submit" class="btn">Clean & Balance Automatisch</button>
    </form>

    <?php if ($result): ?>
        <h2>📊 Overzicht</h2>
        <table class="report-table">
            <tr><th>Playlist</th><th>Voor</th><th>Na</th><th>Verplaatst</th><th>Verwijderd</th></tr>
            <?php foreach ($result['report'] as $pid => $data): ?>
            <tr>
                <td><?php echo htmlspecialchars($data['name']); ?></td>
                <td><?php echo $data['before']; ?></td>
                <td><?php echo $data['after']; ?></td>
                <td class="badge-green"><?php echo $data['moved']; ?></td>
                <td class="badge-red"><?php echo $data['removed']; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <div class="log">
            <h3>📜 Gedetailleerd verslag:</h3>
            <ul>
                <?php foreach ($result['trackLog'] as $log): ?>
                    <li><?php echo $log; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>