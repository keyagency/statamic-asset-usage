<script>
import { Button } from '@statamic/cms/ui'
import { escapeHtml } from '../support/formatting.js'
import { joinPdf } from '../support/joinPdf.js'

/** Thumbnails per request, the most ExportController::makeThumbnails() takes. */
const BATCH_SIZE = 5

/**
 * The list as a PDF, with the page's current filters and order. The images
 * without a thumbnail are sent a few at a time first, the way "Compress all"
 * sends its images, so no request has to decode hundreds of originals. The
 * PDF itself comes in parts of a few hundred rows, which the page joins into
 * one file, since dompdf slows down sharply on a long one. The button counts
 * both meanwhile.
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

            if (this.run.parts > 1) {
                return __('asset-usage::messages.export.creating_part', { number: this.run.part, total: this.run.parts })
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

            this.run = { done: 0, total: 0, part: 0, parts: 0, stopping: false }

            // Taken back from this.run, which is the reactive version, so the button follows the counts.
            const run = this.run

            // The dates in the PDF are in the viewer's timezone, like the ones on the page.
            const params = { ...this.params, page: undefined, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone }

            try {
                const { ids, parts } = (await this.$axios.get(this.thumbnailsUrl, { params })).data

                run.total = ids.length

                for (let i = 0; i < ids.length && !run.stopping; i += BATCH_SIZE) {
                    const batch = ids.slice(i, i + BATCH_SIZE)

                    // A batch that fails leaves those images without a thumbnail, which the PDF can do without.
                    await this.$axios.post(this.makeThumbnailsUrl, { ids: batch }).catch(() => {})
                    run.done += batch.length
                }

                run.total = 0
                run.parts = parts

                const buffers = []
                let filename = null

                for (let part = 0; part < parts && !run.stopping; part++) {
                    run.part = part + 1

                    const response = await this.$axios.get(this.url, { params: { ...params, part }, responseType: 'arraybuffer' })

                    filename ??= this.filename(response)
                    buffers.push(response.data)
                }

                if (!run.stopping) {
                    const bytes = await joinPdf(buffers, (number, total) => __('asset-usage::messages.export.page', { number, total }))

                    if (!run.stopping) this.save(new Blob([bytes], { type: 'application/pdf' }), filename)
                }
            } catch (error) {
                if (!run.stopping) this.$toast.error(escapeHtml(await this.errorMessage(error)))
            }

            this.run = null
        },

        /** The name the server gives the first part, with the time it was made. */
        filename(response) {
            const disposition = response.headers['content-disposition'] ?? ''

            return disposition.match(/filename="?([^";]+)"?/)?.[1] ?? 'asset-usage.pdf'
        },

        save(blob, filename) {
            const url = URL.createObjectURL(blob)
            const link = Object.assign(document.createElement('a'), { href: url, download: filename })

            document.body.appendChild(link)
            link.click()
            link.remove()

            // Revoked once the click has been handled; some browsers cancel the download otherwise.
            setTimeout(() => URL.revokeObjectURL(url), 1000)
        },

        /** A refusal on the PDF itself arrives as bytes, since that is what was asked for. */
        async errorMessage(error) {
            let data = error.response?.data

            if (data instanceof ArrayBuffer) {
                try {
                    data = JSON.parse(new TextDecoder().decode(data))
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
