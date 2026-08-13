<script>
import { FieldtypeMixin } from '@statamic/cms'
import { Badge, Button, Text } from '@statamic/cms/ui'
import UsageList from '../components/UsageList.vue'

/**
 * The "Used in" panel in the asset editor. Everything it shows comes from field
 * meta, resolved server-side in Fieldtypes\AssetUsage::preload() — there is no
 * value to edit here, so the field never emits an update.
 *
 * The list sits behind a toggle: an asset used in dozens of places would
 * otherwise push the rest of the form off the screen.
 */
export default {
    mixins: [FieldtypeMixin],

    components: { Badge, Button, Text, UsageList },

    data() {
        return {
            expanded: false,
        }
    },

    computed: {
        /** Config can switch the panel off while keeping the browser column. */
        visible() {
            return this.config.panel !== false
        },

        usages() {
            return this.meta.usages ?? []
        },

        count() {
            return this.meta.count ?? 0
        },

        summary() {
            return __n('asset-usage::messages.used_count', this.count, { count: this.count })
        },
    },
}
</script>

<template>
    <div v-if="visible">
        <template v-if="meta.building">
            <Text size="sm" variant="subtle" :text="__('asset-usage::messages.index.checking')" />
        </template>

        <template v-else-if="!meta.indexed">
            <Text size="sm" variant="subtle" :text="__('asset-usage::messages.index.not_ready_instructions')" />
            <Button
                class="mt-2"
                size="sm"
                :href="meta.toolsUrl"
                :text="__('asset-usage::messages.view_overview')"
            />
        </template>

        <template v-else-if="count === 0">
            <Badge color="orange" :text="__('asset-usage::messages.unused')" />
        </template>

        <template v-else>
            <Button
                size="sm"
                variant="ghost"
                class="-ms-2"
                :icon-append="expanded ? 'chevron-up' : 'chevron-down'"
                :aria-expanded="expanded"
                :text="summary"
                @click="expanded = !expanded"
            />

            <UsageList
                v-if="expanded"
                class="mt-3"
                :usages="usages"
                :site-titles="meta.siteTitles"
                :multisite="meta.multisite"
            />
        </template>
    </div>
</template>
