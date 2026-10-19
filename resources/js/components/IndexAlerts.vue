<script>
import { Alert } from '@statamic/cms/ui'
import { formatNumber } from '../support/formatting.js'
import RunFailure from './RunFailure.vue'
import RunProgress from './RunProgress.vue'

/**
 * What the overview says about the usage data: being checked, a check that
 * didn't finish, not to be believed, or due for a rebuild.
 */
export default {
    components: { Alert, RunFailure, RunProgress },

    props: {
        index: Object,
    },

    computed: {
        /** The first check has no earlier count to estimate from, so it only counts. */
        progressText() {
            const { done, total } = this.index.progress

            return total
                ? __('asset-usage::messages.index.progress', { done: formatNumber(done), total: formatNumber(total) })
                : __n('asset-usage::messages.index.progress_count', done, { count: formatNumber(done) })
        },

        /**
         * What an overdue rebuild is worth saying depends on whether anything
         * has been keeping the index current in the meantime.
         */
        agedInstructions() {
            const key = this.index.auto_update ? 'aged_instructions' : 'aged_instructions_manual'

            return __(`asset-usage::messages.index.${key}`, { time: this.index.built_at_relative })
        },
    },
}
</script>

<template>
    <Alert v-if="index.building" class="mb-4">
        <p v-text="__('asset-usage::messages.index.checking')" />
        <RunProgress v-if="index.progress" :progress="index.progress" :text="progressText" />
    </Alert>

    <RunFailure
        v-else-if="index.failure"
        :failure="index.failure"
        :heading="__('asset-usage::messages.index.failed')"
        command="php please asset-usage:index"
    />

    <Alert
        v-else-if="index.stale"
        class="mb-4"
        variant="warning"
        :heading="index.built_at ? __('asset-usage::messages.index.stale') : __('asset-usage::messages.index.not_ready')"
        :text="index.built_at ? __('asset-usage::messages.index.stale_instructions') : __('asset-usage::messages.index.not_ready_overview')"
    />

    <!-- Deliberately not a warning: the data is usable, a rebuild is just overdue. -->
    <Alert
        v-else-if="index.aged"
        class="mb-4"
        :heading="__('asset-usage::messages.index.aged')"
        :text="agedInstructions"
    />
</template>
