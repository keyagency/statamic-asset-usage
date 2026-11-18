<script>
import { IndexFieldtypeMixin } from '@statamic/cms'
import { Icon } from '@statamic/cms/ui'

export default {
    mixins: [IndexFieldtypeMixin],

    components: { Icon },

    computed: {
        usage() {
            return this.value ?? { indexed: false, count: 0 }
        },

        used() {
            return this.usage.count > 0
        },

        tooltip() {
            if (!this.usage.indexed) return __('asset-usage::messages.index.not_ready')

            return this.used
                ? __n('asset-usage::messages.used_count', this.usage.count, { count: this.usage.count })
                : __('asset-usage::messages.unused')
        },
    },
}
</script>

<template>
    <span :title="tooltip" :aria-label="tooltip">
        <span v-if="!usage.indexed" class="text-gray-400 dark:text-gray-500">&mdash;</span>

        <Icon
            v-else
            class="size-4"
            :class="used ? 'not-dark:text-green-600 dark:text-green-500' : 'text-gray-400 dark:text-gray-500'"
            :name="used ? 'checkmark' : 'x'"
        />
    </span>
</template>
