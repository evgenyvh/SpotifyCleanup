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
    '4NowFcgobU419IvwzO30UU',
    '7lVoiUPCS6ybdyM2N4ft3y',
    '35vAphzyCEvVNjmfFSrZ3w',
    '2b0mMUJSxpCMthgYhlzsu8',
    '2jHC7HxtpRcuQ7JBEdxLK4',
    '4chcAHApol5NtOOaxrw1KL',
    '3WkmShRLy44QT1SeOCYBqZ',
    '36d0oGY8XUWU0fkZdLL3Sw'
];

// ===============
// Helper functies
// ===============
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
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function fetchTracks($playlistId, $token) {
    $tracks = [];
    $url = "https://api.spotify.com/v1/playlists/$playlistId/tracks?fields=items(added_at,track(uri,id,name)),next";
    while ($url) {
        $data = spotifyApiCall($url, $token);
        if (!$data || !isset($data['items'])) break;
        $tracks = array_merge($tracks, $data['items']);
        $url = $data['next'] ?? null;
    }
    usort($tracks, fn($a,$b) => strtotime($a['added_at']) - strtotime($b['added_at']));
    return $tracks;
}

// ===============
// Balancing functie
// ===============
function balancePlaylists($playlists, $token) {
    $report = [
        'removed_old' => [],
        'moved' => [],
        'deleted_duplicates' => [],
        'deleted_no_space' => []
    ];

    $playlistTracks = [];
    $existing = [];

    // Alle tracks ophalen
    foreach ($playlists as $p) {
        $tracks = fetchTracks($p['id'], $token);
        $playlistTracks[$p['id']] = $tracks;
        $existing[$p['id']] = array_map(fn($t) => $t['track']['uri'], $tracks);
    }

    // Ruimte maken in alle playlists > 50
    foreach ($playlistTracks as $pid => $tracks) {
        $excess = count($tracks) - 50;
        if ($excess > 0) {
            $toDelete = array_slice($tracks, 0, $excess);
            $uris = array_map(fn($t) => ['uri' => $t['track']['uri']], $toDelete);
            spotifyApiCall("https://api.spotify.com/v1/playlists/$pid/tracks", $token, 'DELETE', ['tracks'=>$uris]);
            $report['removed_old'][] = "$excess track(s) verwijderd uit {$pid}";
            $playlistTracks[$pid] = array_slice($tracks, $excess);
            $existing[$pid] = array_map(fn($t) => $t['track']['uri'], $playlistTracks[$pid]);
        }
    }

    // Overschot verzamelen
    $overshot = [];
    foreach ($playlistTracks as $pid => $tracks) {
        if (count($tracks) > 50) {
            $extra = array_slice($tracks, 50);
            foreach ($extra as $t) {
                $overshot[] = ['uri'=>$t['track']['uri'],'from'=>$pid,'name'=>$t['track']['name']];
            }
        }
    }

    // Round-robin verdeling
    $targetPlaylists = array_keys($playlistTracks);
    $idx = 0;
    foreach ($overshot as $track) {
        $placed = false;
        for ($i=0; $i<count($targetPlaylists); $i++) {
            $did = $targetPlaylists[($idx+$i)%count($targetPlaylists)];
            if ($did !== $track['from'] && !in_array($track['uri'], $existing[$did]) && count($playlistTracks[$did])<50) {
                spotifyApiCall("https://api.spotify.com/v1/playlists/$did/tracks", $token, 'POST', ['uris'=>[$track['uri']]]);
                $playlistTracks[$did][] = ['track'=>['uri'=>$track['uri']]];
                $existing[$did][] = $track['uri'];
                $report['moved'][] = "Track '{$track['name']}' verplaatst van {$track['from']} → {$did}";
                $placed = true;
                $idx++;
                break;
            }
        }
        if (!$placed) {
            $report['deleted_duplicates'][] = "Track '{$track['name']}' kon niet geplaatst worden (duplicaat in alle playlists)";
            spotifyApiCall("https://api.spotify.com/v1/playlists/{$track['from']}/tracks", $token, 'DELETE', [
                'tracks'=>[['uri'=>$track['uri']]]
            ]);
        }
    }

    return $report;
}

// ===============
// OAuth & UI
// ===============
if (isset($_GET['code'])) {
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type'=>'authorization_code',
        'code'=>$_GET['code'],
        'redirect_uri'=>REDIRECT_URI,
        'client_id'=>SPOTIFY_CLIENT_ID,
        'client_secret'=>SPOTIFY_CLIENT_SECRET
    ]));
    $response = curl_exec($ch);
    curl_close($ch);
    $tokenData = json_decode($response, true);
    if (isset($tokenData['access_token'])) {
        $_SESSION['access_token']=$tokenData['access_token'];
        header('Location: /'); exit;
    }
}

if (isset($_GET['logout'])) {session_destroy();header('Location: /');exit;}

$isLoggedIn = isset($_SESSION['access_token']);
$report=null;

if ($isLoggedIn) {
    $user = spotifyApiCall('https://api.spotify.com/v1/me', $_SESSION['access_token']);
    $playlists=[];
    foreach ($MY_PLAYLISTS as $id) {
        $pl=spotifyApiCall("https://api.spotify.com/v1/playlists/$id?fields=id,name,owner,tracks(total)",$_SESSION['access_token']);
        if($pl&&isset($pl['id']))$playlists[]=$pl;
    }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $report=balancePlaylists($playlists,$_SESSION['access_token']);
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
body{font-family:Arial,sans-serif;background:#121212;color:#fff;margin:0;padding:0}
.container{max-width:900px;margin:40px auto;padding:20px;background:#181818;border-radius:8px}
h1{text-align:center;color:#1DB954}
button{background:#1DB954;border:none;padding:12px 20px;color:#fff;font-size:16px;cursor:pointer;border-radius:5px}
.report{margin-top:20px;background:#222;padding:15px;border-radius:5px}
.report h2{color:#1DB954}
ul{list-style:none;padding:0}
li{padding:5px 0;border-bottom:1px solid #333}
.download-btn{margin-top:10px;display:inline-block;padding:8px 14px;background:#333;color:#fff;text-decoration:none;border-radius:4px}
</style>
</head>
<body>
<div class="container">
<h1>🎵 Playlist Cleaner - Auto Balance</h1>
<?php if(!$isLoggedIn): ?>
<p style="text-align:center">Verbind met Spotify om automatisch te balanceren.</p>
<div style="text-align:center">
<a href="https://accounts.spotify.com/authorize?client_id=<?php echo SPOTIFY_CLIENT_ID;?>&response_type=code&redirect_uri=<?php echo urlencode(REDIRECT_URI); ?>&scope=<?php echo urlencode(SCOPES); ?>">
<button>🔗 Verbinden met Spotify</button></a>
</div>
<?php else: ?>
<form method="POST">
<div style="text-align:center;margin-bottom:20px">
<button type="submit">🚀 Start Auto Balance Run</button>
</div>
</form>
<?php if($report): ?>
<div class="report">
<h2>📄 Run Rapport</h2>
<h3>🗑 Oude nummers verwijderd</h3><ul><?php foreach($report['removed_old'] as $r)echo"<li>$r</li>";?></ul>
<h3>🔀 Verplaatst</h3><ul><?php foreach($report['moved'] as $r)echo"<li>$r</li>";?></ul>
<h3>❌ Dubbele tracks verwijderd</h3><ul><?php foreach($report['deleted_duplicates'] as $r)echo"<li>$r</li>";?></ul>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>