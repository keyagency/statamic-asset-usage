<?php

return [

    'nav_title' => 'Utilisation des assets',
    'view_overview' => 'Ouvrir Utilisation des assets',

    'loading' => 'Chargement…',
    'details' => 'Détails',
    'field_label' => 'Champ',
    'all_sites' => 'Tous les sites',

    'column_label' => 'Utilisé',

    'unused' => 'Utilisé nulle part',
    'used_count' => '{1} Utilisé à 1 endroit|[2,*] Utilisé à :count endroits',

    'no_results' => 'Aucun asset ne correspond à ces filtres.',
    'no_containers' => 'Aucun conteneur d’assets n’est activé pour cet addon.',

    'not_scanned' => '« Inutilisé » signifie qu’aucune référence n’a été trouvée dans le contenu analysé. Les templates, les URL Glide et les données qu’un addon conserve dans son propre stockage ne sont pas visibles ici, vérifiez-les donc avant de supprimer quoi que ce soit.',

    'disclaimer' => 'La suppression d’assets est définitive et se fait à vos propres risques. Conservez une sauvegarde de vos fichiers. L’auteur décline toute responsabilité pour les fichiers perdus ou tout autre dommage résultant de l’utilisation de cet addon.',
    'made_by' => 'Réalisé par',

    'permissions' => [
        'group' => 'Utilisation des assets',
        'view' => 'Voir l’utilisation des assets',
        'delete' => 'Supprimer les assets inutilisés',
    ],

    'index' => [
        'refresh' => 'Actualiser les données d’utilisation',
        'refreshing' => 'Actualisation…',
        'refresh_started' => 'Les données d’utilisation sont en cours d’actualisation.',
        'refreshed' => 'Les données d’utilisation ont été actualisées.',
        'updated_at' => 'Dernière mise à jour :time',
        'checking' => 'Vérification de votre contenu…',
        'not_ready' => 'Pas encore vérifié',
        'not_ready_instructions' => 'Actualisez les données d’utilisation pour voir où cet asset est utilisé.',
        'stale' => 'Les données d’utilisation sont obsolètes',
        'stale_instructions' => 'Elles ont été collectées pour d’autres conteneurs ou paramètres. Actualisez-les pour obtenir des résultats exacts.',
        'aged' => 'Les données d’utilisation n’ont pas été reconstruites depuis un moment',
        'aged_instructions' => 'Elles ont été reconstruites pour la dernière fois :time. Les enregistrements et suppressions ont été pris en compte depuis, mais une reconstruction détecte aussi ce qui a changé sans que Statamic le remarque, comme des fichiers déposés directement sur le disque.',
        'aged_instructions_manual' => 'Elles ont été reconstruites pour la dernière fois :time. La mise à jour automatique est désactivée, rien ne les a donc modifiées depuis et elles peuvent être en retard sur votre contenu.',
    ],

    'filters' => [
        'usage' => 'Utilisation',
        'all' => 'Tous les assets',
        'used' => 'Utilisés',
        'unused' => 'Inutilisés',
        'container' => 'Conteneur',
        'all_sites' => 'Tous les sites',
        'search_placeholder' => 'Rechercher par chemin…',
    ],

    'sort' => [
        'name_asc' => 'Nom (A-Z)',
        'name_desc' => 'Nom (Z-A)',
        'newest' => 'Plus récents d’abord',
        'oldest' => 'Plus anciens d’abord',
        'used' => 'Plus utilisés d’abord',
        'unused' => 'Inutilisés d’abord',
    ],

    'delete' => [
        'action' => 'Supprimer',
        'selected' => 'Supprimer la sélection',
        'all_unused' => 'Supprimer tous les inutilisés',
        'all_unused_scope' => 'Cela concerne chaque asset inutilisé correspondant aux filtres actuels, y compris ceux des autres pages.',
        'select_all' => 'Sélectionner tous les supprimables',
        'confirm_title' => '{1} Supprimer cet asset ?|[2,*] Supprimer ces :count assets ?',
        'confirm' => '{1} Le fichier sera définitivement supprimé. Cette action est irréversible.|[2,*] Les fichiers seront définitivement supprimés. Cette action est irréversible.',
        'success' => '{1} 1 asset supprimé|[2,*] :count assets supprimés',
        'none' => 'Rien n’a été supprimé.',
    ],

    'item_type' => [
        'entry' => 'Entrée',
        'entry_draft' => 'Brouillon',
        'global' => 'Ensemble global',
        'term' => 'Terme de taxonomie',
        'nav' => 'Navigation',
        'user' => 'Utilisateur',
        'asset' => 'Asset',
        'form_submission' => 'Soumission de formulaire',
        'collection' => 'Valeurs par défaut de la collection',
        'taxonomy' => 'Valeurs par défaut de la taxonomie',
        'addon_settings' => 'Paramètres de l’addon',
        'blueprint' => 'Blueprint',
        'fieldset' => 'Fieldset',
    ],

    'errors' => [
        'stale_index' => 'Les données d’utilisation sont obsolètes. Actualisez-les avant de supprimer quoi que ce soit.',
        'no_usage_data' => 'Il n’y a pas encore de données d’utilisation, rien n’est donc connu comme inutilisé. Actualisez-les avant de supprimer quoi que ce soit.',
        'asset_is_used' => 'Cet asset est utilisé à :count endroits et n’a pas été supprimé.',
        'asset_ignored' => 'Cet asset est protégé par la configuration `ignore` et n’a pas été supprimé.',
        'asset_too_new' => 'Cet asset a été téléversé il y a moins de :days jours et n’a pas été supprimé.',
        'asset_missing' => 'Cet asset n’existe plus.',
    ],

];
