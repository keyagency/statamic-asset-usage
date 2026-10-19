<script>
import { Alert, Description, Heading } from '@statamic/cms/ui'

/** The image driver can't be built on this server, so nothing can be analysed or compressed. */
export default {
    components: { Alert, Description, Heading },

    props: {
        /** The requirements from the compression status: the driver and where to read about installing it. */
        requirements: Object,
    },
}
</script>

<template>
    <Alert class="mb-4" variant="warning">
        <Heading :text="__('asset-usage::messages.compress.unavailable_heading')" />
        <Description :text="__('asset-usage::messages.compress.unavailable', { driver: requirements.driver })" />
        <a
            v-if="requirements.driver_install_url"
            class="mt-1 inline-block text-sm font-medium underline hover:no-underline"
            :href="requirements.driver_install_url"
            target="_blank"
            rel="noopener noreferrer"
            v-text="__('asset-usage::messages.compress.driver_link', { driver: requirements.driver })"
        />
    </Alert>
</template>
