# 🆘 MoodleSecours

Solution de secours pour afficher les cours Moodle (.mbz) quand la plateforme académique est en panne.

## 🎯 Fonctionnalités

- **Upload temporaire** : Les professeurs peuvent uploader leurs cours .mbz (suppression auto après 24h)
- **Cours permanents** : Liaison avec Google Drive pour les cours fréquemment utilisés
- **Support H5P** : Affichage des contenus interactifs H5P (CoursePresentation, MultiChoice, etc.)
- **Quiz Moodle** : Tous les types de questions avec correction automatique
- **Responsive** : Interface adaptée mobile/tablette pour les élèves
- **Sécurisé** : Mot de passe prof, quota de stockage, suppression automatique

## 📋 Prérequis

- PHP 7.4+ (8.0+ recommandé)
- Extensions PHP : `zip`, `curl`, `json`, `fileinfo`, `phar`
- Apache avec mod_rewrite (ou nginx)
- ~100 Mo d'espace disque pour l'application + espace pour les cours

## 🚀 Installation

### 1. Téléchargement

```bash
# Téléchargez et décompressez dans votre hébergement
cd /var/www/votre-site
unzip moodle-secours.zip
```

### 2. Configuration

Éditez le fichier `config.php` :

```php
// URL de votre installation
define('SITE_URL', 'https://votre-site.com/moodle-secours');

// Mot de passe professeur (hashé avec password_hash)
define('UPLOAD_PASSWORD', password_hash('votre-mot-de-passe', PASSWORD_DEFAULT));

// ID du dossier Google Drive (optionnel)
define('GDRIVE_FOLDER_ID', 'VOTRE_ID_DE_DOSSIER');

// Quota de stockage (en Mo)
define('MAX_STORAGE_MB', 500);

// Durée de vie des cours uploadés (en heures)
define('COURSE_LIFETIME_HOURS', 24);
```

### 3. Permissions

```bash
# Les dossiers courses et tmp doivent être accessibles en écriture
chmod 755 courses tmp
chown -R www-data:www-data courses tmp
```

### 4. Cron (nettoyage automatique)

Ajoutez cette ligne à votre crontab :

```bash
# Nettoyage toutes les heures
0 * * * * /usr/bin/php /chemin/vers/moodle-secours/cron.php
```

Ou via l'URL (avec clé secrète) :
```
https://votre-site.com/moodle-secours/cron.php?key=VOTRE_CLE_SECRETE
```

## ☁️ Configuration Google Drive

L'app peut lister automatiquement les cours .mbz de tes dossiers Google Drive.

### Étape 1 : Créer une clé API Google (5 minutes, gratuit)

1. Va sur **https://console.cloud.google.com/**
2. Connecte-toi avec ton compte Google
3. Clique **"Sélectionner un projet"** → **"Nouveau projet"**
4. Donne un nom (ex: "MoodleSecours") → **Créer**
5. Dans le menu ☰, va dans **"APIs et services"** → **"Bibliothèque"**
6. Cherche **"Google Drive API"** → Clique dessus → **"Activer"**
7. Va dans **"APIs et services"** → **"Identifiants"**
8. Clique **"Créer des identifiants"** → **"Clé API"**
9. **Copie la clé** qui s'affiche

### Étape 2 : Partager tes dossiers Drive

1. Dans Google Drive, crée des dossiers pour tes cours (ex: "6ème", "5ème"...)
2. Mets tes fichiers .mbz dedans
3. Pour chaque dossier : **clic droit → Partager → "Tout le monde avec le lien"**
4. Note l'ID de chaque dossier (dans l'URL : `drive.google.com/drive/folders/XXXXX`)

### Étape 3 : Configurer l'app

Édite `config.php` :

```php
// Colle ta clé API
define('GDRIVE_API_KEY', 'VOTRE_CLE_API_GOOGLE...');

// Liste tes dossiers
$GDRIVE_FOLDERS = [
    '6ème' => '1ABC123...',  // ID du dossier 6ème
    '5ème' => '1DEF456...',  // ID du dossier 5ème
    '4ème' => '1GHI789...',  // etc.
    '3ème' => '1JKL012...',
];
```

**C'est tout !** L'app listera automatiquement tous les .mbz de ces dossiers.

## 📖 Utilisation

### Pour les professeurs

1. Accédez à la page d'accueil
2. Glissez votre fichier .mbz dans la zone de dépôt
3. Entrez le mot de passe professeur
4. Choisissez un identifiant (ex: `dupont`, `mmartin`)
5. Partagez le lien généré avec vos élèves

### Pour les élèves

1. Ouvrez le lien fourni par le professeur
2. Naviguez dans les sections du cours
3. Complétez les quiz et activités H5P

## 🧩 Types de contenus supportés

### Activités Moodle
- ✅ Page
- ✅ Ressource (fichier)
- ✅ URL
- ✅ Étiquette (label)
- ✅ Livre (book)
- ✅ Dossier (folder)
- ✅ Leçon (lesson)
- ✅ Quiz (avec correction automatique)
- ⚠️ Forum, Wiki, Devoir (affichage limité)

### Types de questions Quiz
- ✅ Choix multiple (multichoice)
- ✅ Vrai/Faux (truefalse)
- ✅ Réponse courte (shortanswer)
- ✅ Numérique (numerical)
- ✅ Appariement (match)
- ✅ Texte à trous - sélection (gapselect)
- ✅ Glisser-déposer sur texte (ddwtos)
- ✅ Ordonnancement (ordering)
- ✅ Cloze (multianswer)
- ⚠️ Rédaction (essay) - affichage seul

### Contenus H5P
- ✅ Course Presentation
- ✅ Multiple Choice
- ✅ Drag and Drop
- ✅ Fill in the Blanks
- ✅ Interactive Video
- ✅ True/False
- ✅ Et bien d'autres...

## 🔧 Dépannage

### Le fichier est trop gros

Augmentez les limites PHP dans `.htaccess` :
```apache
php_value upload_max_filesize 500M
php_value post_max_size 510M
```

### Erreur lors de l'extraction

Vérifiez que l'extension `phar` est activée :
```php
<?php phpinfo(); // Cherchez "phar"
```

### Les contenus H5P ne s'affichent pas

Les bibliothèques H5P doivent être présentes dans `/h5p-libraries/`. Contactez l'administrateur si elles manquent.

### Google Drive : quota dépassé

Les fichiers Google Drive publics ont un quota de téléchargement. Attendez quelques heures ou utilisez un autre compte.

## 📁 Structure des fichiers

```
moodle-secours/
├── index.php           # Page d'accueil
├── view.php            # Affichage des cours
├── upload.php          # API d'upload
├── file.php            # Serveur de fichiers
├── cron.php            # Nettoyage automatique
├── config.php          # ⚙️ CONFIGURATION (à éditer)
├── .htaccess           # Config Apache
│
├── includes/
│   ├── MbzParser.php       # Extraction des .mbz
│   ├── GoogleDriveLoader.php # Chargement depuis GDrive
│   └── CourseRenderer.php  # Génération HTML
│
├── assets/
│   ├── css/style.css   # Styles
│   └── js/app.js       # JavaScript
│
├── h5p-libraries/      # Bibliothèques H5P
├── courses/            # Cours uploadés (temporaire)
└── tmp/                # Fichiers temporaires
```

## 🆕 Ajouter une bibliothèque H5P

Si un cours utilise un type H5P non supporté :

1. Envoyez-moi le fichier .mbz
2. Je téléchargerai la bibliothèque depuis h5p.org
3. Je l'ajouterai dans `/h5p-libraries/`

## 📄 Licence

Usage interne - Éducation nationale française

---

Développé avec ❤️ pour les enseignants
