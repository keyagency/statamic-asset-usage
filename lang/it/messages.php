<?php

return [

    'nav_title' => 'Utilizzo degli asset',
    'view_overview' => 'Apri Utilizzo degli asset',

    'loading' => 'Caricamento…',
    'details' => 'Dettagli',
    'field_label' => 'Campo',
    'all_sites' => 'Tutti i siti',

    'column_label' => 'In uso',

    'unused' => 'Non usato da nessuna parte',
    'used_count' => '{1} Usato in 1 punto|[2,*] Usato in :count punti',

    'no_results' => 'Nessun asset corrisponde a questi filtri.',
    'no_containers' => 'Nessun contenitore di asset è attivato per questo addon.',

    'not_scanned' => '"Non usato" significa che non è stato trovato alcun riferimento nei contenuti analizzati. Template, URL di Glide e dati che un addon conserva in un proprio archivio non sono visibili qui, quindi controllali prima di eliminare qualcosa.',

    'disclaimer' => 'L’eliminazione degli asset è definitiva e avviene a tuo rischio. Conserva un backup dei tuoi file. L’autore non si assume alcuna responsabilità per file persi o per qualsiasi altro danno derivante dall’uso di questo addon.',
    'made_by' => 'Realizzato da',

    'permissions' => [
        'group' => 'Utilizzo degli asset',
        'view' => 'Visualizzare l’utilizzo degli asset',
        'delete' => 'Eliminare gli asset non usati',
    ],

    'index' => [
        'refresh' => 'Aggiorna i dati di utilizzo',
        'refreshing' => 'Aggiornamento…',
        'refresh_started' => 'I dati di utilizzo sono in fase di aggiornamento.',
        'refreshed' => 'I dati di utilizzo sono stati aggiornati.',
        'updated_at' => 'Ultimo aggiornamento :time',
        'checking' => 'Controllo dei contenuti in corso…',
        'not_ready' => 'Non ancora controllato',
        'not_ready_instructions' => 'Aggiorna i dati di utilizzo per vedere dove viene usato questo asset.',
        'stale' => 'I dati di utilizzo non sono aggiornati',
        'stale_instructions' => 'Sono stati raccolti per altri contenitori o impostazioni. Aggiornali per ottenere risultati precisi.',
        'aged' => 'I dati di utilizzo non vengono ricostruiti da un po’',
        'aged_instructions' => 'Sono stati ricostruiti l’ultima volta :time. Da allora salvataggi ed eliminazioni sono stati applicati, ma una ricostruzione rileva anche ciò che è cambiato senza che Statamic se ne accorgesse, come file caricati direttamente sul disco.',
        'aged_instructions_manual' => 'Sono stati ricostruiti l’ultima volta :time. L’aggiornamento automatico è disattivato, quindi da allora nulla li ha modificati e potrebbero essere indietro rispetto ai tuoi contenuti.',
    ],

    'filters' => [
        'usage' => 'Utilizzo',
        'all' => 'Tutti gli asset',
        'used' => 'Usati',
        'unused' => 'Non usati',
        'container' => 'Contenitore',
        'all_sites' => 'Tutti i siti',
        'search_placeholder' => 'Cerca per percorso…',
    ],

    'sort' => [
        'name_asc' => 'Nome (A-Z)',
        'name_desc' => 'Nome (Z-A)',
        'newest' => 'Più recenti prima',
        'oldest' => 'Meno recenti prima',
        'used' => 'Più usati prima',
        'unused' => 'Non usati prima',
    ],

    'delete' => [
        'action' => 'Elimina',
        'selected' => 'Elimina selezionati',
        'all_unused' => 'Elimina tutti i non usati',
        'all_unused_scope' => 'Riguarda ogni asset non usato che corrisponde ai filtri attuali, compresi quelli nelle altre pagine.',
        'select_all' => 'Seleziona tutti gli eliminabili',
        'confirm_title' => '{1} Eliminare questo asset?|[2,*] Eliminare questi :count asset?',
        'confirm' => '{1} Il file verrà eliminato definitivamente. L’operazione non può essere annullata.|[2,*] I file verranno eliminati definitivamente. L’operazione non può essere annullata.',
        'success' => '{1} 1 asset eliminato|[2,*] :count asset eliminati',
        'none' => 'Non è stato eliminato nulla.',
    ],

    'item_type' => [
        'entry' => 'Voce',
        'entry_draft' => 'Bozza',
        'global' => 'Set globale',
        'term' => 'Termine di tassonomia',
        'nav' => 'Navigazione',
        'user' => 'Utente',
        'asset' => 'Asset',
        'form_submission' => 'Invio del modulo',
        'collection' => 'Valori predefiniti della collezione',
        'taxonomy' => 'Valori predefiniti della tassonomia',
        'addon_settings' => 'Impostazioni dell’addon',
        'blueprint' => 'Blueprint',
        'fieldset' => 'Fieldset',
    ],

    'errors' => [
        'stale_index' => 'I dati di utilizzo non sono aggiornati. Aggiornali prima di eliminare qualcosa.',
        'no_usage_data' => 'Non ci sono ancora dati di utilizzo, quindi di nulla si sa che non è usato. Aggiornali prima di eliminare qualcosa.',
        'asset_is_used' => 'Questo asset è usato in :count punti e non è stato eliminato.',
        'asset_ignored' => 'Questo asset è protetto dalla configurazione `ignore` e non è stato eliminato.',
        'asset_too_new' => 'Questo asset è stato caricato meno di :days giorni fa e non è stato eliminato.',
        'asset_missing' => 'Questo asset non esiste più.',
    ],

];
