<script>
import { Button } from '@statamic/cms/ui'
import { escapeHtml } from '../support/formatting.js'

/** Thumbnails per request, the most ExportController::makeThumbnails() takes. */
const BATCH_SIZE = 5

/**
 * The list as a PDF, with the page's current filters and order. The images
 * without a thumbnail are sent a few at a time first, the way "Compress all"
 * sends its images, so no request has to decode hundreds of originals. The
 * button counts them meanwhile, then waits for the PDF and saves it.
 *
 * Fetched rather than linked to: Statamic's Button turns an href into an
 * Inertia link, which can't take a file.
 */
export default {
    components: { Button },

    props: {
        /** The page's current filters and sort, the same the list is loaded with. */
        params: Object,
        url: String,
        thumbnailsUrl: String,
        makeThumbnailsUrl: String,
        disabled: Boolean,
    },

    data() {
        return {
            /** The download in progress, null when none is. */
            run: null,
        }
    },

    computed: {
        text() {
            if (!this.run) return __('asset-usage::messages.export.download')

            if (this.run.total) {
                return __('asset-usage::messages.export.creating_thumbnails', { done: this.run.done, count: this.run.total })
            }

            return __('asset-usage::messages.export.creating')
        },
    },

    beforeUnmount() {
        // Leaving the page ends the run after the request in flight, and nothing is saved.
        if (this.run) this.run.stopping = true
    },

    methods: {
        async download() {
            if (this.run) return

            this.run = { done: 0, total: 0, stopping: false }

            // Taken back from this.run, which is the reactive version, so the button follows the counts.
            const run = this.run

            // The dates in the PDF are in the viewer's timezone, like the ones on the page.
            const params = { ...this.params, page: undefined, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone }

            try {
                const ids = (await this.$axios.get(this.thumbnailsUrl, { params })).data.ids

                run.total = ids.length

                for (let i = 0; i < ids.length && !run.stopping; i += BATCH_SIZE) {
                    const batch = ids.slice(i, i + BATCH_SIZE)

                    // A batch that fails leaves those images without a thumbnail, which the PDF can do without.
                    await this.$axios.post(this.makeThumbnailsUrl, { ids: batch }).catch(() => {})
                    run.done += batch.length
                }

                run.total = 0

                if (!run.stopping) {
                    const response = await this.$axios.get(this.url, { params, responseType: 'blob' })

                    if (!run.stopping) this.save(response)
                }
            } catch (error) {
                if (!run.stopping) this.$toast.error(escapeHtml(await this.errorMessage(error)))
            }

            this.run = null
        },

        save(response) {
            const disposition = response.headers['content-disposition'] ?? ''
            const filename = disposition.match(/filename="?([^";]+)"?/)?.[1] ?? 'asset-usage.pdf'
            const url = URL.createObjectURL(response.data)
            const link = Object.assign(document.createElement('a'), { href: url, download: filename })

            document.body.appendChild(link)
            link.click()
            link.remove()

            // Revoked once the click has been handled; some browsers cancel the download otherwise.
            setTimeout(() => URL.revokeObjectURL(url), 1000)
        },

        /** A refusal on the PDF itself arrives as a blob, since that is what was asked for. */
        async errorMessage(error) {
            let data = error.response?.data

            if (data instanceof Blob) {
                try {
                    data = JSON.parse(await data.text())
                } catch {
                    data = null
                }
            }

            return data?.message ?? __('asset-usage::messages.export.failed')
        },
    },
}
</script>

<template>
    <Button
        icon="download"
        :disabled="disabled || run !== null"
        :text="text"
        @click="download"
    />

    <!-- The count for screen readers, which don't follow a button's text as it changes. -->
    <span class="sr-only" role="status" v-text="run ? text : ''" />
</template>
