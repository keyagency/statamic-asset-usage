<script>
import { formatBytes, formattingLocale } from '../support/formatting.js'

/**
 * One line with what the addon saved, such as "12 images · 70.6 MB → 13.3 MB
 * (−81.1%) · 5 resized". Restored compressions are mentioned but not counted.
 */
export default {
    props: {
        totals: Object,
    },

    computed: {
        images() {
            return __n('asset-usage::messages.log.images', this.totals.count, { count: this.totals.count })
        },

        before() {
            return formatBytes(this.totals.before_bytes)
        },

        after() {
            return formatBytes(this.totals.after_bytes)
        },

        saving() {
            const { before_bytes: before, after_bytes: after } = this.totals
            const percent = before ? ((before - after) / before) * 100 : 0

            return `−${new Intl.NumberFormat(formattingLocale(), { maximumFractionDigits: 1 }).format(percent)}%`
        },
    },
}
</script>

<template>
    <span class="tabular-nums">
        <span v-text="images" />
        <template v-if="totals.count">
            <span aria-hidden="true"> · </span>
            <strong class="font-semibold" v-text="before" />
            →
            <strong class="font-semibold" v-text="after" />
            <span class="text-green-700 dark:text-green-400" v-text="` (${saving})`" />
            <template v-if="totals.resized">
                <span aria-hidden="true"> · </span>
                <span v-text="__('asset-usage::messages.log.resized', { count: totals.resized })" />
            </template>
        </template>
        <template v-if="totals.restored">
            <span aria-hidden="true"> · </span>
            <span v-text="__('asset-usage::messages.log.restored', { count: totals.restored })" />
        </template>
    </span>
</template>
