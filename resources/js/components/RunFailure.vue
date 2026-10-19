<script>
import { Alert, Heading } from '@statamic/cms/ui'

/** Why the last run didn't finish, and how to see the error itself. */
export default {
    components: { Alert, Heading },

    props: {
        /** reason ('error', 'not_started' or 'stalled') and the server's message, when there was one. */
        failure: Object,
        heading: String,
        /** The command that runs the same thing in the terminal, where the error shows. */
        command: String,
    },

    computed: {
        text() {
            const { reason, message } = this.failure

            const why = reason === 'error' && message
                ? __('asset-usage::messages.run.error', { message })
                : __(`asset-usage::messages.run.${reason === 'error' ? 'stalled' : reason}`)

            return `${why} ${__('asset-usage::messages.run.retry', { command: this.command })}`
        },
    },
}
</script>

<template>
    <!-- The message comes from the server, so it is set as text, never as HTML. -->
    <Alert class="mb-4" variant="warning">
        <Heading :text="heading" />
        <p class="mt-0.5 text-sm" v-text="text" />
    </Alert>
</template>
