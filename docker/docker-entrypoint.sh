#!/bin/bash
# =============================================================================
#  Badal — Docker entrypoint
#  Seeds demo podcast "Limites" on first run (when config/config.php is absent).
# =============================================================================

set -e

CONFIG_FILE="/var/www/html/config/config.php"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "🎙  Badal — First run detected. Seeding demo podcast..."

    # ── Generate bcrypt hash for "admin" password ──────────────────────────
    HASH=$(php -r "echo password_hash('admin', PASSWORD_BCRYPT, ['cost' => 12]);")

    # ── Config ────────────────────────────────────────────────────────────────
    cat > "$CONFIG_FILE" << PHPEOF
<?php
return [
    'base_url'            => 'http://localhost:8080',
    'podcast_title'       => 'Limites',
    'podcast_description' => 'Sept frontières invisibles protègent la stabilité de la Terre. Nous en avons déjà franchi six. Ce podcast explore, une par une, les limites planétaires.',
    'author'              => 'Camille Durand',
    'email'               => 'demo@example.com',
    'language'            => 'fr-FR',
    'category'            => 'Science',
    'cover_image'         => '',
    'admin_username'      => 'admin',
    'admin_password_hash' => '${HASH}',
    'content_dir'         => '/var/www/html/content',
    'audio_dir'           => '/var/www/html/audio',
];
PHPEOF

    # ── Theme ─────────────────────────────────────────────────────────────────
    cat > /var/www/html/config/home.json << 'EOF'
{
  "home_tagline": "Les frontières invisibles de la Terre",
  "home_cta_label": "S'abonner via RSS",
  "home_cta_url": "/rss.xml",
  "home_footer_text": "Un podcast indépendant propulsé par Badal — le CMS podcast open-source.",
  "social_spotify": "",
  "social_apple": "",
  "lang": "fr"
}
EOF

    # ── Episodes ──────────────────────────────────────────────────────────────
    EPISODES_DIR="/var/www/html/content/episodes"
    AUDIO_DIR="/var/www/html/audio"
    mkdir -p "$EPISODES_DIR" "$AUDIO_DIR"

    # Episode 1
    cat > "$EPISODES_DIR/01-les-frontieres-invisibles.md" << 'EOF'
---
title: Les frontières invisibles
date: 2026-01-06
episode: 1
duration: 00:30
description: Introduction aux limites planétaires — un cadre scientifique pour comprendre ce que la Terre peut encaisser avant de basculer.
audio: 01-les-frontieres-invisibles.mp3
---

## Les frontières invisibles

En 2009, une équipe menée par Johan Rockström au Stockholm Resilience Centre identifie **neuf processus biophysiques** qui régulent la stabilité du système Terre — et propose pour chacun une **limite à ne pas franchir**.

Tant qu'on reste dans ces limites, la Terre conserve les conditions stables de l'Holocène. Au-delà, on entre en **terre inconnue**.
EOF

    # Episode 2
    cat > "$EPISODES_DIR/02-changement-climatique.md" << 'EOF'
---
title: Changement climatique — la limite emblématique
date: 2026-01-20
episode: 2
duration: 00:30
description: Le CO₂ atmosphérique a dépassé 420 ppm. Pourquoi 350 était la limite, et ce que signifie vivre au-delà.
audio: 02-changement-climatique.mp3
---

## Le thermostat de la Terre

La limite planétaire pour le changement climatique est fixée à **350 ppm de CO₂**. Nous sommes à plus de **420 ppm**. Ce qui rend cette limite dangereuse, ce sont les **boucles de rétroaction positives** : le permafrost qui dégèle, la banquise qui fond, les forêts qui brûlent.
EOF

    # Episode 3
    cat > "$EPISODES_DIR/03-erosion-de-la-biodiversite.md" << 'EOF'
---
title: Érosion de la biodiversité — la limite la plus transgressée
date: 2026-02-03
episode: 3
duration: 00:30
description: Le taux d'extinction actuel est 100 à 1000 fois supérieur au taux naturel.
audio: 03-erosion-de-la-biodiversite.mp3
---

## La sixième extinction

Le taux d'extinction actuel est estimé entre **100 et 1 000 fois** le niveau naturel. La biodiversité n'est pas un luxe esthétique — c'est l'**infrastructure vivante** de la biosphère. Les insectes pollinisent 75% des cultures, les forêts régulent l'eau, les océans produisent 50% de notre oxygène.
EOF

    # ── Generate silent MP3s with ffmpeg (30 seconds each, ~500 KB) ──────────
    echo "   Generating demo audio files..."
    for ep in 01-les-frontieres-invisibles 02-changement-climatique 03-erosion-de-la-biodiversite; do
        if [ ! -f "$AUDIO_DIR/${ep}.mp3" ]; then
            ffmpeg -y -f lavfi -i anullsrc=r=44100:cl=mono -t 30 -q:a 9 \
                "$AUDIO_DIR/${ep}.mp3" 2>/dev/null
        fi
    done

    # ── Sample stats ──────────────────────────────────────────────────────────
    cat > /var/www/html/config/stats.json << 'EOF'
{
  "01-les-frontieres-invisibles": { "total": 4287, "daily": { "2026-01-06": 185, "2026-01-07": 210, "2026-01-08": 178, "2026-01-09": 145 } },
  "02-changement-climatique": { "total": 3842, "daily": { "2026-01-20": 165, "2026-01-21": 195, "2026-01-22": 172 } },
  "03-erosion-de-la-biodiversite": { "total": 2956, "daily": { "2026-02-03": 142, "2026-02-04": 168, "2026-02-05": 155 } }
}
EOF

    # ── Permissions ───────────────────────────────────────────────────────────
    chown -R www-data:www-data /var/www/html/config \
                                /var/www/html/content \
                                /var/www/html/audio

    echo "✅  Demo podcast ready!"
    echo "   → http://localhost:8080"
    echo "   → Admin: http://localhost:8080/admin/  (admin / admin)"
    echo ""
fi

# Hand off to Apache
exec "$@"
