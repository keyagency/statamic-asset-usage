<script>
import { Card } from '@statamic/cms/ui'

/** Varying widths, so the placeholder does not read as a table. */
const ROW_WIDTHS = ['w-2/5', 'w-3/5', 'w-1/3', 'w-1/2', 'w-2/3', 'w-1/4']

/**
 * Stands in for the list until the first response lands. Without it the page
 * renders its defaults for a moment, which reads as "not indexed yet" even when
 * the index is fine.
 */
export default {
    components: { Card },

    computed: {
        rows() {
            return ROW_WIDTHS
        },
    },
}
</script>

<template>
    <Card inset role="status" :aria-label="__('asset-usage::messages.loading')">
        <div class="animate-pulse divide-y divide-gray-200 dark:divide-gray-700">
            <div v-for="(width, index) in rows" :key="index" class="flex items-center gap-3 px-4 py-3">
                <div class="size-10 shrink-0 rounded bg-gray-200 dark:bg-gray-700" />
                <div class="h-3 rounded-full bg-gray-200 dark:bg-gray-700" :class="width" />
                <div class="ms-auto h-3 w-12 shrink-0 rounded-full bg-gray-200 dark:bg-gray-700" />
            </div>
        </div>
    </Card>
</template>
