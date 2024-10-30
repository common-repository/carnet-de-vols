=== Carnet de vols ULM ===
Contributors: georgesmc
Donate link: https://www.paypal.com/donate/?hosted_button_id=WR99E4X4SBKLY
Tags: ulm, avion, carnet de vols, pilote, aeroclub
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.1.3
Requires PHP: 7.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Gestion de carnets de vols, multi-pilotes et multi-ULM, avec gestion des comptes des pilotes (spécial clubs).

== Description ==

Cette extension WordPress est destinée à faciliter la gestion des carnets de vols de pilotes et d'ULMs pour un club de propriétaires : liste des pilotes, liste des ULMs, calcul automatique du coût du vol en se basant sur l'horamètre et le tarif à l'heure de chaque ULM, gestion des comptes des pilotes avec crédit / débit / solde, carnet d'entretien des ULMs.
Chaque pilote peut voir son propre carnet de vols et son compte, chaque ULM dispose de son carnet de vols, la gestion des comptes des pilotes est séparée (par exemple pour qu'un trésorier s'en charge), etc.

Chaque fonctionnalité (ajout d'un vol, carnet de vol des pilotes, carnet de vols des ULMs, comptes des pilotes, gestion des comptes, etc.) est gérée grâce à un shortcode différent, ce qui permet de gérer les autorisations avec n'importe quelle extension de gestion des droits des utilisateurs de WordPress.

Le backoffice permet de gérer les ULMs (liste, prix à l'heure, actif/inactif), et les pilotes (date du brevet, emport passager, autorisation de vols ULM par ULM, actif/inactif).

A venir :

* Le calcul automatique de la consommation à l'heure entre deux pleins complets,
* Le choix du nombre de lignes à afficher dans les différentes listes.

== Installation ==

1. Téléchargez l'extension dans le répertoire `/wp-content/plugins/carnet-de-vols`, ou installez le directement à partir de l'écran l'extension WordPress,
1. Activez l'extension sur l'écran 'Extensions' de WordPress,
1. Rendez-vous sur l'écran Tableau de bord->Carnet de vols ULM pour la configuration (voir la FAQ pour savoir comment faire).

== Frequently Asked Questions ==

= Comment débuter avec cette extension ? =

Tout d'abord l'installer et l'activer.
Ensuite, il faudra :

1. Ajouter un ou plusieurs pilote(s),
1. Un ou plusieurs ULM (voir ci-dessous),
1. Lier au moins un ULM à chaque pilote,
1. Sans oublier bien sûr de créer les pages nécessaires en utilisant les shortcodes (voir aussi ci-dessous).

**IMPORTANT : Ne pas oublier de créer au moins un ULM et de l'ajouter dans la liste des ULM(s) que chaque pilote peut utiliser.**

= Comment ajouter un nouveau pilote ? =

Un pilote est un utilisateur de WordPress. Il faut donc d'abord créer un nouvel utilisateur WordPress (Comptes -> Ajouter). Une fois ce compte créé, on peut ajuster les autorisations du pilote via l'extension carnet de vols (Carnet de vols ULM -> Gestion des pilotes). Attention, pour qu'un pilote puisse ajouter des vols, il faut qu'il soit autorisé à utiliser un ULM : il faut sélectionner un ou plusieurs ULM(s) dans la liste de la fiche d'édition du pilote.

= Comment ajouter un ULM ? =

Dans le back office de WordPress : Carnet de vols ULM -> Gestion des ULM. Ne pas oublier un prix à l'heure pour l'utilisation de l'ULM, c'est ce prix qui sera décompté à la minute près en se basant sur la saisie de l'horamètre au départ et à l'arrivée.

= Quels sont les shortcodes utilisables ? =

* `carnet-vol-enregistre-vol` : Page d'enregistrement d'un vol
* `carnet-vol-pilote` : Affichage du carnet de vol du pilote actuellement connecté (identifié par son compte WordPress)
* `carnet-vol-gestion-soldes-pilote` : Affichage des comptes et soldes des pilotes (pouvoir listAll pour voir les comptes des autres pilotes, pouvoir tresorier pour ajouter des débits / crédits)
* `carnet-vol-ulm` : Affichage du carnet de vol d'un ULM. L'id de l'ULM (affiché dans la colonne "id" de la liste des ULMs) est à passer en paramètre `ulm` (exemple : `[carnet-vol-ulm ulm=1]`)
* `carnet-vol-enregistre-entretien` : Page d'enregistrement d'un entretien d'un ULM
* `carnet-entretien-ulm` : Affichage du carnet d'entretien d'un ULM. L'id de l'ULM (affiché dans la colonne "id" de la liste des ULMs) est à passer en paramètre `ulm` (exemple : `[carnet-entretien-ulm ulm=1]`)
* `carnet-vol-enregistre-frais` : Page d'enregistrement d'une note de frais (fichier à télécharger pour justifier + montant de la note à saisir qui sera crédité au compte du pilote).
* `carnet-vol-liste-frais` : Liste des notes de frais qui ont été saisies, triées par dates (la plus récente en 1er). Permet aussi l'affichage du détail de chaque note.
* `carnet-vol-heures-pilotes` : Affichage du nombre total d'heures de vol pilote par pilote, par tranches de 6 mois

= Comment limiter les accès aux différentes pages ? =

Comme chaque pilote est avant tout un utilisateur de WordPress, et que chaque fonctionnalité est gérée par un un shortcode : n'importe quelle extension de gestion des droits d'accès fera l'affaire pour savoir qui a le droit de faire quoi (ajouter un vol, voir son compte, les carnets de vols, voir ou gérer les autres comptes, etc.).

== Screenshots ==

1. [https://carnet-de-vols.georgesdick.com/screens/GestionPilotes.png Gestion des pilotes dans le back-office]
1. [https://carnet-de-vols.georgesdick.com/screens/SaisirUnVol.png Saisie d'un vol. Les zones "horamètre au départ", "terrain de départ" et carburant au départ" sont pré-remplies avec les données du dernier vol de l'ULM choisi]
1. [https://carnet-de-vols.georgesdick.com/screens/CarnetDeVolULM.png Carnet de vol d'un ULM : liste des vols, des pilotes, des remarques, etc. Le carnet de vols d'un pilote est presque identique (il affiche les ULMs et le coût de chaque vol)]
1. [https://carnet-de-vols.georgesdick.com/screens/LivreDeComptes.png Livre de comptes d'un pilote : La liste de ses crédits et débits, plus son solde]
1. [https://carnet-de-vols.georgesdick.com/screens/PageDuTresorier.png Affichage des comptes de tous les pilotes en version avec possibilité de saisie d'écritures (par exemple pour le trésorier)]

== Changelog ==

= 1.11.3 =
* Colonne de solde indicatif ajoutée dans les livres de comptes des pilotes.

= 1.11.1 =
* Possibilité de tourner les pages dans toutes les listes.

= 1.10.6 =
* Diverses corrections de bug, frontend et backend

= 1.10.3 =
* Mensualité automatique sur les comptes des pilotes. Un administrateur peut définir un montant mensuel par défaut, et l'ajuster pilote par pilote.
* Choix de la date de dépôt automatique pilote par pilote, le 5e jour du mois par défaut, mais peut être changé pour chaque pilote.
* Envoi d'un courriel de confirmation de dépôt automatique au trésorier, avec le nom du pilote et le montant crédité.

= 1.9.7 =
* Sanizize, escape, validate
* Commentaires suplémentaires
* Factorisation et optimisation du code de construction d'URL
* Ajout de nonces à tous les formulaires POST et GET

= 1.8.4 =
* Toutes les entrées _GET, _POST, _FILES, et _COOKIES sont filtrées ou nettoyées,
* Sécurisations des SQL query (en utilisant prepare),
* Variables échappées avant de les afficher (echo),
* License clrifiée (GPL v3 ou ultérieure),
* Utilisation du file uploader de WordPress au lieu de clui standard de PHP,
* Changement de noms de quelques fonctions, options et tables.

= 1.8b =
* Ajout de la possibilité de voir les comptes des pilotes inactifs (accessible uniquement au(x) trésorier(s))

= 1.7a =
* Gestion des notes de frais v2 (choix du crédit automatique ou nécessitant une validation, possibilité d'invalider une note ou de la valider).

= 1.4a =
* Gestion des notes de frais v1 (seulement crédit automatique, la validation à priori suivra).

= 1.3a =
* Gestion des préfixes des tables
* Ajout du lien antre carnet de vols et comptes des pilotes

= 1.2d =
* Correction du bug d'arrondi des enregistrement d'heures de vol

= 1.2a =
* Fusion des pages d'affichage et de gestion des soldes des pilotes

= 1.1a =
* Ajout du carnet d'entretien des ULMs.

= 1.0 =
* Première version déposée dans le dépôt des extensions WordPress.

= 0.9rc4 =
* Première version totalement utilisable par notre club.

== Upgrade Notice ==

= 1.0 =
Version avec toutes les fonctionnalités de base utilisables.

