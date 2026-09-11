---
description: Créer et publier une nouvelle version du plugin sur GitHub (Releases)
---

Ce workflow met à jour les numéros de version, crée un commit, pousse vers GitHub, génère le package d'asset et publie la release officielle.

1. Analyser les derniers commits depuis le précédent tag Git en exécutant la commande :
   ```bash
   git log $(git describe --tags --abbrev=0)..HEAD
   ```
   (ou l'intégralité du log s'il n'y a pas encore de tag), ainsi que les modifications locales en cours.
2. En déduire la **nouvelle version sémantique** (patch, mineur, ou majeur) en fonction de l'importance des changements.
3. Rédiger automatiquement un **changelog** récapitulatif clair et concis.
4. Mettre à jour la ligne `Version: x.x.x` et `define( 'WFBT_VERSION', 'x.x.x' );` dans `woo-fb-tracking-server-side.php`.
5. Mettre à jour `Stable tag: x.x.x` et ajouter les détails de la version générée dans la section `== Changelog ==` du fichier `readme.txt`.
6. **Contrôle de sécurité strict obligatoire (Zéro Info Sensible)** :
   - Vérifier qu'aucune information sensible (jeton Meta `EAAB...`, token GitHub `ghp_...`, mot de passe, clé privée ou URL confidentielle) n'est présente dans les fichiers modifiés ou le code source via `git diff` ou recherche de motifs sensibles.

// turbo
7. Ajouter les fichiers modifiés (`git add woo-fb-tracking-server-side.php readme.txt AGENTS.md .agent/workflows/agent-releaser.md`)

// turbo
8. Créer le commit (`git commit -m "Bump version to v[VERSION]"`)

// turbo
9. Pousser le commit vers la branche principale (`git push origin main`)

// turbo
10. Créer un tag Git pour la release (`git tag -a v[VERSION] -m "Release v[VERSION]"`)

// turbo
11. Pousser le tag vers GitHub (`git push origin v[VERSION]`)

12. Créer le package zip de release `woo-meta-tracking-server-side.zip` :
    ```powershell
    $tempDir = Join-Path $env:TEMP "woo-meta-tracking-server-side"
    Remove-Item -Recurse -Force $tempDir -ErrorAction SilentlyContinue
    New-Item -ItemType Directory -Path $tempDir | Out-Null
    Get-ChildItem -Path . -Exclude ".git", "*.zip" | Copy-Item -Destination $tempDir -Recurse
    $zipPath = ".\woo-meta-tracking-server-side.zip"
    Compress-Archive -Path $tempDir -DestinationPath $zipPath
    Remove-Item -Recurse -Force $tempDir -ErrorAction SilentlyContinue
    ```

13. Publier la release sur GitHub avec l'outil GitHub CLI (`gh`) :
    ```bash
    gh release create v[VERSION] "woo-meta-tracking-server-side.zip" --title "v[VERSION]" --notes "[CHANGELOG]"
    ```
    Puis supprimer le zip local temporaire :
    ```powershell
    Remove-Item -Force ".\woo-meta-tracking-server-side.zip" -ErrorAction SilentlyContinue
    ```
