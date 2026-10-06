<script>
import { Button, ConfirmationModal } from '@statamic/cms/ui'
import { escapeHtml, formatBytes, formatDate } from '../support/formatting.js'

/** Images per request: small enough that one request never meets the time limit. */
const BATCH_SIZE = 3

/** Failures shown one by one afterwards; the rest are counted in one message. */
const MAX_FAILURE_TOASTS = 3

/**
 * "Compress all" on the Compression page: the button, the warning before it
 * starts and the progress while it runs. The images are sent a few at a time
 * rather than in one request, so a long run never meets the time limit, also
 * on the sync queue, and the page can show how far it is.
 */
export default {
    components: { Button, ConfirmationModal },

    props: {
        /** What a run would cover, from the overview's meta: count, savable_bytes and icc_lost. */
        summary: Object,
        /** The page's current filters, which the list of images follows. */
        params: Object,
        allUrl: String,
        batchUrl: String,
        keepOriginalsDays: Number,
        disabled: Boolean,
    },

    emits: ['start', 'finish'],

    data() {
        return {
            confirming: false,
            /** The run in progress, null when none is. */
            run: null,
        }
    },

    computed: {
        count() {
            return this.summary?.count ?? 0
        },

        title() {
            return __n('asset-usage::messages.compress.all_confirm_title', this.count, { count: this.count })
        },

        text() {
            return __n('asset-usage::messages.compress.all_confirm', this.count, {
                size: formatBytes(this.summary?.savable_bytes ?? 0),
            })
        },

        /** The date an original kept today would be kept until, the same as on the before and after page. */
        keepText() {
            if (this.keepOriginalsDays === null || this.keepOriginalsDays === undefined) {
                return __('asset-usage::messages.compress.all_keep_forever')
            }

            const date = new Date(Date.now() + this.keepOriginalsDays * 86400000)

            return __('asset-usage::messages.compress.all_keep', {
                date: formatDate(date.toISOString(), { preset: 'date', month: 'long' }),
            })
        },

        percent() {
            return this.run?.total ? Math.round((this.run.done / this.run.total) * 100) : 0
        },
    },

    beforeUnmount() {
        // Leaving the page ends the run after the batch in flight.
        if (this.run) this.run.stopping = true
    },

    methods: {
        /** Stopping waits for the batch in flight. */
        async start() {
            // A second confirm while the modal closes would start a second run over the same images.
            if (this.run) return

            this.confirming = false
            // Starts from the count on the button, so the progress never reads "0 of 0" while the list loads.
            this.run = { total: this.count, done: 0, compressed: 0, saved: 0, errors: [], stopping: false, failed: false }
            this.$emit('start')

            const run = this.run

            try {
                const ids = (await this.$axios.get(this.allUrl, { params: this.params })).data.ids

                run.total = ids.length

                for (let i = 0; i < ids.length && !run.stopping; i += BATCH_SIZE) {
                    const batch = ids.slice(i, i + BATCH_SIZE)
                    const result = (await this.$axios.post(this.batchUrl, { ids: batch })).data

                    run.done += batch.length
                    run.compressed += result.compressed
                    run.saved += result.saved_bytes
                    run.errors.push(...result.errors)

                    if (result.halt) break
                }
            } catch (error) {
                run.failed = true
                this.$toast.error(escapeHtml(error.response?.data?.message ?? error.message))
            }

            this.finish(run)
        },

        /** Once the page is gone Vue drops the finish event, so nothing reloads a list nobody sees. */
        finish(run) {
            const done = __n('asset-usage::messages.compress.all_done', run.compressed, {
                count: run.compressed,
                size: formatBytes(run.saved),
            })

            if (run.compressed) {
                this.$toast.success(done)
            } else if (!run.errors.length && !run.failed) {
                this.$toast.info(done)
            }

            run.errors
                .slice(0, MAX_FAILURE_TOASTS)
                .forEach(error => this.$toast.error(escapeHtml(`${error.path}: ${error.message}`)))

            const more = run.errors.length - MAX_FAILURE_TOASTS

            if (more > 0) {
                this.$toast.error(__n('asset-usage::messages.compress.all_failed_more', more, { count: more }))
            }

            this.run = null
            this.$emit('finish')
        },
    },
}
</script>

<template>
    <div v-if="run || count" class="mb-4">
        <div
            v-if="run"
            class="flex flex-col gap-3 rounded-lg border border-gray-200 px-3 py-2.5 sm:flex-row sm:items-center sm:gap-4 dark:border-gray-700"
        >
            <div class="min-w-0 flex-1">
                <p
                    id="asset-usage-compress-all-progress"
                    class="text-sm text-gray-700 tabular-nums dark:text-gray-300"
                    v-text="__('asset-usage::messages.compress.all_progress', { done: run.done, count: run.total })"
                />
                <div
                    class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                    role="progressbar"
                    aria-labelledby="asset-usage-compress-all-progress"
                    :aria-valuenow="run.done"
                    aria-valuemin="0"
                    :aria-valuemax="run.total"
                >
                    <div class="h-full rounded-full bg-green-600 transition-[width] dark:bg-green-500" :style="{ width: `${percent}%` }" />
                </div>
            </div>
            <Button
                class="shrink-0"
                size="sm"
                :disabled="run.stopping"
                :text="__('asset-usage::messages.compress.all_stop')"
                @click="run.stopping = true"
            />
        </div>

        <Button
            v-else
            :disabled="disabled"
            :text="`${__('asset-usage::messages.compress.all')} (${count})`"
            @click="confirming = true"
        />

        <ConfirmationModal
            :open="confirming"
            :title="title"
            :button-text="__('asset-usage::messages.compress.compress')"
            @confirm="start"
            @update:open="open => (confirming = open)"
        >
            <p class="mb-2 text-gray-700 antialiased dark:text-gray-200" v-text="text" />
            <p class="mb-2 text-sm text-gray-500 dark:text-gray-400" v-text="__('asset-usage::messages.compress.all_scope')" />
            <p class="mb-2 text-sm text-gray-500 dark:text-gray-400" v-text="keepText" />
            <p
                v-if="summary?.icc_lost"
                class="mb-2 text-sm text-amber-700 dark:text-amber-400"
                v-text="__n('asset-usage::messages.compress.all_icc', summary.icc_lost, { count: summary.icc_lost })"
            />
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300" v-text="__('asset-usage::messages.compress.all_stay')" />
        </ConfirmationModal>
    </div>
</template>
