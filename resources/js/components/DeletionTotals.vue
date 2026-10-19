<script>
import { Button } from '@statamic/cms/ui'
import { formatBytes } from '../support/formatting.js'

/** What was deleted so far, with the way to the log that lists it. The same bar as the compression totals. */
export default {
    components: { Button },

    props: {
        /** count and bytes, from the overview's meta. */
        deletions: Object,
        logUrl: String,
    },

    methods: {
        formatBytes,
    },
}
</script>

<template>
    <div class="mb-4 flex flex-col gap-2 rounded-lg bg-gray-50 px-3 py-2.5 text-sm sm:flex-row sm:items-center sm:justify-between dark:bg-gray-800/60">
        <p class="text-gray-700 tabular-nums dark:text-gray-300">
            {{ __n('asset-usage::messages.log.deleted_count', deletions.count, { count: deletions.count }) }}
            <span aria-hidden="true"> · </span>
            <strong class="font-semibold" v-text="__('asset-usage::messages.log.freed', { size: formatBytes(deletions.bytes) })" />
        </p>
        <Button v-if="logUrl" class="shrink-0" size="sm" :href="logUrl" :text="__('asset-usage::messages.log.view')" />
    </div>
</template>
