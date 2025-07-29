# Spotify Playlist Cleaner

Houdt je Spotify playlists automatisch op 50 tracks door de oudste nummers te verwijderen.

## Quick Start

1. **Update de Spotify credentials in `index.php`:**
- `SPOTIFY_CLIENT_ID` (regel 5)
- `SPOTIFY_CLIENT_SECRET` (regel 6)
- `REDIRECT_URI` (regel 7) - je Render URL
1. **Push naar GitHub:**
   
   ```bash
   git add .
   git commit -m "Initial commit"
   git push origin main
   ```
1. **Deploy op Render:**
- Ga naar [render.com](https://render.com)
- New → Web Service
- Connect je GitHub repo
- Deploy!
1. **Spotify App Setup:**
- Ga naar [Spotify Developer Dashboard](https://developer.spotify.com/dashboard)
- Maak een nieuwe app
- Voeg je Render URL toe als Redirect URI

## Bestanden

- `index.php` - De complete app
- `styles.css` - Modern Spotify-geïnspireerd design
- `Dockerfile` - Voor Render deployment
- `start.sh` - Startup script voor Apache op Render

That’s it! 🎵