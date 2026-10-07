<script>
import { Input, Select } from '@statamic/cms/ui'

/**
 * Stands in for "any site" in the filter. An option can't carry an empty
 * value (the Select refuses to open when one does) and no site handle can
 * contain an asterisk, so this can never collide with a real one.
 */
export const ANY_SITE = '*'

/** The search field and the filters above the list, each with a v-model of its own. */
export default {
    components: { Input, Select },

    props: {
        /** 'usage' or 'compression', which decides the filters on offer. */
        view: String,
        containers: Array,
        sites: Array,
        multisite: Boolean,
        search: String,
        usage: String,
        compression: String,
        container: String,
        site: String,
    },

    emits: ['update:search', 'update:usage', 'update:compression', 'update:container', 'update:site'],

    computed: {
        compressionOptions() {
            return ['all', 'compressible', 'compressed'].map(value => ({
                value,
                label: __(`asset-usage::messages.compress.filters.${value}`),
            }))
        },

        usageOptions() {
            return [
                { value: 'all', label: __('asset-usage::messages.filters.all') },
                { value: 'used', label: __('asset-usage::messages.filters.used') },
                { value: 'unused', label: __('asset-usage::messages.filters.unused') },
            ]
        },

        containerOptions() {
            return this.containers.map(container => ({ value: container.handle, label: container.title }))
        },

        siteOptions() {
            return [
                { value: ANY_SITE, label: __('asset-usage::messages.filters.all_sites') },
                ...this.sites.map(site => ({ value: site.handle, label: site.title })),
            ]
        },
    },
}
</script>

<template>
    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
        <Input
            :model-value="search"
            class="w-full sm:min-w-32 sm:flex-1"
            type="search"
            :placeholder="__('asset-usage::messages.filters.search_placeholder')"
            @update:model-value="$emit('update:search', $event)"
        />

        <Select
            v-if="view === 'compression'"
            :model-value="compression"
            class="w-full sm:w-auto! sm:min-w-44 sm:shrink-0"
            :options="compressionOptions"
            option-label="label"
            option-value="value"
            @update:model-value="$emit('update:compression', $event)"
        />

        <Select
            v-if="view === 'usage'"
            :model-value="usage"
            class="w-full sm:w-auto! sm:min-w-44 sm:shrink-0"
            :options="usageOptions"
            option-label="label"
            option-value="value"
            @update:model-value="$emit('update:usage', $event)"
        />

        <Select
            v-if="containers.length > 1"
            :model-value="container"
            clearable
            class="w-full sm:w-auto! sm:min-w-56 sm:shrink-0"
            :options="containerOptions"
            option-label="label"
            option-value="value"
            :placeholder="__('asset-usage::messages.filters.container')"
            @update:model-value="$emit('update:container', $event)"
        />

        <Select
            v-if="multisite && view === 'usage'"
            :model-value="site"
            class="w-full sm:w-auto! sm:min-w-56 sm:shrink-0"
            :options="siteOptions"
            option-label="label"
            option-value="value"
            @update:model-value="$emit('update:site', $event)"
        />
    </div>
</template>
