=== Carnet de vols ULM ===
Contributors: georgesmc
Donate link: https://www.paypal.com/donate/?hosted_button_id=WR99E4X4SBKLY
Tags: ulm, ultralight, carnet de vols, pilote, aeroclub
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.11.3
Requires PHP: 7.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html
License: GPLv3 or later

Flight log designed for clubs: multi-pilots and multiple ultralight planes, and pilots accounts management.

== Description ==

This WordPress plugin is designed to help flying clubs manage flight logs for several pilots and several ultralights: pilots list, ultralights list, ultralight maintenance log, automatic flight cost compute, using each ultralight hour meter and the cost per hour set by account managers, each pilot having his own account book (credit, debits and balance).
Each pilot can see his own flight log, each ultralight has his own flight log, pilots account book can be managed by the club treasurer.

Each functionality (adding a flight, pilots flight log, ultralights flight log, pilots account book display, pilots account book management, etc.) are handled through different shortcodes, thus any WordPress user permissions management extension can be used to let people access or not any function.

The backoffice let administrator manage ultralights (list, hourly rate, active/inactive), and pilots (licence date, wich ultralights they can use, active/inactive).

Next to come:

* Automatic hourly fuel consumption computation (between two full tank load),
* Number of lines change possible in each list.

== Installation ==

1. Upload the entire `performance-lab` folder to the `/wp-content/plugins/` directory, or install directly using WordPress extensions screen,
1. Activate the Ultralight flight log plugin,
1. On your WordPress use Dashboard->Carnet de vols ULM for the configuration (see the FAQ below).

== Frequently Asked Questions ==

= How to start with this plugin? =

First install and activate it.
Next you will need to:

1. Add one or several pilots accounts,
1. Add one or several ultralights,
1. Link at least one ultralight to each pilot (see below),
1. Use the different shortcodes to create the pages you'll need (also see below).

**IMPORTANT: Do not forget to add at least one ultralight, and to add it in the pilots allowed aircrafts.**

= How to add a new pilot? =

A pilot is a WordPress user. You'll need to first create a WordPress user (Users->Add new user). Then you'll be able to adjust pilots authorizations using this plugin (Carnet de vols ULM -> Gestion des pilotes).
Warning: you must select one or several ultralights in each pilot's list in order for the pilot to be able to register his flights.

= How to add an ultralight? =

In your WordPress Dashboard: Carnet de vols ULM -> Gestion des ULM. Don't forget to add an hourly rate, it will be use for each pilot's account book management using declared hour meter (difference between start and end of flight).

= List of all shortcodes =

* `carnet-vol-enregistre-vol` : Flight registration page.
* `carnet-vol-pilote` : Connected (using WordPress account) pilot's flight log.
* `compte-pilote` : Connected (using WordPress account) pilot's account book (credits, debits, balance).
* `carnet-vol-gestion-soldes-pilote` : List or manage all pilots balance, (listAll level to display every pilot's balance, tresorier level to manage accounts).
* `carnet-vol-ulm` : One ultralight flight log. The ultralight id (see the "id" column in the ultralights list) must be set as "ulm" parameter (e.g.: `[carnet-vol-ulm ulm=1]`)
* `carnet-vol-enregistre-entretien` : Ultralight maintenance registration page.
* `carnet-entretien-ulm` : One ultralight maintenance log. The ultralight id (see the "id" column in the ultralights list) must be set as "ulm" parameter (e.g.: `[carnet-entretien-ulm ulm=1]`)
* `carnet-vol-enregistre-frais` : Expense report registration page (file upload for the expense reoprt, plus amount to enter that will be credited to the pilot's account)
* `carnet-vol-liste-frais` : Expense reports list (newest first), with each expense report display.
* `carnet-vol-heures-pilotes` : Number of flight hours per pilot, per 6-month period.

= How do I limit acces for each page? =

As each pilot is a WordPress user, and every function is managed using a different shortcode: any WordPress users management plugin can be used to allow or not access to any function (add a flight, see his own account book, display or manage all users account books, etc.).

== Screenshots ==

1. [https://carnet-de-vols.georgesdick.com/screens/GestionPilotes.png Backoffice pilots management]
1. [https://carnet-de-vols.georgesdick.com/screens/SaisirUnVol.png Flight registration. "horamètre au départ", "terrain de départ" and "carburant au départ" fields will be pre-filled using the choosen ultralight last flight data]
1. [https://carnet-de-vols.georgesdick.com/screens/CarnetDeVolULM.png Ultralight flight log: flights list, pilots, comments, etc. Pilots flight logs are almost identical (ultralight list instead of pilots list, and flight cost is displayed]
1. [https://carnet-de-vols.georgesdick.com/screens/LivreDeComptes.png Pilot's account book: credits, debits, balance]
1. [https://carnet-de-vols.georgesdick.com/screens/PageDuTresorier.png All pilots accounts. There are two versions: display only, or management (credits and debits, mainly used by the club's treasurer)]

== Changelog =

= 1.11.3 =
* A column showing estimated balances has been added to pilots' accounts

= 1.11.1 =
* Page turning in every list

= 1.10.6 =
* Various bug fixes, frontend and backend

= 1.10.3 =
* Automatic monthly deposit on the pilot's account. An administrator can set a default monthly deposit amount, and can adjut it for every pilot.
* Automatic monthly deposit date chooser for each pilot. The 5th by default, but can be changed for every pilot.
* Automatic monthly deposit confirmation e-mail sent to the treasurer, including pilot's name and amounts.

= 1.9.7 =
* Sanizize, escape, validate
* More comments
* URL construction code refactoring and optimization
* Nonce added to all POST and GET

= 1.8.4 =
* All _GET, _POST, _FILES, and _COOKIES entries filtered, escaped, sanizized,
* SQL queries secured (using prepare),
* Echo'd variables escaped,
* Licence clarified (GPL V3 or later),
* WordPress' file uploader instead of PHP standard one,
* Some functions, options and tables renamed.

= 1.8b =
* Added the ability to view inactive pilots accounts (accessible only to treasurer(s))

= 1.7a =
* Expense reports management v2 (automatic or manual credit approval, ability to reject or approve an expense report).

= 1.4a =
* Expense reports management v1 (automatic pilot's account credit only, pre-validation to come).

= 1.3a =
* table prefix handling
* link between accounting and flights added

= 1.2d =
* Flight time round bug fixed.

= 1.2a =
* Accounts display and management pages fusion.

= 1.1a =
* Ultralights maintenance log.

= 1.0 =
* First version submitted to the WordPress team.

= 0.9rc4 =
* First version fully usable by our aero club.

== Upgrade Notice ==

= 1.0 =
Fully usable version including all mandatory functions.

