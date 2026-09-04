<?php

return [

    'nav_title' => 'Assetgebruik',
    'view_overview' => 'Assetgebruik openen',

    'loading' => 'Laden…',
    'details' => 'Details',
    'field_label' => 'Veld',
    'all_sites' => 'Alle sites',

    /** De kolom toont een vinkje of kruisje, dus die leest als een ja/nee-vraag. */
    'column_label' => 'Gebruikt',

    'unused' => 'Nergens gebruikt',
    'used_count' => '{1} Gebruikt op 1 plek|[2,*] Gebruikt op :count plekken',

    'no_results' => 'Geen assets voor deze filters.',
    'no_containers' => 'Er zijn geen asset containers ingeschakeld voor deze addon.',

    'not_scanned' => '"Niet gebruikt" betekent dat er geen verwijzing is gevonden in de gescande content. Templates, Glide-URL\'s en data die een addon in een eigen opslag bewaart zijn hier niet zichtbaar, dus controleer die voordat je verwijdert.',

    'disclaimer' => 'Assets verwijderen is definitief en gebeurt op eigen risico. Zorg voor een back-up van je bestanden. De maker aanvaardt geen aansprakelijkheid voor verloren bestanden of andere schade door het gebruik van deze addon.',
    'made_by' => 'Gemaakt door',

    'permissions' => [
        'group' => 'Assetgebruik',
        'view' => 'Assetgebruik bekijken',
        'delete' => 'Ongebruikte assets verwijderen',
    ],

    /** Alles over de staat van de gebruiksgegevens, in gewone taal. */
    'index' => [
        'refresh' => 'Gebruik opnieuw controleren',
        'refreshing' => 'Bezig met controleren…',
        'refresh_started' => 'Het gebruik wordt opnieuw gecontroleerd.',
        'refreshed' => 'Het gebruik is opnieuw gecontroleerd.',
        'updated_at' => 'Laatst bijgewerkt :time',
        'checking' => 'Je content wordt gecontroleerd…',
        'not_ready' => 'Nog niet gecontroleerd',
        'not_ready_instructions' => 'Controleer het gebruik om te zien waar deze asset gebruikt wordt.',
        'stale' => 'De gebruiksgegevens zijn verouderd',
        'stale_instructions' => 'Ze zijn opgehaald voor andere containers of instellingen. Controleer opnieuw voor kloppende resultaten.',
    ],

    'filters' => [
        'usage' => 'Gebruik',
        'all' => 'Alle assets',
        'used' => 'Gebruikt',
        'unused' => 'Ongebruikt',
        'container' => 'Container',
        'site' => 'Site',
        'all_sites' => 'Alle sites',
        'search_placeholder' => 'Zoek op pad…',
    ],

    'sort' => [
        'name_asc' => 'Naam (A-Z)',
        'name_desc' => 'Naam (Z-A)',
        'used' => 'Meest gebruikt eerst',
        'unused' => 'Ongebruikt eerst',
    ],

    'delete' => [
        'action' => 'Verwijderen',
        'selected' => 'Selectie verwijderen',
        'all_unused' => 'Alle ongebruikte verwijderen',
        'all_unused_scope' => 'Dit gaat over elke ongebruikte asset binnen de huidige filters, ook die op andere pagina\'s.',
        'select_all' => 'Alles selecteren wat verwijderd kan worden',
        'confirm_title' => '{1} Deze asset verwijderen?|[2,*] Deze :count assets verwijderen?',
        'confirm' => '{1} Hiermee wordt het bestand definitief verwijderd. Dit kun je niet ongedaan maken.|[2,*] Hiermee worden de bestanden definitief verwijderd. Dit kun je niet ongedaan maken.',
        'success' => '{1} 1 asset verwijderd|[2,*] :count assets verwijderd',
        'none' => 'Er is niets verwijderd.',
    ],

    'item_type' => [
        'entry' => 'Entry',
        'entry_draft' => 'Concept',
        'global' => 'Global set',
        'term' => 'Taxonomieterm',
        'nav' => 'Navigatie',
        'user' => 'Gebruiker',
        'asset' => 'Asset',
        'form_submission' => 'Formulierinzending',
        'collection' => 'Collectie-standaardwaarden',
        'taxonomy' => 'Taxonomie-standaardwaarden',
        'addon_settings' => 'Addon-instellingen',
        'blueprint' => 'Blueprint',
        'fieldset' => 'Fieldset',
    ],

    'errors' => [
        'stale_index' => 'De gebruiksgegevens zijn verouderd. Controleer opnieuw voordat je iets verwijdert.',
        'asset_is_used' => 'Deze asset wordt op :count plekken gebruikt en is niet verwijderd.',
        'asset_ignored' => 'Deze asset is beschermd via de `ignore`-config en is niet verwijderd.',
        'asset_too_new' => 'Deze asset is minder dan :days dagen geleden geüpload en is niet verwijderd.',
        'asset_missing' => 'Die asset bestaat niet meer.',
    ],

];
