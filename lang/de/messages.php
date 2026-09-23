<?php

return [

    'nav_title' => 'Asset-Nutzung',
    'view_overview' => 'Asset-Nutzung öffnen',

    'loading' => 'Wird geladen…',
    'details' => 'Details',
    'field_label' => 'Feld',
    'all_sites' => 'Alle Websites',

    'column_label' => 'Verwendet',

    'unused' => 'Nirgends verwendet',
    'used_count' => '{1} An 1 Stelle verwendet|[2,*] An :count Stellen verwendet',

    'no_results' => 'Keine Assets entsprechen diesen Filtern.',
    'no_containers' => 'Für dieses Addon sind keine Asset-Container aktiviert.',

    'not_scanned' => '„Unbenutzt“ bedeutet, dass in den durchsuchten Inhalten kein Verweis gefunden wurde. Templates, Glide-URLs und Daten, die ein Addon in einem eigenen Speicher ablegt, sind hier nicht sichtbar. Prüfe diese also, bevor du etwas löschst.',

    'disclaimer' => 'Das Löschen von Assets ist endgültig und geschieht auf eigene Gefahr. Bewahre ein Backup deiner Dateien auf. Der Autor übernimmt keine Haftung für verlorene Dateien oder sonstige Schäden, die aus der Nutzung dieses Addons entstehen.',
    'made_by' => 'Erstellt von',

    'permissions' => [
        'group' => 'Asset-Nutzung',
        'view' => 'Asset-Nutzung anzeigen',
        'delete' => 'Unbenutzte Assets löschen',
    ],

    'index' => [
        'refresh' => 'Nutzungsdaten aktualisieren',
        'refreshing' => 'Wird aktualisiert…',
        'refresh_started' => 'Die Nutzungsdaten werden aktualisiert.',
        'refreshed' => 'Die Nutzungsdaten wurden aktualisiert.',
        'updated_at' => 'Zuletzt aktualisiert :time',
        'checking' => 'Deine Inhalte werden geprüft…',
        'not_ready' => 'Noch nicht geprüft',
        'not_ready_instructions' => 'Aktualisiere die Nutzungsdaten, um zu sehen, wo dieses Asset verwendet wird.',
        'stale' => 'Die Nutzungsdaten sind veraltet',
        'stale_instructions' => 'Sie wurden für andere Container oder Einstellungen erfasst. Aktualisiere sie, um genaue Ergebnisse zu erhalten.',
        'aged' => 'Die Nutzungsdaten wurden länger nicht neu aufgebaut',
        'aged_instructions' => 'Sie wurden zuletzt :time neu aufgebaut. Speichern und Löschen wurden seitdem übernommen, aber ein Neuaufbau erfasst auch Änderungen, die Statamic nicht bemerkt hat, etwa Dateien, die direkt auf dem Datenträger abgelegt wurden.',
        'aged_instructions_manual' => 'Sie wurden zuletzt :time neu aufgebaut. Die automatische Aktualisierung ist ausgeschaltet, daher hat sich seitdem nichts daran geändert und sie könnten hinter deinen Inhalten zurückliegen.',
    ],

    'filters' => [
        'usage' => 'Nutzung',
        'all' => 'Alle Assets',
        'used' => 'Verwendet',
        'unused' => 'Unbenutzt',
        'container' => 'Container',
        'all_sites' => 'Alle Websites',
        'search_placeholder' => 'Nach Pfad suchen…',
    ],

    'sort' => [
        'name_asc' => 'Name (A-Z)',
        'name_desc' => 'Name (Z-A)',
        'newest' => 'Neueste zuerst',
        'oldest' => 'Älteste zuerst',
        'used' => 'Meistverwendete zuerst',
        'unused' => 'Unbenutzte zuerst',
    ],

    'delete' => [
        'action' => 'Löschen',
        'selected' => 'Auswahl löschen',
        'all_unused' => 'Alle unbenutzten löschen',
        'all_unused_scope' => 'Das betrifft jedes unbenutzte Asset, das den aktuellen Filtern entspricht, auch die auf anderen Seiten.',
        'select_all' => 'Alle löschbaren auswählen',
        'confirm_title' => '{1} Dieses Asset löschen?|[2,*] Diese :count Assets löschen?',
        'confirm' => '{1} Die Datei wird endgültig gelöscht. Dies kann nicht rückgängig gemacht werden.|[2,*] Die Dateien werden endgültig gelöscht. Dies kann nicht rückgängig gemacht werden.',
        'success' => '{1} 1 Asset gelöscht|[2,*] :count Assets gelöscht',
        'none' => 'Es wurde nichts gelöscht.',
    ],

    'item_type' => [
        'entry' => 'Eintrag',
        'entry_draft' => 'Entwurf',
        'global' => 'Globales Set',
        'term' => 'Taxonomie-Begriff',
        'nav' => 'Navigation',
        'user' => 'Benutzer',
        'asset' => 'Asset',
        'form_submission' => 'Formulareinsendung',
        'collection' => 'Standardwerte der Collection',
        'taxonomy' => 'Standardwerte der Taxonomie',
        'addon_settings' => 'Addon-Einstellungen',
        'blueprint' => 'Blueprint',
        'fieldset' => 'Fieldset',
    ],

    'errors' => [
        'stale_index' => 'Die Nutzungsdaten sind veraltet. Aktualisiere sie, bevor du etwas löschst.',
        'no_usage_data' => 'Es gibt noch keine Nutzungsdaten, daher ist von nichts bekannt, dass es unbenutzt ist. Aktualisiere sie, bevor du etwas löschst.',
        'asset_is_used' => 'Dieses Asset wird an :count Stellen verwendet und wurde nicht gelöscht.',
        'asset_ignored' => 'Dieses Asset ist durch die `ignore`-Konfiguration geschützt und wurde nicht gelöscht.',
        'asset_too_new' => 'Dieses Asset wurde vor weniger als :days Tagen hochgeladen und wurde nicht gelöscht.',
        'asset_missing' => 'Dieses Asset existiert nicht mehr.',
    ],

];
