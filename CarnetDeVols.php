<?php
/*
 * Plugin Name:       Carnet de vols
 * Plugin URI:        https://cap83.org/
 * Description:       Ce plugin permet de gérer un carnet de vols, multi-ULM et multi-pilotes.
 * Version:           1.11.3
 * Author:            Georges <georges.dick@gmail.com>
 * Author URI:        http://georgesdick.com/
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
*/


// Vers 2160 téléchargement CSV carnet d'entretien à faire

if (!defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Le niveau admin pour voir les comptes des autres pilotes
$gdcarnet_niveauListAll = 1;
// Le niveau admin "trésorier" (peut gérer les comptes des pilotes)
$gdcarnet_niveauTresorier = 2;
// Le niveau admin "vol mécano" (peut faire un vol sans le payer (e.g. pour vérifications après intervention mécanicien))
$gdcarnet_niveauMecano = 4;
// Le flag "vol mécano" (à true par défaut)
$gdcarnet_volMecanoFlag = 1;
// Le nombre maximal de lignes à afficher
$gdcarnet_nbMaxAff = 25;
// Ajout automatique du montant de la note de frais  (à true par défaut)
$gdcarnet_autoAjoutNoteDeFrais = 1;
// Les valeurs des différentes tables pour les fichiers téléchargés
$gdcarnet_tableNotesDeFrais = 0;
$gdcarnet_tableEntretien = 1;
// Valeur par défaut pour le nombre de mois affichés dans la liste des pilotes
$gdcarnet_nombreMoisPrec = 3;
// La liste des valeurs possibles pour plein_complet
$gdcarnet_plein_complet_liste = array('non', 'avant', 'apres');
$gdcarnet_plein_complet_liste_texte = array('Non', 'Avant le vol', 'Apr&egrave;s le vol');


if (!get_option ('gdcarnet_updated')) {
	echo "<hr /><hr /><center><h1>UPDATE OPTIONS</h1></center><hr /><hr />\n";
	$getDestNotes = get_option ('dest_notes');
	add_option('gdcarnet_dest_notes', $getDestNotes);
	$getPreval = get_option ('prevalidation');
	add_option('gdcarnet_prevalidation', $getPreval);
	add_option('gdcarnet_updated', true);
	}

// Les modifications éventuelles de tables sont à la fin de la fonction !!!
// Créer les tables de base de données pour stocker les informations
function gdcarnet_create_table() {
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

	// La mise en route du cron des cotisations automatiques
	if (! wp_next_scheduled ( 'gdcarnet_cotisations_planifiees' )) {
		$timestamp_suivant_premier_jour = strtotime( 'Tomorrow 01:00:00' );	// Tous les jours à partir de demain
		wp_schedule_event( $timestamp_suivant_premier_jour, 'daily', 'gdcarnet_cotisations_planifiees' );
		add_action( 'gdcarnet_cotisations_planifiees', 'gdcarnet_ajoute_cotisations_mensuelles' );
		}

	// Table de pré-remplissage des motifs de gestion des comptes des pilotes
	$table_name = $wpdb->prefix . 'gdcarnet_table_motifs_comptes';
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,	# id unique
		motif varchar(255) NOT NULL,				# Texte du motif
		actif BOOLEAN NOT NULL DEFAULT TRUE,		# Actif O?N
		PRIMARY KEY (id)
    ) $charset_collate;";
    dbDelta($sql);

	// Table des carnets d'entretien des ULMs
	$table_name = $wpdb->prefix . 'gdcarnet_table_entretien';
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,	# id unique
		ulm mediumint(9) NOT NULL,					# id de l'ULM concerné
        date_reparation date NOT NULL,				# Date de l'intervention
		horametre_debut int(11) NOT NULL,			# Horamètre avant le début de l'intervention
		horametre_fin int(11) NOT NULL,				# Horamètre à la fin de l'intervention
		mecano text,								# Texte libre pour nom du mécanicien
		objet text,									# Objet de la réparation
		nature text,								# Nature de l'intervention
		reste text,									# Eventuel reste à faire
		resultat text,								# Résultat de l'intervention
		saisi_par mediumint(9) NOT NULL,			# id du pilote qui a saisi l'intervention
		facture VARCHAR(200) NULL,					# Nom du fichier de la facture (qui doit être tléchargée)
		date_creation timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,	# Timestamp de l'enregistrement
        PRIMARY KEY (id)
    ) $charset_collate;";
    dbDelta($sql);
	
	// Table des vols
	$table_name = $wpdb->prefix . 'gdcarnet_table_vols';
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,	# id unique
		pilote mediumint(9) NOT NULL,				# id du pilote concerné
		flags mediumint(9),							# Flags divers (e.g. est-ce un vol mécano)
		ulm mediumint(9) NOT NULL,					# id de l'ULM de ce vol
        date_vol date NOT NULL,						# Date du vol
		heure_depart time NOT NULL,					# Heure de départ
		heure_arrivee time NOT NULL,				# Heure d'arrivée
		horametre_depart int(11) NOT NULL,			# Horamètre au départ
		horametre_arrivee int(11) NOT NULL,			# Horamètre à l'arrivée
		minutes_de_vol mediumint(9) NOT NULL,		# Minutes de vol (calculé via les horamètres départ et arrivée)
		cout_du_vol FLOAT NOT NULL DEFAULT '0.0',	# Coût du vol (calculé à la minute via le coût à l'heure de l'ULM et de la durée de vol)
		terrain_depart_oaci char(9) NOT NULL,		# Terrain de départ
		terrain_arrivee_oaci char(9) NOT NULL,		# Terrain d'arrivée
		carburant_depart mediumint(9) NOT NULL,		# Nombre de litres de carburant au départ (avant le vol)
		carburant_arrivee mediumint(9) NOT NULL,	# Nombre de litres de carburant à l'arrivée (après le vol)
		carburant_ajoute mediumint(9) NOT NULL DEFAULT 0,	# Nombre de litres de carburant ajouté
		plein_complet ENUM('non','avant','apres') NOT NULL DEFAULT 'non',	# Est-ce un plein complet ?
		remarques text,								# Remarques sur l'ULM (texte libre)
		notes_pilote text,							# Notes du pilote (texte libre)
		derniere_modification timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,	# Timestamp de la dernière modification
		date_creation timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,	# Timestamp de la création
        PRIMARY KEY (id)
    ) $charset_collate;";
    dbDelta($sql);
	
	// Table des ULMs
	$table_name = $wpdb->prefix . 'gdcarnet_table_ulm';
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,	# id unique
		immatriculation varchar(200) NOT NULL,		# Immatriculation de cet ULM
		modele varchar(200) NOT NULL,				# Modèle de cet ULM
		actif boolean NOT NULL DEFAULT TRUE,		# Est-il actif O?N
		tarif_heure smallint(3) NOT NULL DEFAULT 0,	# Tarif à l'heure (en Euros)
		remarques text,								# Texte libre
        PRIMARY KEY (id)
    ) $charset_collate;";
    dbDelta($sql);
	
	// Table des pilotes
	$table_name = $wpdb->prefix . 'gdcarnet_table_pilote';
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,	# id unique
		actif BOOLEAN NOT NULL DEFAULT TRUE,		# Le pilote est-il actif O?N
		user_login varchar(50),						# Login de ce pilote (login WordPress)
		nom_pilote varchar(200) NOT NULL,			# Nom de ce pilote
		brevet varchar(50) NOT NULL,				# Numéro de son brevet
		lache boolean NOT NULL,						# A-t-il été lâché O?N (non lâché => ne peut pas enregistrer de vol)
		date_lache date NOT NULL,					# Date du lâcher
		emport boolean NOT NULL,					# A-t-il son emport passager O?N
		date_emport date NOT NULL,					# Date de l'emport
		niveau_admin mediumint(9),					# Flags de niveau (vols mécano, voir liste des comptes, trésorier)
		mensualite mediumint(9),					# Montant éventuel de mensualité automatique
		jour_mensualite ENUM('1','5','10','15','20','25') NOT NULL DEFAULT '5',	# Le jour de la mensualité automatique
		remarques text,								# Texte libre
        PRIMARY KEY (id)
    ) $charset_collate;";
    dbDelta($sql);
	
	// Liste des aérodromes avec code OACI
	$table_name = $wpdb->prefix . 'gdcarnet_table_oaci';
	// Cette table doit toujours être vide et re-remplie lors d'une activation de cette extension
	$sql = "DROP TABLE IF EXISTS $table_name";
	$wpdb->query($sql);
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,	# id unique
		departement char(3) NOT NULL,				# Département du terrain
		nom text NOT NULL,							# Nom du terrain
		commune text NOT NULL,						# Commune du terrain
		iata char(5),								# Identification IATA du terrain
		oaci char(6) NOT NULL,						# Identification OACI du terrain
		altitude smallint(3),						# Altitude du terrain
		nb_pistes tinyint(3),						# Nombre de pistes
		remarques text,								# Texte libre
        PRIMARY KEY (id),
		INDEX(oaci)
    ) $charset_collate;";
    dbDelta($sql);
	
	// Comptes des pilotes (finances)
	$table_name = $wpdb->prefix . 'gdcarnet_table_pilote_comptes';
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,	# id unique
		pilote mediumint(9) NOT NULL,				# id du pilote concerné
		auteur mediumint(9) NOT NULL,				# id de l'auteur de cette écriture (pilote ou trésorier)
		motif varchar(255) NOT NULL,				# Motif de l'écriture
        credit decimal(12,2) NOT NULL,				# Montant du crédit
        debit decimal(12,2) NOT NULL,				# Montant du débits
		flag_type_ecriture mediumint(9),			# Flag du type d'écriture (bits de signification)
		id_vol mediumint(9),						# id du vol (si débit lié à un vol)
		id_frais mediumint(9),						# id de frais (si crédit lié à une note de frais)
        date date NOT NULL,							# Date de l'écriture
		PRIMARY KEY (id)
	) $charset_collate;";
	dbDelta($sql);
	
	// Liens pilotes -> ULM (qui est lâché sur quel appareil ?)
	$table_name = $wpdb->prefix . 'gdcarnet_table_pilote_ulm_lache';
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,	# id unique
		pilote mediumint(9) NOT NULL,				# id du pilote concerné
		ulm mediumint(9) NOT NULL,					# id de l'ULM concerné
		date_lache DATE NOT NULL,					# Date de l'autorisation de vole sur cet appareil
		PRIMARY KEY (id)
	) $charset_collate;";
	dbDelta($sql);
	
	// Table des fichiers téléchargés (notes de frais, entretiens, etc.)
	$table_name = $wpdb->prefix . 'gdcarnet_table_fichiers';
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,	# id unique
		clef_autre mediumint(9) NOT NULL,			# id dans la table correspondante au téléchargement (note de frais ou intervention sur un ULM)
		table_autre mediumint(9) NOT NULL DEFAULT 0,# id de la table correspondante au téléchargement (note de frais ou intervention sur un ULM)
		fichier VARCHAR(200) NULL,					# Nom du fichier téléchargé
		description varchar(255) NOT NULL,			# Description (texte libre)
		PRIMARY KEY (id)
	) $charset_collate;";
	dbDelta($sql);
	
	// Table des notes de frais
	$table_name = $wpdb->prefix . 'gdcarnet_table_frais';
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,	# id unique
		valide BOOLEAN NOT NULL DEFAULT TRUE,		# La note est-elle validée (par le trésorier) ?
		pilote mediumint(9) NOT NULL,				# Pilote concerné
		description text NOT NULL,					# Description de la note (texte libre)
		valeur decimal(12,2) NOT NULL,				# Valeur de la note (reportée comme écriture si la note est validé)
		date_note timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP, # Date d'enregistrement de cette note
		PRIMARY KEY (id)
	) $charset_collate;";
	dbDelta($sql);
	
	// Ajout éventuel de la colonne flag_type_ecriture dans la table gdcarnet_table_pilote_comptes
	$query_verif_flag = 'SELECT IF (EXISTS (SELECT * FROM information_schema.COLUMNS WHERE column_name="flag_type_ecriture" AND table_name="' . $wpdb->prefix . 'gdcarnet_table_pilote_comptes" AND table_schema="cap83"), 1, 0) AS resu';
	$select_verif_flag = $wpdb->get_results($query_verif_flag);
	if ($select_verif_flag[0]->resu == 0) {
		$query_alter_flag = 'ALTER TABLE ' . $wpdb->prefix . 'gdcarnet_table_pilote_comptes ADD flag_type_ecriture mediumint(9) UNSIGNED NOT NULL DEFAULT 0 AFTER debit';
		$wpdb->get_results($query_alter_flag);
		}

	// Ajout éventuel de la colonne mensualite dans la table gdcarnet_table_pilote
	$query_verif_mensualite = 'SELECT IF (EXISTS (SELECT * FROM information_schema.COLUMNS WHERE column_name="mensualite" AND table_name="' . $wpdb->prefix . 'gdcarnet_table_pilote" AND table_schema="cap83"), 1, 0) AS resu';
	$select_verif_mensualite = $wpdb->get_results($query_verif_mensualite);
	if ($select_verif_mensualite[0]->resu == 0) {
		$query_alter_mensualite = 'ALTER TABLE ' . $wpdb->prefix . 'gdcarnet_table_pilote ADD mensualite mediumint(9) UNSIGNED NOT NULL DEFAULT 0 AFTER niveau_admin';
		$wpdb->get_results($query_alter_mensualite);
		}
	
	// Ajout éventuel de la colonne jour_mensualite dans la table gdcarnet_table_pilote
	$query_verif_jour_mensualite = 'SELECT IF (EXISTS (SELECT * FROM information_schema.COLUMNS WHERE column_name="jour_mensualite" AND table_name="' . $wpdb->prefix . 'gdcarnet_table_pilote" AND table_schema="cap83"), 1, 0) AS resu';
	$select_verif_jour_mensualite = $wpdb->get_results($query_verif_jour_mensualite);
	if ($select_verif_jour_mensualite[0]->resu == 0) {
		$query_alter_jour_mensualite = 'ALTER TABLE ' . $wpdb->prefix . "gdcarnet_table_pilote ADD jour_mensualite ENUM('1','5','10','15','20','25') NOT NULL DEFAULT '5' AFTER mensualite";
		$wpdb->get_results($query_alter_jour_mensualite);
		}
	
//	gdcarnet_fill_oaci_airports();			// Remplissage de la liste des aérodromes OACI
	gdcarnet_pre_fill_motifs_comptes();	// Pré-remplissage des motifs de la table des comptes
}

// Lecture de la table des aérodromes pour remplir la base
function gdcarnet_fill_oaci_airports() {
	global $wpdb;
	$table_name = $wpdb->prefix . 'gdcarnet_table_oaci';
	
	$csvFile = __DIR__ . '\oaci_tab_ok.csv';
	
	if (($handle = fopen($csvFile, 'r')) !== false) {
		// Ignorer la première ligne (en-têtes de colonne)
		fgetcsv($handle);
		// Parcourir les lignes du fichier CSV
		while (($data = fgetcsv($handle,500,";","\"","\\")) !== false) {
			$uneLigneSQL= 'INSERT INTO ' . $table_name . ' (departement, nom, commune, iata, oaci, altitude, nb_pistes, remarques) VALUES ("' .  $data[0] . '", "' .  $data[1] . '","' .  $data[2] . '","' .  $data[3] . '","' .  $data[4] . '",' .  $data[5] . ',' .  $data[6] . ',"' .  $data[7] . '")';
			dbDelta($uneLigneSQL);
			}
		fclose($handle);
		}
}

// Pré-remplissage des motifs de la table des comptes
function gdcarnet_pre_fill_motifs_comptes() {
	global $wpdb;

	// Les motifs possibles en pré-remplissage dans la gestion des comptes des pilotes
	$listeMotifs = [ 'Mensualité', 'Crédit d\'heures', 'Remboursement de frais' ];

	foreach ($listeMotifs as $un_motif) {
		$query_select_motif = $wpdb->prepare('SELECT id, motif FROM ' . $wpdb->prefix .'gdcarnet_table_motifs_comptes WHERE motif="%s"', $un_motif);
		$select_motif = $wpdb->get_results($query_select_motif);
		if ($select_motif == null) {
			$insert_ligne = 'INSERT INTO ' . $wpdb->prefix .'gdcarnet_table_motifs_comptes SET motif="' . $un_motif . '"';
			dbDelta($insert_ligne);
			}
		}
}

// Le hook de désinstalation du plugin
register_uninstall_hook(__FILE__, 'gdcarnet_uninstall');

// Fonction de désinstalation (suppression des tables)
function gdcarnet_uninstall () {
// drop des tables
// drop des tables
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gdcarnet_table_vols" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gdcarnet_table_ulm" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gdcarnet_table_pilote" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gdcarnet_table_oaci" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gdcarnet_table_pilote_comptes" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gdcarnet_table_pilote_ulm_lache" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gdcarnet_table_motifs_comptes" );
}

// appelle la fonction gdcarnet_create_table() lors de l'activation du plugin
register_activation_hook(__FILE__, 'gdcarnet_create_table');


// Affichage des détails d'un vol
function gdcarnet_display_un_vol ($numvol, $type_carnet, $offset) {	// type_carnet : 0 => pilote, 1 => ulm
	global $wpdb;
	global $gdcarnet_volMecanoFlag;
	$current_user = wp_get_current_user();
	
	if (!is_numeric($numvol))	// numvol doit être numérique !
		return;
	if (($type_carnet != 0) && ($type_carnet != 1))	// Forcément 0 ou 1 !
		$type_carnet = 1;
	
	$select_num_pilote_query = $wpdb->prepare('SELECT id, niveau_admin FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE user_login=%s', $current_user->user_login);
	$select_num_pilote = $wpdb->get_results($select_num_pilote_query);
	
	$le_vol_query = $wpdb->prepare('SELECT ' . $wpdb->prefix . 'gdcarnet_table_vols.id, ' . $wpdb->prefix . 'gdcarnet_table_vols.flags, ' . $wpdb->prefix . 'gdcarnet_table_vols.date_vol, ' . $wpdb->prefix . 'gdcarnet_table_vols.heure_depart, ' . $wpdb->prefix . 'gdcarnet_table_vols.heure_arrivee, TIME_FORMAT(SEC_TO_TIME(' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_arrivee-' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_depart), "ForMatHour1") AS duree, TIME_FORMAT(SEC_TO_TIME(' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_depart), "ForMatHour2") AS hora_start, TIME_FORMAT(SEC_TO_TIME(' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_arrivee), "ForMatHour2") AS hora_end, ' . $wpdb->prefix . 'gdcarnet_table_vols.terrain_depart_oaci, ' . $wpdb->prefix . 'gdcarnet_table_vols.terrain_arrivee_oaci, ' . $wpdb->prefix . 'gdcarnet_table_vols.carburant_depart, ' . $wpdb->prefix . 'gdcarnet_table_vols.carburant_arrivee, ' . $wpdb->prefix . 'gdcarnet_table_vols.carburant_ajoute, ' . $wpdb->prefix . 'gdcarnet_table_vols.plein_complet, ' . $wpdb->prefix . 'gdcarnet_table_vols.remarques, ' . $wpdb->prefix . 'gdcarnet_table_vols.notes_pilote, ' . $wpdb->prefix . 'gdcarnet_table_pilote.nom_pilote, ' . $wpdb->prefix . 'gdcarnet_table_ulm.modele, ' . $wpdb->prefix . 'gdcarnet_table_ulm.immatriculation FROM ' . $wpdb->prefix . 'gdcarnet_table_vols, ' . $wpdb->prefix . 'gdcarnet_table_pilote, ' . $wpdb->prefix . 'gdcarnet_table_ulm WHERE ' . $wpdb->prefix . 'gdcarnet_table_pilote.id=' . $wpdb->prefix . 'gdcarnet_table_vols.pilote AND ' . $wpdb->prefix . 'gdcarnet_table_ulm.id=' . $wpdb->prefix . 'gdcarnet_table_vols.ulm AND ' . $wpdb->prefix . 'gdcarnet_table_vols.id=%d', $numvol);
	$le_vol_query = str_replace('ForMatHour1', '%Hh%imn', $le_vol_query);
	$le_vol_query = str_replace('ForMatHour2', '%H,%i', $le_vol_query);
	$le_vol = $wpdb->get_results($le_vol_query);
	?>
	<h1>D&eacute;tails d'un vol</h1>
	<table border="1">
		<tbody>
			<tr><td>Date du vol&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->date_vol); ?></td></tr>
			<tr><td>Heure de d&eacute;part&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->heure_depart); ?></td></tr>
			<tr><td>Heure d'arriv&eacute;e&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->heure_arrivee); ?></td></tr>
			<tr><td>Pilote&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->nom_pilote); ?></td></tr>
<?php
	if (($le_vol[0]->flags & $gdcarnet_volMecanoFlag) != 0)
		echo '<tr><td bgcolor="yellow">Vol m&eacute;cano&nbsp;:</td><td align="center" bgcolor="yellow">oui</td></tr>';
?>
			<tr><td>ULM&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->modele . ' ' . $le_vol[0]->immatriculation); ?></td></tr>
			<tr><td>Horam&egrave;tre au d&eacute;part&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->hora_start); ?></td></tr>
			<tr><td>Horam&egrave;tre &agrave; la fin&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->hora_end); ?></td></tr>
			<tr><td>Dur&eacute;e du vol&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->duree); ?></td></tr>
			<tr><td>Terrain de d&eacute;part&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->terrain_depart_oaci); ?></td></tr>
			<tr><td>Terrain d'arriv&eacute;e&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->terrain_arrivee_oaci); ?></td></tr>
			<tr><td>Carburant au d&eacute;part&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->carburant_depart); ?> litres</td></tr>
			<tr><td>Carburant &agrave; l'arriv&eacute;e&nbsp;: </td><td align="center"><?php echo esc_html($le_vol[0]->carburant_arrivee); ?> litres</td></tr>
<?php
			if ($le_vol[0]->carburant_ajoute != 0) {
				if (!strcmp ($le_vol[0]->plein_complet, 'non'))
					echo '<tr><td>Carburant ajout&eacute;&nbsp;: </td><td align="center">' . esc_html($le_vol[0]->carburant_ajoute) . ' litres</td></tr>';
				else if (!strcmp ($le_vol[0]->plein_complet, 'avant'))
					echo '<tr><td>Carburant ajout&eacute;&nbsp;: </td><td align="center">' . esc_html($le_vol[0]->carburant_ajoute) . ' litres (plein avant)</td></tr>';
				else
					echo '<tr><td>Carburant ajout&eacute;&nbsp;: </td><td align="center">' . esc_html($le_vol[0]->carburant_ajoute) . ' litres (plein apr&egrave;s)</td></tr>';
					
			}
?>
			<tr><td>Remarques&nbsp;: </td><td align="left"><?php echo wp_kses_post(nl2br($le_vol[0]->remarques)); ?></td></tr>
			<tr><td>Notes pilote&nbsp;: </td><td align="left"><?php echo wp_kses_post(nl2br($le_vol[0]->notes_pilote)); ?></td></tr>
			</tr>
		</tbody>
    </table>
	<?php
	$actual_link = gdcarnet_get_debut_link();
	$resu = gdcarnet_get_start_uri();
	echo '<a href="' . esc_url($actual_link . $resu . '?offset=' . $offset) . '">Retour &agrave; la liste</a>';
}

// Appel de l'affichage du carnet de vols en mode carnet d'un ULM
function gdcarnet_display_carnet_ulm($atts = array(), $content = null, $tag = '') {
	$atts = array_change_key_case((array) $atts, CASE_LOWER );
	$numUlm = esc_html($atts['ulm']);
	gdcarnet_display_carnet(1, $numUlm);
}

// Appel de l'affichage du carnet de vols en mode carnet du pilote
function gdcarnet_display_carnet_pilote() {
	gdcarnet_display_carnet(0, 0);
}

// Avant le template (spécial pour quand il faut envoyer des headers (téléchargements))
function gdcarnet_before_template () {
	global $wpdb;
	
	if (isset ($_GET['csv'])) {
		if (isset($_GET['type_aff']))
			$myTypeAff = sanitize_text_field($_GET['type_aff']);
		else
			$myTypeAff = 1;
		gdcarnet_generer_CSV ($myTypeAff, (isset($_GET['ulm'])) ? sanitize_text_field($_GET['ulm']) : 0);	// Cette fonction nettoie elle-même les entrées
		exit;
		}
	if (isset($_GET['fichier_note'])) {
		$numFichier = sanitize_text_field($_GET['fichier_note']);
		$le_fichier_query = $wpdb->prepare('SELECT id, fichier FROM ' . $wpdb->prefix . 'gdcarnet_table_fichiers WHERE id=%d', $numFichier);
		$le_fichier  = $wpdb->get_results($le_fichier_query);
		$fileNewName = $le_fichier[0]->id;
		$upload_dir   = wp_upload_dir(null, false);
		$monRepertoire = $upload_dir['basedir'] . '/carnetdevols/fichiers';
		$monFichier = $monRepertoire . '/' . $fileNewName;
		
		$fileContent = file_get_contents($monFichier);
		
		// Mettre en mémoire tampon la sortie
		ob_start();

		// Générer le contenu du fichier
		echo $fileContent;	// Can't be escaped (it's a downloaded file)

		// Récupérer le contenu de la mémoire tampon et vider la mémoire tampon
		$fileContentBuffer = ob_get_clean();

		// Envoyer le fichier à télécharger
		header('Content-Type: application/octet-stream; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $le_fichier[0]->fichier . '"');
		echo $fileContentBuffer;	// Can't be escaped (it's a buffered output, before sending a file)
		exit;
		}
	if (isset ($_GET['facture'])) {
		$numEntretien = sanitize_text_field($_GET['facture']);
		$la_facture_query = $wpdb->prepare('SELECT id, facture FROM ' . $wpdb->prefix . 'gdcarnet_table_entretien WHERE id=%d', $numEntretien);
		$la_facture  = $wpdb->get_results($la_facture_query);
		$fileNewName = $la_facture[0]->id;
		$upload_dir   = wp_upload_dir(null, false);
		$monRepertoire = $upload_dir['basedir'] . '/carnetdevols';
		$monFichier = $monRepertoire . '/' . $fileNewName;
		
		$fileContent = file_get_contents($monFichier);
		
		// Mettre en mémoire tampon la sortie
		ob_start();

		// Générer le contenu du fichier
		echo $fileContent;	// Can't be escaped (it's a downloaded file)

		// Récupérer le contenu de la mémoire tampon et vider la mémoire tampon
		$fileContentBuffer = ob_get_clean();

		// Envoyer le fichier à télécharger
		header('Content-Type: application/octet-stream; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $la_facture[0]->facture . '"');
		echo $fileContentBuffer;	// Can't be escaped (it's a buffered output, before sending a file)
		exit;
		}
	if (isset ($_GET['flipflopActifs'])) {
		if (!strcmp ($_GET['flipflopActifs'], 'afficheInactifs')) {
			setcookie('flipflopActifs', 'oui', time() + 600);
			}
		else {
			setcookie('flipflopActifs', 'non', time() + 600);
			}
		}
	else {
		if (isset ($_COOKIE['flipflopActifs'])) {
			if (!strcmp ($_COOKIE['flipflopActifs'], 'oui')) {
				setcookie('flipflopActifs', 'oui', time() + 600);
				}
			}
		}
}

// Gestion des soldes des pilotes
function gdcarnet_gestion_liste_soldes_pilote () {
	$current_url = sanitize_text_field($_SERVER['REQUEST_URI']);
	if (strpos($current_url, '/wp-json/') !== false ) return;	// Si dans l'interface d'admin en cours de JSON => on retourne tout de suite pour ne pas provoquer d'erreur JSON
	gdcarnet_display_gestion_liste_soldes_pilote (1);
}

// Liste des pilotes et de leurs soldes
function gdcarnet_display_liste_soldes_pilote () {
	gdcarnet_display_gestion_liste_soldes_pilote (0);
}
	
// Gestion ou liste des pilotes et de leurs soldes
function gdcarnet_display_gestion_liste_soldes_pilote () {
	global $wpdb;
	global $gdcarnet_niveauTresorier;
	global $gdcarnet_niveauListAll;
	
	$current_user = wp_get_current_user();
	$select_num_pilote_query = $wpdb->prepare('SELECT id, niveau_admin FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE user_login=%s', $current_user->user_login);
	$select_num_pilote = $wpdb->get_results($select_num_pilote_query);
	if ($select_num_pilote == null) {
		echo '<center><h2><font color="red">Veuillez vous identifier</font></h2></center>';
		return;
		}

	if (($select_num_pilote[0]->niveau_admin & ($gdcarnet_niveauListAll + $gdcarnet_niveauTresorier)) == 0) {
		gdcarnet_display_compte_pilote(null);
		return;
		}
	$afficheInactifs = false;
	if (isset ($_GET['flipflopActifs'])) {
		check_admin_referer('afficheInactifs' . $select_num_pilote[0]->id);	// Vérification du nonce
		if (!strcmp ($_GET['flipflopActifs'], 'afficheInactifs')) {
			$afficheInactifs = true;
			}
		}
	else {
		if (isset ($_COOKIE['flipflopActifs'])) {
			if (!strcmp ($_COOKIE['flipflopActifs'], 'oui')) {
				$afficheInactifs = true;
				}
			}
		}
	// Niveau trésorier pour saisir une écriture
	if (($select_num_pilote[0]->niveau_admin & $gdcarnet_niveauTresorier) != 0) {
		echo "<H1>Acc&egrave;s Tr&eacute;sorier</H1>\n";
		echo '<form method="get" action="" id="afficheInactifs">';
		wp_nonce_field('afficheInactifs' . $select_num_pilote[0]->id);
		if ($afficheInactifs) {
			echo '<input type="hidden" name="flipflopActifs" value="masqueInactifs" id="masqueInactifs" />';
			echo '<input type="submit" value="Masquer les comptes inactifs" style="background:green"/>';
			}
		else {
			echo '<input type="hidden" name="flipflopActifs" value="afficheInactifs" id="afficheInactifs" />';
			echo '<input type="submit" value="Afficher les comptes inactifs" style="background:orange"/>';
			}
		echo '</form>';
		if (isset ($_POST['motif_operation'])) {
			$piloteOp = sanitize_text_field($_POST['pilote_operation']);
			$dateOp = sanitize_text_field($_POST['date_operation']);
			$motifOp = sanitize_textarea_field($_POST['motif_operation']);
			$creditOp = sanitize_text_field($_POST['credit_operation']);
			$debitOp = sanitize_text_field($_POST['debit_operation']);
			check_admin_referer('addFrais' . $piloteOp);	// Vérification du nonce
			if ($creditOp == '')
				$creditOp = 0;
			if ($debitOp == '')
				$debitOp = 0;
			$wpdb->insert(
				$wpdb->prefix . 'gdcarnet_table_pilote_comptes',
				array(
					'id' => NULL,
					'pilote' => $piloteOp,
					'motif' => $motifOp,
					'auteur' => $select_num_pilote[0]->id,
					'credit' => $creditOp,
					'debit' => $debitOp,
					'date' => $dateOp
					)
				);
			}
		if (isset ($_GET['pilote'])) {
			$nomPilote = gdcarnet_display_compte_pilote(sanitize_text_field($_GET['pilote']));
			$numPilote = sanitize_text_field($_GET['pilote']);
			if (!is_numeric($numPilote)) {
				echo '<hr /><h1>ERREUR DE SAISIE</h1></hr>';
				return;
				}
			if (($select_num_pilote[0]->niveau_admin & $gdcarnet_niveauTresorier) != 0) {
				echo '<h2>Ajouter une op&eacute;ration &agrave; ' . esc_html($nomPilote) . '</h2>';
				
				echo "\n<script type='text/javascript'>\n";
				echo "function onMotifChange (motifSelect) {\n";
				echo 'document.getElementById("motif_operation").value=motifSelect.value' . "\n";
				echo "}\n";
				echo "</script>\n";
				
				echo '<form method="post" action="" id="formFrais">';
				echo '<input type="hidden" id="pilote_operation" name="pilote_operation" value="' . esc_html($numPilote) . '" /></td>';
				wp_nonce_field('addFrais' . $numPilote);
				echo '<table border="1"><thead><tr><th>Date (*)</th><th>Motif (*)</th><th>Cr&eacute;dit</th><th>D&eacute;bit</th><th></th></thead><tbody>';
				echo '<td><input type="date" id="date_operation" name="date_operation" required /></td>';
				echo '<td align="center"><select id="motif" name="motif" onchange="onMotifChange(this)">';
				echo '<option value="">-- Choisissez un motif --</option>';
				$liste_motifs = $wpdb->get_results('SELECT motif FROM ' . $wpdb->prefix . 'gdcarnet_table_motifs_comptes WHERE actif=true ORDER BY motif ASC');	// Pas de paramètre, pas besoin de prepare
				foreach ($liste_motifs as $un_motif)
					echo '<option value="' . esc_html($un_motif->motif) . '">' . esc_html($un_motif->motif) . '</option>';
				echo '</select>';
				echo '<br />Ou saisissez un motif<br />';
				echo '<textarea id="motif_operation" name="motif_operation" rows=5 cols=30 maxlength="200" required></textarea></td>';
				echo '<td><input type="number" step="0.01" id="credit_operation" name="credit_operation" /></td>';
				echo '<td><input type="number" step="0.01" id="debit_operation" name="debit_operation" /></td>';
				echo '<td><input type="submit" id="submitFrais"/><input type="button" id="attenteFrais" value="Envoi en cours..." style="display:none;background:orange"/></td>';
				echo '</tbody></table>';
				echo '</form>';
				echo "\n<script>\n";
				echo "document.getElementById('formFrais').addEventListener('submit', function() {\n";
				echo "document.getElementById('submitFrais').disabled = true;\n";
				echo "document.getElementById('submitFrais').style.display = 'none';\n";
				echo "document.getElementById('attenteFrais').style.display = 'block';\n";
				echo "});\n";
				echo "</script>\n";
				}
			}
			echo '<h1>Les soldes des pilotes</h1>';
		}
	else
		echo '<h1>Soldes des pilotes</h1>';
	$listePilotes = get_users();
	
	foreach ($listePilotes as $unPilote) {
		$select_un_pilote_query = $wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE user_login=%s', $unPilote->user_login);
		$select_un_pilote = $wpdb->get_results($select_un_pilote_query);
		if ($select_un_pilote == null) { // Si on n'a pas encore un carnet de vols pour cet utilisateur
				$table_name = $wpdb->prefix . 'gdcarnet_table_pilote';
				$wpdb->insert(
					$table_name,
					array(
						'id' => NULL,
						'user_login' => $unPilote->user_login,
						'nom_pilote' => $unPilote->display_name,
						'mensualite' => get_option('mensualite_std', 0),
						'lache' => 0,
						'remarques' => "Creation automatique"
						)
					);
				$pilote_id = $wpdb->insert_id;
			}
		}
	// Pas de paramètre, pas besoin de prepare
	$liste_pilotes = $wpdb->get_results( 'SELECT id, user_login, nom_pilote, brevet, lache, date_lache, emport, date_emport, remarques, actif FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote ORDER BY nom_pilote ASC');
	echo '<table border="1"><tbody><tr><td>Compte</td><td>Nom pilote</td><td>Remarques</td><td>Solde</td></tr>';

	foreach ($liste_pilotes as $un_pilote) {
		if (!$afficheInactifs || ($select_num_pilote[0]->niveau_admin & $gdcarnet_niveauTresorier) == 0) {
			if ($un_pilote->actif != true)
				continue;
			}
		if ($un_pilote->actif != true) {
			echo '<tr bgcolor="#FB9D62">';
			$remarques = $un_pilote->remarques . ' (inactif)';
			}
		else {
			echo '<tr>';
			$remarques = $un_pilote->remarques;
			}
		echo '<td><a href=?pilote=' . esc_html($un_pilote->id) . '>' . esc_html($un_pilote->user_login) . '</a></td><td>' . esc_html($un_pilote->nom_pilote) . '</td><td>'. esc_html($remarques) . '</td>';
		$pilote_solde_query = $wpdb->prepare('SELECT SUM(credit) AS entrees, SUM(debit) AS sorties FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote_comptes WHERE pilote=%d', $un_pilote->id);
		$pilote_solde = $wpdb->get_results($pilote_solde_query);
		if ($pilote_solde == null)
			$le_solde = 0;
		else {
			if ($pilote_solde[0]->entrees == null)
				$entrees = 0;
			else
				$entrees = $pilote_solde[0]->entrees;

			if ($pilote_solde[0]->sorties == null)
				$sorties = 0;
			else
				$sorties = $pilote_solde[0]->sorties;
			
			$le_solde = $entrees - $sorties;
			}
		if ($le_solde < 0)
			echo '<td align=center bgcolor=#FF9999>' . esc_html($le_solde) . '</td></tr>';
		else
			echo '<td align=center>' . esc_html($le_solde) . '</td></tr>';
		}
		echo '</tbody></table>';
}

// Affichage du livre de compte du pilote en cours ou de celui passé en paramètre
function gdcarnet_display_compte_pilote($num_pilote) {
    // Affiche le contenu de la sous-page
	global $wpdb;
	global $gdcarnet_nbMaxAff;
	$myOffset = 0;
	
	if (isset ($_GET['offset']))
			$offset = sanitize_text_field($_GET['offset']);
		else
			$offset = 0;
	if (!is_numeric($offset))
		$offset = 0;
	$prev = $offset + 1;
	$suiv = $offset - 1;
	$myOffset = $offset * $gdcarnet_nbMaxAff;
	
	if ($num_pilote == null) {
		$current_user = wp_get_current_user();
		$ulm_lache_query = $wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE user_login=%s', $current_user->user_login);
		$select_num_pilote = $wpdb->get_results($ulm_lache_query);
		if ($select_num_pilote == null) { // Si on n'a pas encore affiché un carnet de vols pour cet utilisateur
			$table_name = $wpdb->prefix . 'gdcarnet_table_pilote';
			$wpdb->insert(
				$table_name,
				array(
					'id' => NULL,
					'user_login' => $current_user->user_login,
					'nom_pilote' => $current_user->display_name,
					'mensualite' => get_option('mensualite_std', 0),
					'lache' => 0,
					'remarques' => "Creation automatique"
					)
				);
			$pilote_id = $wpdb->insert_id;
			}
		else
			$pilote_id = $select_num_pilote[0]->id;
		echo '<h1>Mon livre de comptes</h1>';
		}
	else {
		$pilote_id = $num_pilote;
		if (!is_numeric($pilote_id)) {
			echo '<hr /><h1>ERREUR DE SAISIE</h1></hr>';
			return;
			}
		$ulm_lache_query = $wpdb->prepare('SELECT nom_pilote, actif FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE id=%d', $num_pilote);
		$get_nom_pilote = $wpdb->get_results($ulm_lache_query);
		if ($get_nom_pilote[0]->actif)
			echo '<h1>Livre de comptes de ' . esc_html($get_nom_pilote[0]->nom_pilote) . '</h1>';
		else
			echo '<h1>Livre de comptes de ' . esc_html($get_nom_pilote[0]->nom_pilote) . ' (INACTIF)</h1>';
		}
	echo '<table border="1">';
	$pilote_solde_query = $wpdb->prepare('SELECT SUM(credit) AS entrees, SUM(debit) AS sorties FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote_comptes WHERE pilote=%d', $pilote_id);
	$pilote_solde = $wpdb->get_results($pilote_solde_query);
	if ($pilote_solde == null)
		$le_solde = 0;
	else {
		if ($pilote_solde[0]->entrees == null)
			$entrees = 0;
		else
			$entrees = $pilote_solde[0]->entrees;

		if ($pilote_solde[0]->sorties == null)
			$sorties = 0;
		else
			$sorties = $pilote_solde[0]->sorties;
		
		$le_solde = $entrees - $sorties;
		}
	
	$actual_link = gdcarnet_get_debut_link() . gdcarnet_get_start_uri() . '?offset=';
	$link_prev = $actual_link . $prev;
	$link_suiv = $actual_link . $suiv;
	$link_zero = $actual_link . '0';
	
	if ($num_pilote != null) {
		$link_prev .= '&pilote=' . $num_pilote;
		$link_suiv .= '&pilote=' . $num_pilote;
		$link_zero .= '&pilote=' . $num_pilote;
		}
	
	echo '<tr><td colspan=4 align="left" style="border-right-style: hidden">&nbsp;&nbsp;<a href="' . esc_url($link_prev) . '">&lt;</a></td><td align="right" style="border-left-style: hidden">';
	if ($offset != 0) {
		echo '<a href="' . esc_url($link_suiv) . '">&gt;</a>&nbsp;&nbsp;';
		echo '<a href="' . esc_url($link_zero) . '">&gt;&gt;</a>';
		}	
	echo '&nbsp;&nbsp;</td>';

	echo '<tr><td colspan=2 align=center bgcolor=#CCCCCC>Solde officiel</td>';
	if ($le_solde < 0)
		echo '<td colspan=3 align=center bgcolor=#FF9999>' . esc_html($le_solde) . '</td></tr>';
	else
		echo '<td colspan=3 align=center bgcolor=#99FF99>' . esc_html($le_solde) . '</td></tr>';
	echo '<tr><td>Date</td><td>Motif</td><td>Cr&eacute;dit</td><td>D&eacute;bit</td><td>Solde indicatif</td></tr>';
	$mes_comptes_query = 'SELECT motif, auteur, credit, debit, DATE_FORMAT(date, "%d/%m/%Y") AS le_jour, date FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote_comptes ';
	$mes_comptes_query .= $wpdb->prepare('WHERE pilote=%d ORDER BY date DESC, id DESC LIMIT %d, %d', $pilote_id, $myOffset, $gdcarnet_nbMaxAff);
	$mes_comptes = $wpdb->get_results($mes_comptes_query);
	foreach ($mes_comptes as $une_ligne) {
		$calcul_solde_query = $wpdb->prepare('SELECT SUM(credit) AS entrees, SUM(debit) AS sorties FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote_comptes WHERE pilote=%d AND date <= %s', $pilote_id, $une_ligne->date);
		$debit_credit = $wpdb->get_results($calcul_solde_query);
		$le_solde = $debit_credit[0]->entrees - $debit_credit[0]->sorties;
		echo '<tr><td align="center">' . esc_html($une_ligne->le_jour) . '</td><td>&nbsp;' . esc_html($une_ligne->motif) . '</td><td align="right">' . esc_html((($une_ligne->credit=="0.0")?'':$une_ligne->credit)) . '</td><td align="right">' . esc_html((($une_ligne->debit=="0.0")?'':$une_ligne->debit)) . '</td><td align="right">' . esc_html($le_solde) . '</td></tr>';
		}
	echo '</table>';
	if ($num_pilote != null)
		return $get_nom_pilote[0]->nom_pilote;
}

// Génération du carnet de vols au format CSV après nettoyage des entrées
function gdcarnet_generer_CSV ($type_aff, $num_ulm) {
	global $wpdb;
	if ($type_aff == 0) { // 0 => pilote
			$current_user = wp_get_current_user();
			$pilote_solde_query = $wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE user_login=%s', $current_user->user_login);
			$select_num_pilote = $wpdb->get_results($pilote_solde_query);
			if ($select_num_pilote == null) { // Si on n'a pas encore affiché un carnet de vols pour cet utilisateur
				$table_name = $wpdb->prefix . 'gdcarnet_table_pilote';
				$wpdb->insert(
					$table_name,
					array(
						'id' => NULL,
						'user_login' => $current_user->user_login,
						'nom_pilote' => $current_user->display_name,
						'mensualite' => get_option('mensualite_std', 0),
						'lache' => 0,
						'remarques' => "Creation automatique"
						)
					);
				$pilote_id = $wpdb->insert_id;
				}
			else
				$pilote_id = $select_num_pilote[0]->id;

			// On récupère tous les vols
			if (!is_numeric($pilote_id)) {
				echo '<center><h1>ERREUR SYSTEME</h1></center>';
				return;
				}
			$cleaned_pilote_id = $wpdb->prepare('%d', $pilote_id);
					
			$vols = $wpdb->get_results( 'SELECT ' . $wpdb->prefix . 'gdcarnet_table_vols.id, ' . $wpdb->prefix . 'gdcarnet_table_vols.date_vol, ' . $wpdb->prefix . 'gdcarnet_table_vols.heure_depart, ' . $wpdb->prefix . 'gdcarnet_table_vols.heure_arrivee, TIME_FORMAT(SEC_TO_TIME(' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_arrivee-' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_depart), "%Hh%imn") AS duree, TIME_FORMAT(SEC_TO_TIME(' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_depart), "%H,%i") AS hora_start, TIME_FORMAT(SEC_TO_TIME(' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_arrivee), "%H,%i") AS hora_end, ' . $wpdb->prefix . 'gdcarnet_table_vols.terrain_depart_oaci, ' . $wpdb->prefix . 'gdcarnet_table_vols.terrain_arrivee_oaci, ' . $wpdb->prefix . 'gdcarnet_table_vols.carburant_depart, ' . $wpdb->prefix . 'gdcarnet_table_vols.carburant_arrivee, ' . $wpdb->prefix . 'gdcarnet_table_vols.carburant_ajoute, ' . $wpdb->prefix . 'gdcarnet_table_vols.plein_complet, ' . $wpdb->prefix . 'gdcarnet_table_vols.remarques, ' . $wpdb->prefix . 'gdcarnet_table_vols.notes_pilote, ' . $wpdb->prefix . 'gdcarnet_table_pilote.nom_pilote, ' . $wpdb->prefix . 'gdcarnet_table_ulm.modele, ' . $wpdb->prefix . 'gdcarnet_table_ulm.immatriculation FROM ' . $wpdb->prefix . 'gdcarnet_table_vols, ' . $wpdb->prefix . 'gdcarnet_table_pilote, ' . $wpdb->prefix . 'gdcarnet_table_ulm WHERE ' . $wpdb->prefix . 'gdcarnet_table_vols.ulm=' . $wpdb->prefix . 'gdcarnet_table_ulm.id AND ' . $wpdb->prefix . 'gdcarnet_table_vols.pilote=' . $cleaned_pilote_id . ' AND ' . $wpdb->prefix . 'gdcarnet_table_pilote.id=' . $cleaned_pilote_id . ' ORDER BY ' . $wpdb->prefix . 'gdcarnet_table_vols.id DESC');
			}
		else if ($type_aff == 1) { // 1 => ulm
			$prepared_num_ulm = $wpdb->prepare('%d', $num_ulm);
			$vols = $wpdb->get_results( "SELECT " . $wpdb->prefix . "gdcarnet_table_vols.id, " . $wpdb->prefix . "gdcarnet_table_vols.date_vol, " . $wpdb->prefix . "gdcarnet_table_vols.heure_depart, " . $wpdb->prefix . "gdcarnet_table_vols.heure_arrivee, TIME_FORMAT(SEC_TO_TIME(" . $wpdb->prefix . "gdcarnet_table_vols.horametre_arrivee-" . $wpdb->prefix . "gdcarnet_table_vols.horametre_depart), \"%Hh%imn\") AS duree, TIME_FORMAT(SEC_TO_TIME(" . $wpdb->prefix . "gdcarnet_table_vols.horametre_depart), \"%H,%i\") AS hora_start, TIME_FORMAT(SEC_TO_TIME(" . $wpdb->prefix . "gdcarnet_table_vols.horametre_arrivee), \"%H,%i\") AS hora_end, " . $wpdb->prefix . "gdcarnet_table_vols.terrain_depart_oaci, " . $wpdb->prefix . "gdcarnet_table_vols.terrain_arrivee_oaci, " . $wpdb->prefix . "gdcarnet_table_vols.carburant_depart, " . $wpdb->prefix . "gdcarnet_table_vols.carburant_arrivee, " . $wpdb->prefix . "gdcarnet_table_vols.carburant_ajoute, " . $wpdb->prefix . "gdcarnet_table_vols.plein_complet, " . $wpdb->prefix . "gdcarnet_table_vols.remarques, " . $wpdb->prefix . "gdcarnet_table_vols.notes_pilote, " . $wpdb->prefix . "gdcarnet_table_pilote.nom_pilote, " . $wpdb->prefix . "gdcarnet_table_ulm.modele, " . $wpdb->prefix . "gdcarnet_table_ulm.immatriculation FROM " . $wpdb->prefix . "gdcarnet_table_vols, " . $wpdb->prefix . "gdcarnet_table_pilote, " . $wpdb->prefix . "gdcarnet_table_ulm WHERE " . $wpdb->prefix . "gdcarnet_table_vols.ulm=$prepared_num_ulm AND " . $wpdb->prefix . "gdcarnet_table_ulm.id=" . $wpdb->prefix . "gdcarnet_table_vols.ulm AND " . $wpdb->prefix . "gdcarnet_table_pilote.id=" . $wpdb->prefix . "gdcarnet_table_vols.pilote ORDER BY " . $wpdb->prefix . "gdcarnet_table_vols.id DESC" );
			}
		else	// type_aff n'est ni 0 ni 1 => entrée invalide !
			return;

	// Première ligne du CSV (étiquettes)
	$fileContent8 = 'Date du vol;Heure de départ;Heure d\'arrivée;Pilote;ULM;Horamètre au départ;Horamètre à la fin;Durée du vol;Terrain de départ;Terrain d\'arrivée;Carburant au départ;Carburant à l\'arrivée;Carburant ajouté;Plein complet;Remarques;Notes pilote' . "\n";
	$fileContent = mb_convert_encoding ($fileContent8, 'ISO-8859-1', 'UTF-8');
	foreach ($vols as $un_vol) {
		$fileContent .= mb_convert_encoding ($un_vol->date_vol . ';' . $un_vol->heure_depart . ';' . $un_vol->heure_arrivee . ';' . $un_vol->nom_pilote . ';' . $un_vol->modele . ' ' . $un_vol->immatriculation . ';' . $un_vol->hora_start . ';' . $un_vol->hora_end . ';' . $un_vol->duree . ';' . $un_vol->terrain_depart_oaci . ';' . $un_vol->terrain_arrivee_oaci . ';' . $un_vol->carburant_depart . ';' . $un_vol->carburant_arrivee . ';' . $un_vol->carburant_ajoute . ';' . $un_vol->plein_complet . ';' . $un_vol->remarques . ';' . $un_vol->notes_pilote . "\n", 'ISO-8859-1', 'UTF-8');
		}

    // Mettre en mémoire tampon la sortie
    ob_start();

    // Générer le contenu du fichier
    echo $fileContent;	// Can't be escaped (it's a donwloaded file)

    // Récupérer le contenu de la mémoire tampon et vider la mémoire tampon
    $fileContentBuffer = ob_get_clean();

    // Envoyer le fichier à télécharger
    header('Content-Type: application/octet-stream; charset=utf-8');
    header('Content-Disposition: attachment; filename="CarnetDeVols.csv"');
    echo $fileContentBuffer;	// Can't be escaped (it's a buffered output, before sending a file)
	exit;
}


// Affichage de la liste des vols enregistrés
function gdcarnet_display_carnet($type_aff, $num_ulm) {
	global $gdcarnet_nbMaxAff;
	global $gdcarnet_volMecanoFlag;
	$myOffset = 0;
	
	$current_url = sanitize_text_field($_SERVER['REQUEST_URI']);
	if (strpos($current_url, '/wp-json/') !== false ) return;	// Si dans l'interface d'admin en cours de JSON => on retourne tout de suite pour ne pas provoquer d'erreur JSON

	if (isset ($_GET['offset']))
			$offset = sanitize_text_field($_GET['offset']);
		else
			$offset = 0;
	if (!is_numeric($offset))
		$offset = 0;
	$prev = $offset + 1;
	$suiv = $offset - 1;
	if (isset ($_GET['vol'])) {
		$type_carnet = 1;
		if (isset ($_GET['type_carnet']))
			$type_carnet = sanitize_text_field($_GET['type_carnet']);
		if (($type_carnet != 0) && ($type_carnet != 1))
			$type_carnet = 1;
		gdcarnet_display_un_vol (sanitize_text_field($_GET['vol']), $type_carnet, $offset);	// Cette fonction nettoie elle-même ses entrées
		return;
		}
    global $wpdb;
	
	echo '<div class="wrap">';
		$myOffset = $gdcarnet_nbMaxAff * $offset;
		if ($type_aff == 0) { // 0 => pilote
			$current_user = wp_get_current_user();
			$select_num_pilote_query = $wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE user_login=%s', $current_user->user_login);
			$select_num_pilote = $wpdb->get_results($select_num_pilote_query);
			if ($select_num_pilote == null) { // Si on n'a pas encore affiché un carnet de vols pour cet utilisateur
				$table_name = $wpdb->prefix . 'gdcarnet_table_pilote';
				$wpdb->insert(
					$table_name,
					array(
						'id' => NULL,
						'user_login' => $current_user->user_login,
						'nom_pilote' => $current_user->display_name,
						'mensualite' => get_option('mensualite_std', 0),
						'lache' => 0,
						'remarques' => "Creation automatique"
						)
					);
				$pilote_id = $wpdb->insert_id;
				}
			else
				$pilote_id = $select_num_pilote[0]->id;

			// On n'affiche que les gdcarnet_nbMaxAff derniers vols		
			$vols_query = $wpdb->prepare('SELECT ' . $wpdb->prefix . 'gdcarnet_table_vols.id, ' . $wpdb->prefix . 'gdcarnet_table_vols.date_vol, ' . $wpdb->prefix . 'gdcarnet_table_vols.heure_depart, TIME_FORMAT(SEC_TO_TIME(' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_arrivee-' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_depart), "ForMatHour1") AS duree, (' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_arrivee-' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_depart) AS duree_secondes, ' . $wpdb->prefix . 'gdcarnet_table_vols.cout_du_vol, TIME_FORMAT(SEC_TO_TIME(' . $wpdb->prefix . 'gdcarnet_table_vols.horametre_arrivee), "ForMatHour2") AS hora_end, ' . $wpdb->prefix . 'gdcarnet_table_vols.carburant_arrivee, ' . $wpdb->prefix . 'gdcarnet_table_vols.terrain_depart_oaci, ' . $wpdb->prefix . 'gdcarnet_table_vols.terrain_arrivee_oaci, ' . $wpdb->prefix . 'gdcarnet_table_vols.remarques, ' . $wpdb->prefix . 'gdcarnet_table_vols.notes_pilote, ' . $wpdb->prefix . 'gdcarnet_table_pilote.nom_pilote, ' . $wpdb->prefix . 'gdcarnet_table_ulm.tarif_heure, ' . $wpdb->prefix . 'gdcarnet_table_ulm.modele, ' . $wpdb->prefix . 'gdcarnet_table_ulm.immatriculation FROM ' . $wpdb->prefix . 'gdcarnet_table_vols, ' . $wpdb->prefix . 'gdcarnet_table_pilote, ' . $wpdb->prefix . 'gdcarnet_table_ulm ' . 'WHERE ' . $wpdb->prefix . 'gdcarnet_table_vols.ulm=' . $wpdb->prefix . 'gdcarnet_table_ulm.id AND ' . $wpdb->prefix . 'gdcarnet_table_vols.pilote=%d AND ' . $wpdb->prefix . 'gdcarnet_table_pilote.id=%d ORDER BY ' . $wpdb->prefix . 'gdcarnet_table_vols.id DESC LIMIT %d, %d', $pilote_id, $pilote_id, $myOffset, $gdcarnet_nbMaxAff);
			$vols_query = str_replace('ForMatHour1', '%Hh%imn', $vols_query);
			$vols_query = str_replace('ForMatHour2', '%H,%i', $vols_query);
			$vols = $wpdb->get_results($vols_query);
			$tot_heures_query = $wpdb->prepare('SELECT TIME_FORMAT(SEC_TO_TIME(SUM(minutes_de_vol)*60), "ForMatHour1") AS heures FROM ' . $wpdb->prefix . 'gdcarnet_table_vols WHERE pilote=%d', $pilote_id);
			$tot_heures_query = str_replace('ForMatHour1', '%Hh%imn', $tot_heures_query);
			$tot_heures = $wpdb->get_results($tot_heures_query);
			echo '<h1>Carnet de vols de ' . esc_html($current_user->display_name) . '</h1>';
			}
		else if ($type_aff == 1) { // 1 => ulm
			$prepared_num_ulm = $wpdb->prepare('%d', $num_ulm);			
//			$query_vols = $wpdb->prepare("SELECT " . $wpdb->prefix ."gdcarnet_table_vols.id, " . $wpdb->prefix ."gdcarnet_table_vols.date_vol, " . $wpdb->prefix ."gdcarnet_table_vols.flags, " . $wpdb->prefix ."gdcarnet_table_vols.heure_depart, TIME_FORMAT(SEC_TO_TIME(" . $wpdb->prefix ."gdcarnet_table_vols.horametre_arrivee-" . $wpdb->prefix ."gdcarnet_table_vols.horametre_depart), 'ForMatHour1') AS duree, TIME_FORMAT(SEC_TO_TIME(" . $wpdb->prefix ."gdcarnet_table_vols.horametre_arrivee), 'ForMatHour2') AS hora_end, " . $wpdb->prefix ."gdcarnet_table_vols.carburant_arrivee, " . $wpdb->prefix ."gdcarnet_table_vols.remarques, " . $wpdb->prefix ."gdcarnet_table_vols.notes_pilote,  " . $wpdb->prefix ."gdcarnet_table_pilote.nom_pilote, " . $wpdb->prefix ."gdcarnet_table_ulm.modele, " . $wpdb->prefix ."gdcarnet_table_ulm.immatriculation, " . $wpdb->prefix ."gdcarnet_table_vols.terrain_depart_oaci, " . $wpdb->prefix ."gdcarnet_table_vols.terrain_arrivee_oaci FROM " . $wpdb->prefix ."gdcarnet_table_vols, " . $wpdb->prefix ."gdcarnet_table_pilote, " . $wpdb->prefix ."gdcarnet_table_ulm WHERE " . $wpdb->prefix ."gdcarnet_table_vols.ulm=$prepared_num_ulm AND " . $wpdb->prefix ."gdcarnet_table_ulm.id=" . $wpdb->prefix ."gdcarnet_table_vols.ulm AND " . $wpdb->prefix ."gdcarnet_table_pilote.id=" . $wpdb->prefix ."gdcarnet_table_vols.pilote ORDER BY " . $wpdb->prefix ."gdcarnet_table_vols.id DESC LIMIT %d, %d", $myOffset, $gdcarnet_nbMaxAff);
			$query_vols = $wpdb->prepare("SELECT " . $wpdb->prefix ."gdcarnet_table_vols.id, " . $wpdb->prefix ."gdcarnet_table_vols.date_vol, " . $wpdb->prefix ."gdcarnet_table_vols.flags, " . $wpdb->prefix ."gdcarnet_table_vols.heure_depart, TIME_FORMAT(SEC_TO_TIME(" . $wpdb->prefix ."gdcarnet_table_vols.horametre_arrivee-" . $wpdb->prefix ."gdcarnet_table_vols.horametre_depart), 'ForMatHour1') AS duree, TIME_FORMAT(SEC_TO_TIME(" . $wpdb->prefix ."gdcarnet_table_vols.horametre_arrivee), 'ForMatHour2') AS hora_end, " . $wpdb->prefix ."gdcarnet_table_vols.carburant_arrivee, " . $wpdb->prefix ."gdcarnet_table_vols.remarques, " . $wpdb->prefix ."gdcarnet_table_vols.notes_pilote,  " . $wpdb->prefix ."gdcarnet_table_pilote.nom_pilote, " . $wpdb->prefix ."gdcarnet_table_ulm.modele, " . $wpdb->prefix ."gdcarnet_table_ulm.immatriculation, " . $wpdb->prefix ."gdcarnet_table_vols.terrain_depart_oaci, " . $wpdb->prefix ."gdcarnet_table_vols.terrain_arrivee_oaci FROM " . $wpdb->prefix ."gdcarnet_table_vols, " . $wpdb->prefix ."gdcarnet_table_pilote, " . $wpdb->prefix ."gdcarnet_table_ulm WHERE " . $wpdb->prefix ."gdcarnet_table_vols.ulm=$prepared_num_ulm AND " . $wpdb->prefix ."gdcarnet_table_ulm.id=" . $wpdb->prefix ."gdcarnet_table_vols.ulm AND " . $wpdb->prefix ."gdcarnet_table_pilote.id=" . $wpdb->prefix ."gdcarnet_table_vols.pilote ORDER BY " . $wpdb->prefix ."gdcarnet_table_vols.date_vol DESC, " . $wpdb->prefix ."gdcarnet_table_vols.heure_depart DESC LIMIT %d, %d", $myOffset, $gdcarnet_nbMaxAff);
			$query_vols = str_replace('ForMatHour1', '%Hh%imn', $query_vols);
			$query_vols = str_replace('ForMatHour2', '%H,%i', $query_vols);
			$vols = $wpdb->get_results($query_vols);

			$tot_heures_query = $wpdb->prepare('SELECT TIME_FORMAT(SEC_TO_TIME(SUM(minutes_de_vol)*60), "ForMatHour1") AS heures FROM ' . $wpdb->prefix . 'gdcarnet_table_vols WHERE ulm=%d', $num_ulm);
			$tot_heures_query = str_replace('ForMatHour1', '%Hh%imn', $tot_heures_query);
			$tot_heures = $wpdb->get_results($tot_heures_query);
			if ($vols == null) {
				$leULM = $wpdb->get_results('SELECT modele, immatriculation FROM ' . $wpdb->prefix .'gdcarnet_table_ulm WHERE id=' . $prepared_num_ulm);
				$leTitre = '<h1>Carnet de vols du ' . esc_html($leULM[0]->modele . ' ' . $leULM[0]->immatriculation) . '</h1>';
				echo $leTitre;
				}
			else {
				echo '<h1>Carnet de vols du ' . esc_html($vols[0]->modele . ' ' . $vols[0]->immatriculation) . '</h1>';
				}
			}
		$urlCsv = '?csv=oui&type_aff=' . $type_aff;
		if ($type_aff == 1)
			$urlCsv .= '&ulm=' . $num_ulm;
		echo '<a href="' . esc_url($urlCsv) . '" >T&eacute;l&eacute;charger le carnet de vols</a><br /><br />';
		$actual_link = gdcarnet_get_debut_link();
		$actual_link .= gdcarnet_get_start_uri();
		$actual_link .= '?offset=';
	?>
        <table border="1">
            <thead>
				<tr>
					<th colspan=8 align="left" style="border-right-style: hidden">&nbsp;&nbsp;<a href="<?php echo esc_url($actual_link.$prev)?>">&lt;</a></th><th style="border-left-style: hidden" align="right"><?php if ($offset != 0) echo '<a href="' . esc_url($actual_link.$suiv) . '">&gt;</a>&nbsp;&nbsp;<a href="' . esc_url($actual_link.'0') . '">&gt;&gt;</a>'?>&nbsp;&nbsp;</th>
				</tr>
                <tr>
                    <th>Date<br />du vol</th>
					<th>Heure<br />d&eacute;part</th>
                    <th>Dur&eacute;e<br />du vol</th>
	<?php
					if ($type_aff == 0) { // 0 => pilote
						echo '<th>Co&ucirc;t<br />du vol</th>';
						}
					else if ($type_aff == 1) { // 1 => ulm
						echo '<th>Horam&egrave;tre<br />arriv&eacute;e</th>';
						}
	?>
					<th>Carburant<br />arriv&eacute;e</th>
					<th>Terrain<br />d&eacute;part</th>
					<th>Terrain<br />arriv&eacute;e</th>
	<?php
					if ($type_aff == 0) { // 0 => pilote
						echo '<th>ULM</th>';
						echo '<th>Notes pilote</th>';
						}
					else if ($type_aff == 1) { // 1 => ulm
						echo '<th>Pilote</th>';
						echo '<th>Remarques</th>';
						}
	?>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($vols as $un_vol) {
                    ?>
                    <tr>
                        <td align="center"><?php echo '<a href=' . esc_html('?vol=' . $un_vol->id . '&type_carnet=' . $type_aff) . '&offset=' . esc_html($offset) . '>' . esc_html($un_vol->date_vol) . '</a>'; ?></td>
						<td align="center"><?php echo esc_html($un_vol->heure_depart); ?></td>
                        <td align="center"><?php echo esc_html($un_vol->duree); ?></td>
	<?php
					if ($type_aff == 0) { // 0 => pilote
						$coutDuVol = $un_vol->cout_du_vol;
						echo '<td align="center">' . esc_html(number_format((float)$coutDuVol, 2, '&euro;', '')) . '</td>';
						}
					else if ($type_aff == 1) { // 1 => ulm
						echo '<td align="center">' . esc_html($un_vol->hora_end) . '</td>';
						}
	?>
						<td align="center"><?php echo esc_html($un_vol->carburant_arrivee); ?></td>
						<td align="center"><?php echo esc_html($un_vol->terrain_depart_oaci); ?></td>
						<td align="center"><?php echo esc_html($un_vol->terrain_arrivee_oaci); ?></td>
	<?php
					if ($type_aff == 0) { // 0 => pilote
                        echo '<td align="center">' . esc_html($un_vol->modele . ' ' . $un_vol->immatriculation) . '</td>';
						$texte_libre = $un_vol->notes_pilote;
						}
					else if ($type_aff == 1) { // 1 => ulm
						if (($un_vol->flags & $gdcarnet_volMecanoFlag) != 0)
							echo '<td align="center" bgcolor="yellow">' . esc_html($un_vol->nom_pilote) . '</td>';
						else
							echo '<td align="center">' . esc_html($un_vol->nom_pilote) . '</td>';
						$texte_libre = $un_vol->remarques;
						}
				if (strlen($texte_libre) > 20) {
					$remarques = substr($texte_libre,0,17);
					echo '<td align="center">' . esc_html($remarques) . ' <b>(...)</b></td>';
					}
				else
					echo '<td align="center">' . esc_html($texte_libre) . '</td>';
	?>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
		Total depuis le premier vol enregistr&eacute;&nbsp;: <?php echo esc_html($tot_heures[0]->heures); ?>
    </div>
    <?php
}

// Gestion de l'ajout d'un vol dans la base
function gdcarnet_ajoute_vol() {
	global $wpdb;
	global $gdcarnet_plein_complet_liste;
	global $gdcarnet_plein_complet_liste_texte;
	global $gdcarnet_niveauMecano;
	
	$current_url = sanitize_text_field($_SERVER['REQUEST_URI']);
	if (strpos($current_url, '/wp-json/') !== false ) return;	// Si dans l'interface d'admin en cours de JSON => on retourne tout de suite pour ne pas provoquer d'erreur JSON
	
	$current_user = wp_get_current_user();
	$select_num_pilote_query = $wpdb->prepare('SELECT id, actif, niveau_admin FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE user_login=%s', $current_user->user_login);
	$select_num_pilote = $wpdb->get_results($select_num_pilote_query);
	if ($select_num_pilote == null) {
		echo '<center><h2><font color="red">Veuillez vous identifier</font></h2></center>';
		return;
		}
	$pilote_id = $select_num_pilote[0]->id;
	
	if ($select_num_pilote[0]->actif == false) {
		echo '<center><h2><font color="red">Compte d&eacute;sactiv&eacute;, contactez votre administrateur</font></h2></center>';
		return;
		}

    // si le formulaire a été soumis, ajoute la transaction dans la base de données
    if (isset($_POST['heure_depart']) && isset($_POST['heure_arrivee']) && isset($_POST['horametre_arrivee'])) {
        global $wpdb;

		check_admin_referer('addVol' . $pilote_id);	// Vérification du nonce
		// Récupération / nettoyage des entrées
		if (isset($_POST['volmecano']))
			$volmecano = true;
		else
			$volmecano = false;
        $date_vol = sanitize_text_field($_POST['date_vol']);
		$heure_depart = sanitize_text_field($_POST['heure_depart']) . ':00';
		$heure_arrivee = sanitize_text_field($_POST['heure_arrivee']) . ':00';
		$ulm = sanitize_text_field($_POST['ulm']);
        $horametre_depart = floatval(sanitize_text_field(str_replace(',' , '.', $_POST['horametre_depart'])));
		
		$horametre_arrivee = floatval(sanitize_text_field(str_replace(',' , '.', $_POST['horametre_arrivee'])));
		$terrain_depart_oaci = sanitize_text_field($_POST['terrain_depart_oaci']);
		$terrain_arrivee_oaci = sanitize_text_field($_POST['terrain_arrivee_oaci']);
		$carburant_depart = sanitize_text_field($_POST['carburant_depart']);
		$carburant_arrivee = sanitize_text_field($_POST['carburant_arrivee']);
		$carburant_ajoute = sanitize_text_field($_POST['carburant_ajoute']);
		$leplein = sanitize_text_field($_POST['leplein']);
		$remarques = sanitize_textarea_field($_POST['remarques']);
		$notes_pilote = sanitize_textarea_field($_POST['notes_pilote']);
		
		$heures_horametre_depart = (int)$horametre_depart;
		$minutes_horametre_depart = round(($horametre_depart - $heures_horametre_depart) * 100);
		$horadepart = (($heures_horametre_depart * 60) + $minutes_horametre_depart) * 60;

		$heures_horametre_arrivee = (int)$horametre_arrivee;
		$minutes_horametre_arrivee = round(($horametre_arrivee - $heures_horametre_arrivee) * 100);
		$horaarrivee = (($heures_horametre_arrivee * 60) + $minutes_horametre_arrivee) * 60;
		
		$duree_de_vol = ($horaarrivee - $horadepart) / 60;
		
		// Vérifications
		$aff_erreur = false;
		$msg_erreur = 'Erreur dans le formulaire&nbsp;:<br />';
		// Format de date
		$tab_date_vol = explode("-",$date_vol);
		if (!checkdate($tab_date_vol[1], $tab_date_vol[2], $tab_date_vol[0])) {
			$aff_erreur = true;
			$msg_erreur .= 'Date de vol invalide<br />';
			}
		// Ordre des relevés de l'horamètre
		if ($horadepart > $horaarrivee) {
			$aff_erreur = true;
			$msg_erreur .= 'Horam&egrave;tre arriv&eacute;e inf&eacute;rieur &agrave; celui de d&eacute;part<br />';
			}
		// Format heure de départ
		if (preg_match("/^(?:2[0-4]|[01][1-9]|10):([0-5][0-9]):00$/", $heure_depart) != 1) {
			$aff_erreur = true;
			$msg_erreur .= 'Heure de d&eacute;part invalide<br />';
			}
		// Format heure d'arrivée
		if (preg_match("/^(?:2[0-4]|[01][1-9]|10):([0-5][0-9]):00$/", $heure_arrivee) != 1) {
			$aff_erreur = true;
			$msg_erreur .= 'Heure d\'arriv&eacute;e invalide<br />';
			}
		// Ordre des horaires
		$tab_heure_depart = explode (':', $heure_depart);
		$timedepart = strtotime ($heure_depart);
		$timearrivee = strtotime ($heure_arrivee);
		if ($timearrivee <= $timedepart) {
			$aff_erreur = true;
			$msg_erreur .= 'Heure d\'arriv&eacute;e ant&eacute;rieure &agrave; l\'heure de d&eacute;part<br />';
			}
		
		if ($aff_erreur) {
			echo esc_html($msg_erreur);
			echo '<hr /><br /><a href="javascript:history.back()">Retour pour corriger</a>';
			exit;
			}
		else {
			$tarif_ulm_query = $wpdb->prepare('SELECT tarif_heure, immatriculation FROM ' . $wpdb->prefix .'gdcarnet_table_ulm WHERE id=%d', $ulm);
			$tarif_ulm = $wpdb->get_results($tarif_ulm_query);
			if ($volmecano)
				$coutDuVol = 0;
			else
				$coutDuVol = ($tarif_ulm[0]->tarif_heure / 60) * $duree_de_vol;
			$valeurFlags = 0;
			if ($volmecano)
				$valeurFlags |= $gdcarnet_volMecanoFlag;
			$wpdb->insert(
			$wpdb->prefix .'gdcarnet_table_vols',
				array(
					'pilote' => $pilote_id,
					'flags' => $valeurFlags,
					'ulm' => $ulm,
					'date_vol' => $date_vol,
					'heure_depart' => $heure_depart,
					'heure_arrivee' => $heure_arrivee,
					'horametre_depart' => $horadepart,
					'horametre_arrivee' => $horaarrivee,
					'minutes_de_vol' => $duree_de_vol,
					'cout_du_vol' => $coutDuVol,
					'terrain_depart_oaci' => $terrain_depart_oaci,
					'terrain_arrivee_oaci' => $terrain_arrivee_oaci,
					'carburant_depart' => $carburant_depart,
					'carburant_arrivee' => $carburant_arrivee,
					'carburant_ajoute' => $carburant_ajoute,
					'plein_complet' => $leplein,
					'remarques' => $remarques,
					'notes_pilote' => $notes_pilote,
					'date_creation' => current_time('mysql', 1),
					'derniere_modification' => current_time('mysql', 1)
					)
				);
			$idvol = $wpdb->insert_id;
			if ($coutDuVol != 0) {
				$wpdb->insert(
				$wpdb->prefix .'gdcarnet_table_pilote_comptes',
					array(
						'pilote' => $pilote_id,
						'motif' => 'vol sur ' . $tarif_ulm[0]->immatriculation,
						'auteur' => $pilote_id,
						'debit' => $coutDuVol,
						'id_vol' => $idvol,
						'date' => $date_vol
						)
					);
				}
			echo '<br /><hr /><b>Votre saisie a &eacute;t&eacute; enregistr&eacute;e, merci.</b><hr /><br />';
			}

    }

    // affiche le formulaire d'ajout de vol
	$liste_ulm_lache_actif_query = $wpdb->prepare('SELECT ' . $wpdb->prefix .'gdcarnet_table_ulm.id, immatriculation, modele FROM ' . $wpdb->prefix .'gdcarnet_table_ulm, ' . $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache, ' . $wpdb->prefix .'gdcarnet_table_pilote WHERE ' . $wpdb->prefix .'gdcarnet_table_ulm.id=' . $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache.ulm AND ' . $wpdb->prefix .'gdcarnet_table_ulm.actif=true AND ' . $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache.pilote=' . $wpdb->prefix .'gdcarnet_table_pilote.id AND ' . $wpdb->prefix .'gdcarnet_table_pilote.user_login=%s', $current_user->user_login);
	$liste_ulm_lache_actif = $wpdb->get_results($liste_ulm_lache_actif_query);
	$nbULM = 0;
	foreach ($liste_ulm_lache_actif as $un_ulm) {
		if (!is_numeric($un_ulm->id)) {
			echo '<center><h1>ERREUR SYSTEME</h1></center>';
			return;
			}
		$monULMid = $un_ulm->id;
		if (!is_numeric($monULMid))
			$monULMid = 0;
		$query_dernier_vol_part3 = $wpdb->prepare('SELECT TIME_FORMAT(SEC_TO_TIME(' . $wpdb->prefix .'gdcarnet_table_vols.horametre_arrivee), "ForMatHour") AS hora_end, carburant_arrivee, terrain_arrivee_oaci FROM ' . $wpdb->prefix .'gdcarnet_table_vols WHERE ulm=%d ORDER BY horametre_arrivee DESC LIMIT 1', $monULMid);
		$query_dernier_vol_part3 = str_replace('ForMatHour', '%H.%i', $query_dernier_vol_part3);
		$dernier_vol = $wpdb->get_results($query_dernier_vol_part3);
		$nbULM++;
		$tab_ulm_id[] = $un_ulm->id;
		if ($dernier_vol == null) {
			$tab_hora_end[] = 0;
			$tab_terrain_arrivee_oaci[] = '';
			$tab_carburant_arrivee[] = 0;
			}
		else {
			$tab_hora_end[] = $dernier_vol[0]->hora_end;
			$tab_terrain_arrivee_oaci[] = $dernier_vol[0]->terrain_arrivee_oaci;
			$tab_carburant_arrivee[] = $dernier_vol[0]->carburant_arrivee;
			}
		}
		if ($nbULM == 0) {
			$tab_hora_end[] = 0;
			$tab_terrain_arrivee_oaci[] = '';
			$tab_carburant_arrivee[] = 0;
			}
		
		echo "<script type='text/javascript'>\n";
		echo 'let tab_ulm_id = [ ' . esc_html($tab_ulm_id[0]);
		for ($i = 1; $i < $nbULM; $i++)
			echo ', ' . esc_html($tab_ulm_id[$i]);
		echo " ];\n";
		echo 'let tab_hora_end = [ ' . esc_html($tab_hora_end[0]);
		for ($i = 1; $i < $nbULM; $i++)
			echo ', ' . esc_html($tab_hora_end[$i]);
		echo " ];\n";
		echo 'let tab_terrain_arrivee_oaci = [ "' . esc_html($tab_terrain_arrivee_oaci[0]);
		for ($i = 1; $i < $nbULM; $i++)
			echo '", "' . esc_html($tab_terrain_arrivee_oaci[$i]);
		echo '"' . " ];\n";
		echo 'let tab_carburant_arrivee = [ ' . esc_html($tab_carburant_arrivee[0]);
		for ($i = 1; $i < $nbULM; $i++)
			echo ', ' . esc_html($tab_carburant_arrivee[$i]);
		echo " ];\n";
		echo "function onUlmChange (ulmSelect) {\n";
		echo 'for (numULM = 0; numULM < ' . esc_html($nbULM) . '; numULM++) {' . "\n";
		echo 'if (tab_ulm_id[numULM] == ulmSelect.value) {' . "\n";
		echo 'document.getElementById("horametre_depart").value=tab_hora_end[numULM]' . "\n";
		echo 'document.getElementById("terrain_depart_oaci").value=tab_terrain_arrivee_oaci[numULM]' . "\n";
		echo 'document.getElementById("carburant_depart").value=tab_carburant_arrivee[numULM]' . "\n";
		echo "}\n";
		echo "}\n";
		echo "}\n";
		echo "</script>\n";
    ?>
    <form method="post" action="" id="formUnVol">
<?php	wp_nonce_field('addVol' . $pilote_id); ?>
		<table border="0">
			<tbody>
				<tr><td>Pilote&nbsp;:</td><td align="left"><?php echo esc_html($current_user->display_name); ?></td></tr>
<?php
	if (($select_num_pilote[0]->niveau_admin & $gdcarnet_niveauMecano) != 0)
		echo '<tr><td>Vol m&eacute;cano&nbsp;? </td><td align="left"><input type="checkbox" id="volmecano" name="volmecano" /></td></tr>';
?>
				<tr><td>Date du vol&nbsp;: (*)</td><td align="left"><input type="date" id="date_vol" name="date_vol" value="<?php echo esc_html(date('Y-m-d')); ?>" required /></td></tr>
				<tr><td>Heure de d&eacute;part&nbsp;: (*)</td><td align="left"><input type="time" id="heure_depart" name="heure_depart" required /></td></tr>
				<tr><td>Heure d'arriv&eacute;e&nbsp;: (*)</td><td align="left"><input type="time" id="heure_arrivee" name="heure_arrivee" required /></td></tr>
				<tr><td>ULM&nbsp;: (*)</td><td align="left">
				<select id="ulm" name="ulm" onchange="onUlmChange(this)">
<?php
	foreach ($liste_ulm_lache_actif as $un_ulm) {
		echo '<option value=' . esc_html($un_ulm->id) . '>' . esc_html($un_ulm->modele . ' ' . $un_ulm->immatriculation) . '</option>';
		}
?>
				</select>
				</td></tr>
				<tr><td>Horam&egrave;tre au d&eacute;part&nbsp;: (*)</td><td align="left"><input type="number" step="0.01" id="horametre_depart" name="horametre_depart" value ="<?php echo esc_html($tab_hora_end[0]); ?>" required /></td></tr>
				<tr><td>Horam&egrave;tre &agrave; la fin&nbsp;: (*)</td><td align="left"><input type="number" step="0.01" id="horametre_arrivee" name="horametre_arrivee" required /></td></tr>
				<tr><td>Terrain de d&eacute;part&nbsp;: (*)</td><td align="left"><input type="text" id="terrain_depart_oaci" name="terrain_depart_oaci" value ="<?php echo esc_html($tab_terrain_arrivee_oaci[0]); ?>" required /></td></tr>
				<tr><td>Terrain d'arriv&eacute;e&nbsp;: (*)</td><td align="left"><input type="text" id="terrain_arrivee_oaci" name="terrain_arrivee_oaci" required /></td></tr>
				<tr><td>Carburant au d&eacute;part (litres en arrivant &agrave; l'avion)&nbsp;: (*)</td><td align="left"><input type="number" id="carburant_depart" name="carburant_depart" value ="<?php echo esc_html($tab_carburant_arrivee[0]); ?>" required /></td></tr>
				<tr><td>Carburant &agrave; l'arriv&eacute;e (litres au moment de partir apr&egrave;s le vol, et apr&egrave;s un &eacute;ventuel ajout de carburant)&nbsp;: (*)</td><td align="left"><input type="number" id="carburant_arrivee" name="carburant_arrivee" required /></td></tr>
				<tr><td>Carburant ajout&eacute;&nbsp;? (litres)&nbsp;:</td><td align="left"><input type="number" id="carburant_ajoute" name="carburant_ajoute" />&nbsp; Plein complet&nbsp;?&nbsp;<select id="leplein" name="leplein">
<?php
			$i = 0;
			foreach ($gdcarnet_plein_complet_liste as $une_valeur) {
				echo '<option value="' . esc_html($une_valeur) . '">' . esc_html($gdcarnet_plein_complet_liste_texte[$i]) . '</option>';
				$i++;
				}
?>
				</select></td></tr>
				<tr><td>Remarques ULM&nbsp;: </td><td align="left"><textarea id=remarques" name="remarques" rows=8></textarea></td></tr>
				<tr><td>Notes pilote&nbsp;: </td><td align="left"><textarea id=notes_pilote" name="notes_pilote" rows=8></textarea></td></tr>
				<tr><td colspan=2 align="center"><input type="submit" id="submitUnVol" /><input type="button" id="attenteEnvoi" value="Envoi en cours..." style="display:none;background:orange"/><br />(*) => champ obligatoire</td></tr>
			</tbody>
		</table>
    </form>
	<script>
    document.getElementById('formUnVol').addEventListener('submit', function() {
        document.getElementById('submitUnVol').disabled = true;
		document.getElementById('submitUnVol').style.display = 'none';
		document.getElementById('attenteEnvoi').style.display = 'block';
    });
	</script>

    <?php
}

// Ajouter une note de frais (téléchargement du fichier, saisie du montant et du motif
function gdcarnet_ajoute_frais () {
	global $wpdb;
	global $gdcarnet_tableNotesDeFrais;
	$gdcarnet_autoAjoutNoteDeFrais = gdcarnet_getPrevalFlagValue ();
	
	$current_url = sanitize_text_field($_SERVER['REQUEST_URI']);
	if (strpos($current_url, '/wp-json/') !== false ) return;	// Si dans l'interface d'admin en cours de JSON => on retourne tout de suite pour ne pas provoquer d'erreur JSON
	
	$current_user = wp_get_current_user();
	$select_num_pilote_query = $wpdb->prepare('SELECT id, actif, niveau_admin FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE user_login=%s', $current_user->user_login);
	$select_num_pilote = $wpdb->get_results($select_num_pilote_query);
	if ($select_num_pilote == null) {
		echo '<center><h2><font color="red">Veuillez vous identifier</font></h2></center>';
		return;
		}
	$pilote_id = $select_num_pilote[0]->id;
	
	if ($select_num_pilote[0]->actif == false) {
		echo '<center><h2><font color="red">Compte d&eacute;sactiv&eacute;, contactez votre administrateur</font></h2></center>';
		return;
		}
		
	// si le formulaire a été soumis, ajoute la note dans la base de données
    if (isset($_FILES['facture'])) {
		check_admin_referer('addNote' . $pilote_id);	// Vérification du nonce
		// Récupération et nettoyage des entrées
		if (isset($_POST['montant_note']))
			$montant_note = sanitize_text_field($_POST['montant_note']);
		else
			$montant_note = 0;
		if (!is_numeric($montant_note))
			$montant_note = 0;
		if (isset($_POST['motif_note']))
			$motif_note = sanitize_text_field($_POST['motif_note']);
		else
			$motif_note = '';
		if ($_FILES['facture'] != null)
				$nom_facture = sanitize_file_name($_FILES['facture']['name']);
			else
				$nom_facture = null;
			$wpdb->insert(
			$wpdb->prefix .'gdcarnet_table_frais',
				array(
					'pilote' => $pilote_id,
					'description' => $motif_note,
					'valeur' => $montant_note,
					'valide' => $gdcarnet_autoAjoutNoteDeFrais
					)
				);
			$uploadFileId = $wpdb->insert_id;
			$wpdb->insert(
			$wpdb->prefix .'gdcarnet_table_fichiers',
				array(
					'clef_autre' => $uploadFileId,
					'table_autre' => $gdcarnet_tableNotesDeFrais,
					'fichier' => $nom_facture,
					'description' => 'Note de frais'
					)
				);
			$fileNewName = $wpdb->insert_id;
			$upload_dir   = wp_upload_dir(null, false);
			$monRepertoire = $upload_dir['basedir'] . '/carnetdevols/fichiers';
			$monFichier = $monRepertoire . '/' . $fileNewName;
			if (!is_dir($monRepertoire))
				mkdir ($monRepertoire);
//			move_uploaded_file($_FILES['facture']['tmp_name'], $monFichier);
			$result = wp_handle_upload( $_FILES['facture'], array('test_form' => false,));
			$uploaded_file_path = $result['file'];
			rename( $uploaded_file_path, $monFichier );

			if ($gdcarnet_autoAjoutNoteDeFrais) {	// Si ajout automatique de la note de frais au crédit du pilote, on le crédite.
					$wpdb->insert(
						$wpdb->prefix . 'gdcarnet_table_pilote_comptes',
						array(
							'id' => NULL,
							'pilote' => $pilote_id,
							'motif' => 'Remboursement de frais',
							'auteur' => $pilote_id,
							'credit' => $montant_note,
							'debit' => 0,
							'id_frais' => $fileNewName,
							'date' => current_time('mysql', 1)
							)
						);
					}
			echo '<br /><hr />Votre note a &eacute;t&eacute; enregistr&eacute;e, merci.<hr /><br />';
			if (get_option( 'gdcarnet_dest_notes' )) {
				$destinataire = get_option( 'gdcarnet_dest_notes' );
				$texteCourriel = "Nouvelle note de frais enregistrée :\n\n";
				$texteCourriel .= 'Pilote : ' . $current_user->display_name . "\n";
				$texteCourriel .= 'Montant : ' . $montant_note . "\n";
				$texteCourriel .= 'Motif : ' . $motif_note . "\n";
				$texteCourriel .= 'Fichier : ' . $nom_facture . "\n";
				$tempFile = $monRepertoire . '/' . $nom_facture;
				copy($monFichier, $tempFile);
				$resu_mail = wp_mail ($destinataire, 'Note de frais', $texteCourriel, '', $tempFile);
				unlink($tempFile);
				}
			return;
		}
?>
	<form method="post" action="" enctype="multipart/form-data" id="formNote" >
<?php	wp_nonce_field('addNote' . $pilote_id); ?>
		<table border="1">
			<tbody>
				<tr><td>Pilote&nbsp;: </td><td align="left"><?php echo esc_html($current_user->display_name); ?></td></tr>
				<tr><td>Montant de la note&nbsp;:</td><td align="left"><input type="number" step="0.01" id="montant_note" name="montant_note" /></td></tr>
				<tr><td>Motif / explications&nbsp;: </td><td align="left"><textarea id="motif_note" name="motif_note" rows=8 cols=45></textarea></td></tr>
				<tr><td>Facture / ticket (*)&nbsp;:</td><td align="center"><input type="file" id="facture" name="facture" required/></td></tr>
				<tr><td colspan=2 align="center"><input type="submit" id="submitNote"/><input type="button" id="attenteNote" value="Envoi en cours..." style="display:none;background:orange"/><br />(*) => champ obligatoire</td></tr>
			</tbody>
		</table>
    </form>
	<script>
    document.getElementById('formNote').addEventListener('submit', function() {
        document.getElementById('submitNote').disabled = true;
		document.getElementById('submitNote').style.display = 'none';
		document.getElementById('attenteNote').style.display = 'block';
    });
	</script>
<?php
}

// Affichage de la liste des notes de frais
function gdcarnet_liste_frais() {
	global $wpdb;
	global $gdcarnet_nbMaxAff;
	global $gdcarnet_tableNotesDeFrais;
	global $gdcarnet_niveauTresorier;
	$myOffset = 0;
	
	$current_url = sanitize_text_field($_SERVER['REQUEST_URI']);
	if (strpos($current_url, '/wp-json/') !== false ) return;	// Si dans l'interface d'admin en cours de JSON => on retourne tout de suite pour ne pas provoquer d'erreur JSON
	
	if (isset ($_GET['offset']))
		$offset = sanitize_text_field($_GET['offset']);
	else
		$offset = 0;
	if (!is_numeric($offset))
		$offset = 0;
	$prev = $offset + 1;
	$suiv = $offset - 1;

	if (isset($_GET['note'])) {
		$current_user = wp_get_current_user();
		$select_num_pilote_query = $wpdb->prepare('SELECT id, niveau_admin FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE user_login=%s', $current_user->user_login);
		$select_num_pilote = $wpdb->get_results($select_num_pilote_query);
		if ($select_num_pilote == null) {
			echo '<center><h2><font color="red">Veuillez vous identifier</font></h2></center>';
			return;
			}
		if (isset($_GET['swapto'])) {
			if (($select_num_pilote[0]->niveau_admin & $gdcarnet_niveauTresorier) != 0) {	// Vérification pour s'assurer que c'est bien un trésorier......
				if (!strcmp ($_GET['swapto'], 'oui')) {
					$prepareGetIdPilote = $wpdb->prepare('SELECT pilote, valeur, date_note FROM ' . $wpdb->prefix .'gdcarnet_table_frais WHERE id=%d', sanitize_text_field($_GET['note']));
					$leIdPilote = $wpdb->get_results($prepareGetIdPilote);
					$nouveauValide = true;
					$wpdb->insert(
						$wpdb->prefix . 'gdcarnet_table_pilote_comptes',
						array(
							'id' => NULL,
							'pilote' => $leIdPilote[0]->pilote,
							'motif' => 'Remboursement de frais',
							'auteur' => $select_num_pilote[0]->id,
							'credit' => $leIdPilote[0]->valeur,
							'debit' => 0,
							'id_frais' => sanitize_text_field($_GET['note']),
							'date' => $leIdPilote[0]->date_note
							)
						);
					}
				else {
					$wpdb->delete( $wpdb->prefix .'gdcarnet_table_pilote_comptes', array( 'id_frais' => sanitize_text_field($_GET['note'] )));
					$nouveauValide = false;
					}
				$wpdb->update(
					$wpdb->prefix .'gdcarnet_table_frais',
					array(
						'valide' => $nouveauValide
						),
					array(
						'id' => sanitize_text_field($_GET['note'])
						)
					);
				}
			}
		$prepareLaNote = $wpdb->prepare('SELECT ' . $wpdb->prefix .'gdcarnet_table_frais.id, date_note, nom_pilote, description, valeur, valide FROM ' . $wpdb->prefix .'gdcarnet_table_frais,  ' . $wpdb->prefix .'gdcarnet_table_pilote WHERE ' . $wpdb->prefix . 'gdcarnet_table_pilote.id=' . $wpdb->prefix .'gdcarnet_table_frais.pilote AND ' . $wpdb->prefix .'gdcarnet_table_frais.id=%d', sanitize_text_field($_GET['note']));
		$laNote = $wpdb->get_results($prepareLaNote);
		
		if ($laNote == null) {
			echo '<hr /><h1>ERREUR DE SAISIE</h1></hr>';
			exit;
			}
		
		$prepareLeFichier = $wpdb->prepare('SELECT id, fichier FROM ' . $wpdb->prefix .'gdcarnet_table_fichiers WHERE table_autre=%d AND clef_autre=%d', $gdcarnet_tableNotesDeFrais, sanitize_text_field($_GET['note']));
		$leFichier = $wpdb->get_results($prepareLeFichier);
		echo '<table border="1">';
		echo '<tbody>';
		echo '<tr><td>Date&nbsp;:</td><td>' . esc_html($laNote[0]->date_note) . '</td></tr>';
		echo '<tr><td>Pilote&nbsp;:</td><td>' . esc_html($laNote[0]->nom_pilote) . '</td></tr>';
		echo '<tr><td>Montant&nbsp;:</td><td>' . esc_html($laNote[0]->valeur) . '</td></tr>';
		echo '<tr><td>Motif&nbsp;:</td><td>' . esc_html($laNote[0]->description) . '</td></tr>';
		echo '<tr><td>Note&nbsp;:</td><td><a href="' . esc_url('?fichier_note=' . $leFichier[0]->id) . '">' . esc_html($leFichier[0]->fichier) . '</a></td></tr>';
		if (($select_num_pilote[0]->niveau_admin & $gdcarnet_niveauTresorier) != 0) {
			$monGetNote = sanitize_text_field($_GET['note']);
			if ($laNote[0]->valide)
				echo '<tr><td>Validit&eacute;&nbsp;:</td><td bgcolor="lightgreen"><a href="' . esc_url('?note=' . $monGetNote . '&swapto=non') . '">oui</a></td></tr>';
			else
				echo '<tr><td>Validit&eacute;&nbsp;:</td><td bgcolor="#ffaaaa"><a href="' . esc_url('?note=' . $monGetNote . '&swapto=oui') . '">non</a></td></tr>';
			}
		else {
			if ($laNote[0]->valide)
				echo '<tr><td>Validit&eacute;&nbsp;:</td><td bgcolor="lightgreen">oui</td></tr>';
			else
				echo '<tr><td>Validit&eacute;&nbsp;:</td><td bgcolor="#ffaaaa">non</td></tr>';
			}
		echo '</tbody>';
		echo '</table>';
		$actual_link = gdcarnet_get_debut_link();
		$resu = gdcarnet_get_start_uri();
		echo '<a href="' . esc_url($actual_link . $resu . '?offset=' . $offset) . '">Retour &agrave; la liste</a>';
		return;
		}

	$myOffset = $gdcarnet_nbMaxAff * $offset;
	$prepareListeFrais = $wpdb->prepare('SELECT ' . $wpdb->prefix .'gdcarnet_table_frais.id, date_note, nom_pilote, description, valeur, valide FROM ' . $wpdb->prefix .'gdcarnet_table_frais,  ' . $wpdb->prefix .'gdcarnet_table_pilote WHERE ' . $wpdb->prefix .'gdcarnet_table_pilote.id=' . $wpdb->prefix .'gdcarnet_table_frais.pilote ORDER BY date_note DESC LIMIT %d, %d', $myOffset, $gdcarnet_nbMaxAff);
	$listeFrais = $wpdb->get_results($prepareListeFrais);
	echo '<table border="1">';
	echo '<tbody>';
	$actual_link = gdcarnet_get_debut_link() . gdcarnet_get_start_uri() . '?offset=';
	echo '<tr><td colspan=4 align="left" style="border-right-style: hidden">&nbsp;&nbsp;<a href="' . esc_url($actual_link.$prev) . '">&lt;</a></td><td align="right" style="border-left-style: hidden">';
	if ($offset != 0) {
		echo '<a href="' . esc_url($actual_link.$suiv) . '">&gt;</a>&nbsp;&nbsp;';
		echo '<a href="' . esc_url($actual_link.'0') . '">&gt;&gt;</a>';
		}	
	echo '&nbsp;&nbsp;</td>';

	echo '<tr><td>Date</td><td>Pilote</td><td>Montant</td><td>Motif</td>';
	echo '<td>Valid&eacute;e&nbsp;?</td>';
	echo '</tr>';
	foreach ($listeFrais AS $unFrais) {
		echo '<tr>';
		echo '<td><a href="' . esc_url('?note=' . $unFrais->id . '&offset=' . $offset) . '">' . esc_html($unFrais->date_note) . '</a></td>';
		echo '<td>' . esc_html($unFrais->nom_pilote) . '</td>';
		echo '<td align="center">' . esc_html($unFrais->valeur) . '</td>';
		echo '<td>' . esc_html($unFrais->description) . '</td>';
		if ($unFrais->valide)
			echo '<td align="center" bgcolor="lightgreen">oui</td>';
		else
			echo '<td align="center" bgcolor="#ffaaaa">non</td>';
		echo '</tr>';
		}
	echo '</tbody>';
	echo '</table>';
}

function gdcarnet_menu() {
    // Ajoute une nouvelle page dans le menu d'administration
    add_menu_page(
        'Carnet de vols',    // Titre de la page
        'Carnet de vols ULM',    // Titre du menu
        'manage_options', // Niveau d'accès requis
        'carnet-de-vol', // Slug de la page
        'gdcarnet_plugin_page_content', // Fonction qui affiche le contenu de la page
        'dashicons-admin-plugins', // Icône du menu
        50 // Position dans le menu
    );
    
	// Ajoute une sous-page "Gestion des pilotes" à notre page du plugin
    add_submenu_page(
        'carnet-de-vol', // Slug de la page parente
        'Gestion des pilotes', // Titre de la sous-page
        'Gestion des pilotes', // Titre du menu
        'manage_options', // Niveau d'accès requis
        'carnet-plugin-settings', // Slug de la sous-page
        'gdcarnet_plugin_settings_gestion_pilotes' // Fonction qui affiche le contenu de la sous-page
    );
	
	// Ajoute une sous-page "Gestion de la liste des ULM" à notre page du plugin
    add_submenu_page(
        'carnet-de-vol', // Slug de la page parente
        'Gestion des ULM', // Titre de la sous-page
        'Gestion des ULM', // Titre du menu
        'manage_options', // Niveau d'accès requis
        'carnet-plugin-liste-ulm', // Slug de la sous-page
        'gdcarnet_settings_ulm' // Fonction qui affiche le contenu de la sous-page
    );
	
	// Ajoute une sous-page "Gestion de la liste des motifs des comptes" à notre page du plugin
    add_submenu_page(
        'carnet-de-vol', // Slug de la page parente
        'Gestion des messages des comptes', // Titre de la sous-page
        'Gestion des messages des comptes', // Titre du menu
        'manage_options', // Niveau d'accès requis
        'carnet-plugin-liste-messages-comptes', // Slug de la sous-page
        'gdcarnet_settings_messages_comptes' // Fonction qui affiche le contenu de la sous-page
    );
}

// Gestion de la liste des ULMs (en back office)
function gdcarnet_settings_ulm() {
	global $wpdb;
	$prepareUpdate = false;
	if (isset ($_POST['id'])) {
		$numid = sanitize_text_field($_POST['id']);
		if (!is_numeric($numid))
			return;
		$varNonce = 'gestULM'. $numid;
		check_admin_referer($varNonce);	// Vérification du nonce
		$immatriculation = sanitize_text_field($_POST['immatriculation']);
		$modele = sanitize_text_field($_POST['modele']);
		$tarif_heure = sanitize_text_field($_POST['tarif_heure']);
		$remarques = sanitize_text_field($_POST['remarques']);
		$wpdb->update(
			$wpdb->prefix .'gdcarnet_table_ulm',
			array(
				'id' => $numid,
				'immatriculation' => $immatriculation,
				'modele' => $modele,
				'tarif_heure' => $tarif_heure,
				'remarques' => $remarques
				),
			array(
				'id' => $numid
				)
			);
		}
	else if (isset ($_POST['immatriculation'])) {
		$varNonce = 'gestULM';
		check_admin_referer($varNonce);	// Vérification du nonce
		$immatriculation = sanitize_text_field($_POST['immatriculation']);
		$modele = sanitize_text_field($_POST['modele']);
		$tarif_heure = sanitize_text_field($_POST['tarif_heure']);
		$remarques = sanitize_text_field($_POST['remarques']);
		$wpdb->insert(
			$wpdb->prefix .'gdcarnet_table_ulm',
			array(
				'id' => NULL,
				'immatriculation' => $immatriculation,
				'modele' => $modele,
				'tarif_heure' => $tarif_heure,
				'remarques' => $remarques
				)
			);
		}
	else if (isset ($_GET['flipflopactif'])) {
		$numid = sanitize_text_field($_GET['flipflopactif']);
		if (!is_numeric($numid))	// Si numid n'est pas numérique, on a un gros problème.....
			return;
		check_admin_referer('flipFlopURL_'.$numid);	// Vérification du nonce
		$doFlipFlop = $wpdb->prepare('UPDATE ' . $wpdb->prefix .'gdcarnet_table_ulm SET actif = NOT actif WHERE id=%d', $numid);
		$wpdb->query($doFlipFlop);
		}
	else if (isset ($_GET['ulm'])) {
		$prepareUpdate = true;
		$numid = sanitize_text_field($_GET['ulm']);
		if (!is_numeric($numid))	// Si numid n'est pas numérique, on a un gros problème.....
			return;
		$edit_ulm_actif_query = $wpdb->prepare('SELECT id, immatriculation, modele, tarif_heure, remarques FROM ' . $wpdb->prefix .'gdcarnet_table_ulm WHERE id=%d', $numid);
		$edit_ulm = $wpdb->get_results($edit_ulm_actif_query);
		}
	// Pas de paramètre => pas besoin de prepare
	$liste_ulm = $wpdb->get_results( 'SELECT id, immatriculation, modele, actif, tarif_heure, remarques FROM ' . $wpdb->prefix .'gdcarnet_table_ulm ORDER BY modele ASC');
?>
	<h1>Gestion des ULM</h1>
	<hr />
	<table border="1">
			<tbody>
				<tr><td>Immatriculation</td><td>Mod&egrave;le</td><td>Actif</td><td>Prix &agrave; l'heure</td><td>Remarques</td><td>id</td></tr>
<?php
	$actual_link = gdcarnet_get_link_avec_page(sanitize_text_field($_GET['page']));
	foreach ($liste_ulm as $un_ulm) {
		$flipFlopURL = $actual_link . '&flipflopactif=' . $un_ulm->id;
		$complete_url = wp_nonce_url($flipFlopURL, 'flipFlopURL_'.$un_ulm->id);
		echo '<tr><td><a href=' . esc_url($actual_link . '&ulm=' . $un_ulm->id) . '>' . esc_html($un_ulm->immatriculation) . '</a></td><td>' . esc_html($un_ulm->modele) . '</td><td bgcolor="' . (($un_ulm->actif == 1) ? 'lightgreen' : '#FF6666') . '"><a href="' . esc_url($complete_url) . '" />' . (($un_ulm->actif == 1) ? 'oui' : 'non') . '</a></td><td align="right">' . esc_html($un_ulm->tarif_heure) . '&nbsp;&euro;</td><td>' . esc_html($un_ulm->remarques) . '</td><td>' . esc_html($un_ulm->id) . '</td></tr>';
		}
?>
			</tbody>
		</table>
		<hr />
<?php
	$varNonce = 'gestULM';
	if ($prepareUpdate) {
		echo 'Modifier un ULM&nbsp;:<br />';
		$varNonce .= $edit_ulm[0]->id;
		}
	else {
		echo 'Ajouter un ULM&nbsp;:<br />';
		}
?>
		<form method="post" action="">
<?php	wp_nonce_field($varNonce); ?>
		<table border="1">
			<tbody>
				<tr><td>Immatriculation</td><td>Mod&egrave;le</td><td>Prix &agrave; l'heure</td><td>Remarques</td><td></td></tr>
				<tr>
<?php
	if ($prepareUpdate) {
		echo '<td><input type="hidden" name="id" id="id" value="' . esc_html($edit_ulm[0]->id) . '" /><input type="text" name="immatriculation" id="immatriculation" value="' . esc_html($edit_ulm[0]->immatriculation) . '"/></td>';
		echo '<td><input type="text" name="modele" id = "modele" value="' . esc_html($edit_ulm[0]->modele) . '"/></td>';
		echo '<td><input type="text" name="tarif_heure" id = "tarif_heure" value="' . esc_html($edit_ulm[0]->tarif_heure) . '"/></td>';
		echo '<td><input type="text" name="remarques" id="remarques" value="' . esc_html($edit_ulm[0]->remarques) . '"/></td>';
		echo '<td><input type="submit" value="Enregistrer" /></td>';
		}
	else {
?>
					<td><input type="text" name="immatriculation" id="immatriculation" /></td>
					<td><input type="text" name="modele" id = "modele" /></td>
					<td><input type="text" name="tarif_heure" id = "tarif_heure" /></td>
					<td><input type="text" name="remarques" id="remarques" /></td>
					<td><input type="submit" value="Enregistrer" /></td>
<?php
	}
?>
				</tr>
			</tbody>
		</table>
<?php
}

// Gestion des pilotes en back office
function gdcarnet_plugin_settings_gestion_pilotes() {
    // Affiche le contenu de la sous-page
	global $wpdb;
	global $gdcarnet_niveauTresorier;
	global $gdcarnet_niveauListAll;
	global $gdcarnet_niveauMecano;
	global $gdcarnet_nombreMoisPrec;
    
    echo '<h1>Gestion des pilotes</h1>';
	$editPilote = false;
	$niveau_pilote = 0;
	if (isset ($_POST['pilote'])) {
		// Récupération / nettoyage des entrées
        $id_pilote = sanitize_text_field($_POST['id_pilote']);
		check_admin_referer('addVol' . $id_pilote);	// Vérification du nonce
		if (isset($_POST['actif']))
			$actif = true;
		else
			$actif = false;
		$pilote = sanitize_text_field($_POST['pilote']);
		$nom_pilote = sanitize_text_field($_POST['nom_pilote']);
		$brevet = sanitize_text_field($_POST['brevet']);
		$mensualite = sanitize_text_field($_POST['mensualite']);
		if (!is_numeric($mensualite)) $mensualite = 0;
		$jour_mensualite = sanitize_text_field($_POST['jour_mensualite']);
		if (!is_numeric($jour_mensualite)) $jour_mensualite = 5;
		// Vérification que la valeur du jour est dans le ENUM
		switch ($jour_mensualite) {
			case 1 :
			case 5 :
			case 10 :
			case 15 :
			case 20 :
			case 25 :
				break;
			default :
				$jour_mensualite = 5;
			}
		if (isset($_POST['lache'])) {
			$lache = true;
			$date_lache = sanitize_text_field($_POST['date_lache']);
			}
		else {
			$lache = false;
			$date_lache = '0000-00-00';
			}
		if (isset($_POST['emport'])) {
			$emport = true;
			$date_emport = sanitize_text_field($_POST['date_emport']);
			}
		else {
			$emport = false;
			$date_emport = '0000-00-00';
			}
		if (isset($_POST['listall']))
			$niveau_pilote |= $gdcarnet_niveauListAll;
		if (isset($_POST['tresorier']))
			$niveau_pilote |= $gdcarnet_niveauTresorier | $gdcarnet_niveauListAll;	// Un trésorier voit forcément tous les comptes
		if (isset($_POST['volmecano']))
			$niveau_pilote |= $gdcarnet_niveauMecano;
		$remarques = sanitize_textarea_field($_POST['remarques']);

		$wpdb->update(
			$wpdb->prefix .'gdcarnet_table_pilote',
			array(
				'nom_pilote' => $nom_pilote,
				'actif' => $actif,
				'mensualite' => $mensualite,
				'jour_mensualite' => $jour_mensualite,
				'brevet' => $brevet,
				'lache' => $lache,
				'date_lache' => $date_lache,
				'emport' => $emport,
				'date_emport' => $date_emport,
				'niveau_admin' => $niveau_pilote,
				'remarques' => $remarques
				),
			array(
				'user_login' => $pilote
				)
			);
		$wpdb->delete( $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache', array( 'pilote' => $id_pilote ));
		if (isset($_POST['ulm'])) {
			$nbULM = 0;
			foreach ($_POST['ulm'] as $selectedOption) {
				$nbULM++;
				if ($nbULM > 100)	// Not more than 100 ULMs (obviously....)
					break;
				$wpdb->insert(
					$wpdb->prefix .'gdcarnet_table_pilote_ulm_lache',
					array(
						'id' => NULL,
						'pilote' => $id_pilote,
						'ulm' => sanitize_text_field($selectedOption),
						'date_lache' => current_time('mysql', 1)
						)
					);
				}
			}
		}
		else if (isset ($_GET['pilote']))
			$editPilote = true;
	$listePilotes = get_users();
	foreach ($listePilotes as $unPilote) {
		$select_un_pilote_query = $wpdb->prepare('SELECT id FROM ' . $wpdb->prefix .'gdcarnet_table_pilote WHERE user_login=%s', $unPilote->user_login);
		$select_un_pilote = $wpdb->get_results($select_un_pilote_query);
		if ($select_un_pilote == null) { // Si on n'a pas encore un carnet de vols pour cet utilisateur
				$table_name = $wpdb->prefix .'gdcarnet_table_pilote';
				$wpdb->insert(
					$table_name,
					array(
						'id' => NULL,
						'user_login' => $unPilote->user_login,
						'nom_pilote' => $unPilote->display_name,
						'mensualite' => get_option('mensualite_std', 0),
						'lache' => 0,
						'remarques' => "Creation automatique"
						)
					);
				$pilote_id = $wpdb->insert_id;
			}
		}
		// Pas de paramètre => pas besoin de prepare
		$liste_pilotes = $wpdb->get_results( 'SELECT id, actif, user_login, nom_pilote, mensualite, jour_mensualite, brevet, lache, date_lache, emport, date_emport, niveau_admin, remarques FROM ' . $wpdb->prefix .'gdcarnet_table_pilote ORDER BY nom_pilote ASC');
?>
<script type='text/javascript'>
function clicSurLache (lacheCheck) {
	if (lacheCheck.checked)
		document.getElementById("date_lache").required = true;
	else
		document.getElementById("date_lache").required = false;
}

function clicSurEmport (emportCheck) {
	if (emportCheck.checked)
		document.getElementById("date_emport").required = true;
	else
		document.getElementById("date_emport").required = false;
}

</script>
<hr />
	<table border="1">
			<tbody>
				<tr><td>Compte</td><td>Actif</td><td>Nom pilote</td><td>Mensualit&eacute;</td><td>Brevet</td><td>Date l&acirc;ch&eacute;</td><td>Date emport</td><td>Remarques</td><td>ULM(s)</td><td>Niveau</td><td>Minutes mois en cours</td>
<?php
if ($gdcarnet_nombreMoisPrec >= 2)
	echo '<td>Minutes mois pr&eacute;c&eacute;dent</td>';
if ($gdcarnet_nombreMoisPrec == 3)
	echo '<td>Minutes il y a 2 mois</td>';
?>
				</tr>
<?php
$actual_link = gdcarnet_get_link_avec_page(sanitize_text_field($_GET['page']));
foreach ($liste_pilotes as $un_pilote) {
		if (!is_numeric($un_pilote->id)) {
			echo '<center><h1>ERREUR SYSTEME</h1></center>';
			return;
			}
		$ulm_lache_query = $wpdb->prepare('SELECT ' . $wpdb->prefix .'gdcarnet_table_ulm.immatriculation, ' . $wpdb->prefix .'gdcarnet_table_ulm.modele, ' . $wpdb->prefix .'gdcarnet_table_ulm.actif, ' . $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache.date_lache FROM ' . $wpdb->prefix .'gdcarnet_table_ulm, ' . $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache WHERE ' . $wpdb->prefix .'gdcarnet_table_ulm.id=' . $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache.ulm AND ' . $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache.pilote=%d', $un_pilote->id);
		$ulm_lache = $wpdb->get_results($ulm_lache_query);
		echo '<tr><td><a href=' . esc_url($actual_link . '&pilote=' . $un_pilote->id) . '>' . esc_html($un_pilote->user_login) . '</a></td><td align="center" bgcolor="' . (($un_pilote->actif == 1) ? 'lightgreen' : '#FF6666') . '">' . (($un_pilote->actif == 1) ? 'oui' : 'non') . '</td><td>' . esc_html($un_pilote->nom_pilote) . '</td><td align="center">' . esc_html($un_pilote->mensualite) . ' (' . esc_html($un_pilote->jour_mensualite) . ')</td><td>' . esc_html($un_pilote->brevet) . '</td><td align="center" bgcolor="' . (($un_pilote->lache == 1) ? 'lightgreen' : '#FF6666') . '">' . (($un_pilote->lache == 1) ? esc_html($un_pilote->date_lache) : 'n/a') . '</td><td align="center" bgcolor="' . (($un_pilote->emport == 1) ? 'lightgreen' : '#FF6666') . '">' . (($un_pilote->emport == 1) ? esc_html($un_pilote->date_emport) : 'n/a') . '</td><td>'. esc_html($un_pilote->remarques) . '</td><td>';
		$num_ulm = 0;
		foreach ($ulm_lache as $un_ulm) {
			if ($num_ulm != 0) echo '<br />';
			echo esc_html($un_ulm->immatriculation . ' ' . $un_ulm->modele . ' (' . $un_ulm->date_lache . ')');
			$num_ulm++;
			}
		echo '</td>';
		if (!is_numeric($un_pilote->id)) {
			echo '<center><h1>ERREUR SYSTEME</h1></center>';
			return;
			}
		// $un_pilote->id provient d'un query => pas besoin de prepare (en plus on a vérifié qu'il est bien numérique)
		$monPiloteId = $un_pilote->id;
		if (!is_numeric($monPiloteId)) {
			echo '<center><h1>ERREUR SYSTEME</h1></center>';
			return;
			}
		$query_pilote_minutes_mois_en_cours = $wpdb->prepare('SELECT SUM(minutes_de_vol) AS minutes FROM ' . $wpdb->prefix .'gdcarnet_table_vols WHERE pilote=%d AND MONTH(date_vol) = MONTH(now()) AND YEAR(date_vol) = YEAR(now())', $monPiloteId);
		$pilote_minutes_mois_en_cours = $wpdb->get_results($query_pilote_minutes_mois_en_cours);
		if ($pilote_minutes_mois_en_cours == null) {
			$tot_minutes_cour = 0;
			}
		else if ($pilote_minutes_mois_en_cours[0]->minutes == null) {
			$tot_minutes_cour = 0;
			}
		else {
			$tot_minutes_cour = $pilote_minutes_mois_en_cours[0]->minutes;
			}
		$query_pilote_minutes_mois_precedent = $wpdb->prepare('SELECT SUM(minutes_de_vol) AS minutes FROM ' . $wpdb->prefix .'gdcarnet_table_vols WHERE pilote=%d AND MONTH(date_vol) = MONTH(DATE_ADD(NOW(),INTERVAL -1 MONTH)) AND YEAR(date_vol) = YEAR(DATE_ADD(NOW(),INTERVAL -2 MONTH))', $monPiloteId);
		$pilote_minutes_mois_precedent = $wpdb->get_results($query_pilote_minutes_mois_precedent);
		if ($pilote_minutes_mois_precedent == null) {
			$tot_minutes_prec = 0;
			}
		else if ($pilote_minutes_mois_precedent[0]->minutes == null) {
			$tot_minutes_prec = 0;
			}
		else {
			$tot_minutes_prec = $pilote_minutes_mois_precedent[0]->minutes;
			}
		
		$query_pilote_minutes_il_y_a_deux_mois = $wpdb->prepare('SELECT SUM(minutes_de_vol) AS minutes FROM ' . $wpdb->prefix .'gdcarnet_table_vols WHERE pilote=%d AND MONTH(date_vol) = MONTH(DATE_ADD(NOW(),INTERVAL -2 MONTH)) AND YEAR(date_vol) = YEAR(DATE_ADD(NOW(),INTERVAL -3 MONTH))', $monPiloteId);
		$pilote_minutes_il_y_a_deux_mois = $wpdb->get_results($query_pilote_minutes_il_y_a_deux_mois);
		if ($pilote_minutes_il_y_a_deux_mois == null) {
			$tot_minutes_2_mois_prec = 0;
			}
		else if ($pilote_minutes_il_y_a_deux_mois[0]->minutes == null) {
			$tot_minutes_2_mois_prec = 0;
			}
		else {
			$tot_minutes_2_mois_prec = $pilote_minutes_il_y_a_deux_mois[0]->minutes;
			}
		
		$niveau = '';
		if ($un_pilote->niveau_admin != 0) {
			$nbNiveaux = 0;
			if (($un_pilote->niveau_admin & $gdcarnet_niveauListAll) != 0) {
				$niveau .= 'Liste comptes';
				$nbNiveaux++;
				}
			if (($un_pilote->niveau_admin & $gdcarnet_niveauTresorier) != 0) {
				if ($nbNiveaux++ > 0)
					$niveau .= '<br />';
				$niveau .= 'Tr&eacute;sorier';
				$nbNiveaux++;
				}
			if (($un_pilote->niveau_admin & $gdcarnet_niveauMecano) != 0) {
				if ($nbNiveaux++ > 0)
					$niveau .= '<br />';
				$niveau .= 'Vol m&eacute;cano';
				$nbNiveaux++;
				}
			}
		echo '<td align=center>' . wp_kses_post($niveau) . '</td>';
		echo '<td align=center>' . esc_html($tot_minutes_cour) . '</td>';
		if ($gdcarnet_nombreMoisPrec >= 2)
			echo '<td align=center>' . esc_html($tot_minutes_prec) . '</td>';
		if ($gdcarnet_nombreMoisPrec == 3)
			echo '<td align=center>' . esc_html($tot_minutes_2_mois_prec) . '</td>';
		echo '</tr>';
		}
		
?>
			</tbody>
		</table>
		<hr />
		<hr />
<?php
if ($editPilote) {
		echo '<form method="post" action="">';
		wp_nonce_field('addVol' . sanitize_text_field($_GET['pilote']));
		echo '<table border="0">';
		echo '<tbody>';
		$select_edit_pilote_query = $wpdb->prepare('SELECT id, actif, mensualite, jour_mensualite, user_login, nom_pilote, brevet, lache, date_lache, emport, date_emport, niveau_admin, remarques FROM ' . $wpdb->prefix .'gdcarnet_table_pilote WHERE id=%d', sanitize_text_field($_GET['pilote']));
		$select_edit_pilote = $wpdb->get_results($select_edit_pilote_query);
		$select_liste_ulm = $wpdb->get_results( 'SELECT id, immatriculation, modele, actif FROM ' . $wpdb->prefix .'gdcarnet_table_ulm');
		$select_liste_lache_query = $wpdb->prepare('SELECT ulm, date_lache FROM ' . $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache WHERE pilote=%d', sanitize_text_field($_GET['pilote']));
		$select_liste_lache = $wpdb->get_results($select_liste_lache_query);
?>
				<tr><td>Compte&nbsp;: </td><td align="left"><?php echo esc_html($select_edit_pilote[0]->user_login); ?><input type="hidden" id="pilote" name="pilote" value="<?php echo esc_html($select_edit_pilote[0]->user_login); ?>"  /><input type="hidden" id="id_pilote" name="id_pilote" value="<?php echo esc_html($select_edit_pilote[0]->id); ?>"  /></td></tr>
				<tr><td>Nom du pilote&nbsp;: </td><td align="left"><input type="text" id="nom_pilote" name="nom_pilote" value="<?php echo esc_html ($select_edit_pilote[0]->nom_pilote); ?>" required /></td></tr>
				<tr><td>Actif&nbsp;? </td><td align="left"><input type="checkbox" id="actif" name="actif" <?php echo ($select_edit_pilote[0]->actif == 1)? 'checked' : ''; ?> /></td></tr>
				<tr><td>Mensualit&eacute; automatique&nbsp;&nbsp;: </td><td align="left"><input type="text" id="mensualite" name="mensualite" value="<?php echo esc_html($select_edit_pilote[0]->mensualite); ?>" /></td></tr>
				<tr><td>Jour de la mensualit&eacute; automatique&nbsp;&nbsp;: </td><td align="left">
				<select id="jour_mensualite" name="jour_mensualite">
					<option value=1 <?php if ($select_edit_pilote[0]->jour_mensualite == 1) echo 'selected' ?>>1</option>
					<option value=5 <?php if ($select_edit_pilote[0]->jour_mensualite == 5) echo 'selected' ?>>5</option>
					<option value=10 <?php if ($select_edit_pilote[0]->jour_mensualite == 10) echo 'selected' ?>>10</option>
					<option value=15 <?php if ($select_edit_pilote[0]->jour_mensualite == 15) echo 'selected' ?>>15</option>
					<option value=20 <?php if ($select_edit_pilote[0]->jour_mensualite == 20) echo 'selected' ?>>20</option>
					<option value=25 <?php if ($select_edit_pilote[0]->jour_mensualite == 25) echo 'selected' ?>>25</option>
				</select>
				</td></tr>
				<tr><td>Brevet&nbsp;: </td><td align="left"><input type="text" id="brevet" name="brevet" value="<?php echo esc_html($select_edit_pilote[0]->brevet); ?>" /></td></tr>
				<tr><td>Lach&eacute;&nbsp;? </td><td align="left"><input type="checkbox" id="lache" name="lache" onclick="clicSurLache(this);" <?php echo ($select_edit_pilote[0]->lache == 1)? 'checked' : ''; ?> /></td></tr>
				<tr><td>Date du l&acirc;ch&eacute;&nbsp;: </td><td align="left"><input type="date" id="date_lache" name="date_lache" value="<?php echo esc_html($select_edit_pilote[0]->date_lache); ?>" <?php echo ($select_edit_pilote[0]->lache == 1)? 'required' : ''; ?> /></td></tr>
				<tr><td>Emport de passager&nbsp;? </td><td align="left"><input type="checkbox" id="emport" name="emport" onclick="clicSurEmport(this);" <?php echo esc_html($select_edit_pilote[0]->emport == 1)? 'checked' : ''; ?> /></td></tr>
				<tr><td>Date emport passager&nbsp;: </td><td align="left"><input type="date" id="date_emport" name="date_emport" value="<?php echo esc_html($select_edit_pilote[0]->date_emport); ?>" <?php echo ($select_edit_pilote[0]->emport == 1)? 'required' : ''; ?> /></td></tr>
				<tr><td>Remarques&nbsp;: </td><td align="left"><textarea id="remarques" name="remarques" rows=8 cols=45><?php echo esc_textarea($select_edit_pilote[0]->remarques); ?></textarea></td></tr>
				<tr><td>ULM(s)&nbsp;: </td><td align="left"><select id="ulm" name="ulm[]" multiple>
<?php
	foreach ($select_liste_ulm as $un_ulm) {
		$isSelected = '';
		foreach ($select_liste_lache as $un_lache) {
			if ($un_ulm->id == $un_lache->ulm)
				$isSelected = 'selected';
			}
		echo '<option value=' . esc_html($un_ulm->id) . ' ' . esc_html($isSelected) . '>' . esc_html($un_ulm->modele . ' ' . $un_ulm->immatriculation) . '</option>';
		}
?>
				</select></td></tr>
	<tr><td>Niveaux </td><td align="left"><input type="checkbox" id="listall" name="listall" <?php echo (($select_edit_pilote[0]->niveau_admin & $gdcarnet_niveauListAll) != 0)? 'checked' : ''; ?>>&nbsp;Liste comptes</td></tr>
	<tr><td> </td><td align="left"><input type="checkbox" id="tresorier" name="tresorier" <?php echo (($select_edit_pilote[0]->niveau_admin & $gdcarnet_niveauTresorier) != 0)? 'checked' : ''; ?>>&nbsp;Tr&eacute;sorier</td></tr>
	<tr><td> </td><td align="left"><input type="checkbox" id="volmecano" name="volmecano" <?php echo (($select_edit_pilote[0]->niveau_admin & $gdcarnet_niveauMecano) != 0)? 'checked' : ''; ?>>&nbsp;Vol m&eacute;cano</td></tr>
			<tr><td colspan=2 align="center"><input type="submit" value="Enregistrer" /></td></tr>
			</tbody>
		</table>
    </form>
<?php
	}
}

// Gestion de la liste des motifs des comptes
function gdcarnet_settings_messages_comptes () {
	global $wpdb;
	
	if (isset ($_POST['motif'])) {
		check_admin_referer('addMotif');	// Vérification du nonce
		$leMotif = sanitize_text_field($_POST['motif']);
		echo 'Ajout de ' . esc_html($leMotif) . '<hr />';
		$query_cherche_motif = $wpdb->prepare('SELECT id, motif FROM ' . $wpdb->prefix .'gdcarnet_table_motifs_comptes WHERE motif=%s', $leMotif);
		$select_motif = $wpdb->get_results($query_cherche_motif);
		if ($select_motif == null) {
			$wpdb->insert(
			$wpdb->prefix .'gdcarnet_table_motifs_comptes',
				array(
					'motif' => $leMotif
					)
				);
			}
		}
	if (isset ($_GET['flipflopactif'])) {
		$numid = sanitize_text_field($_GET['flipflopactif']);
		check_admin_referer('flipFlopURL_' . $numid);	// Vérification du nonce
		$doFlipFlop = $wpdb->prepare('UPDATE ' . $wpdb->prefix .'gdcarnet_table_motifs_comptes SET actif = NOT actif WHERE id=%d', $numid);
		$wpdb->query($doFlipFlop);
		}
	echo '<br /><br />Les motifs disponibles&nbsp;:<br /><ul style="list-style-type:circle;">';
	$liste_motifs = $wpdb->get_results('SELECT id, motif, actif FROM ' . $wpdb->prefix .'gdcarnet_table_motifs_comptes ORDER BY motif ASC');	// Pas de variable, pas besoin de prepare
	echo'<table border=1><tr><th>Motif</th><th>actif</th></tr>';
	
	$actual_link = gdcarnet_get_link_avec_page(sanitize_text_field($_GET['page']));

	foreach ($liste_motifs as $un_motif) {
		$flipFlopURL = $actual_link . '&flipflopactif=' . $un_motif->id;
		$complete_url = wp_nonce_url($flipFlopURL, 'flipFlopURL_' . $un_motif->id);
		if ($un_motif->actif)
			echo '<td>' . esc_html($un_motif->motif) . '</td><td bgcolor="lightgreen" align="center"><a href="' . esc_url($complete_url) . '" />oui</a></td></tr>';
		else
			echo '<td>' . esc_html($un_motif->motif) . '</td><td bgcolor="#FF6666" align="center"><a href="' . esc_url($complete_url) . '" />non</a></td></tr>';
		}
	echo '</table><hr />Ajouter un motif&nbsp;:';
	echo '<form method="post" action="">';
	wp_nonce_field('addMotif');
	echo '<input type="text" id="motif" name="motif" /><input type="submit" />';
	echo '</form>';
}

// Gestion menu général de gestion (backoffice)
function gdcarnet_plugin_page_content() {
	// Récupération du numéro de version officiel
	$plugin_data = get_plugin_data( __FILE__ );
	$versionActuelle = $plugin_data['Version'];
	
	$dayAutoRun = 5;
/*
	if (isset($_GET['doautorun'])) {
		check_admin_referer('DoAutoRun_'.$dayAutoRun);	// Vérification du nonce
		gdcarnet_do_ajoute_cotisations_mensuelles($dayAutoRun);
		}
*/
	
    // Affiche le contenu de la page
    echo '<h1>Gestion du carnet de vols</h1>';
    echo '<p>Plugin de gestion de carnets de vols multi-pilotes et multi-ULM</p>';

	if (get_option( 'mensualite_std' ))
		$mensualite_std = get_option( 'mensualite_std' );
	else
		$mensualite_std = 0;
	if (isset($_POST['dest_notes'])) {
		check_admin_referer('ParamGeneraux');	// Vérification du nonce
		$dest_email = filter_var($_POST['dest_notes'], FILTER_SANITIZE_EMAIL);
		if (get_option( 'gdcarnet_dest_notes' ))
			update_option('gdcarnet_dest_notes', $dest_email);
		else {
			delete_option( 'gdcarnet_dest_notes' );
			add_option('gdcarnet_dest_notes', $dest_email);
			}
		if (isset($_POST['prevalidation']))
			$prevalidation = 1;
		else
			$prevalidation = 0;
		if (isset($_POST['mensualite_std']))
			$mensualite_std = sanitize_text_field($_POST['mensualite_std']);
		else
			$mensualite_std = 0;
		if (!is_numeric($mensualite_std))
			$mensualite_std = 0;
		if (!is_numeric($mensualite_std)) $mensualite_std = 0;
		if (get_option( 'mensualite_std' ))
			update_option('mensualite_std', $mensualite_std);
		else {
			delete_option( 'mensualite_std' );
			add_option('mensualite_std', $mensualite_std);
			}
		}
	else {
		$prevalidation = gdcarnet_getPrevalFlagValue ();
		}
	if (get_option( 'gdcarnet_dest_notes' ))
		$destinataire = get_option( 'gdcarnet_dest_notes' );
	else
		$destinataire = '';
	
	$getPreval = get_option( 'gdcarnet_prevalidation' );
	if (($getPreval == 0) || ($getPreval == 1)) {
		update_option('gdcarnet_prevalidation', $prevalidation);
		}
	else {
		delete_option( 'gdcarnet_prevalidation' );
		add_option('gdcarnet_prevalidation', $prevalidation);
		}
	if ($prevalidation)
		$is_checked = ' checked';
	else
		$is_checked = '';
	
	echo '<h2>Version du plugin&nbsp;: ' . esc_html($versionActuelle) . '</h2><hr />';
    echo '<h1>Réglages g&eacute;n&eacute;raux</h1><hr />';

	echo '<form method="post" action="">';
	wp_nonce_field('ParamGeneraux');
	echo '<table>';
	echo '<tr><td>Pr&eacute;-validation des notes de frais&nbsp;?</td><td><input type="checkbox" id="prevalidation" name="prevalidation"' . esc_html($is_checked) . ' /></td></tr>';
	echo '<tr><td>Destinataire des notes de frais&nbsp;:</td><td><input type="text" id="dest_notes" name="dest_notes" value="' . esc_html($destinataire) . '" /></td></tr>';
	echo '<tr><td>Montant de mensualit&eacute; par d&eacute;faut&nbsp;:</td><td><input type="number" id="mensualite_std" name="mensualite_std" value="' . esc_html($mensualite_std) . '" /></td></tr>';
	echo '<tr><td colspan=2><input type="submit" value="Enregistrer" /></td></tr>';
	echo '</table></form>';
	
	$actual_link = gdcarnet_get_link_avec_page(sanitize_text_field($_GET['page']));
	$actual_link .= '&doautorun=yes';
	$actual_link .= wp_nonce_url($actual_link, 'DoAutoRun_'.$dayAutoRun);
//	echo '<hr /><a href="' . $actual_link . '">Do run</a><hr />';
	
	$resuLast = get_option('gdcarnet_last_auto_exec');
	if (!$resuLast)
		$resuLast = 'none';
	echo '<hr />Last auto run : ' . $resuLast . '<hr />';
	
	$resuLast = get_option('gdcarnet_last_auto_credit');
	if (!$resuLast)
		$resuLast = 'none';
	echo 'Last auto credit : ' . $resuLast . '<hr />';
	
	if (! wp_next_scheduled ( 'gdcarnet_cotisations_planifiees' )) {
		$timestamp_suivant_premier_jour = strtotime( 'Tomorrow 01:00:00' );	// Tous les jours à partir de demain
		wp_schedule_event( $timestamp_suivant_premier_jour, 'daily', 'gdcarnet_cotisations_planifiees' );
		add_action( 'gdcarnet_cotisations_planifiees', 'gdcarnet_ajoute_cotisations_mensuelles' );
		}
	$resuNext = wp_next_scheduled ( 'gdcarnet_cotisations_planifiees' );
	echo 'Next auto run : ' . date ('d/m/Y H:i', $resuNext) . '<hr />';
}

function gdcarnet_getPrevalFlagValue () {
global $gdcarnet_autoAjoutNoteDeFrais;

$getPreval = get_option( 'gdcarnet_prevalidation' );
if (($getPreval == 0) || ($getPreval == 1))
	$prevalidation = $getPreval;
else
	$prevalidation = $gdcarnet_autoAjoutNoteDeFrais;
return $prevalidation;
}

// Gestion de l'ajout d'un entretien d'ULM dans la base
function gdcarnet_ajoute_entretien() {
	global $wpdb;
	
	$current_url = sanitize_text_field($_SERVER['REQUEST_URI']);
	if (strpos($current_url, '/wp-json/') !== false ) return;	// Si dans l'interface d'admin en cours de JSON => on retourne tout de suite pour ne pas provoquer d'erreur JSON
	
	$current_user = wp_get_current_user();
	$select_num_pilote_query = $wpdb->prepare('SELECT id, actif FROM ' . $wpdb->prefix .'gdcarnet_table_pilote WHERE user_login=%s', $current_user->user_login);
	$select_num_pilote = $wpdb->get_results($select_num_pilote_query);
	if ($select_num_pilote == null) {
		echo '<center><h2><font color="red">Veuillez vous identifier</font></h2></center>';
		return;
		}
	$pilote_id = $select_num_pilote[0]->id;
	
	if ($select_num_pilote[0]->actif == false) {
		echo '<center><h2><font color="red">Compte d&eacute;sactiv&eacute;, contactez votre administrateur</font></h2></center';
		return;
		}
		
// si le formulaire a été soumis, ajoute la transaction dans la base de données
    if (isset($_POST['mecanicien']) && isset($_POST['objet']) && isset($_POST['nature'])) {
		check_admin_referer('addEntretien' . $pilote_id);	// Vérification du nonce
		// Récupération / nettoyage des entrées
        $date_entretien = sanitize_text_field($_POST['date_entretien']);
		$ulm = sanitize_text_field($_POST['ulm']);
		$mecanicien = sanitize_text_field($_POST['mecanicien']);
        $horametre_debut = floatval(sanitize_text_field(str_replace(',' , '.', $_POST['horametre_debut'])));
		$horametre_fin = floatval(sanitize_text_field(str_replace(',' , '.', $_POST['horametre_fin'])));
		$objet = sanitize_textarea_field($_POST['objet']);
		$nature = sanitize_textarea_field($_POST['nature']);
		$reste = sanitize_textarea_field($_POST['reste']);
		$resultat = sanitize_textarea_field($_POST['resultat']);
		
		$heures_horametre_depart = (int)$horametre_debut;
		$minutes_horametre_depart = round(($horametre_debut - $heures_horametre_depart) * 100);
		$horadepart = (($heures_horametre_depart * 60) + $minutes_horametre_depart) * 60;

		$heures_horametre_arrivee = (int)$horametre_fin;
		$minutes_horametre_arrivee = round(($horametre_fin - $heures_horametre_arrivee) * 100);
		$horaarrivee = (($heures_horametre_arrivee * 60) + $minutes_horametre_arrivee) * 60;
		
		$duree_de_vol = ($horaarrivee - $horadepart) / 60;
		
		// Vérifications
		$aff_erreur = false;
		$msg_erreur = 'Erreur dans le formulaire&nbsp;:<br />';
		// Format de date
		$tab_date_vol = explode("-",$date_entretien);
		if (!checkdate($tab_date_vol[1], $tab_date_vol[2], $tab_date_vol[0])) {
			$aff_erreur = true;
			$msg_erreur .= 'Date de l\'entretien invalide<br />';
			}
		// Ordre des relevés de l'horamètre
		if ($horadepart > $horaarrivee) {
			$aff_erreur = true;
			$msg_erreur .= 'Horam&egrave;tre &agrave; la fin inf&eacute;rieur &agrave; celui de d&eacute;but<br />';
			}
		
		if ($aff_erreur) {
			echo esc_html($msg_erreur);
			echo '<hr /><br /><a href="javascript:history.back()">Retour pour corriger</a>';
			exit;
			}
		else {
			if ($_FILES['facture'] != null)
				$nom_facture = sanitize_file_name($_FILES['facture']['name']);
			else
				$nom_facture = null;
			$wpdb->insert(
			$wpdb->prefix .'gdcarnet_table_entretien',
				array(
					'ulm' => $ulm,
					'date_reparation' => $date_entretien,
					'horametre_debut' => $horadepart,
					'horametre_fin' => $horaarrivee,
					'mecano' => $mecanicien,
					'objet' => $objet,
					'nature' => $nature,
					'reste' => $reste,
					'resultat' => $resultat,
					'saisi_par' => $pilote_id,
					'facture' => $nom_facture,
					'date_creation' => current_time('mysql', 1)
					)
				);
			$fileNewName = $wpdb->insert_id;
			$upload_dir   = wp_upload_dir(null, false);
			$monRepertoire = $upload_dir['basedir'] . '/carnetdevols';
			$monFichier = $monRepertoire . '/' . $fileNewName;
			if (!is_dir($monRepertoire))
				mkdir ($monRepertoire);
//			move_uploaded_file($_FILES['facture']['tmp_name'], $monFichier);
			$result = wp_handle_upload( $_FILES['facture'], array('test_form' => false,));
			$uploaded_file_path = $result['file'];
			rename( $uploaded_file_path, $monFichier );
			echo '<br /><hr /><b>Votre saisie a &eacute;t&eacute; enregistr&eacute;e, merci.</b><hr /><br />';
			}
    }
// affiche le formulaire d'ajout d'un entretien
$liste_ulm_lache_actif_query = $wpdb->prepare('SELECT ' . $wpdb->prefix .'gdcarnet_table_ulm.id, immatriculation, modele FROM ' . $wpdb->prefix .'gdcarnet_table_ulm, ' . $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache, ' . $wpdb->prefix .'gdcarnet_table_pilote WHERE ' . $wpdb->prefix .'gdcarnet_table_ulm.id=' . $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache.ulm AND ' . $wpdb->prefix .'gdcarnet_table_ulm.actif=true AND ' . $wpdb->prefix .'gdcarnet_table_pilote_ulm_lache.pilote=' . $wpdb->prefix .'gdcarnet_table_pilote.id AND ' . $wpdb->prefix .'gdcarnet_table_pilote.user_login=%s', $current_user->user_login);
$liste_ulm_lache_actif = $wpdb->get_results($liste_ulm_lache_actif_query);
$nbULM = 0;
foreach ($liste_ulm_lache_actif as $un_ulm) {
	if (!is_numeric($un_ulm->id)) {
		echo '<center><h1>ERREUR SYSTEME</h1></center>';
		return;
		}
	$query_dernier_vol_part3 = $wpdb->prepare('SELECT TIME_FORMAT(SEC_TO_TIME(' . $wpdb->prefix .'gdcarnet_table_vols.horametre_arrivee), "ForMatHour") AS hora_end, carburant_arrivee, terrain_arrivee_oaci FROM ' . $wpdb->prefix .'gdcarnet_table_vols WHERE ulm=%d ORDER BY horametre_arrivee DESC LIMIT 1', $un_ulm->id);
	$query_dernier_vol_part3 = str_replace('ForMatHour', '%H.%i', $query_dernier_vol_part3);
	$dernier_vol = $wpdb->get_results($query_dernier_vol_part3);
	$nbULM++;
	$tab_ulm_id[] = $un_ulm->id;
	if ($dernier_vol == null) {
		$tab_hora_end[] = 0;
		$tab_carburant_arrivee[] = 0;
		}
	else {
		$tab_hora_end[] = $dernier_vol[0]->hora_end;
		$tab_carburant_arrivee[] = $dernier_vol[0]->carburant_arrivee;
		}
	}
	if ($nbULM == 0) {
		$tab_hora_end[] = 0;
		$tab_carburant_arrivee[] = 0;
		}
	
	echo "<script type='text/javascript'>\n";
	echo 'let tab_ulm_id = [ ' . esc_html($tab_ulm_id[0]);
	for ($i = 1; $i < $nbULM; $i++)
		echo ', ' . esc_html($tab_ulm_id[$i]);
	echo " ];\n";
	echo 'let tab_hora_end = [ ' . esc_html($tab_hora_end[0]);
	for ($i = 1; $i < $nbULM; $i++)
		echo ', ' . esc_html($tab_hora_end[$i]);
	echo " ];\n";
	echo 'let tab_carburant_arrivee = [ ' . esc_html($tab_carburant_arrivee[0]);
	for ($i = 1; $i < $nbULM; $i++)
		echo ', ' . esc_html($tab_carburant_arrivee[$i]);
	echo " ];\n";
	echo "function onUlmChange (ulmSelect) {\n";
	echo 'for (numULM = 0; numULM < ' . esc_html($nbULM) . '; numULM++) {' . "\n";
	echo 'if (tab_ulm_id[numULM] == ulmSelect.value) {' . "\n";
	echo 'document.getElementById("horametre_debut").value=tab_hora_end[numULM]' . "\n";
	echo "}\n";
	echo "}\n";
	echo "}\n";
	echo "</script>\n";
    ?>
    <form method="post" action="" enctype="multipart/form-data" id="formEntretien">
<?php	wp_nonce_field('addEntretien' . $pilote_id); ?>
		<table border="0">
			<tbody>
				<tr><td>Pilote&nbsp;: </td><td align="left"><?php echo esc_html($current_user->display_name); ?></td></tr>
				<tr><td>Date de l'entretien&nbsp;: (*)</td><td align="left"><input type="date" id="date_entretien" name="date_entretien" value="<?php echo esc_html(date('Y-m-d')); ?>" required /></td></tr>
				<tr><td>ULM&nbsp;: (*)</td><td align="left">
				<select id="ulm" name="ulm" onchange="onUlmChange(this)">
<?php
	foreach ($liste_ulm_lache_actif as $un_ulm) {
		echo '<option value=' . esc_html($un_ulm->id) . '>' . esc_html($un_ulm->modele . ' ' . $un_ulm->immatriculation) . '</option>';
		}
?>
				</select>
				</td></tr>
				<tr><td>Mécanicien(s)&nbsp;: (*)</td><td align="left"><input type="text" id="mecanicien" name="mecanicien" required /></td></tr>
				<tr><td>Horam&egrave;tre au d&eacute;but&nbsp;: (*)</td><td align="left"><input type="number" step="0.01" id="horametre_debut" name="horametre_debut" value ="<?php echo esc_html($tab_hora_end[0]); ?>" required /></td></tr>
				<tr><td>Horam&egrave;tre &agrave; la fin&nbsp;: (*)</td><td align="left"><input type="number" step="0.01" id="horametre_fin" name="horametre_fin" required /></td></tr>
				<tr><td>Objet (pourquoi cet entretien / r&eacute;paration) (*)&nbsp;: </td><td align="left"><textarea id=objet" name="objet" rows=8 required></textarea></td></tr>
				<tr><td>Nature de l'entretien (ce qui a &eacute;t&eacute; fait) (*)&nbsp;: </td><td align="left"><textarea id=nature" name="nature" rows=8 required></textarea></td></tr>
				<tr><td>Reste &agrave; faire&nbsp;: </td><td align="left"><textarea id=reste" name="reste" rows=8></textarea></td></tr>
				<tr><td>R&eacute;sultat (*)&nbsp;:</td><td align="left"><textarea id=resultat" name="resultat" rows=8 required></textarea></td></tr>
				<tr><td>Facture(s) (.zip si plusieurs factures)&nbsp;:</td><td align="left"><input type="file" id="facture" name="facture" /></td></tr>
				<tr><td colspan=2 align="center"><input type="submit" id="submitEntretien" /><input type="button" id="attenteEnretien" value="Envoi en cours..." style="display:none;background:orange"/><br />(*) => champ obligatoire</td></tr>
			</tbody>
		</table>
    </form>
	<script>
    document.getElementById('formEntretien').addEventListener('submit', function() {
        document.getElementById('submitEntretien').disabled = true;
		document.getElementById('submitEntretien').style.display = 'none';
		document.getElementById('attenteEnretien').style.display = 'block';
    });
	</script>
    <?php
}

// Affichage du carnet d'entretien d'un ULM (paramètre 'ulm' avec ID de l'ULM)
function gdcarnet_display_entretien_ulm($atts = array(), $content = null, $tag = '') {
	global $gdcarnet_nbMaxAff;
	global $wpdb;
	$myOffset = 0;
	
	$current_url = sanitize_text_field($_SERVER['REQUEST_URI']);
	if (strpos($current_url, '/wp-json/') !== false ) return;	// Si dans l'interface d'admin en cours de JSON => on retourne tout de suite pour ne pas provoquer d'erreur JSON
	
	$type_aff=0;
	if (isset ($_GET['offset']))
			$offset = sanitize_text_field($_GET['offset']);
		else
			$offset = 0;
	if (!is_numeric($offset))
		$offset = 0;
	$prev = $offset + 1;
	$suiv = $offset - 1;
	$myOffset = $offset * $gdcarnet_nbMaxAff;
	
	$atts = array_change_key_case((array) $atts, CASE_LOWER );
	$numUlm = esc_html($atts['ulm']);
	
	if (isset ($_GET['entretien'])) {
		$type_carnet = 1;
		if (isset ($_GET['type_carnet']))
			$type_carnet = sanitize_text_field($_GET['type_carnet']);
		if (($type_carnet != 0) && ($type_carnet != 1))
			$type_carnet = 1;
		gdcarnet_display_un_entretien (sanitize_text_field($_GET['entretien']), $type_carnet);	// Cette fonction nettoie elle-même ses entrées
		return;
		}

    ?>
    <div class="wrap">
	<?php
	$entretiens_query = 
		$entretiens_query = $wpdb->prepare("SELECT " . $wpdb->prefix . "gdcarnet_table_entretien.id, date_reparation, TIME_FORMAT(SEC_TO_TIME(horametre_debut), 'ForMatHour2') AS hora_debut, TIME_FORMAT(SEC_TO_TIME(horametre_fin), 'ForMatHour2') AS hora_fin, mecano, objet, nature, reste, resultat, nom_pilote, facture, " . $wpdb->prefix . "gdcarnet_table_ulm.modele, " . $wpdb->prefix . "gdcarnet_table_ulm.immatriculation FROM " . $wpdb->prefix . "gdcarnet_table_entretien, " . $wpdb->prefix . "gdcarnet_table_pilote, " . $wpdb->prefix . "gdcarnet_table_ulm WHERE " . $wpdb->prefix . "gdcarnet_table_pilote.id=" . $wpdb->prefix . "gdcarnet_table_entretien.saisi_par AND " . $wpdb->prefix . "gdcarnet_table_ulm.id=" . $wpdb->prefix . "gdcarnet_table_entretien.ulm AND " . 'ulm=%d ORDER BY date_reparation DESC LIMIT %d, %d', $numUlm, $myOffset, $gdcarnet_nbMaxAff);
		$entretiens_query = str_replace('ForMatHour2', '%H,%i', $entretiens_query);
		$entretiens  = $wpdb->get_results($entretiens_query);
		if ($entretiens == null) {
			$prepareLeULM = $wpdb->prepare('SELECT modele, immatriculation FROM ' . $wpdb->prefix . 'gdcarnet_table_ulm WHERE id= %d', $numUlm);
			$leULM = $wpdb->get_results($prepareLeULM);
			echo '<h1>Carnet d\'entretien du ' . esc_html($leULM[0]->modele . ' ' . $leULM[0]->immatriculation) . '</h1>';
			}
		else
			echo '<h1>Carnet d\'entretien du ' . esc_html($entretiens[0]->modele . ' ' . $entretiens[0]->immatriculation) . '</h1>';

//		$urlCsv = '?csv=oui&type_aff=3&$ulm=' . $numUlm;
//		echo '<a href="' . esc_url($urlCsv) . '" >T&eacute;l&eacute;charger le carnet d\'entretien</a><br /><br />';
		$actual_link = gdcarnet_get_debut_link();
		$actual_link .= gdcarnet_get_start_uri();
		$actual_link .= '?offset=';
?>
        <table border="1">
            <thead>
				<tr>
				<th colspan=7 align="left" style="border-right-style: hidden">&nbsp;&nbsp;<a href="<?php echo esc_url($actual_link.$prev)?>">&lt;</a></th><th style="border-left-style: hidden" align="right"><?php if ($offset != 0) echo '<a href="' . esc_url($actual_link.$suiv) . '">&gt;</a>&nbsp;&nbsp;<a href="' . esc_url($actual_link.'0') . '">&gt;&gt;</a>'?>&nbsp;&nbsp;</th>
				</tr>
                <tr>
                    <th>Date<br />de l'entretien / r&eacute;paration</th>
					<th>M&eacute;canicien</th>
                    <th>Horam&egrave;tre au d&eacute;but</th>
					<th>Horam&egrave;tre &agrave; la fin</th>
					<th>Objet de l'intervention</th>
					<th>Nature de l'intervention</th>
					<th>R&eacute;sultat</th>
					<th>Reste &agrave; faire</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($entretiens as $un_entretien) {
                    ?>
                    <tr>
                        <td align="center"><?php echo '<a href=' . esc_url('?entretien=' . $un_entretien->id . '&type_carnet=' . $type_aff . '&offset=' . $offset) . '>' . esc_html($un_entretien->date_reparation) . '</a>'; ?></td>
						<td align="center"><?php echo esc_html(stripslashes($un_entretien->mecano)); ?></td>
						<td align="center"><?php echo esc_html($un_entretien->hora_debut); ?></td>
						<td align="center"><?php echo esc_html($un_entretien->hora_fin); ?></td>
<?php
						if (strlen($un_entretien->objet) > 20) {
							$objet = substr(stripslashes($un_entretien->objet),0,17);
							echo '<td align="center">' . esc_html($objet) . ' <b>(...)</b></td>';
							}
						else
							echo '<td align="center">' . esc_html(stripslashes($un_entretien->objet)) . '</td>';
						if (strlen($un_entretien->nature) > 20) {
							$nature = substr(stripslashes($un_entretien->nature),0,17);
							echo '<td align="center">' . esc_html($nature) . ' <b>(...)</b></td>';
							}
						else
							echo '<td align="center">' . esc_html(stripslashes($un_entretien->nature)) . '</td>';
						if (strlen($un_entretien->resultat) > 20) {
							$resultat = substr(stripslashes($un_entretien->resultat),0,17);
							echo '<td align="center">' . esc_html($resultat) . ' <b>(...)</b></td>';
							}
						else
							echo '<td align="center">' . esc_html(stripslashes($un_entretien->resultat)) . '</td>';
						if (strlen($un_entretien->reste) > 20) {
							$reste = substr(stripslashes($un_entretien->reste),0,17);
							echo '<td align="center">' . esc_html($reste) . ' <b>(...)</b></td>';
							}
						else
							echo '<td align="center">' . esc_html(stripslashes($un_entretien->reste)) . '</td>';
?>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Affichage des détails d'un entretien
function gdcarnet_display_un_entretien ($numentretien, $type_carnet) {	// type_carnet : 0 => pilote, 1 => ulm
	global $wpdb;
	
	if (($type_carnet != 0) && ($type_carnet != 1))
		$type_carnet = 1;
	
	if (isset ($_GET['offset']))
			$offset = sanitize_text_field($_GET['offset']);
		else
			$offset = 0;
	if (!is_numeric($offset))
		$offset = 0;
	
	$current_user = wp_get_current_user();
	$cet_entretiens_query = "SELECT " . $wpdb->prefix . "gdcarnet_table_entretien.id, date_reparation, TIME_FORMAT(SEC_TO_TIME(horametre_debut), 'ForMatHour2') AS hora_debut, TIME_FORMAT(SEC_TO_TIME(horametre_fin), 'ForMatHour2') AS hora_fin, mecano, objet, nature, reste, resultat, nom_pilote, facture, " . $wpdb->prefix . "gdcarnet_table_ulm.modele, " . $wpdb->prefix . "gdcarnet_table_ulm.immatriculation, " . $wpdb->prefix . "gdcarnet_table_entretien.ulm FROM " . $wpdb->prefix . "gdcarnet_table_entretien, " . $wpdb->prefix . "gdcarnet_table_pilote, " . $wpdb->prefix . "gdcarnet_table_ulm WHERE " . $wpdb->prefix . "gdcarnet_table_pilote.id=" . $wpdb->prefix . "gdcarnet_table_entretien.saisi_par AND " . $wpdb->prefix . "gdcarnet_table_ulm.id=" . $wpdb->prefix . "gdcarnet_table_entretien.ulm AND ";
	$cet_entretiens_query .= $wpdb->prepare($wpdb->prefix . 'gdcarnet_table_entretien.id=%d', $numentretien);
	$cet_entretiens_query = str_replace('ForMatHour2', '%H,%i', $cet_entretiens_query);
	$cet_entretien  = $wpdb->get_results($cet_entretiens_query);
	?>
	<h1>D&eacute;tails d'un entretien</h1>
	<table border="1">
		<tbody>
			<tr><td>Date du l'entretien&nbsp;: </td><td align="center"><?php echo esc_html($cet_entretien[0]->date_reparation); ?></td></tr>
			<tr><td>M&eacute;canicien&nbsp;: </td><td align="center"><?php echo esc_html(stripslashes($cet_entretien[0]->mecano)); ?></td></tr>
			<tr><td>Horam&egrave;tre au d&eacute;but&nbsp;: </td><td align="center"><?php echo esc_html($cet_entretien[0]->hora_debut); ?></td></tr>
			<tr><td>Horam&egrave;tre &agrave; la fin&nbsp;: </td><td align="center"><?php echo esc_html($cet_entretien[0]->hora_fin); ?></td></tr>
			<tr><td>Objet de l'intervention&nbsp;: </td><td align="left"><?php echo nl2br(esc_html(stripslashes($cet_entretien[0]->objet))); ?></td></tr>
			<tr><td>Nature de l'intervention&nbsp;: </td><td align="left"><?php echo nl2br(esc_html(stripslashes($cet_entretien[0]->nature))); ?></td></tr>
			<tr><td>R&eacute;sultat de l'intervention&nbsp;: </td><td align="left"><?php echo nl2br(esc_html(stripslashes($cet_entretien[0]->resultat))); ?></td></tr>
			<tr><td>Reste &agrave; faire&nbsp;: </td><td align="left"><?php echo nl2br(esc_html(stripslashes($cet_entretien[0]->reste))); ?></td></tr>
<?php
		if ($cet_entretien[0]->facture != null)
			echo '<tr><td>Facture&nbsp;: </td><td align="center"><a href="' . esc_url ('?facture=' . $cet_entretien[0]->id . '&ulm=' . $cet_entretien[0]->ulm) . '">' . esc_html($cet_entretien[0]->facture) . '</a></td></tr>'
?>
		</tbody>
    </table>
	<?php
	$actual_link = gdcarnet_get_debut_link();
	$resu = gdcarnet_get_start_uri();
	echo '<a href="' . esc_url($actual_link . $resu . '?offset=' . $offset) . '">Retour &agrave; la liste</a>';
}

// Affichage du nombre d'heures de vol mois par mois
function gdcarnet_heures_pilotes () {
	global $wpdb;
	$nb_mois = 5;
	
	$current_url = sanitize_text_field($_SERVER['REQUEST_URI']);
	if (strpos($current_url, '/wp-json/') !== false ) return;	// Si dans l'interface d'admin en cours de JSON => on retourne tout de suite pour ne pas provoquer d'erreur JSON
	
	$current_user = wp_get_current_user();
	$select_num_pilote_query = $wpdb->prepare('SELECT id, actif, niveau_admin FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE user_login=%s', $current_user->user_login);
	$select_num_pilote = $wpdb->get_results($select_num_pilote_query);
	if ($select_num_pilote == null) {
		echo '<center><h2><font color="red">Veuillez vous identifier</font></h2></center>';
		return;
		}
	$pilote_id = $select_num_pilote[0]->id;
	
	if ($select_num_pilote[0]->actif == false) {
		echo '<center><h2><font color="red">Compte d&eacute;sactiv&eacute;, contactez votre administrateur</font></h2></center>';
		return;
		}

	$actual_link = gdcarnet_get_debut_link();
	$resu = gdcarnet_get_start_uri();
	if (isset ($_GET['offset']))
		$offset = sanitize_text_field($_GET['offset']);
	else
		$offset = 0;
	if (!is_numeric($offset))
		$offset = 0;
	$prev = $offset + 1;
	$suiv = $offset - 1;

	$actual_link = gdcarnet_get_debut_link();
	$actual_link .= gdcarnet_get_start_uri();
	$actual_link .= '?offset=';
	// Pas de paramètre, pas besoin de prepare
	$wpdb->query('SET lc_time_names = "fr_fr"');
	$liste_pilotes = $wpdb->get_results( 'SELECT id, user_login, nom_pilote FROM ' . $wpdb->prefix . 'gdcarnet_table_pilote WHERE actif=true ORDER BY nom_pilote ASC');
	echo '<table border="1"><tbody>';
	if ($offset == 0)
		echo '<tr><td style="border-right-style: hidden" align="left" colspan=' . esc_html($nb_mois + 2) . '>&nbsp;&nbsp;<a href="' . esc_url($actual_link . $prev) . '" style="text-decoration:none">&lt;--</a></td><td style="border-left-style: hidden" align="right">&nbsp;</td></tr>';
	else
		echo '<tr><td style="border-right-style: hidden" align="left" colspan=' . esc_html($nb_mois + 2) . '>&nbsp;&nbsp;<a href="' . esc_url($actual_link . $prev) . '" style="text-decoration:none">&lt;--</a></td><td style="border-left-style: hidden" align="right"><a href="' . esc_url($actual_link . $suiv) . '" style="text-decoration:none">--&gt;</a>&nbsp;&nbsp;</td></tr>';
	echo '<tr><td align="center">Pilote</td>';
	for ($minus_mois = $nb_mois; $minus_mois >= 0; $minus_mois--) {
		$offset_mois = $minus_mois + (($nb_mois + 1) * $offset);
		if (!is_numeric($offset_mois)) {
			echo '<center><h1>ERREUR SYSTEME</h1></center>';
			return;
			}
		$prepare_query_nom_mois = $wpdb->prepare('SELECT YEAR(DATE_SUB(curdate(), INTERVAL %d MONTH)) AS annee, MONTHNAME(DATE_SUB(curdate(), INTERVAL %d MONTH)) AS mois', $offset_mois, $offset_mois);
		$query_nom_mois = $wpdb->get_results($prepare_query_nom_mois);
		echo '<td align="center">' . esc_html($query_nom_mois[0]->mois) . '<br />' . esc_html($query_nom_mois[0]->annee) . '</td>';
		}
	echo '<td align="center">Total</td></tr>';
	$num_col = 0;
	for ($minus_mois = $nb_mois; $minus_mois >= 0; $minus_mois--) {
		$tot_col[$num_col] = 0;
		$num_col++;
		}
	foreach ($liste_pilotes as $un_pilote) {
		$tot_pilote = 0;
		echo '<tr><td>' . esc_html($un_pilote->nom_pilote) . '</td>';
		$num_col = 0;
		for ($minus_mois = $nb_mois; $minus_mois >= 0; $minus_mois--) {
			$offset_mois = $minus_mois + (($nb_mois + 1) * $offset);
			$queryMois = $wpdb->prepare('SELECT nom_pilote, SUM(minutes_de_vol) AS minutes FROM wp_gdcarnet_table_vols, wp_gdcarnet_table_pilote WHERE pilote=%d AND pilote=wp_gdcarnet_table_pilote.id AND MONTH(date_vol) = MONTH(DATE_SUB(curdate(), INTERVAL %d MONTH)) AND YEAR(date_vol) = YEAR(DATE_SUB(curdate(), INTERVAL %d MONTH))', $un_pilote->id, $offset_mois, $offset_mois);
			$resultatMois = $wpdb->get_results ($queryMois);
			$minutes = $resultatMois[0]->minutes;
			if ($minutes == null)
				$minutes = 0;
			$tot_pilote += $minutes;
			echo '<td align="center">' . esc_html($minutes) . '</td>';
			$tot_col[$num_col] += $minutes;
			$num_col++;
			}
		$tot_col[$num_col] = $tot_pilote;
		echo '<td align="center">' . esc_html($tot_pilote) . '</td></tr>';
		}
	$num_col = 0;
	echo '<tr><td>Total</td>';
	$tot_tot = 0;
	for ($minus_mois = $nb_mois; $minus_mois >= 0; $minus_mois--) {
		echo '<td align="center">' . esc_html($tot_col[$num_col]) . '</td>';
		$tot_tot += $tot_col[$num_col];
		$num_col++;
		}
	echo '<td align="center">' . esc_html($tot_tot) . '</td></tr>';
	echo '</table>';
}

// Recuperation du début du lien reel
function gdcarnet_get_debut_link() {
	$my_url = esc_url((is_ssl() ? 'https://' : 'http://') . sanitize_text_field($_SERVER['HTTP_HOST']));
	return $my_url;
}

// Renvoi du lien avec un numero de page passe en parametre
function gdcarnet_get_link_avec_page ($ma_page) {
	$my_url_page = gdcarnet_get_debut_link();
	$my_url_page .= sanitize_text_field($_SERVER['SCRIPT_NAME']) . '?page=' . $ma_page;
	return esc_url($my_url_page);
}

// Renvoi du lien avec le début de l'URI (nom de la page appellee via un short code)
function gdcarnet_get_start_uri () {
	$tab_link = explode ('/', sanitize_text_field($_SERVER['REQUEST_URI']));
	$sz = sizeof ($tab_link);
	$resu = '';
	for ($i = 0; $i < $sz -1; $i++) {
		$resu .= $tab_link[$i] . '/';
		}
	return esc_url($resu);
}

// Affichage des fonctions deprecated
function gdcarnet_display_deprecated() {
	echo '<center><font color="red"><h1>Fonction obsol&egrave;te,<br />voir manuel pour la remplacer.</h1></font></center>';
}

// Lancement automatique de l'ajout des cotisations mensuelles des pilotes actifs dont le jour de cotisation correspond
function gdcarnet_ajoute_cotisations_mensuelles () {
	global $wpdb;
	
	$date_auto_exec = date ('d/m/Y H:i');
	if (get_option( 'gdcarnet_last_auto_exec' ))
		update_option('gdcarnet_last_auto_exec', $date_auto_exec);
	else {
		delete_option( 'gdcarnet_last_auto_exec' );
		add_option('gdcarnet_last_auto_exec', $date_auto_exec);
		}
	$jourDuMois = date('d');
	gdcarnet_do_ajoute_cotisations_mensuelles ($jourDuMois);
}

// Ajout des cotisations mensuelles automatiques des pilotes actifs dont le jour de cotisation correspond
// Cette fonction peut aussi être lancée à la main en spécifiant un jour (ATTENTION ! TRES DANGEREUX !!!)
function gdcarnet_do_ajoute_cotisations_mensuelles ($jourDuMois) {
	global $wpdb;

	$nbPilotesCredites = 0;
	$messagePilotesCredites = "Liste des pilotes crédités aujourd'hui :\n\n";
	$query_get_mensualites = $wpdb->prepare('SELECT id, user_login, nom_pilote, mensualite FROM wp_gdcarnet_table_pilote WHERE actif=true AND jour_mensualite=%s', $jourDuMois);
	$select_get_mensualites = $wpdb->get_results($query_get_mensualites);
	$laDateSql = date ('Y-m') . '-' . $jourDuMois;
	foreach ($select_get_mensualites as $une_mensualite) {
		// On ne fait pas d'enregistrement à zéro.
		if ($une_mensualite->mensualite == 0)
			continue;
		$wpdb->insert(
			$wpdb->prefix . 'gdcarnet_table_pilote_comptes',
			array(
				'id' => NULL,
				'pilote' => $une_mensualite->id,
				'motif' => 'Mensualité automatique',
				'auteur' => '-1',
				'credit' => $une_mensualite->mensualite,
				'debit' => 0,
//				'date' => current_time('mysql', 1)
				'date' => $laDateSql
				)
			);
		$nbPilotesCredites++;
		$messagePilotesCredites .= $une_mensualite->user_login . ' (' . $une_mensualite->nom_pilote .') : ' . $une_mensualite->mensualite . "\n";
		}
	if ($nbPilotesCredites > 0) {	// Si au moins un pilote est concerné, on envoie un courriel au trésorier et on enregistre la date
		$messagePilotesCredites .= "\n\nA votre service,\nL'extension WordPress Carnet de vols\n";
		$destinataire = get_option( 'gdcarnet_dest_notes' );
		$resu_mail = wp_mail ($destinataire, 'Cotisations automatiques', $messagePilotesCredites);
		$date_auto_credit = date ('d/m/Y H:i');
		if (get_option( 'gdcarnet_last_auto_credit' ))
			update_option('gdcarnet_last_auto_credit', $date_auto_credit);
		else {
			delete_option( 'gdcarnet_last_auto_credit' );
			add_option('gdcarnet_last_auto_credit', $date_auto_credit);
			}
		}
	}

add_action('admin_menu', 'gdcarnet_menu');

// Page de saisie d'un vol
add_shortcode('carnet-vol-enregistre-vol', 'gdcarnet_ajoute_vol');
// Page d'affichage du carnet de vol du pilote connecté
add_shortcode('carnet-vol-pilote', 'gdcarnet_display_carnet_pilote');
// Page d'affichage des comptes et soldes des pilotes (pouvoir listAll pour voir les comptes des autres pilotes, pouvoir tresorier pour ajouter des débits / crédits)
add_shortcode('gestion-soldes-pilote', 'gdcarnet_gestion_liste_soldes_pilote');	// Conservé pour compatibilité, à virer avant re-soumission
add_shortcode('carnet-vol-gestion-soldes-pilote', 'gdcarnet_gestion_liste_soldes_pilote');
// Page d'affichage du carnet de vol de l'ULM passé en paramètre (ulm=id_de_l_ulm)
add_shortcode('carnet-vol-ulm', 'gdcarnet_display_carnet_ulm');
// Page de saisie d'un entretien d'un ULM
add_shortcode('carnet-vol-enregistre-entretien', 'gdcarnet_ajoute_entretien');
// Page d'affichage du carnet d'entretien de l'ULM passé en paramètre (ulm=id_de_l_ulm)
add_shortcode('carnet-entretien-ulm', 'gdcarnet_display_entretien_ulm');
// Page de saisie d'une note de frais
add_shortcode('carnet-vol-enregistre-frais', 'gdcarnet_ajoute_frais');
// Page de liste des notes de frais
add_shortcode('carnet-vol-liste-frais', 'gdcarnet_liste_frais');
// Page d'affichage du nombre d'heures de vol mois par mois
add_shortcode('carnet-vol-heures-pilotes', 'gdcarnet_heures_pilotes');

// Enregistre un champ de réglage dans la base de données
function gdcarnet_register_settings() {
    register_setting( 'geo_account_settings', 'geo_account_enable_multi' );
}
add_action('admin_init', 'gdcarnet_register_settings' );
add_action('template_redirect', 'gdcarnet_before_template');
// L'action d'ajout des cotisations planifiées
add_action( 'gdcarnet_cotisations_planifiees', 'gdcarnet_ajoute_cotisations_mensuelles' );

