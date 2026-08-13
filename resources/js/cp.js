import AssetUsage from './pages/AssetUsage.vue'
import AssetUsageFieldtype from './fieldtypes/AssetUsageFieldtype.vue'
import AssetUsageIndexFieldtype from './fieldtypes/AssetUsageIndexFieldtype.vue'

Statamic.booting(() => {
    Statamic.$inertia.register('asset-usage::AssetUsage', AssetUsage)

    /**
     * The panel in the asset editor and the column in the asset browser. Both
     * come from the one `asset_usage` field the addon injects into every
     * enabled container's blueprint.
     */
    Statamic.$components.register('asset_usage-fieldtype', AssetUsageFieldtype)
    Statamic.$components.register('asset_usage-fieldtype-index', AssetUsageIndexFieldtype)
})
