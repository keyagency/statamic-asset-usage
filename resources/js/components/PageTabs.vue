<script>
import { Link } from '@statamic/cms/inertia'

/** Links rather than Statamic's Tabs, which switch content within one page. */
export default {
    components: { Link },

    props: {
        current: String,
        usageUrl: String,
        compressionUrl: String,
        logUrl: String,
    },

    computed: {
        tabs() {
            return [
                { key: 'usage', url: this.usageUrl, label: __('asset-usage::messages.nav.usage') },
                { key: 'compression', url: this.compressionUrl, label: __('asset-usage::messages.nav.compression') },
                { key: 'log', url: this.logUrl, label: __('asset-usage::messages.nav.log') },
            ].filter(tab => tab.url)
        },
    },
}
</script>

<template>
    <nav class="mb-6 flex gap-6 overflow-x-auto shadow-[inset_0_-1px_0_var(--theme-color-gray-200)] forced-colors:border-b dark:shadow-[inset_0_-1px_0_var(--theme-color-gray-700)]">
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.url"
            class="border-b-2 px-0.5 pb-2.5 text-sm font-medium whitespace-nowrap"
            :class="tab.key === current
                ? 'text-gray-900 not-dark:border-gray-900 dark:border-white dark:text-white'
                : 'border-transparent text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white'"
            :aria-current="tab.key === current ? 'page' : null"
            v-text="tab.label"
        />
    </nav>
</template>
