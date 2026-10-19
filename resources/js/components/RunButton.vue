<script>
import { Button, ConfirmationModal } from '@statamic/cms/ui'

/**
 * The button that starts a long run (the usage check, the compression
 * analysis) and stops it while it runs, after asking first. Red while it
 * runs, so it can't be mistaken for the button that starts it.
 */
export default {
    components: { Button, ConfirmationModal },

    props: {
        running: Boolean,
        /** Asked to stop and not done yet: a busy run saves what it has first. */
        stopping: Boolean,
        disabled: Boolean,
        text: String,
        stopText: String,
        stopHeading: String,
        stopConfirm: String,
    },

    emits: ['start', 'stop'],

    data() {
        return {
            confirming: false,
        }
    },

    watch: {
        // A run that ends while the question is open has nothing left to stop.
        running(running) {
            if (!running) this.confirming = false
        },
    },

    methods: {
        stop() {
            this.confirming = false
            this.$emit('stop')
        },
    },
}
</script>

<template>
    <Button
        v-if="running"
        variant="danger"
        :disabled="stopping"
        :text="stopping ? __('asset-usage::messages.run.stopping') : stopText"
        @click="confirming = true"
    />
    <Button v-else variant="primary" :disabled="disabled" :text="text" @click="$emit('start')" />

    <ConfirmationModal
        :open="confirming"
        danger
        :title="stopHeading"
        :button-text="stopText"
        @confirm="stop"
        @update:open="open => (confirming = open)"
    >
        <p class="text-gray-700 antialiased dark:text-gray-200" v-text="stopConfirm" />
    </ConfirmationModal>
</template>
