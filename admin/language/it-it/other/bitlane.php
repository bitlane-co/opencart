<?php

/**
 * Bitlane Search for OpenCart
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from NMC, in the file
 * LICENSE.md that comes with it. Use, copy, modification or distribution of
 * this file or its parts without NMC's written permission is not allowed.
 *
 * @author    Bitlane <https://bitlane.co>
 * @copyright Since 2026 NMC
 * @license   Commercial license, see LICENSE.md
 */

declare(strict_types=1);

// Italian, keyed by the English text (Bitlane\OpenCart\Support\Translator).
// composer check:translations finds the texts missing here and the unused
// ones. "Engine" stays in English: it's Bitlane's name for the product.
// OpenCart's menus stay as OpenCart names them (System › Settings): it ships
// no Italian, and community packs name them differently.
$_['heading_title'] = 'Bitlane';

// The jobs of the sync.
$_['Check %source% in %language%'] = 'Controlla %source% in %language%';
$_['Daily check'] = 'Controllo giornaliero';
$_['Find the products of a changed %what% (#%id%)'] = 'Trova i prodotti dopo una modifica: %what% #%id%';
$_['Full sync: %source%'] = 'Sincronizzazione completa: %source%';
$_['Refresh the storefront’s widget'] = 'Aggiorna il widget del negozio';
$_['Send additions to the cart to Bitlane'] = 'Invia a Bitlane le aggiunte al carrello';
$_['Send order #%id% to Bitlane'] = 'Invia a Bitlane l’ordine #%id%';
$_['Send the refund of order #%id% to Bitlane'] = 'Invia a Bitlane il rimborso dell’ordine #%id%';
$_['Sync %count% changed items: %source%'] = 'Sincronizza %count% elementi modificati: %source%';
$_['Sync the products whose specials or availability start or end today'] = 'Sincronizza i prodotti le cui offerte o disponibilità iniziano o finiscono oggi';
$_['attribute'] = 'attributo';
$_['brand'] = 'marca';
$_['category'] = 'categoria';
$_['filter'] = 'filtro';
$_['filter group'] = 'gruppo di filtri';
$_['option'] = 'opzione';

// Updates.
$_['Bitlane %version% is out. To update: uninstall Bitlane in Extensions › Extensions (Other), then in Extensions › Installer uninstall and delete its package, upload the new one and install it, and install Bitlane again. Your connection, settings and sync stay.'] = 'È disponibile Bitlane %version%. Per aggiornare: disinstalla Bitlane in Extensions › Extensions (Other), poi in Extensions › Installer disinstalla ed elimina il suo pacchetto, carica il nuovo e installalo, e installa di nuovo Bitlane. Collegamento, impostazioni e sincronizzazione restano.';
$_['Download'] = 'Scarica';

// The storefront and the orders Bitlane counts.
$_['No active widget yet: the search shows on your pages once one is active in Bitlane.'] = 'Ancora nessun widget attivo: la ricerca compare nelle tue pagine quando uno è attivo in Bitlane.';
$_['Purchases: orders count when they reach'] = 'Acquisti: gli ordini contano quando arrivano a';
$_['Refunds: a counted order is refunded when it reaches'] = 'Rimborsi: un ordine contato è rimborsato quando arriva a';
$_['Show the search on the storefront'] = 'Mostra la ricerca nel negozio';
$_['Storefront'] = 'Negozio online';
$_['The search opens from your theme’s search box on every page. Searches, clicks, additions to the cart and orders are counted in Bitlane’s analytics.'] = 'La ricerca si apre dal campo di ricerca del tuo tema in ogni pagina. Ricerche, clic, aggiunte al carrello e ordini sono contati nelle statistiche di Bitlane.';
$_['With a cookie policy (System › Settings › Option), visits are counted once the visitor accepts it. Consent banners using Google Consent Mode work too.'] = 'Con una cookie policy (System › Settings › Option), le visite sono contate quando il visitatore la accetta. Funzionano anche i banner di consenso che usano Google Consent Mode.';

// The setup, the documents and the Overview.
$_['%content% in %languages%'] = '%content% in %languages%';
$_['%label% (%count%)'] = '%label% (%count%)';
$_['A new engine'] = 'Un nuovo engine';
$_['A new engine, or one your Bitlane project already has.'] = 'Un nuovo engine, o uno che il tuo progetto Bitlane ha già.';
$_['Active'] = 'Attivo';
$_['Availability'] = 'Disponibilità';
$_['Best selling'] = 'Più venduti';
$_['Bitlane could not set this store up.'] = 'Bitlane non è riuscito a configurare questo negozio.';
$_['Bitlane is creating the engine, indexes, keys and search widget. A new engine takes a minute or two.'] = 'Bitlane sta creando l’engine, gli indici, le chiavi e il widget di ricerca. Un nuovo engine richiede un paio di minuti.';
$_['Brand'] = 'Marca';
$_['Brand color'] = 'Colore del brand';
$_['Brands'] = 'Marche';
$_['CSS selectors for your theme’s search field and buttons, separated by commas: clicking one opens Bitlane’s search.'] = 'Selettori CSS del campo e dei pulsanti di ricerca del tuo tema, separati da virgole: un clic su uno apre la ricerca di Bitlane.';
$_['Categories'] = 'Categorie';
$_['Category'] = 'Categoria';
$_['Choose at least one kind of content to search.'] = 'Scegli almeno un tipo di contenuto da cercare.';
$_['Connect the store to Bitlane first.'] = 'Collega prima il negozio a Bitlane.';
$_['Content'] = 'Contenuti';
$_['Content to search'] = 'Contenuti da cercare';
$_['Deleting'] = 'In eliminazione';
$_['Draft'] = 'Bozza';
$_['Drag to change the order'] = 'Trascina per cambiare l’ordine';
$_['Each kind gets its own index, kept up to date. Shoppers find those in the search widget in one list of results; the others are for your own use.'] = 'Ogni tipo ha il suo indice, sempre aggiornato. I clienti trovano quelli nel widget di ricerca in un’unica lista di risultati; gli altri sono per il tuo uso.';
$_['Each kind of content has its own index in each of the store’s languages, kept up to date as it changes.'] = 'Ogni tipo di contenuto ha il suo indice in ogni lingua del negozio, aggiornato a ogni modifica.';
$_['Each of the store’s languages gets its own indexes, and the widget searches the one of the page. A language you add later is added on its own.'] = 'Ogni lingua del negozio ha i suoi indici, e il widget cerca in quella della pagina. Una lingua aggiunta in seguito si aggiunge da sola.';
$_['Engine'] = 'Engine';
$_['Engines'] = 'Engine';
$_['Failed'] = 'Non riuscito';
$_['Fewer records, but filters combine variants: a red S and a blue XL match “red, XL”, and stock is the product’s.'] = 'Meno record, ma i filtri uniscono le varianti: una S rossa e una XL blu corrispondono a “rosso, XL”, e la disponibilità è quella del prodotto.';
$_['Filters'] = 'Filtri';
$_['In stock'] = 'Disponibile';
$_['In the search widget'] = 'Nel widget di ricerca';
$_['Jobs waiting: %count%'] = 'Attività in attesa: %count%';
$_['Languages'] = 'Lingue';
$_['Last error: %message% (%date%)'] = 'Ultimo errore: %message% (%date%)';
$_['Logo'] = 'Logo';
$_['Logo address'] = 'Indirizzo del logo';
$_['Moving'] = 'In spostamento';
$_['Name: A to Z'] = 'Nome: dalla A alla Z';
$_['Newest'] = 'Più recenti';
$_['No color'] = 'Nessun colore';
$_['No indexes yet.'] = 'Ancora nessun indice.';
$_['Not in the search widget'] = 'Non nel widget di ricerca';
$_['Not synced yet'] = 'Non ancora sincronizzato';
$_['Oldest'] = 'Meno recenti';
$_['On backorder'] = 'Disponibile su ordinazione';
$_['One result per product'] = 'Un risultato per prodotto';
$_['One result per variant (recommended)'] = 'Un risultato per variante (consigliato)';
$_['Open'] = 'Apri';
$_['Open engine'] = 'Apri engine';
$_['Options and stock filter exactly: “red, XL, in stock” finds only products with a red XL in stock. Each variant counts as a record in your plan.'] = 'Opzioni e disponibilità filtrano con precisione: “rosso, XL, disponibile” trova solo i prodotti con una XL rossa disponibile. Ogni variante conta come un record nel tuo piano.';
$_['Out of stock'] = 'Esaurito';
$_['Overview'] = 'Panoramica';
$_['Page'] = 'Pagina';
$_['Pages'] = 'Pagine';
$_['Price'] = 'Prezzo';
$_['Price: high to low'] = 'Prezzo: dal più alto';
$_['Price: low to high'] = 'Prezzo: dal più basso';
$_['Products'] = 'Prodotti';
$_['Products with variants'] = 'Prodotti con varianti';
$_['Relevance'] = 'Rilevanza';
$_['Remove'] = 'Rimuovi';
$_['Resync'] = 'Sincronizza di nuovo';
$_['Resync all'] = 'Sincronizza tutto di nuovo';
$_['Save'] = 'Salva';
$_['Save and set up'] = 'Salva e configura';
$_['Saving moves the store to this engine: its indexes are made there and filled again, and the current engine’s are deleted. Search has fewer results until the sync is done.'] = 'Salvando, il negozio passa a questo engine: i suoi indici vengono creati lì e riempiti di nuovo, e quelli dell’engine attuale vengono eliminati. La ricerca ha meno risultati finché la sincronizzazione non è finita.';
$_['Search'] = 'Ricerca';
$_['Search box'] = 'Casella di ricerca';
$_['Search widget'] = 'Widget di ricerca';
$_['Shown at the top of the search widget’s filters. Bitlane uses it for a widget without a logo; after that, change it in Bitlane.'] = 'Mostrato in cima ai filtri del widget di ricerca. Bitlane lo usa per un widget senza logo; poi cambialo in Bitlane.';
$_['Sorting'] = 'Ordinamento';
$_['Starting'] = 'In avvio';
$_['Stopped'] = 'Fermo';
$_['Synced %date%'] = 'Sincronizzato il %date%';
$_['Syncing: %done% of %total%'] = 'Sincronizzazione: %done% di %total%';
$_['The choices shoppers can sort the results by, in the order they show. Change them later in Bitlane’s widget designer.'] = 'Le scelte con cui i clienti possono ordinare i risultati, nell’ordine in cui compaiono. Puoi cambiarle in seguito nell’editor del widget di Bitlane.';
$_['The search widget’s buttons and highlights. Bitlane uses it for a new widget; after that, change the colors in Bitlane.'] = 'I pulsanti e le evidenziazioni del widget di ricerca. Bitlane lo usa per un nuovo widget; poi cambia i colori in Bitlane.';
$_['The widget opens from your theme’s search box. Its look and texts are set in Bitlane.'] = 'Il widget si apre dalla casella di ricerca del tuo tema. Aspetto e testi si impostano in Bitlane.';
$_['Top rated'] = 'Più votati';
$_['Updating'] = 'In aggiornamento';
$_['Use the store’s logo'] = 'Usa il logo del negozio';
$_['What shoppers can narrow the results by, in the order they show.'] = 'Con cosa i clienti possono restringere i risultati, nell’ordine in cui compaiono.';
$_['Where your catalogue is searched: an index per kind of content and language.'] = 'Dove si cerca nel tuo catalogo: un indice per tipo di contenuto e lingua.';

$_['%name% (%url%) is connected to Bitlane.'] = '%name% (%url%) è collegato a Bitlane.';
$_['All stores'] = 'Tutti i negozi';
$_['Attempt %count%'] = 'Tentativo %count%';
$_['Background sync'] = 'Sincronizzazione in background';
$_['Changes have waited since %date%: check that the cron runs.'] = 'Le modifiche sono in attesa dal %date%: controlla che il cron venga eseguito.';
$_['Changes reach Bitlane through a cron job that calls this store every minute. Add this line to the server’s crontab, or have your hosting panel call the address in it every minute:'] = 'Le modifiche arrivano a Bitlane tramite un cron che chiama questo negozio ogni minuto. Aggiungi questa riga al crontab del server, oppure fai chiamare dal pannello del tuo hosting l’indirizzo che contiene ogni minuto:';
$_['Connection'] = 'Connessione';
$_['Delete'] = 'Elimina';
$_['Error: %message%'] = 'Errore: %message%';
$_['Failed %date%'] = 'Non riuscita il %date%';
$_['It hasn’t run yet.'] = 'Non è ancora stato eseguito.';
$_['Jobs'] = 'Attività';
$_['Last run: %date%'] = 'Ultima esecuzione: %date%';
$_['Nothing to do: the sync is up to date.'] = 'Niente da fare: la sincronizzazione è aggiornata.';
$_['Retry'] = 'Riprova';
$_['Run now'] = 'Esegui ora';
$_['Running'] = 'In esecuzione';
$_['Scheduled: %date%'] = 'Programmata: %date%';
$_['Send the store’s address and name to Bitlane'] = 'Invia a Bitlane indirizzo e nome del negozio';
$_['The cron hasn’t run yet: changes wait until it does. While this page is open, it runs them.'] = 'Il cron non è ancora stato eseguito: le modifiche restano in attesa finché non parte. Finché questa pagina è aperta, le esegue lei.';
$_['The store is in maintenance mode, so Bitlane can’t bring you back to it after you approve. Turn maintenance mode off (System › Settings › Server) while you connect.'] = 'Il negozio è in modalità manutenzione, quindi Bitlane non può riportarti qui dopo l’approvazione. Disattiva la modalità manutenzione (System › Settings › Server) mentre ti colleghi.';
$_['The store is in maintenance mode, which stops the cron’s calls too: changes wait until it’s off, or run while this page is open.'] = 'Il negozio è in modalità manutenzione, che ferma anche le chiamate del cron: le modifiche restano in attesa finché non la disattivi, o vengono eseguite mentre questa pagina è aperta.';
$_['Waiting'] = 'In attesa';
$_['Waiting: %waiting% · Failed: %failed%'] = 'In attesa: %waiting% · Non riuscite: %failed%';
$_['What the sync has to do. Failed jobs stay a week.'] = 'Cosa deve fare la sincronizzazione. Le attività non riuscite restano una settimana.';
$_['Without a cron, OpenCart’s own (Extensions › Cron Jobs) runs them hourly.'] = 'Senza un cron, quello di OpenCart (Extensions › Cron Jobs) le esegue ogni ora.';
$_['A window opens where you sign in to Bitlane (or create an account) and allow this store to use it. Bitlane then sets up:'] = 'Si apre una finestra in cui accedi a Bitlane (o crei un account) e consenti a questo negozio di usarlo. Bitlane poi configura:';
$_['Bitlane'] = 'Bitlane';
$_['Bitlane can’t run here'] = 'Bitlane non può funzionare qui';
$_['Bitlane could not be reached. Try again in a moment.'] = 'Impossibile raggiungere Bitlane. Riprova tra poco.';
$_['Bitlane could not do that. Try again in a moment.'] = 'Bitlane non è riuscito a farlo. Riprova tra poco.';
$_['Bitlane does not accept new connections right now.'] = 'Al momento Bitlane non accetta nuove connessioni.';
$_['Bitlane ended this store’s connection, so its search stopped updating. Connect again to resume.'] = 'Bitlane ha chiuso la connessione di questo negozio, quindi la ricerca non si aggiorna più. Collegalo di nuovo per riprendere.';
$_['Bitlane ended this store’s connection. Connect again to keep searching.'] = 'Bitlane ha chiuso la connessione di questo negozio. Collegalo di nuovo per continuare a cercare.';
$_['Bitlane for OpenCart (%host%)'] = 'Bitlane per OpenCart (%host%)';
$_['Bitlane needs PHP 8.1 or newer; this server runs %version%.'] = 'Bitlane richiede PHP 8.1 o successivo; questo server usa PHP %version%.';
$_['Bitlane needs PHP’s sodium extension, which keeps the connection’s keys encrypted.'] = 'Bitlane richiede l’estensione sodium di PHP, che tiene cifrate le chiavi della connessione.';
$_['Bitlane project for this store'] = 'Progetto Bitlane per questo negozio';
$_['Bitlane refused the connection.'] = 'Bitlane ha rifiutato la connessione.';
$_['Bitlane refused to register this store.'] = 'Bitlane ha rifiutato di registrare questo negozio.';
$_['Bitlane sent no authorization code.'] = 'Bitlane non ha inviato alcun codice di autorizzazione.';
$_['Bitlane works with OpenCart %oldest% to 4.1; this store runs %version%.'] = 'Bitlane funziona con OpenCart da %oldest% a 4.1; questo negozio usa la %version%.';
$_['Choose a project'] = 'Scegli un progetto';
$_['Check again'] = 'Controlla di nuovo';
$_['Choose a project first.'] = 'Scegli prima un progetto.';
$_['Choose a store'] = 'Scegli un negozio';
$_['Choose a store first.'] = 'Scegli prima un negozio.';
$_['Close'] = 'Chiudi';
$_['Close this window and click Connect again.'] = 'Chiudi questa finestra e fai di nuovo clic su Collega.';
$_['Connect to Bitlane'] = 'Collega a Bitlane';
$_['Connect your store to Bitlane'] = 'Collega il tuo negozio a Bitlane';
$_['Connected'] = 'Collegato';
$_['Connected to Bitlane.'] = 'Collegato a Bitlane.';
$_['Continue'] = 'Continua';
$_['Disconnect'] = 'Scollega';
$_['Disconnect this store from Bitlane? Its search stops updating; the engine and indexes stay in Bitlane.'] = 'Scollegare questo negozio da Bitlane? La sua ricerca smette di aggiornarsi; l’engine e gli indici restano in Bitlane.';
$_['Disconnected'] = 'Scollegato';
$_['Each store is connected and set up on its own: pick the one to work on.'] = 'Ogni negozio si collega e si configura a parte: scegli quello su cui lavorare.';
$_['Loading…'] = 'Caricamento…';
$_['Not connected'] = 'Non collegato';
$_['Open in Bitlane'] = 'Apri in Bitlane';
$_['Ready'] = 'Pronto';
$_['Setting up'] = 'Configurazione in corso';
$_['Setup failed'] = 'Configurazione non riuscita';
$_['Store'] = 'Negozio';
$_['Something went wrong. The details are in System › Maintenance › Error Logs.'] = 'Qualcosa è andato storto. I dettagli sono in System › Maintenance › Error Logs.';
$_['The answer did not come from Bitlane.'] = 'La risposta non viene da Bitlane.';
$_['The connection request expired or came from elsewhere. Click Connect again.'] = 'La richiesta di connessione è scaduta o viene da altrove. Fai di nuovo clic su Collega.';
$_['The connection was declined in Bitlane.'] = 'La connessione è stata rifiutata in Bitlane.';
$_['This store doesn’t exist.'] = 'Questo negozio non esiste.';
$_['This store isn’t connected to Bitlane.'] = 'Questo negozio non è collegato a Bitlane.';
$_['Waiting for setup'] = 'In attesa di configurazione';
$_['You can close this window.'] = 'Puoi chiudere questa finestra.';
$_['You do not have permission to do this.'] = 'Non hai i permessi per farlo.';
$_['Your Bitlane account has no project yet. Create one in Bitlane, then come back here.'] = 'Il tuo account Bitlane non ha ancora un progetto. Creane uno in Bitlane, poi torna qui.';
$_['Your browser blocked Bitlane’s window. Allow pop-ups for this page, then click Connect again.'] = 'Il browser ha bloccato la finestra di Bitlane. Consenti i pop-up per questa pagina, poi fai di nuovo clic su Collega.';
$_['a search widget for your storefront'] = 'un widget di ricerca per il tuo negozio';
$_['an engine, or one your project already has'] = 'un engine, o uno che il tuo progetto ha già';
$_['indexes for your catalogue in %languages%'] = 'indici per il tuo catalogo in %languages%';
$_['keys that only reach this store’s indexes'] = 'chiavi che raggiungono solo gli indici di questo negozio';
