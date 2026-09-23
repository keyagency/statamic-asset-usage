<?php

return [

    'nav_title' => 'Uso de assets',
    'view_overview' => 'Abrir Uso de assets',

    'loading' => 'Cargando…',
    'details' => 'Detalles',
    'field_label' => 'Campo',
    'all_sites' => 'Todos los sitios',

    'column_label' => 'En uso',

    'unused' => 'No se usa en ningún sitio',
    'used_count' => '{1} Se usa en 1 lugar|[2,*] Se usa en :count lugares',

    'no_results' => 'Ningún asset coincide con estos filtros.',
    'no_containers' => 'No hay contenedores de assets activados para este addon.',

    'not_scanned' => '"Sin usar" significa que no se encontró ninguna referencia en el contenido analizado. Las plantillas, las URL de Glide y los datos que un addon guarda en su propio almacenamiento no se ven aquí, así que revísalos antes de eliminar nada.',

    'disclaimer' => 'Eliminar assets es permanente y se hace bajo tu propia responsabilidad. Guarda una copia de seguridad de tus archivos. El autor no se hace responsable de archivos perdidos ni de cualquier otro daño derivado del uso de este addon.',
    'made_by' => 'Creado por',

    'permissions' => [
        'group' => 'Uso de assets',
        'view' => 'Ver el uso de assets',
        'delete' => 'Eliminar assets sin usar',
    ],

    'index' => [
        'refresh' => 'Actualizar datos de uso',
        'refreshing' => 'Actualizando…',
        'refresh_started' => 'Se están actualizando los datos de uso.',
        'refreshed' => 'Los datos de uso se han actualizado.',
        'updated_at' => 'Última actualización :time',
        'checking' => 'Revisando tu contenido…',
        'not_ready' => 'Aún sin revisar',
        'not_ready_instructions' => 'Actualiza los datos de uso para ver dónde se usa este asset.',
        'stale' => 'Los datos de uso están desactualizados',
        'stale_instructions' => 'Se recopilaron para otros contenedores o ajustes. Actualízalos para obtener resultados precisos.',
        'aged' => 'Los datos de uso no se han reconstruido en un tiempo',
        'aged_instructions' => 'Se reconstruyeron por última vez :time. Desde entonces se han aplicado los guardados y eliminaciones, pero una reconstrucción también detecta lo que cambió sin que Statamic lo notara, como archivos colocados directamente en el disco.',
        'aged_instructions_manual' => 'Se reconstruyeron por última vez :time. La actualización automática está desactivada, así que nada los ha cambiado desde entonces y pueden ir por detrás de tu contenido.',
    ],

    'filters' => [
        'usage' => 'Uso',
        'all' => 'Todos los assets',
        'used' => 'En uso',
        'unused' => 'Sin usar',
        'container' => 'Contenedor',
        'all_sites' => 'Todos los sitios',
        'search_placeholder' => 'Buscar por ruta…',
    ],

    'sort' => [
        'name_asc' => 'Nombre (A-Z)',
        'name_desc' => 'Nombre (Z-A)',
        'newest' => 'Más recientes primero',
        'oldest' => 'Más antiguos primero',
        'used' => 'Más usados primero',
        'unused' => 'Sin usar primero',
    ],

    'delete' => [
        'action' => 'Eliminar',
        'selected' => 'Eliminar selección',
        'all_unused' => 'Eliminar todos los sin usar',
        'all_unused_scope' => 'Esto incluye todos los assets sin usar que coinciden con los filtros actuales, también los de otras páginas.',
        'select_all' => 'Seleccionar todos los eliminables',
        'confirm_title' => '{1} ¿Eliminar este asset?|[2,*] ¿Eliminar estos :count assets?',
        'confirm' => '{1} El archivo se eliminará de forma permanente. No se puede deshacer.|[2,*] Los archivos se eliminarán de forma permanente. No se puede deshacer.',
        'success' => '{1} 1 asset eliminado|[2,*] :count assets eliminados',
        'none' => 'No se eliminó nada.',
    ],

    'item_type' => [
        'entry' => 'Entrada',
        'entry_draft' => 'Borrador',
        'global' => 'Conjunto global',
        'term' => 'Término de taxonomía',
        'nav' => 'Navegación',
        'user' => 'Usuario',
        'asset' => 'Asset',
        'form_submission' => 'Envío de formulario',
        'collection' => 'Valores predeterminados de la colección',
        'taxonomy' => 'Valores predeterminados de la taxonomía',
        'addon_settings' => 'Ajustes del addon',
        'blueprint' => 'Blueprint',
        'fieldset' => 'Fieldset',
    ],

    'errors' => [
        'stale_index' => 'Los datos de uso están desactualizados. Actualízalos antes de eliminar nada.',
        'no_usage_data' => 'Todavía no hay datos de uso, así que no se sabe de nada que esté sin usar. Actualízalos antes de eliminar nada.',
        'asset_is_used' => 'Este asset se usa en :count lugares y no se ha eliminado.',
        'asset_ignored' => 'Este asset está protegido por la configuración `ignore` y no se ha eliminado.',
        'asset_too_new' => 'Este asset se subió hace menos de :days días y no se ha eliminado.',
        'asset_missing' => 'Ese asset ya no existe.',
    ],

];
