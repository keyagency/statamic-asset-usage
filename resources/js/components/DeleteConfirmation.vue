<script>
import { ConfirmationModal } from '@statamic/cms/ui'

/**
 * The warning before deleting: for a list of assets, or for every unused
 * asset the filters cover, which has no list to show and says so instead.
 */
export default {
    components: { ConfirmationModal },

    props: {
        /** The assets about to go, or null. */
        assets: { type: Array, default: null },
        allUnused: Boolean,
        unusedTotal: Number,
        busy: Boolean,
    },

    emits: ['confirm', 'close'],

    computed: {
        open() {
            return this.assets !== null || this.allUnused
        },

        /** One asset or many changes the wording, so both read naturally. */
        count() {
            return this.allUnused ? this.unusedTotal : (this.assets?.length ?? 0)
        },

        title() {
            return __n('asset-usage::messages.delete.confirm_title', this.count, { count: this.count })
        },

        text() {
            return __n('asset-usage::messages.delete.confirm', this.count, { count: this.count })
        },

        body() {
            if (!this.assets) return ''

            const paths = this.assets.map(asset => asset.path)

            return paths.length <= 5 ? paths.join('\n') : `${paths.slice(0, 5).join('\n')}\n…`
        },

        scope() {
            return this.allUnused ? __('asset-usage::messages.delete.all_unused_scope') : ''
        },
    },
}
</script>

<template>
    <ConfirmationModal
        :open="open"
        danger
        :title="title"
        :button-text="__('asset-usage::messages.delete.action')"
        :busy="busy"
        @confirm="$emit('confirm')"
        @update:open="value => { if (!value) $emit('close') }"
    >
        <p class="mb-2 text-gray-700 antialiased dark:text-gray-200" v-text="text" />
        <p v-if="scope" class="mb-2 text-sm text-gray-500 dark:text-gray-400" v-text="scope" />
        <pre v-if="body" class="max-h-40 overflow-auto rounded bg-gray-100 p-2 text-xs dark:bg-gray-800" v-text="body" />
    </ConfirmationModal>
</template>
