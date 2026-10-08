<script>
import { Alert } from '@statamic/cms/ui'

/** What the overview says about the usage data: being checked, not to be believed, or due for a rebuild. */
export default {
    components: { Alert },

    props: {
        index: Object,
    },

    computed: {
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
    <Alert v-if="index.building" class="mb-4" :text="__('asset-usage::messages.index.checking')" />

    <Alert
        v-else-if="index.stale"
        class="mb-4"
        variant="warning"
        :heading="index.exists ? __('asset-usage::messages.index.stale') : __('asset-usage::messages.index.not_ready')"
        :text="index.exists ? __('asset-usage::messages.index.stale_instructions') : __('asset-usage::messages.index.not_ready_instructions')"
    />

    <!-- Deliberately not a warning: the data is usable, a rebuild is just overdue. -->
    <Alert
        v-else-if="index.aged"
        class="mb-4"
        :heading="__('asset-usage::messages.index.aged')"
        :text="agedInstructions"
    />
</template>
