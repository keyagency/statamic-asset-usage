<script>
import { Badge } from '@statamic/cms/ui'

/**
 * The places one asset is used, grouped by site. Items that belong to no site
 * (other assets, users, form submissions) are collected under one heading so
 * they don't look like they're missing a site.
 */
export default {
    components: { Badge },

    props: {
        usages: { type: Array, required: true },
        siteTitles: { type: Object, default: () => ({}) },
        multisite: { type: Boolean, default: false },
    },

    computed: {
        groups() {
            const groups = []

            this.usages.forEach(usage => {
                const key = usage.site ?? '__no_site__'
                let group = groups.find(group => group.key === key)

                if (!group) {
                    group = { key, site: usage.site, usages: [] }
                    groups.push(group)
                }

                group.usages.push(usage)
            })

            return groups
        },
    },

    methods: {
        siteLabel(site) {
            if (site === null) return __('asset-usage::messages.all_sites')

            return this.siteTitles[site] ?? site
        },

        /** Never rendered as a heading when there's only one site to speak of. */
        showsSiteHeading(group) {
            return this.multisite && this.groups.length > 1
        },
    },
}
</script>

<template>
    <div class="space-y-3">
        <div v-for="group in groups" :key="group.key">
            <div
                v-if="showsSiteHeading(group)"
                class="mb-1 text-xs font-medium text-gray-500 dark:text-gray-400"
                v-text="siteLabel(group.site)"
            />

            <ul class="space-y-1">
                <li v-for="(usage, index) in group.usages" :key="index" class="flex items-start gap-2 text-sm">
                    <Badge v-if="usage.type_label" size="sm" :text="usage.type_label" />

                    <a
                        v-if="usage.url"
                        class="text-blue-600 hover:underline dark:text-blue-400"
                        :href="usage.url"
                        v-text="usage.title"
                    />
                    <span v-else v-text="usage.title" />

                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('asset-usage::messages.field_label') }}: <code v-text="usage.field" />
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
